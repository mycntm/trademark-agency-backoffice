<?php
/**
 * 工单详情：查看 → 出具报价 → 发送 → 生成订单并开通客户账号
 */
require_once __DIR__ . '/_layout.php';

$id  = (int)get('id', 0);
$enq = db_one('SELECT * FROM enquiries WHERE id = ?', [$id]);
if (!$enq) abort404('Enquiry not found');

$msg = '';
$err = '';

/* ---------- 保存 / 更新报价 ---------- */
if (is_post() && post('act') === 'save_quote') {
    if (!csrf_check()) {
        $err = t('enq.err_csrf');
    } else {
        // 一口价口径：后台只填一个「全包总价」，与客户在前端/邮件里看到的那一个数一致。
        // quotes 表的 official_fee/service_fee/translation_fee 三列保留做结构兼容：
        // 总价全额记入 service_fee，official/translation 记 0，extras 清空——
        // 客户页与报价邮件里「official+service+translation」的合并行因此恒等于 total，不会出现分项。
        $total    = round((float)post('total_fee', 0), 2);
        $official = 0.0;
        $service  = $total;
        $trans    = 0.0;
        $days     = (int)post('valid_days', (int)setting('quote_valid_days', 30));
        $noteQ    = post('quote_note');
        $extras   = [];
        $valid = date('Y-m-d', time() + max(1, $days) * 86400);

        $q = db_one('SELECT * FROM quotes WHERE enquiry_id = ? ORDER BY id DESC LIMIT 1', [$id]);
        if ($q) {
            db_exec('UPDATE quotes SET official_fee=?,service_fee=?,translation_fee=?,extras_json=?,total=?,valid_until=?,note=? WHERE id=?',
                [$official, $service, $trans, json_encode($extras, JSON_UNESCAPED_UNICODE), $total, $valid, $noteQ, $q['id']]);
            $qid = (int)$q['id'];
        } else {
            $ref = next_ref('quotes', 'Q');
            $token = random_token(24);
            $qid = (int)db_exec(
                'INSERT INTO quotes (enquiry_id,ref,official_fee,service_fee,translation_fee,extras_json,total,currency,valid_until,token,note,status)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,"draft")',
                [$id, $ref, $official, $service, $trans, json_encode($extras, JSON_UNESCAPED_UNICODE), $total,
                 $enq['currency'], $valid, $token, $noteQ]
            );
        }
        $msg = t('adm.saved');
        /* 处理动作：new 工单自动进入「处理中」，退出侧栏 New 计数（2026-10-04） */
        if ($enq['status'] === 'new') {
            db_exec("UPDATE enquiries SET status='processing' WHERE id=? AND status='new'", [$id]);
        }
    }
}

/* ---------- 发送报价给客户 ---------- */
if (is_post() && post('act') === 'send_quote') {
    if (!csrf_check()) {
        $err = t('enq.err_csrf');
    } else {
        $q = db_one('SELECT * FROM quotes WHERE enquiry_id = ? ORDER BY id DESC LIMIT 1', [$id]);
        if (!$q) {
            $err = lang() === 'zh' ? '请先保存报价。' : 'Please save the quotation first.';
        } else {
            db_exec("UPDATE quotes SET status='sent' WHERE id=?", [$q['id']]);
            db_exec("UPDATE enquiries SET status='quoted' WHERE id=?", [$id]);
            $q = db_one('SELECT * FROM quotes WHERE id=?', [$q['id']]);
            $url = site_url('quote.php?t=' . urlencode($q['token']));
            /* 勾选才发信：有时只想把报价挂上去让客户自己看，不想再发一封（2026-09-29） */
            if (ad_wants_notify()) {
                mail_quote_to_client($enq, $q, $url);
                $msg = t('adm.quote_sent_ok');
            } else {
                $msg = t('adm.notify_skipped');
            }
        }
    }
}

