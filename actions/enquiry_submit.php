<?php
/**
 * 询价提交接口（AJAX · FormData）
 * 返回 JSON：{ok:true, ref:"EN-20260929-0001"} 或 {ok:false, message:"..."}
 */
require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');

/* ---------- 1. 蜜罐：机器人静默放行 ---------- */
if (honeypot_tripped()) {
    echo json_encode(['ok' => true, 'ref' => '', 'message' => 'ok']);
    exit;
}

/* ---------- 2. CSRF ---------- */
if (!csrf_check()) {
    echo json_encode(['ok' => false, 'message' => t('enq.err_csrf')]);
    exit;
}

/* ---------- 3. 频率限制 ---------- */
if (!rate_limit('enquiry')) {
    echo json_encode(['ok' => false, 'message' => t('enq.err_rate')]);
    exit;
}

/* ---------- 4. 业务类型（决定客户必须填什么） ---------- */
$svcKey   = post('service_type');
$svcItem  = svc_one($svcKey);
if (!$svcItem) {
    $svcKey  = 'registration';
    $svcItem = svc_one('registration');
}
$svcMode = svc_mode($svcItem);

/* ---------- 5. 字段校验 ---------- */
$markName = post('mark_name');
$name     = post('name');
$email    = strtolower(post('email'));
$company  = post('company');
$country  = post('country');
$phone    = post('phone');
$note     = post('note');
$markType = post('mark_type', 'word');
$appType  = post('applicant_type', 'company');
$consent  = (int)post('consent', 0);
$itemsRaw = (string)($_POST['items_json'] ?? '[]');
$regNo    = post('existing_reg_no');

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => false, 'message' => t('enq.err_required')]);
    exit;
}
if ($svcMode === 'regno') {
    // 后续业务：只需商标号 + 联系方式
    if ($regNo === '') {
        echo json_encode(['ok' => false, 'message' => t('enq.err_no_regno')]);
        exit;
    }
    $markName = ''; // 不允许前端夹带
} elseif ($markName === '') {
    echo json_encode(['ok' => false, 'message' => t('enq.err_no_mark')]);
    exit;
}
if (!$consent) {
    echo json_encode(['ok' => false, 'message' => t('enq.err_consent')]);
    exit;
}

if (!in_array($markType, ['word', 'device', 'combined', 'sound', 'other'], true)) $markType = 'word';
if (!in_array($appType, ['individual', 'company', 'other'], true)) $appType = 'company';
if ($regNo !== '' && !preg_match('/^[A-Za-z0-9\-\/\.\s]{3,40}$/', $regNo)) $regNo = '';

/* ---------- 6. 规范化商品清单（仅新申请 / 检索类业务需要）并估价 ---------- */
$clean   = [];
$byClass = [];
if ($svcMode === 'classes') {
    $items = json_decode($itemsRaw, true);
    if (!is_array($items) || count($items) === 0) {
        echo json_encode(['ok' => false, 'message' => t('enq.err_no_items')]);
        exit;
    }
    foreach ($items as $it) {
        if (!is_array($it)) continue;
        $cls = preg_replace('/\D/', '', (string)($it['cls'] ?? ''));
        $cls = str_pad(substr($cls, 0, 2), 2, '0', STR_PAD_LEFT);
        $nm  = trim((string)($it['name'] ?? ''));
        if ($cls === '' || $nm === '') continue;
        $clean[] = [
            'cls'  => $cls,
            'grp'  => trim((string)($it['grp'] ?? '')),
            'grp_name' => mb_substr(trim((string)($it['grp_name'] ?? '')), 0, 255),
            'code' => trim((string)($it['code'] ?? '')),
            'name' => mb_substr($nm, 0, 120),
            'en'   => mb_substr(trim((string)($it['en'] ?? '')), 0, 200),
        ];
        $byClass[$cls] = ($byClass[$cls] ?? 0) + 1;
    }
    if (!count($clean)) {
        echo json_encode(['ok' => false, 'message' => t('enq.err_no_items')]);
        exit;
    }
}

