<?php
/**
 * 后台附件 AJAX 上传（订单详情页专用）
 *
 * 与前台 upload_device.php 同一套「无论出什么事都返回合法 JSON」的壳子，
 * 差别只在：这里挂的是真实订单（owner_id = order_id），且要管理员登录态。
 * 表单本身仍保留普通 POST 兜底（JS 失效时 act=upload 那条路照常能走）。
 *
 * 返回 JSON：{ok:true, saved:2, ids:[12,13], failed:[]}
 *        或 {ok:false, message:"...", error:"...", diag:{...}}
 */
require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');

$__sent = false;
/** 统一出口：任何路径都只输出一段 JSON，且只输出一次 */
function ud_out(array $payload, $code = 200)
{
    global $__sent;
    if ($__sent) return;
    $__sent = true;
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** 失败时附带的最小结构诊断（只有表/列名，不含业务数据） */
function ud_diag()
{
    $d = ['php' => PHP_VERSION];
    try {
        $n = (int)db_val("SELECT COUNT(*) FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents'", [], 0);
        $d['table'] = $n > 0 ? 'documents:yes' : 'documents:MISSING';
        if ($n > 0) {
            $cols = db_all("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents'
                            ORDER BY ORDINAL_POSITION");
            $names = [];
            foreach ($cols as $c) $names[] = $c['COLUMN_NAME'];
            $d['cols'] = implode(',', $names);
        }
    } catch (Throwable $e) {
        $d['probe'] = 'failed: ' . $e->getMessage();
    }
    return $d;
}

/** 兜底一：致命错误也必须吐 JSON，不能吐空白让前端 JSON.parse 炸掉 */
register_shutdown_function(function () {
    $e = error_get_last();
    if (!$e) return;
    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array((int)$e['type'], $fatal, true)) return;
    $where = basename((string)$e['file']) . ':' . (int)$e['line'];
    app_log('admin doc upload FATAL: ' . $e['message'] . ' @ ' . $where, 'ERROR');
    ud_out([
        'ok'      => false,
        'message' => lang() === 'zh' ? '服务器错误，上传失败。' : 'Server error — upload failed.',
        'error'   => 'fatal: ' . $e['message'] . ' @ ' . $where,
        'diag'    => ud_diag(),
    ], 500);
});

try {

    /* ---------- 1. 登录态（JSON 401，不做 redirect） ---------- */
    if (!admin_id()) {
        ud_out(['ok' => false, 'message' => 'Not signed in.', 'error' => 'auth'], 401);
    }

    /* ---------- 2. CSRF ---------- */
    if (!csrf_check()) {
        ud_out(['ok' => false, 'message' => t('enq.err_csrf'), 'error' => 'csrf'], 419);
    }

    /* ---------- 3. 订单必须存在 ---------- */
    $orderId = (int)post('order_id', 0);
    $order   = $orderId ? db_one('SELECT id FROM orders WHERE id = ?', [$orderId]) : null;
    if (!$order) {
        ud_out(['ok' => false, 'message' => 'Order not found.', 'error' => 'order'], 404);
    }

    /* ---------- 4. 类型 / 敏感标记 / 附加字段（与 order.php 表单处理完全同规则） ---------- */
    $kind = post('kind', 'other');
    if (!in_array($kind, ['device', 'poa', 'id_doc', 'notice', 'certificate', 'other'], true)) $kind = 'other';
    $sensitive = in_array($kind, ['poa', 'id_doc'], true) ? 1 : 0;
    $extra = [
        'event_id' => ((int)post('event_id') > 0 ? (int)post('event_id') : null),
        'title'    => trim((string)post('doc_title')),
        'note'     => trim((string)post('doc_note')),
        'visible'  => (int)post('doc_visible', 1) ? 1 : 0,
    ];

    /* ---------- 5. 保存（多文件逐个独立处理） ---------- */
    $up = save_uploads('files', 'order', $orderId, $kind, $sensitive, 'admin', $extra);
    if (!$up['ok']) {
        app_log('admin doc upload failed: ' . $up['error'], 'WARN');
        $out = [
            'ok'      => false,
            'message' => (lang() === 'zh' ? '上传失败：' : 'Upload failed: ') . $up['error'],
            'error'   => $up['error'],
        ];
        if (strpos((string)$up['error'], 'db write failed') === 0) $out['diag'] = ud_diag();
        ud_out($out, 400);
    }

    foreach ($up['ids'] as $did) {
        admin_log_action('upload', 'document', (int)$did, $kind . ($extra['event_id'] ? ' @event ' . $extra['event_id'] : ''));
    }

    // 与表单兜底路径同规则：登记注册证 = 订单推进到已注册
    if ($kind === 'certificate') {
        db_exec('UPDATE orders SET status="registered" WHERE id=? AND status IN ("published","examining")', [$orderId]);
    }

    ud_out([
        'ok'     => true,
        'saved'  => count($up['ids']),
        'ids'    => array_map('intval', $up['ids']),
        'failed' => $up['errors'],
    ]);

} catch (Throwable $e) {
    /* 兜底二：任何未捕获异常 */
    app_log('admin doc upload exception: ' . $e->getMessage(), 'ERROR');
    ud_out([
        'ok'      => false,
        'message' => lang() === 'zh' ? '服务器错误，上传失败。' : 'Server error — upload failed.',
        'error'   => 'exception: ' . $e->getMessage()
                     . ' @ ' . basename((string)$e->getFile()) . ':' . (int)$e->getLine(),
        'diag'    => ud_diag(),
    ], 500);
}