/* ---------- 生成订单 + 开通客户账号 ---------- */
if (is_post() && post('act') === 'create_order') {
    if (!csrf_check()) {
        $err = t('enq.err_csrf');
    } else {
        $q = db_one('SELECT * FROM quotes WHERE enquiry_id = ? ORDER BY id DESC LIMIT 1', [$id]);
        try {
            $orderId = create_order_from_enquiry($id, $q ? $q['id'] : 0, [
                'registrant_name' => post('registrant_name', $enq['company'] ?: $enq['name']),
                'registrant_addr' => post('registrant_addr', ''),
            ]);
            $order = db_one('SELECT * FROM orders WHERE id=?', [$orderId]);
            $client = db_one('SELECT * FROM clients WHERE id=?', [$order['client_id']]);
            /* 直达这一单的链接：客户点开即登录并直接落在订单页（2026-09-30） */
            $orderUrl = order_deeplink($client['id'], $order['id']);
            /* 不勾则不发「账号已开通」邮件；登录链接仍可在订单页「客户登录链接」处查到（2026-09-29） */
            if (ad_wants_notify()) {
                mail_account_ready($order, $client, $orderUrl);
            }
            $msg = t('adm.order_created_ok') . ' <a href="' . h(site_url('app/admin/order.php?id=' . $orderId)) . '">' . h($order['ref']) . '</a>'
                 . (ad_wants_notify() ? '' : ' <span class="lg-sm" style="opacity:.7;">(' . h(t('adm.notify_skipped')) . ')</span>');
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'order already exists') !== false) {
                $err = lang() === 'zh'
                    ? '该询价已生成过订单，重复建单已被拦截。请打开下方「已有订单」里的链接查看。'
                    : 'An order already exists for this enquiry (duplicate blocked). Open the existing order below.';
            } elseif (strpos($e->getMessage(), 'expired on') !== false) {
                // 2026-09-30：过期报价不许成交。给一句能直接照做的提示，别只抛英文异常。
                $err = lang() === 'zh'
                    ? '该报价已过有效期，不能按旧价生成订单。请先在上方「报价」里更新金额并把有效期往后延，然后再生成订单。'
                    : 'This quotation has expired, so an order cannot be created at the old price. Update the quotation above (amount and validity date) first, then create the order.';
            } else {
                $err = 'Failed: ' . $e->getMessage();
            }
            app_log('create_order failed: ' . $e->getMessage(), 'ERROR');
        }
    }
}

/* ---------- 状态 / 备注 ---------- */
if (is_post() && post('act') === 'set_status') {
    if (!csrf_check()) {
        $err = t('enq.err_csrf');
    } else {
        $st = post('status');
        if (in_array($st, ['new', 'processing', 'quoted', 'confirmed', 'declined', 'closed'], true)) {
            db_exec('UPDATE enquiries SET status=? WHERE id=?', [$st, $id]);
            $msg = t('adm.saved');
        }
    }
}
if (is_post() && post('act') === 'add_note') {
    if (!csrf_check()) {
        $err = t('enq.err_csrf');
    } else {
        $body = trim((string)post('note_body'));
        if ($body !== '') {
            db_exec('INSERT INTO messages (enquiry_id,sender_role,sender_id,body) VALUES (?, "admin", ?, ?)',
                [$id, admin_id(), $body]);
            $msg = t('adm.saved');
            /* 处理动作：new 工单自动进入「处理中」（2026-10-04） */
            if ($enq['status'] === 'new') {
                db_exec("UPDATE enquiries SET status='processing' WHERE id=? AND status='new'", [$id]);
            }
        }
    }
}