$estTotal = svc_estimate($svcItem, $byClass);   // null = 协商定价
$estPrice = $estTotal === null ? 0.00 : (float)$estTotal;
$cur      = 'USD';
$lang     = lang();

/* ---------- 7. 落库 ---------- */
try {
    $ref = next_ref('enquiries', 'EN');

    $cols = ['ref','email','name','company','country','phone','mark_name','mark_type','applicant_type',
             'classes_json','items_count','class_count','est_price','currency','note','lang','ip','ua'];
    $vals = [
        $ref, $email, $name, $company, $country, $phone,
        mb_substr($markName, 0, 255), $markType, $appType,
        json_encode($clean, JSON_UNESCAPED_UNICODE),
        count($clean), count($byClass), $estPrice, $cur,
        mb_substr($note, 0, 4000), $lang, client_ip(), user_agent(),
    ];
    // 平滑迁移：字段未上线时自动跳过，不影响下单
    if (table_has_column('enquiries', 'service_type')) {
        $cols[] = 'service_type';    $vals[] = $svcKey;
    }
    if (table_has_column('enquiries', 'existing_reg_no')) {
        $cols[] = 'existing_reg_no'; $vals[] = mb_substr($regNo, 0, 40);
    }

    $sql = 'INSERT INTO enquiries (`' . implode('`,`', $cols) . '`,consent_terms,consent_at,status,source)'
         . ' VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ',1,NOW(),"new","web")';
    $id  = (int)db_exec($sql, $vals);
} catch (Exception $e) {
    app_log('enquiry insert failed: ' . $e->getMessage(), 'ERROR');
    echo json_encode([
        'ok'      => false,
        'message' => 'We could not save your enquiry. Please email us directly at ' . cfg('company_email') . ' — sorry for the inconvenience.',
    ]);
    exit;
}

/* ---------- 8. 图样（AJAX 预上传优先，表单直传兜底；失败不影响工单） ---------- */
$deviceId = 0;
if ($svcMode === 'classes') {
    $preId = (int)post('device_doc_id', 0);
    if ($preId > 0) {
        // 只认 24 小时内上传、尚未归属任何工单的图样，避免把别人的文件挂到本单
        $owned = (int)db_val(
            "SELECT COUNT(*) FROM documents
             WHERE id = ? AND owner_type = 'enquiry' AND owner_id = 0 AND kind = 'device'
               AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            [$preId], 0
        );
        if ($owned) {
            db_exec('UPDATE documents SET owner_id = ? WHERE id = ?', [$id, $preId]);
            db_exec('UPDATE enquiries SET device_file_id = ? WHERE id = ?', [$preId, $id]);
            $deviceId = $preId;
        } else {
            app_log('device pre-upload rejected: doc ' . $preId . ' not attachable', 'WARN');
        }
    }
    // 兜底：前端 AJAX 失败时浏览器仍会把文件随表单一起提交
    if ($deviceId === 0 && !empty($_FILES['device_file']) && !empty($_FILES['device_file']['tmp_name'])) {
        $up = save_upload('device_file', 'enquiry', $id, 'device', 0, 'client');
        if ($up['ok']) {
            db_exec('UPDATE enquiries SET device_file_id = ? WHERE id = ?', [$up['id'], $id]);
            $deviceId = (int)$up['id'];
        } else {
            app_log('device upload failed: ' . $up['error'], 'WARN');
        }
    }
}

/* ---------- 9. 邮件：客户确认 + 我们收到通知 ---------- */
$enq = db_one('SELECT * FROM enquiries WHERE id = ?', [$id]);
if ($enq) {
    mail_enquiry_received($enq);
    mail_admin_new_enquiry($enq, site_url('app/admin/enquiry.php?id=' . $id));
}

echo json_encode([
    'ok'      => true,
    'ref'     => $ref,
    'message' => t('enq.ok_title'),
]);
