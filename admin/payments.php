<?php
/**
 * 付款记录：筛选 / 退款登记 / 备注 / CSV 导出
 *
 * 003 迁移没跑时（没有 refund_amount、note 列），页面自动降级为「只能看」，
 * 不会 500 —— 迁移脚本和代码可以分开上线，谁先谁后都不炸。
 */
require_once __DIR__ . '/_layout.php';

$hasRefund = table_has_column('payments', 'refund_amount');
$hasNote   = table_has_column('payments', 'note');

/* ---------- 筛选条件（列表与导出共用同一套，导出的就是眼前看到的） ---------- */
$fStatus = (string)get('status', '');
$from    = (string)get('from', '');
$to      = (string)get('to', '');
$kw      = trim((string)get('kw', ''));

$statuses = ['created', 'pending', 'completed', 'failed', 'refunded', 'partially_refunded'];

$where = [];
$args  = [];
if ($fStatus !== '' && in_array($fStatus, $statuses, true)) { $where[] = 'p.`status` = ?';        $args[] = $fStatus; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from))             { $where[] = 'p.`created_at` >= ?';    $args[] = $from . ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))               { $where[] = 'p.`created_at` <= ?';    $args[] = $to . ' 23:59:59'; }
if ($kw !== '') {
    $where[] = '(o.`ref` LIKE ? OR o.`mark_name` LIKE ? OR p.`provider_ref` LIKE ? OR c.`email` LIKE ?)';
    for ($i = 0; $i < 4; $i++) $args[] = '%' . $kw . '%';
}
$wSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$selSql = 'SELECT p.*, o.`ref` AS order_ref, o.`mark_name`, c.`email` AS client_email
           FROM `payments` p
           LEFT JOIN `orders` o ON o.`id` = p.`order_id`
           LEFT JOIN `clients` c ON c.`id` = o.`client_id`';

$msg = '';
$err = '';