/* ---------- 合并到另一个工单（重复工单） ---------- */
if (is_post() && post('act') === 'merge') {
    if (!csrf_check()) {
        $err = t('enq.err_csrf');
    } else {
        $ref = strtoupper(trim((string)post('merge_ref')));
        $tgt = db_one('SELECT * FROM enquiries WHERE ref = ?', [$ref]);
        if (!$tgt) {
            $err = lang() === 'zh' ? '找不到该工单号。' : 'No enquiry with that reference.';
        } elseif ((int)$tgt['id'] === $id) {
            $err = lang() === 'zh' ? '不能合并到自己。' : 'Cannot merge an enquiry into itself.';
        } else {
            // 留言与附件迁到目标工单，避免「合并完东西都找不到了」
            db_exec('UPDATE messages  SET enquiry_id = ? WHERE enquiry_id = ?', [(int)$tgt['id'], $id]);
            db_exec('UPDATE documents SET owner_id   = ? WHERE owner_type = "enquiry" AND owner_id = ?', [(int)$tgt['id'], $id]);
            db_exec("UPDATE enquiries SET status = 'closed' WHERE id = ?", [$id]);
            if (table_has_column('enquiries', 'merged_into')) {
                db_exec('UPDATE `enquiries` SET `merged_into` = ? WHERE id = ?', [(int)$tgt['id'], $id]);
            }
            db_exec('INSERT INTO messages (enquiry_id,sender_role,sender_id,body) VALUES (?, "system", NULL, ?)',
                [(int)$tgt['id'], 'Merged from duplicate enquiry ' . ($enq['ref'] ?? '')]);
            admin_log_action('merge', 'enquiry', $id, 'merged into ' . $ref);
            redirect(site_url('app/admin/enquiry.php?id=' . (int)$tgt['id'] . '&merged=1'));
        }
    }
}

/* ---------- 删除工单（软删） ---------- */
if (is_post() && post('act') === 'delete') {
    if (!csrf_check()) {
        $err = t('enq.err_csrf');
    } else {
        if (table_has_column('enquiries', 'deleted_at')) {
            db_exec('UPDATE `enquiries` SET `deleted_at` = NOW() WHERE id = ?', [$id]);
        } else {
            // 迁移没跑：只能物理删，同时把留言一起清掉，别留下孤儿
            db_exec('DELETE FROM messages WHERE enquiry_id = ?', [$id]);
            db_exec('DELETE FROM enquiries WHERE id = ?', [$id]);
        }
        admin_log_action('delete', 'enquiry', $id, 'deleted enquiry ' . ($enq['ref'] ?? ''));
        redirect(site_url('app/admin/enquiries.php?deleted=1'));
    }
}

/* ---------- 彻底删除工单（物理删除，不可逆） ---------- */
if (is_post() && post('act') === 'hard_delete') {
    if (!csrf_check()) {
        $err = t('enq.err_csrf');
    } elseif (!admin_can_hard_delete()) {
        $err = lang() === 'zh' ? '权限不足：只读账号不能执行物理删除。' : 'Permission denied: viewer accounts cannot delete records.';
    } elseif (trim((string)post('confirm_text')) !== '#' . (int)$id) {
        $err = lang() === 'zh' ? '确认编号不匹配，删除已取消（需原样输入 #' . (int)$id . '）。'
                               : 'Confirmation text does not match — deletion cancelled (type #' . (int)$id . ' exactly).';
    } else {
        $r = hard_delete_enquiry($id);
        if ($r['ok']) {
            $st = $r['stats'];
            redirect(site_url('app/admin/enquiries.php?hard_deleted=' . (int)$id
                . '&doc=' . (int)$st['docs'] . '&msg=' . (int)$st['messages'] . '&q=' . (int)$st['quotes']));
        } elseif ($r['error'] === 'has_orders') {
            $err = lang() === 'zh'
                ? '该工单已经生成订单（' . implode('、', $r['refs']) . '），不能删除。请先彻底删除或作废这些订单。'
                : 'This enquiry already produced orders (' . implode(', ', $r['refs']) . '). Delete or void those orders first.';
        } else {
            $err = lang() === 'zh' ? '删除失败：' . $r['error'] : 'Delete failed: ' . $r['error'];
        }
    }
}

