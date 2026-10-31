<?php
/**
 * 客户在线签署服务合同（AJAX，2026-10-15 新增；2026-10-15 晚改为「前端整页渲染」方案）。
 *
 * 2026-10-15 晚修订（用户拍板）：**服务器不再用 GD 合成「印章+签名」小图**——
 *   旧方案有 GD alpha 填充 bug（黑底）且观感差。新方案：
 *   - 本脚本只保存客户手写签名 PNG（documents, kind='other'）。
 *   - 公司印章由 contract.php 在**已签署状态**下内联进合同正文（Provider 签字线上），
 *     该页与 account/invoice 同一套 client session 鉴权，只有合同当事方本人能打开。
 *   - 「整份合同 → 一张 PNG / 一个 PDF」由前端 html2canvas + jsPDF（assets/vendor，
 *     本地自托管）在浏览器里渲染导出，客户拿到的成品与打印版一致。
 *   - 印章源文件仍在 app/seal/company-seal.png（目录 .htaccess 禁止 web 直连），
 *     仅在已签署页以 data URI 内联出现；未签署的 DRAFT 页不含印章。
 *   - 权衡：签署后的合同成品（含印章）本来就会交付给客户，客户端可见印章
 *     与旧方案（合成图同样发给客户）暴露面相同，故接受此方案。
 *
 * 前端在 contract.php 用手写画布（canvas）采集签名，toBlob('image/png') 后以
 * 真正的 multipart 文件上传到这里（之所以不用 base64：save_upload 走
 * move_uploaded_file()，只认 HTTP 上传的临时文件，tempnam 造的临时文件会被判
 * 'move failed'；走真实上传就完全复用 save_upload 的 MIME / 大小 / 落盘 / 入库逻辑）。
 *
 * 安全：
 *   - 登录校验（client_id）
 *   - CSRF 校验
 *   - 频率限制（复用 message 桶）
 *   - 订单归属校验（只能签自己名下的订单）
 *   - 文件类型 / 大小由 save_upload 统一把关
 * 入库：documents（kind='other'，owner=order）+ contracts（upsert，status=signed）。
 * 幂等：同一订单重复签就以 ON DUPLICATE KEY UPDATE 覆盖（保留最后一次签名）。
 */

require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=UTF-8');

/* 客户中心固定英文（与 account.php / contract.php 一致） */
$T = function ($k) { return t_lang($k, [], 'en'); };

if (!client_id())                 json_out(['ok' => false, 'message' => 'Not signed in'], 401);
if (!is_post() || !csrf_check())  json_out(['ok' => false, 'message' => $T('enq.err_csrf')], 419);
if (!rate_limit('message'))       json_out(['ok' => false, 'message' => $T('enq.err_rate')], 429);

$orderId = (int)post('order_id');
$order = db_one('SELECT id FROM orders WHERE id=? AND client_id=?', [$orderId, client_id()]);
if (!$order) json_out(['ok' => false, 'message' => 'Order not found.'], 404);

$signedName = trim((string)post('signed_name', ''));
if (mb_strlen($signedName) < 2)  json_out(['ok' => false, 'message' => 'Please type your full name.']);
if (mb_strlen($signedName) > 120) $signedName = mb_substr($signedName, 0, 120);

if (empty($_FILES['signature']) || empty($_FILES['signature']['tmp_name'])) {
    json_out(['ok' => false, 'message' => 'Please sign in the box before submitting.']);
}

/* 只保存原始手写签名（无印章）；印章由 contract.php 在已签署页内联渲染。 */
$up = save_upload('signature', 'order', $orderId, 'other', 0, 'client', [
    'visible' => 0, /* 不在客户「Documents」列表里显示；后台合同卡片仍可见 */
    'title'   => 'Contract signature',
]);
if (!$up['ok']) {
    app_log('contract signature upload failed for order ' . $orderId . ': ' . $up['error'], 'WARN');
    json_out(['ok' => false, 'message' => $up['error']]);
}

ensure_contracts_table();
try {
    db_exec('INSERT INTO contracts (order_id, client_id, status, signed_name, signature_doc_id, signed_at)
             VALUES (?, ?, "signed", ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
               client_id = VALUES(client_id),
               status = "signed",
               signed_name = VALUES(signed_name),
               signature_doc_id = VALUES(signature_doc_id),
               signed_at = NOW()',
        [$orderId, client_id(), $signedName, (int)$up['id']]);
} catch (Throwable $e) {
    app_log('contract row upsert failed for order ' . $orderId . ': ' . $e->getMessage(), 'ERROR');
    json_out(['ok' => false, 'message' => 'The signature was saved but the contract could not be recorded. Please try again.']);
}

try { mail_admin_contract_signed($orderId, $signedName); }
catch (Throwable $e) { app_log('mail_admin_contract_signed failed: ' . $e->getMessage(), 'ERROR'); }

json_out(['ok' => true, 'doc_id' => (int)$up['id']]);
