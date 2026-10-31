<?php
/**
 * 后台概览
 */
require_once __DIR__ . '/_layout.php';

/* 软删除的工单不进任何统计与「最近询价」（与 enquiries.php 列表同一口径；迁移没跑时退化为全量） */
$enqAlive    = enq_alive_sql();
$enqAliveAnd = ($enqAlive !== '' ? " AND {$enqAlive}" : '');
$stats = [
    'new'   => (int)db_val("SELECT COUNT(*) FROM enquiries WHERE status='new'" . $enqAliveAnd, [], 0),
    'today' => (int)db_val("SELECT COUNT(*) FROM enquiries WHERE DATE(created_at)=CURDATE()" . $enqAliveAnd, [], 0),
    'open'  => (int)db_val("SELECT COUNT(*) FROM orders WHERE status IN ('pending_payment','paid','filed','examining','published','opposed')", [], 0),
    'pending_payment' => (int)db_val("SELECT COUNT(*) FROM orders WHERE status='pending_payment'", [], 0),
    'paid'  => (float)db_val("SELECT IFNULL(SUM(paid_amount),0) FROM orders", [], 0),
];
$recentE = db_all('SELECT * FROM enquiries ' . ($enqAlive !== '' ? "WHERE {$enqAlive} " : '') . 'ORDER BY id DESC LIMIT 8');
$recentO = db_all('SELECT o.*, c.email FROM orders o LEFT JOIN clients c ON c.id=o.client_id ORDER BY o.id DESC LIMIT 8');
$mailFail = (int)db_val("SELECT COUNT(*) FROM mail_queue WHERE status='failed'", [], 0);
$msgOpen  = 0;
try {
    $msgOpen = (int)db_val(
        "SELECT COUNT(*) FROM (
            SELECT m.order_id AS tid FROM messages m
            JOIN (SELECT order_id AS tid, MAX(id) AS last_id FROM messages
                  WHERE order_id IS NOT NULL GROUP BY order_id) t
                ON t.tid = m.order_id AND t.last_id = m.id
            WHERE m.sender_role = 'client'
            UNION ALL
            SELECT m.enquiry_id AS tid FROM messages m
            JOIN (SELECT enquiry_id AS tid, MAX(id) AS last_id FROM messages
                  WHERE enquiry_id IS NOT NULL GROUP BY enquiry_id) t
                ON t.tid = m.enquiry_id AND t.last_id = m.id
            WHERE m.sender_role = 'client'
        ) x", [], 0);
} catch (Exception $e) {}

admin_header('adm.dashboard', 'index');
?>
<?php if ($mailFail > 0): ?>
    <?php echo ad_flash(
        (lang() === 'zh' ? '有 ' : '') . $mailFail . (lang() === 'zh'
            ? ' 封邮件发送失败，请检查 SMTP 配置与 SPF/DKIM/DMARC，然后运行 app/cron/mail_queue.php 重投。'
            : ' emails failed to send. Check the SMTP settings and SPF/DKIM/DMARC, then run app/cron/mail_queue.php to retry.'),
        'bad'
    ); ?>
    <p style="margin:-6px 0 14px;">
        <a class="btn btn-s" href="<?php echo h(site_url('app/admin/smtp_test.php')); ?>">
            <i class="fas fa-envelope"></i> SMTP self-test &amp; failures
        </a>
    </p>
<?php endif; ?>

<div class="ad-work-actions">
    <a class="ad-work-action primary" href="<?php echo h(site_url('app/admin/enquiries.php?status=new')); ?>">
        <span class="icon"><i class="fas fa-inbox"></i></span>
        <span><b>Review new enquiries</b><small><?php echo (int)$stats['new']; ?> waiting for review</small></span>
        <i class="fas fa-arrow-right arrow"></i>
    </a>
    <a class="ad-work-action" href="<?php echo h(site_url('app/admin/enquiry_new.php')); ?>">
        <span class="icon"><i class="fas fa-envelope"></i></span>
        <span><b>Record an email enquiry</b><small>Add a request received outside the website</small></span>
        <i class="fas fa-arrow-right arrow"></i>
    </a>
    <a class="ad-work-action" href="<?php echo h(site_url('app/admin/orders.php?status=pending_payment')); ?>">
        <span class="icon"><i class="fas fa-credit-card"></i></span>
        <span><b>Orders awaiting payment</b><small><?php echo (int)$stats['pending_payment']; ?> pending</small></span>
        <i class="fas fa-arrow-right arrow"></i>
    </a>