$enq    = db_one('SELECT * FROM enquiries WHERE id = ?', [$id]);
$quote  = db_one('SELECT * FROM quotes WHERE enquiry_id = ? ORDER BY id DESC LIMIT 1', [$id]);
$classes = json_decode((string)$enq['classes_json'], true);
if (!is_array($classes)) $classes = [];
$notes  = db_all('SELECT m.*, a.name AS admin_name FROM messages m LEFT JOIN admin_users a ON a.id=m.sender_id
                  WHERE m.enquiry_id = ? ORDER BY m.id DESC LIMIT 20', [$id]);
$device = $enq['device_file_id'] ? db_one('SELECT * FROM documents WHERE id=?', [$enq['device_file_id']]) : null;

admin_header('adm.enquiries', 'enquiries');
?>
<?php if ($msg): ?><?php echo ad_flash(strip_tags($msg, '<a>') ? $msg : '', 'ok'); ?><?php endif; ?>
<?php if ($err): ?><?php echo ad_flash($err, 'bad'); ?><?php endif; ?>
<?php if (get('merged') === '1'): ?><?php echo ad_flash(t('adm.merge_done'), 'ok'); ?><?php endif; ?>

<?php
// 业务类型（老数据可能没有 service_type 字段）
$svcKey   = isset($enq['service_type']) ? (string)$enq['service_type'] : '';
$svcItem  = $svcKey !== '' ? svc_one($svcKey) : null;
$svcMode  = svc_mode($svcItem);
$svcLabel = $svcItem ? ($svcItem['en'] . ' / ' . $svcItem['zh']) : t('adm.not_specified');
$regNo    = isset($enq['existing_reg_no']) ? (string)$enq['existing_reg_no'] : '';
$headName = ($svcMode === 'regno' && $regNo !== '') ? $regNo : $enq['mark_name'];
?>
<div class="ad-card" data-workspace-tab="overview" data-workspace-label="Overview">
    <h2>
        <span class="ad-mono"><?php echo h($enq['ref']); ?></span>
        · <?php echo h($headName); ?>
        <span class="ad-tag <?php echo h($enq['status']); ?>" style="margin-left:8px;"><?php echo h(enquiry_status_label($enq['status'])); ?></span>
    </h2>
    <div class="form-row">
        <div style="grid-column:1/-1;"><label><?php echo tp('adm.service_type'); ?></label>
            <div><?php echo h($svcLabel); ?>
                <?php if ($svcItem && !empty($svcItem['negotiable'])): ?>
                    <span class="ad-tag" style="background:#fff7ed;color:#92400e;margin-left:6px;"><?php echo h(t('adm.complex_case')); ?></span>
                <?php endif; ?>
            </div>
            <?php if ($svcItem && $svcItem['price'] !== null): ?>
                <div class="lg-sm" style="opacity:.7;">List price: <?php echo h(svc_price_text($svcItem)); ?></div>
            <?php endif; ?>
        </div>
        <?php if ($svcMode === 'regno'): ?>
        <div><label><?php echo tp('adm.reg_no'); ?></label><div style="font-size:15px;font-weight:700;"><?php echo h($regNo ?: '—'); ?></div></div>
        <?php endif; ?>
        <div><label><?php echo tp('client'); ?></label>
            <div><?php echo h($enq['name']); ?> &lt;<?php echo h($enq['email']); ?>&gt;</div>
            <?php if ($enq['company']): ?><div class="lg-sm" style="opacity:.7;"><?php echo h($enq['company']); ?></div><?php endif; ?>
        </div>
        <div><label><?php echo tp('country'); ?></label><div><?php echo h($enq['country'] ?: '—'); ?></div></div>
        <div><label><?php echo tp('phone'); ?></label><div><?php echo h($enq['phone'] ?: '—'); ?></div></div>
        <div><label><?php echo tp('enq.applicant_type'); ?></label><div><?php echo h($enq['applicant_type']); ?></div></div>
        <?php if ($svcMode !== 'regno'): ?>
        <div><label><?php echo tp('enq.mark_name'); ?></label><div><?php echo h($enq['mark_name']); ?> <span class="lg-sm" style="opacity:.7;"><?php echo h($enq['mark_type']); ?></span></div></div>
        <?php endif; ?>
        <div><label><?php echo tp('adm.est_price'); ?></label><div><?php echo ((float)$enq['est_price'] > 0) ? h(money($enq['est_price'], $enq['currency'])) : h(t('enq.quote_on_request')); ?></div></div>
        <div><label><?php echo tp('adm.submitted'); ?></label><div><?php echo h($enq['created_at']); ?></div></div>
        <div><label>Consent</label><div><?php echo $enq['consent_terms'] ? ('✓ ' . h($enq['consent_at'])) : '—'; ?></div></div>
    </div>

    <?php if ($enq['note']): ?>
        <div style="background:#f8fafc;border:1px solid var(--bd);border-radius:8px;padding:12px;font-size:13.5px;white-space:pre-wrap;"><?php echo h($enq['note']); ?></div>
    <?php endif; ?>

    <?php if ($device): ?>
    <?php
    $devUrl  = site_url('app/admin/file.php?id=' . $device['id']);
    $devPrev = $devUrl . '&inline=1';
    $devImg  = strpos((string)$device['mime'], 'image/') === 0;
    ?>
    <div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--bd);">
        <label style="display:block;margin-bottom:8px;"><?php echo tp('adm.device'); ?></label>
        <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;">
            <a href="<?php echo h($devUrl); ?>" target="_blank" title="Open full size"
               style="flex:none;width:104px;height:104px;border:1px solid var(--bd);border-radius:9px;background:#fff;
                      display:flex;align-items:center;justify-content:center;overflow:hidden;text-decoration:none;">
                <?php if ($devImg): ?>
                    <img src="<?php echo h($devPrev); ?>" alt="" style="max-width:100%;max-height:100%;object-fit:contain;">
                <?php else: ?>
                    <i class="fas fa-file-pdf" style="font-size:34px;color:#9ca3af;"></i>
                <?php endif; ?>
            </a>
            <div style="min-width:0;">
                <div style="font-size:14px;font-weight:600;word-break:break-all;"><?php echo h($device['original_name']); ?></div>
                <div class="lg-sm" style="opacity:.7;margin-top:3px;">
                    <?php echo h($device['mime']); ?> · <?php echo h(number_format((int)$device['size'] / 1024, 0)); ?> KB · <?php echo h($device['created_at']); ?>
                </div>
                <div style="margin-top:7px;">
                    <a class="btn btn-s" href="<?php echo h($devUrl); ?>"><i class="fas fa-download"></i> Download</a>
                    <a class="btn btn-s" href="<?php echo h($devUrl); ?>" target="_blank">Open in new tab</a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if ($svcMode === 'classes'): ?>
