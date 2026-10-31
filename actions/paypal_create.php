<?php
/**
 * 创建 PayPal 付款单（服务端建单 → 返回 approve_url 让前端跳转）
 * 金额一律以后端订单为准，不接受前端传入的金额。
 */
require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=UTF-8');

if (!client_id()) json_out(['ok' => false, 'message' => 'Not signed in'], 401);
if (!is_post() || !csrf_check()) json_out(['ok' => false, 'message' => t('enq.err_csrf')], 419);
if (!paypal_enabled() || setting('paypal_enabled', '0') !== '1') {
    json_out(['ok' => false, 'message' => t('pay.paypal_off')]);
}

$orderId = (int)post('order_id');
$order = db_one('SELECT * FROM orders WHERE id=? AND client_id=?', [$orderId, client_id()]);
if (!$order) json_out(['ok' => false, 'message' => 'Order not found'], 404);

// 作废的订单不接受付款（服务端拦截：前端藏了按钮，接口也必须挡住）
if (!empty($order['voided_at'])) {
    json_out(['ok' => false, 'message' => lang() === 'zh' ? '该订单已作废，不能付款。' : 'This order has been voided and cannot be paid.'], 409);
}

$due = (float)$order['total'] - (float)$order['paid_amount'];
if ($due <= 0) json_out(['ok' => false, 'message' => t('pay.paid')]);

// 已存在未完成的 PayPal 付款单则复用
$existing = db_one('SELECT * FROM payments WHERE order_id=? AND method="paypal" AND status IN ("created","pending") ORDER BY id DESC LIMIT 1', [$orderId]);

$err = null;
$res = paypal_create_order(
    [
        'ref'       => $order['ref'],
        'mark_name' => $order['mark_name'],
        'total'     => $due,
        'currency'  => $order['currency'] ?: 'USD',
    ],
    site_url('app/actions/paypal_return.php?oid=' . $orderId),
    site_url('account.php?order=' . $orderId . '&pp=cancel'),
    $err
);

if (!$res) {
    app_log('paypal create failed for order ' . $order['ref'] . ': ' . $err, 'ERROR');
    json_out(['ok' => false, 'message' => 'Could not start PayPal checkout. Please try again or use bank transfer.']);
}

if ($existing) {
    db_exec('UPDATE payments SET provider_ref=? WHERE id=?', [$res['id'], $existing['id']]);
} else {
    db_exec('INSERT INTO payments (order_id,amount,currency,method,provider,provider_ref,status)
             SELECT ?, ?, currency, "paypal", "paypal", ?, "created" FROM orders WHERE id=?',
        [$orderId, $due, $res['id'], $orderId]);
}

json_out(['ok' => true, 'id' => $res['id'], 'approve_url' => $res['approve_url']]);
