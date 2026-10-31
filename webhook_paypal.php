<?php
/**
 * PayPal Webhook 接收端
 * 地址：https://example.com/app/webhook_paypal.php
 * 订阅事件：PAYMENT.CAPTURE.COMPLETED / PAYMENT.CAPTURE.DENIED / CHECKOUT.ORDER.APPROVED
 *
 * 必须验签 —— 否则任何人都能伪造"支付成功"。
 */
require_once __DIR__ . '/bootstrap.php';

$raw = file_get_contents('php://input');
if ($raw === '' ) { http_response_code(400); exit; }

$event = json_decode($raw, true);
if (!is_array($event) || empty($event['id'])) { http_response_code(400); exit; }

$eventId   = (string)$event['id'];
$eventType = (string)($event['event_type'] ?? '');

// 1) 验签
$err = null;
$verified = paypal_verify_webhook($_SERVER, $raw, $err);
if (!$verified) {
    app_log("paypal webhook signature FAILED ($eventId): $err", 'ERROR');
    http_response_code(400);
    exit;
}

// 2) 去重登记（幂等）
if (!webhook_register($eventId, $eventType, $raw, 1)) {
    http_response_code(200);   // 已处理过，直接确认
    echo 'duplicate';
    exit;
}

// 3) 处理
if ($eventType === 'PAYMENT.CAPTURE.COMPLETED') {
    $res = $event['resource'] ?? [];
    $captureId = (string)($res['id'] ?? '');
    $amount    = (float)($res['amount']['value'] ?? 0);
    $currency  = (string)($res['amount']['currency_code'] ?? 'USD');

    $ppOrderId = '';
    if (!empty($res['supplementary_data']['related_ids']['order_id'])) {
        $ppOrderId = (string)$res['supplementary_data']['related_ids']['order_id'];
    }
    $ourRef = '';
    if (!empty($res['purchase_units'][0]['reference_id'])) {
        $ourRef = (string)$res['purchase_units'][0]['reference_id'];
    }

    $orderId = 0;
    // 优先用 PayPal 订单号定位我们的付款单
    if ($ppOrderId !== '') {
        $orderId = (int)db_val('SELECT order_id FROM payments WHERE provider_ref=? AND method="paypal" ORDER BY id DESC LIMIT 1', [$ppOrderId], 0);
    }
    // 次之：用我们写入的 reference_id（订单号）
    if (!$orderId && $ourRef !== '') {
        $orderId = (int)db_val('SELECT id FROM orders WHERE ref=?', [$ourRef], 0);
    }

    if ($orderId && $amount > 0) {
        $isNew = apply_payment($orderId, $amount, 'paypal', $captureId, $raw);
        if ($isNew) {
            /* 邮件由 apply_payment() 统一发送，这里不再重复（2026-09-29） */
            db_exec('UPDATE payments SET currency=? WHERE provider_ref=?', [$currency, $captureId]);
        }
        app_log("paypal capture completed: order #$orderId amount $amount $currency", 'INFO');
    } else {
        app_log("paypal webhook: could not locate order for capture $captureId (ppOrder=$ppOrderId ref=$ourRef)", 'WARN');
    }
} elseif ($eventType === 'PAYMENT.CAPTURE.DENIED') {
    app_log('paypal capture denied: ' . $eventId, 'WARN');
}

webhook_mark_processed($eventId);
http_response_code(200);
echo 'ok';