<!-- 类别与商品 -->
<div class="ad-card" data-workspace-tab="goods" data-workspace-label="Goods & classes">
    <h2><?php echo tp('adm.classes'); ?> <span class="lg-sm"><?php echo (int)$enq['class_count']; ?> classes / <?php echo (int)$enq['items_count']; ?> items</span></h2>
    <?php
    // classes_normalize：兼容前台询价（cls 扁平）与后台手工建工单（class 嵌套）两种结构
    $by = classes_normalize($classes);
    foreach ($by as $cls => $items): ?>
        <div style="margin-bottom:10px;">
            <div style="font-weight:700;font-size:13px;">Class <?php echo (int)$cls; ?></div>
            <div style="font-size:12.5px;color:#4b5563;line-height:1.8;">
                <?php $lastGroup = ''; foreach ($items as $it): ?>
                    <?php if ($it['grp'] !== '' && $it['grp'] !== $lastGroup): ?>
                        <div style="margin-top:5px;font-weight:700;color:#4a5fc1;">Similar-goods group <?php echo h($it['grp']); ?><?php echo $it['grp_name'] !== '' ? ' · ' . h($it['grp_name']) : ''; ?></div>
                        <?php $lastGroup = $it['grp']; ?>
                    <?php endif; ?>
                    <?php if ($it['code'] !== ''): ?><span class="ad-mono" style="color:#9ca3af;"><?php echo h($it['code']); ?></span> · <?php endif; ?>
                    • <?php echo h($it['name'] !== '' ? $it['name'] : $it['en']); ?><?php echo ($it['name'] !== '' && $it['en'] !== '') ? ' <span style="color:#9ca3af;">/ ' . h($it['en']) . '</span>' : ''; ?><br>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- 报价 -->
