<?php
/**
 * PayPal 批准后回跳：服务端 capture 扣款
 * 即使客户付款后直接关掉页面，webhook 也会兜底更新，所以这里失败不致命。
 */
require_once __DIR__ . '/../bootstrap.php';

$oid     = (int)get('oid', 0);
$ppToken = get('token');

if (!client_id() || !$oid) {
    redirect(site_url('account.php'));
}

$order = db_one('SELECT * FROM orders WHERE id=? AND client_id=?', [$oid, client_id()]);
if (!$order || $ppToken === '') {
    redirect(site_url('account.php?order=' . $oid));
}

$pay = db_one('SELECT * FROM payments WHERE order_id=? AND provider_ref=? AND method="paypal" LIMIT 1', [$oid, $ppToken]);
if (!$pay) {
    redirect(site_url('account.php?order=' . $oid));
}

// 作废的订单不扣款：客户可能是在作废前就批准了这笔付款，这里直接放弃 capture
if (!empty($order['voided_at'])) {
    app_log('paypal capture skipped: order #' . $oid . ' is voided', 'WARN');
    db_exec('UPDATE payments SET status="failed" WHERE id=?', [$pay['id']]);
    redirect(site_url('account.php?order=' . $oid . '&pp=void'));
}

if ($pay['status'] !== 'completed') {
    $err = null;
    $cap = paypal_capture($ppToken, $err);
    if ($cap && in_array($cap['status'], ['COMPLETED', 'APPROVED'], true)) {
        $amount = (float)($cap['amount'] ?: $pay['amount']);
        // apply_payment 会复用 paypal_create 预插的那条 payments 行并更新为 completed，
        // 这里不能再 UPDATE 一次，否则同一 capture 流水号会出现两条记录。
        /* 「付款已收到」邮件改由 apply_payment() 统一发送（2026-09-29）：
           银行转账在后台登记时也要发，所以收口到 apply_payment 里，
           这里不能再发一次，否则 PayPal 客户会收到两封。 */
        apply_payment($oid, $amount, 'paypal', $cap['capture_id'] ?: $ppToken, json_encode($cap['raw']));
    } else {
        app_log('paypal capture failed for order #' . $oid . ': ' . $err, 'ERROR');
        db_exec('UPDATE payments SET status="failed" WHERE id=?', [$pay['id']]);
    }
}

redirect(site_url('account.php?order=' . $oid));