</div>

<div class="ad-stats">
    <div class="ad-stat"><div class="n"><?php echo $stats['new']; ?></div><div class="l"><?php echo tp('adm.new_count'); ?></div></div>
    <div class="ad-stat"><div class="n"><?php echo $stats['today']; ?></div><div class="l"><?php echo tp('adm.today_count'); ?></div></div>
    <div class="ad-stat"><div class="n"><?php echo $stats['open']; ?></div><div class="l"><?php echo tp('adm.open_orders'); ?></div></div>
    <div class="ad-stat"><div class="n"><?php echo money($stats['paid']); ?></div><div class="l"><?php echo tp('adm.revenue'); ?></div></div>
    <a class="ad-stat" href="<?php echo h(site_url('app/admin/messages.php?open=1')); ?>" style="text-decoration:none;">
        <div class="n" style="color:<?php echo $msgOpen > 0 ? 'var(--bad)' : 'var(--pri)'; ?>;"><?php echo $msgOpen; ?></div>
        <div class="l"><?php echo lang() === 'zh' ? '待回复留言' : 'Messages awaiting reply'; ?></div>
    </a>
</div>

<div class="ad-card">
    <h2><?php echo tp('adm.enquiries'); ?> <span class="lg-sm">Latest</span></h2>
    <?php if (!$recentE): ?>
        <div class="ad-empty"><?php echo h(t('adm.no_data')); ?></div>
    <?php else: ?>
    <table class="ad-tb">
        <tr>
            <th><?php echo tp('adm.ref'); ?></th>
            <th><?php echo tp('adm.mark_name'); ?></th>
            <th><?php echo tp('client'); ?></th>
            <th><?php echo tp('adm.classes'); ?></th>
            <th><?php echo tp('adm.est_price'); ?></th>
            <th><?php echo tp('status'); ?></th>
            <th><?php echo tp('adm.submitted'); ?></th>
            <th><?php echo tp('actions'); ?></th>
        </tr>
        <?php foreach ($recentE as $e): ?>
        <tr>
            <td class="ad-mono"><?php echo h($e['ref']); ?></td>
            <td><?php echo h($e['mark_name']); ?></td>
            <td><?php echo h($e['name']); ?><div class="lg-sm" style="opacity:.6;"><?php echo h($e['email']); ?></div></td>
            <td><?php echo (int)$e['class_count']; ?> / <?php echo (int)$e['items_count']; ?></td>
            <td><?php echo h(money($e['est_price'], $e['currency'])); ?></td>
            <td><span class="ad-tag <?php echo h($e['status']); ?>"><?php echo h(enquiry_status_label($e['status'])); ?></span></td>
            <td><?php echo h($e['created_at']); ?></td>
            <td><a class="btn btn-s" href="<?php echo h(site_url('app/admin/enquiry.php?id=' . $e['id'])); ?>"><?php echo h(t('view')); ?></a></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>

<div class="ad-card">
    <h2><?php echo tp('adm.orders'); ?> <span class="lg-sm">Latest</span></h2>
    <?php if (!$recentO): ?>
        <div class="ad-empty"><?php echo h(t('adm.no_data')); ?></div>
    <?php else: ?>
    <table class="ad-tb">
        <tr>
            <th><?php echo tp('adm.ref'); ?></th>
            <th><?php echo tp('adm.mark_name'); ?></th>
            <th><?php echo tp('client'); ?></th>
            <th><?php echo tp('adm.total_due'); ?></th>
            <th><?php echo tp('adm.paid_amount'); ?></th>
            <th><?php echo tp('status'); ?></th>
            <th><?php echo tp('actions'); ?></th>
        </tr>
        <?php foreach ($recentO as $o): ?>
        <tr>
            <td class="ad-mono"><?php echo h($o['ref']); ?></td>
            <td><?php echo h($o['mark_name']); ?></td>
            <td><?php echo h($o['email']); ?></td>
            <td><?php echo h(money($o['total'], $o['currency'])); ?></td>
            <td><?php echo h(money($o['paid_amount'], $o['currency'])); ?></td>
            <td><span class="ad-tag <?php echo h($o['status']); ?>"><?php echo h(order_status_label($o['status'])); ?></span></td>
            <td><a class="btn btn-s" href="<?php echo h(site_url('app/admin/order.php?id=' . $o['id'])); ?>"><?php echo h(t('view')); ?></a></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>
<?php admin_footer(); ?>