<div class="ad-card" data-workspace-tab="quote" data-workspace-label="Quote & order">
    <h2><?php echo tp('adm.create_quote'); ?><?php if ($quote): ?> <span class="lg-sm ad-mono"><?php echo h($quote['ref']); ?> · <?php echo h($quote['status']); ?></span><?php endif; ?></h2>
    <form method="post">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="act" value="save_quote">
        <div class="form-row">
            <div><label for="total_fee">All-inclusive total fee (<?php echo h($quote['currency'] ?? $enq['currency']); ?>)</label>
                <input type="number" step="0.01" min="0" id="total_fee" name="total_fee" value="<?php echo h($quote['total'] ?? $enq['est_price']); ?>"></div>
            <div><label for="valid_days">Validity (days)</label>
                <input type="number" id="valid_days" name="valid_days" value="<?php echo h((int)setting('quote_valid_days', 30)); ?>"></div>
        </div>
        <div class="lg-sm" style="opacity:.75;margin:2px 0 10px;line-height:1.6;">
            <?php echo lang() === 'zh'
                ? '客户只会看到一个「全部包含」的价格：商标局官费、我方服务费、翻译与制文都含在这一个数里，不再分项列示。'
                : 'The client sees one all-inclusive figure — the CNIPA official fee, our professional fee, and translation/documentation are all inside. Nothing is billed separately.'; ?>
        </div>
        <div class="form-row">
            <div><label for="quote_note">Note to client</label>
                <textarea id="quote_note" name="quote_note" rows="2"><?php echo h($quote['note'] ?? ''); ?></textarea></div>
        </div>
        <button class="btn btn-p" type="submit"><?php echo h(t('save')); ?></button>
        <?php if ($quote): ?>
            <span style="margin-left:14px;font-size:16px;font-weight:700;"><?php echo tp('total'); ?>: <?php echo h(money($quote['total'], $quote['currency'])); ?></span>
        <?php endif; ?>
    </form>

    <?php if ($quote): ?>
    <form method="post" style="margin-top:16px;">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="act" value="send_quote">
        <?php echo ad_notify_field(true, 'notify_send_quote'); ?>
        <button class="btn" type="submit"><i class="fas fa-paper-plane"></i> <?php echo h(t('adm.send_quote')); ?></button>
        <span class="lg-sm" style="margin-left:10px;opacity:.7;">Token: <?php echo h(substr($quote['token'], 0, 12)); ?>…</span>
    </form>
    <?php endif; ?>
</div>

<!-- 生成订单 -->
<?php
// 已有未作废订单时不再显示建单表单（防重复建单，2026-09-29）
$existOrder = db_one('SELECT * FROM orders WHERE enquiry_id = ?'
    . (table_has_column('orders', 'voided_at') ? ' AND voided_at IS NULL' : '')
    . ' ORDER BY id DESC LIMIT 1', [$id]);