/* ---------- 导出 CSV（在输出任何 HTML 之前） ---------- */
if (get('export') === 'csv') {
    $rows = db_all($selSql . ' ' . $wSql . ' ORDER BY p.`id` DESC LIMIT 5000', $args);
    $file = 'payments-' . date('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // BOM：不加的话 Excel 打开 UTF-8 会乱码（2026-09-29 表头改英文后仍保留）
    fputcsv($out, ['ID', 'Order ref', 'Mark', 'Client email', 'Amount', 'Currency', 'Method', 'Status', 'Provider ref', 'Refunded', 'Refund note', 'Note', 'Paid at', 'Created at']);
    foreach ($rows as $p) {
        fputcsv($out, [
            (int)$p['id'],
            csv_cell($p['order_ref']),
            csv_cell($p['mark_name']),
            csv_cell($p['client_email']),
            (float)$p['amount'],
            csv_cell($p['currency']),
            csv_cell($p['method']),
            csv_cell($p['status']),
            csv_cell($p['provider_ref']),
            $hasRefund ? (float)$p['refund_amount'] : 0,
            csv_cell($hasRefund ? ($p['refund_note'] ?? '') : ''),
            csv_cell($hasNote ? ($p['note'] ?? '') : ''),
            csv_cell($p['paid_at']),
            csv_cell($p['created_at']),
        ]);
    }
    fclose($out);
    admin_log_action('export', 'payment', null, 'csv rows=' . count($rows));
    exit;
}

/* ---------- 登记退款 ---------- */
if (is_post() && post('act') === 'refund') {
    if (!csrf_check()) {
        $err = t('enq.err_csrf');
    } else {
        $pid = (int)post('id', 0);
        $amt = (float)post('amount', 0);
        $nt  = trim((string)post('note'));
        $r   = refund_payment($pid, $amt, $nt);
        if ($r['ok']) {
            admin_log_action('refund', 'payment', $pid, $r['amount'] . ' ' . $nt);
            $msg = t('adm.refund_done');
        } else {
            $err = lang() === 'zh' ? '退款登记失败：' . $r['error'] : 'Refund failed: ' . $r['error'];
        }
    }
}

/* ---------- 保存备注 ---------- */
if (is_post() && post('act') === 'note') {
    if (!csrf_check()) {
        $err = t('enq.err_csrf');
    } elseif ($hasNote) {
        db_exec('UPDATE `payments` SET `note` = ? WHERE id = ?', [trim((string)post('note')), (int)post('id', 0)]);
        admin_log_action('note', 'payment', (int)post('id', 0), '');
        $msg = t('adm.saved');
    }
}

/* ---------- 列表 ---------- */
$rows = db_all($selSql . ' ' . $wSql . ' ORDER BY p.`id` DESC LIMIT 500', $args);

// 口径：Received = 曾经到过账的钱。退款只改 status，不改「收过这笔钱」的事实——
// 若只算 completed，全额退款后 Received 归零、Net 变负数（2026-09-29 用户发现）。
$totalReceived = (float)db_val("SELECT IFNULL(SUM(amount),0) FROM payments WHERE status IN ('completed','refunded','partially_refunded')", [], 0);
$totalRefunded = $hasRefund
    ? (float)db_val('SELECT IFNULL(SUM(`refund_amount`),0) FROM `payments`', [], 0)
    : 0.0;
$paypalOn = paypal_enabled();

$qs = [];
if ($fStatus !== '') $qs['status'] = $fStatus;
if ($from !== '')    $qs['from']   = $from;
if ($to !== '')      $qs['to']     = $to;
if ($kw !== '')      $qs['kw']     = $kw;
$exportUrl = site_url('app/admin/payments.php?export=csv' . ($qs ? '&' . http_build_query($qs) : ''));

admin_header('adm.payments', 'payments');
?>
<?php if ($msg): ?><?php echo ad_flash($msg, 'ok'); ?><?php endif; ?>
<?php if ($err): ?><?php echo ad_flash($err, 'bad'); ?><?php endif; ?>

<div class="ad-stats">
    <div class="ad-stat"><div class="n"><?php echo money($totalReceived); ?></div><div class="l"><?php echo tp('adm.revenue'); ?></div></div>
    <div class="ad-stat"><div class="n" style="color:var(--bad);"><?php echo money($totalRefunded); ?></div><div class="l"><?php echo tp('adm.total_refunded'); ?></div></div>
    <div class="ad-stat"><div class="n" style="color:var(--ok);"><?php echo money($totalReceived - $totalRefunded); ?></div><div class="l"><?php echo tp('adm.net_received'); ?></div></div>
    <div class="ad-stat">
        <div class="n" style="font-size:18px;"><?php echo $paypalOn ? 'ON' : 'OFF'; ?></div>
        <div class="l">PayPal status</div>
    </div>
</div>

<!-- 筛选 -->
<div class="ad-card">
    <form method="get" class="form-row" style="align-items:flex-end;">
        <div><label for="f_status"><?php echo tp('status'); ?></label>
            <select name="status" id="f_status">
                <option value=""><?php echo lang() === 'zh' ? '全部' : 'All'; ?></option>
                <?php foreach ($statuses as $s): ?>
                    <option value="<?php echo h($s); ?>" <?php echo $fStatus === $s ? 'selected' : ''; ?>><?php echo h($s); ?></option>
                <?php endforeach; ?>
            </select></div>
        <div><label for="f_from"><?php echo tp('adm.date_from'); ?></label>
            <input type="date" id="f_from" name="from" value="<?php echo h($from); ?>"></div>
        <div><label for="f_to"><?php echo tp('adm.date_to'); ?></label>
            <input type="date" id="f_to" name="to" value="<?php echo h($to); ?>"></div>
        <div><label for="f_kw"><?php echo lang() === 'zh' ? '订单号 / 商标 / 流水号 / 邮箱' : 'Ref / mark / txn id / email'; ?></label>
            <input type="text" id="f_kw" name="kw" value="<?php echo h($kw); ?>"></div>
        <div style="display:flex;gap:8px;align-items:flex-end;">
            <button class="btn btn-p" type="submit"><i class="fas fa-filter"></i> <?php echo h(t('adm.filter')); ?></button>
            <a class="btn" href="<?php echo h(site_url('app/admin/payments.php')); ?>"><?php echo h(t('adm.reset')); ?></a>
            <a class="btn" href="<?php echo h($exportUrl); ?>"><i class="fas fa-file-csv"></i> <?php echo h(t('adm.export_csv')); ?></a>
        </div>
    </form>
    <div class="lg-sm" style="opacity:.75;font-size:12.5px;">
        <?php echo lang() === 'zh'
            ? '导出的是「当前筛选结果」，先看再导。CSV 带 BOM，Excel 直接打开不乱码；文本类单元格已做公式注入防护。'
            : 'The CSV contains exactly what the current filters show. It is written with a UTF-8 BOM so Excel opens it cleanly, and text cells are guarded against formula injection.'; ?>
    </div>
</div>

<div class="ad-card">
    <h2><?php echo tp('adm.payments'); ?> <span class="lg-sm" style="font-weight:400;opacity:.6;"><?php echo count($rows); ?> <?php echo lang() === 'zh' ? '条' : 'rows'; ?></span></h2>
    <?php if (!$rows): ?><div class="ad-empty"><?php echo h(t('adm.no_data')); ?></div>
    <?php else: ?>
    <table class="ad-tb">
        <tr><th><?php echo tp('adm.ref'); ?></th><th><?php echo tp('adm.mark_name'); ?></th><th><?php echo tp('amount'); ?></th>
            <th><?php echo tp('adm.payment_method'); ?></th><th><?php echo tp('status'); ?></th>
            <th><?php echo tp('adm.payment_ref'); ?></th><th><?php echo tp('date'); ?></th>
            <th><?php echo tp('actions'); ?></th></tr>
        <?php foreach ($rows as $p): ?>
        <?php
            $refunded = $hasRefund ? (float)($p['refund_amount'] ?? 0) : 0.0;
            $remain   = round((float)$p['amount'] - $refunded, 2);
            $tagCls   = in_array($p['status'], ['completed'], true) ? 'done'
                      : (in_array($p['status'], ['refunded', 'partially_refunded'], true) ? 'new' : '');
        ?>
        <tr>
            <td class="ad-mono"><?php echo h($p['order_ref']); ?></td>
            <td><?php echo h($p['mark_name']); ?>
                <div class="lg-sm" style="opacity:.6;font-size:12px;"><?php echo h($p['client_email']); ?></div></td>
            <td><?php echo h(money($p['amount'], $p['currency'])); ?>
                <?php if ($refunded > 0): ?>
                    <div style="color:var(--bad);font-size:12px;">-<?php echo h(money($refunded, $p['currency'])); ?></div>
                <?php endif; ?></td>
            <td><?php echo h($p['method']); ?></td>
            <td><span class="ad-tag <?php echo $tagCls; ?>"><?php echo h($p['status']); ?></span></td>
            <td class="ad-mono" style="font-size:12px;"><?php echo h($p['provider_ref']); ?></td>
            <td><?php echo h($p['paid_at'] ?: $p['created_at']); ?></td>
            <td><a class="btn btn-s" href="<?php echo h(site_url('app/admin/order.php?id=' . (int)$p['order_id'])); ?>"><?php echo h(t('detail')); ?></a></td>
        </tr>
        <tr>
            <td colspan="8" style="padding:0 12px 14px;background:#fafbff;">
                <details>
                    <summary style="cursor:pointer;font-size:12.5px;color:var(--pri);padding:6px 0;">
                        <i class="fas fa-rotate-left"></i>
                        <?php echo h(t('adm.refund')); ?> / <?php echo h(t('adm.payment_note')); ?>
                        <?php if (!empty($p['note'] ?? '')): ?><span class="lg-sm" style="color:var(--gray);"> · <?php echo h($p['note']); ?></span><?php endif; ?>
                        <?php if (!empty($p['refund_note'] ?? '')): ?><span class="lg-sm" style="color:var(--bad);"> · <?php echo h($p['refund_note']); ?></span><?php endif; ?>
                    </summary>

                    <div style="border-top:1px dashed var(--bd);padding-top:12px;">
                        <?php if (!$hasRefund): ?>
                            <div class="ad-alert warn"><?php echo lang() === 'zh'
                                ? '退款登记需要先执行 003 迁移（payments.refund_amount 等列）。'
                                : 'Run migration 003 first to enable refund recording (payments.refund_amount etc.).'; ?></div>
                        <?php elseif ($remain <= 0): ?>
                            <div class="ad-alert warn"><?php echo h(t('adm.refunded')); ?><?php if (!empty($p['refunded_at'])): ?> · <?php echo h($p['refunded_at']); ?><?php endif; ?></div>
                        <?php else: ?>
                        <form method="post" class="form-row" style="align-items:flex-end;"
                              onsubmit="return confirm('<?php echo h(t('adm.confirm_refund')); ?>');">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="act" value="refund">
                            <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                            <div><label><?php echo tp('adm.refund_amount'); ?></label>
                                <input type="number" step="0.01" min="0.01" max="<?php echo h($remain); ?>" name="amount" value="<?php echo h($remain); ?>"></div>
                            <div><label><?php echo tp('adm.refund_note'); ?></label>
                                <input type="text" name="note" placeholder="<?php echo lang() === 'zh' ? '如：官费未发生，退回官费部分' : 'e.g. official fee not incurred, refunding that part'; ?>"></div>
                            <div style="display:flex;align-items:flex-end;">
                                <button class="btn btn-d" type="submit"><i class="fas fa-rotate-left"></i> <?php echo h(t('adm.refund')); ?></button>
                            </div>
                            <div class="lg-sm" style="font-size:12px;opacity:.7;align-self:center;">
                                <?php echo lang() === 'zh'
                                    ? '可分批退：金额可改小，退满为止。客户会在订单页看到这条退款记录。'
                                    : 'Partial refunds are allowed — lower the amount and repeat until fully refunded. The client sees each refund on their order page.'; ?>
                            </div>
                        </form>
                        <?php endif; ?>

                        <?php if ($hasNote): ?>
                        <form method="post" class="form-row" style="align-items:flex-end;margin-bottom:0;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="act" value="note">
                            <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                            <div style="grid-column:span 2;"><label><?php echo tp('adm.payment_note'); ?></label>
                                <input type="text" name="note" value="<?php echo h($p['note'] ?? ''); ?>" placeholder="<?php echo lang() === 'zh' ? '内部备注，客户看不到' : 'Internal note, not shown to the client'; ?>"></div>
                            <div style="display:flex;align-items:flex-end;">
                                <button class="btn" type="submit"><i class="fas fa-floppy-disk"></i> <?php echo h(t('save')); ?></button>
                            </div>
                        </form>
                        <?php endif; ?>
                    </div>
                </details>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>

<div class="ad-card">
    <h2>PayPal <span class="lg-sm">Configuration</span></h2>
    <div style="font-size:13px;line-height:1.9;color:#4b5563;">
        <div>Mode: <b><?php echo !empty(cfg('paypal.sandbox')) ? 'Sandbox' : 'Live'; ?></b></div>
        <div>Client ID: <span class="ad-mono"><?php echo h(cfg('paypal.client_id') ? substr(cfg('paypal.client_id'), 0, 10) . '…' : '(not set)'); ?></span></div>
        <div>Webhook ID: <span class="ad-mono"><?php echo h(cfg('paypal.webhook_id') ?: '(not set)'); ?></span></div>
        <div>Webhook URL: <span class="ad-mono"><?php echo h(site_url('app/webhook_paypal.php')); ?></span></div>
    </div>
    <div style="margin-top:14px;">
        <a class="btn btn-s" href="<?php echo h(site_url('app/admin/paypal_test.php')); ?>">
            <i class="fas fa-plug"></i> <?php echo lang() === 'zh' ? '连通性自检（取 token / 建单）' : 'Connectivity self-test'; ?>
        </a>
    </div>
    <div class="lg-sm" style="margin-top:12px;opacity:.8;">
        <?php echo lang() === 'zh'
            ? '在 PayPal 开发者后台订阅事件：PAYMENT.CAPTURE.COMPLETED、PAYMENT.CAPTURE.DENIED、CHECKOUT.ORDER.APPROVED，并把 Webhook ID 填入 config.php。'
            : 'Subscribe to PAYMENT.CAPTURE.COMPLETED, PAYMENT.CAPTURE.DENIED and CHECKOUT.ORDER.APPROVED in the PayPal developer console, then paste the Webhook ID into config.php.'; ?>
    </div>
</div>
<?php admin_footer(); ?>
