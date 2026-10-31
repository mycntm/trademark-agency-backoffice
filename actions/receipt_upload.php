<?php
/**
 * 客户上传电汇回单文件（AJAX 第一步，2026-10-15 新增）。
 *
 * 只负责把文件存好、返回 doc_id；真正的回单记录（币种 / 金额 / 状态）由
 * account.php 的 act=receipt 处理器在客户点「提交」时落库 —— 这样客户可以
 * 先选文件自动上传、再决定币种金额，前端体验更顺。
 *
 * 安全：
 *   - 登录校验（client_id）
 *   - CSRF 校验
 *   - 订单归属校验（只能传到自己名下的订单）
 *   - 文件类型 / 大小由 save_upload 统一把关（白名单 MIME + storage.max_upload_mb）
 * 金额一律以后端订单为准，此处不接收、不校验金额。
 */
require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=UTF-8');

/* 客户中心固定英文（与 account.php 一致），消息按英文返回 */
$T = function ($k) { return t_lang($k, [], 'en'); };

if (!client_id())                 json_out(['ok' => false, 'message' => 'Not signed in'], 401);
if (!is_post() || !csrf_check())  json_out(['ok' => false, 'message' => $T('enq.err_csrf')], 419);
if (!rate_limit('message'))       json_out(['ok' => false, 'message' => $T('enq.err_rate')], 429);

$orderId = (int)post('order_id');
$order = db_one('SELECT id FROM orders WHERE id=? AND client_id=?', [$orderId, client_id()]);
if (!$order) json_out(['ok' => false, 'message' => $T('pay.receipt_err_order')], 404);

if (empty($_FILES['receipt']) || empty($_FILES['receipt']['tmp_name'])) {
    json_out(['ok' => false, 'message' => $T('pay.receipt_err_nofile')]);
}

/* kind 用 'other'：documents.kind 是 ENUM('device','poa','id_doc','notice','certificate','other')，
   没有 'receipt' 值，传 receipt 会被 MySQL 截断报错。回单的归属由 receipts 表记录。 */
$up = save_upload('receipt', 'order', $orderId, 'other', 0, 'client', ['visible' => 0]);
if (!$up['ok']) {
    app_log('receipt upload failed for order ' . $orderId . ': ' . $up['error'], 'WARN');
    json_out(['ok' => false, 'message' => $up['error']]);
}

$name = isset($_FILES['receipt']['name']) ? substr((string)$_FILES['receipt']['name'], 0, 190) : '';
json_out(['ok' => true, 'doc_id' => (int)$up['id'], 'name' => $name]);