?>
<div class="ad-card" data-workspace-tab="quote" data-workspace-label="Quote & order">
    <h2><?php echo tp('adm.create_order'); ?></h2>
    <?php if ($existOrder): ?>
        <div class="ad-alert ok">
            <?php echo lang() === 'zh' ? '该询价已生成订单：' : 'An order already exists for this enquiry: '; ?>
            <a href="<?php echo h(site_url('app/admin/order.php?id=' . (int)$existOrder['id'])); ?>"><b><?php echo h($existOrder['ref']); ?></b></a>
            <span class="lg-sm" style="opacity:.7;">(<?php echo h(order_status_label($existOrder['status'])); ?>)</span>
            <div class="lg-sm" style="margin-top:6px;opacity:.75;">
                <?php echo lang() === 'zh'
                    ? '为避免重复建单，这里不再显示建单表单；如需重开，请先把原订单作废。'
                    : 'The order form is hidden to prevent duplicates. Void the existing order first if you need to re-create one.'; ?>
            </div>
        </div>
    <?php else: ?>
    <form method="post">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="act" value="create_order">
        <div class="form-row">
            <div><label for="registrant_name">Registrant name</label>
                <input type="text" id="registrant_name" name="registrant_name" value="<?php echo h($enq['company'] ?: $enq['name']); ?>"></div>
            <div><label for="registrant_addr">Registrant address</label>
                <input type="text" id="registrant_addr" name="registrant_addr" value=""></div>
        </div>
        <?php echo ad_notify_field(true, 'notify_create_order'); ?>
        <button class="btn btn-p" type="submit"><i class="fas fa-file-signature"></i> <?php echo h(t('adm.create_order')); ?></button>
        <div class="lg-sm" style="margin-top:10px;opacity:.75;">
            <?php echo lang() === 'zh'
                ? '将固化商标名称、图样、类别与注册人四项要素，生成订单、开通客户账号并邮件发送登录链接。'
                : 'This locks in the four elements (name, device, classes, registrant), creates the order, opens the client account and emails a sign-in link.'; ?>
        </div>
    </form>
    <?php endif; ?>
</div>

<!-- 状态 / 备注 -->
<div class="ad-card" data-workspace-tab="notes" data-workspace-label="Notes & status">
    <div class="form-row">
        <form method="post" style="display:flex;gap:8px;align-items:flex-end;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="act" value="set_status">
            <div><label for="status"><?php echo tp('status'); ?></label>
                <select name="status" id="status">
                    <?php foreach (['new','processing','quoted','confirmed','declined','closed'] as $s): ?>
                        <option value="<?php echo h($s); ?>" <?php echo $enq['status'] === $s ? 'selected' : ''; ?>><?php echo h(enquiry_status_label($s)); ?></option>
                    <?php endforeach; ?>
                </select></div>
            <button class="btn" type="submit"><?php echo h(t('save')); ?></button>
        </form>
    </div>
    <form method="post" style="margin-top:6px;">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="act" value="add_note">
        <div class="form-row">
            <div><label for="note_body"><?php echo tp('internal_note'); ?></label>
                <textarea id="note_body" name="note_body" rows="2"></textarea></div>
        </div>
        <button class="btn" type="submit"><i class="fas fa-plus"></i> <?php echo h(t('save')); ?></button>
    </form>
    <?php if ($notes): ?>
        <div style="margin-top:14px;">
            <?php foreach ($notes as $n): ?>
                <div style="border-left:3px solid var(--bd);padding:8px 12px;margin-bottom:8px;font-size:13px;">
                    <div style="color:#9ca3af;font-size:11.5px;"><?php echo h($n['admin_name'] ?: 'admin'); ?> · <?php echo h($n['created_at']); ?></div>
                    <div style="white-space:pre-wrap;"><?php echo h($n['body']); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php if (empty($enq['merged_into'])): ?>
<!-- 合并 / 删除 -->
<div class="ad-card" data-workspace-tab="manage" data-workspace-label="Merge & delete">
    <h2 style="color:var(--bad);"><i class="fas fa-code-merge"></i> <?php echo lang() === 'zh' ? '重复工单：合并 / 删除' : 'Duplicate: merge or delete'; ?></h2>
    <form method="post" class="form-row" onsubmit="return confirm('<?php echo h(t('adm.confirm_delete')); ?>');">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="act" value="merge">
        <div><label for="merge_ref"><?php echo tp('adm.merge_into'); ?></label>
            <input type="text" id="merge_ref" name="merge_ref" placeholder="EN-20260929-0007"
                   style="text-transform:uppercase;"></div>
        <div style="display:flex;align-items:flex-end;">
            <button class="btn" type="submit"><i class="fas fa-code-merge"></i> <?php echo h(t('adm.merged')); ?></button></div>
        <div style="grid-column:1/-1;font-size:12.5px;color:#6b7280;">
            <?php echo lang() === 'zh'
                ? '本工单的留言与附件会迁到目标工单，本单标记「已合并」并关闭 —— 不会丢东西。'
                : 'Messages and attachments move to the target enquiry; this one is marked merged and closed. Nothing is lost.'; ?>
        </div>
    </form>
    <form method="post" style="margin-top:10px;" onsubmit="return confirm('<?php echo h(t('adm.confirm_delete')); ?>');">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="act" value="delete">
        <button class="btn btn-d" type="submit"><i class="fas fa-trash"></i> <?php echo h(t('delete')); ?></button>
        <span class="lg-sm" style="margin-left:10px;opacity:.7;">
            <?php echo lang() === 'zh' ? '软删除：不再出现在列表里，记录仍可追溯。' : 'Soft delete: hidden from the list, records remain traceable.'; ?>
        </span>
    </form>

    <?php if (admin_can_hard_delete()): ?>
    <div style="margin-top:16px;padding-top:14px;border-top:1px dashed var(--border);">
        <div style="font-weight:700;font-size:13px;color:#b91c1c;margin-bottom:6px;">
            <i class="fas fa-radiation"></i> <?php echo lang() === 'zh' ? '彻底删除工单' : 'Permanently delete this enquiry'; ?>
        </div>
        <div class="lg-sm" style="opacity:.8;line-height:1.75;margin-bottom:8px;">
            <?php echo lang() === 'zh'
                ? '物理删除本工单及其附件（含磁盘文件）、留言、报价，<b>不可恢复</b>。已经生成订单的工单不给删。'
                : 'Physically deletes this enquiry with its attachments (files on disk included), messages and quotation. <b>Cannot be undone.</b> Enquiries that already produced an order cannot be deleted.'; ?>
        </div>
        <form method="post" onsubmit="return confirmHardDeleteEnq(this, <?php echo json_encode('#' . (int)$id); ?>);">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="act" value="hard_delete">
            <div class="form-row">
                <div><label for="confirm_text"><?php echo lang() === 'zh' ? '请输入工单编号以确认' : 'Type the enquiry id to confirm'; ?> <span class="ad-mono" style="color:#b91c1c;">#<?php echo (int)$id; ?></span></label>
                    <input type="text" id="confirm_text" name="confirm_text" autocomplete="off" placeholder="#<?php echo (int)$id; ?>"></div>
            </div>
            <button class="btn btn-d" type="submit"><i class="fas fa-trash-can"></i> <?php echo lang() === 'zh' ? '我确认，永久删除' : 'Yes, delete permanently'; ?></button>
        </form>
    </div>
    <script>
    function confirmHardDeleteEnq(form, ref) {
        var zh = <?php echo json_encode(lang() === 'zh'); ?>;
        var v = (form.querySelector('[name="confirm_text"]').value || '').trim();
        if (v !== ref) {
            alert((zh ? '请输入工单编号 ' : 'Please type the enquiry id ') + ref + (zh ? ' 才能删除。' : ' to confirm deletion.'));
            return false;
        }
        return confirm(zh
            ? '确认永久删除工单 ' + ref + '？此操作不可恢复。'
            : 'Permanently delete enquiry ' + ref + '? This cannot be undone.');
    }
    </script>
    <?php else: ?>
    <div class="lg-sm" style="margin-top:10px;opacity:.7;">
        <?php echo lang() === 'zh' ? '当前账号（只读）无权执行物理删除。' : 'Your account (viewer) is not allowed to delete records.'; ?>
    </div>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="ad-alert warn">
    <?php echo h(t('adm.merged')); ?> →
    <a href="<?php echo h(site_url('app/admin/enquiry.php?id=' . (int)$enq['merged_into'])); ?>">
        <?php echo h((string)$enq['merged_into']); ?></a>
</div>
<?php endif; ?>

<p style="margin-top:6px;"><a class="btn btn-s" href="<?php echo h(site_url('app/admin/enquiries.php')); ?>">← <?php echo h(t('back')); ?></a></p>
<?php admin_footer(); ?>
