<?php
/**
 * 客户列表
 */
require_once __DIR__ . '/_layout.php';

$q    = get('q', '');
$args = [];
$wSql = '';
if ($q !== '') {
    $wSql = 'WHERE email LIKE ? OR name LIKE ? OR company LIKE ?';
    for ($i = 0; $i < 3; $i++) $args[] = '%' . $q . '%';
}
$rows = db_all("SELECT c.*,
        (SELECT COUNT(*) FROM orders o WHERE o.client_id=c.id) AS order_count,
        (SELECT IFNULL(SUM(o.paid_amount),0) FROM orders o WHERE o.client_id=c.id) AS paid
        FROM clients c $wSql ORDER BY c.id DESC LIMIT 200", $args);

/* 「新建客户」是主动作，放页头右上角；筛选栏里只留 Apply / Refresh */
admin_header('adm.clients', 'clients',
    '<a class="btn btn-primary" href="' . h(site_url('app/admin/client.php?new=1')) . '">'
    . '<i class="fas fa-plus"></i> ' . h(t('adm.new_client')) . '</a>'
);
?>
<?php if (get('hard_deleted') !== ''): ?>
<?php echo ad_flash(h(lang() === 'zh'
    ? '已彻底删除客户 ' . (string)get('hard_deleted') . '（订单 ' . (int)get('o') . ' / 工单 ' . (int)get('e') . ' / 附件 ' . (int)get('doc') . ' 条一并清除）。'
    : 'Client ' . (string)get('hard_deleted') . ' permanently deleted (orders:' . (int)get('o') . ' enquiries:' . (int)get('e') . ' docs:' . (int)get('doc') . ' removed).'), 'ok'); ?>
<?php endif; ?>
<div class="ad-card">
    <form method="get" class="form-row" style="margin-bottom:0;">
        <div><label for="q"><?php echo tp('search'); ?> <span class="lg-sm">Email / Name / Company</span></label>
            <input type="text" name="q" id="q" value="<?php echo h($q); ?>"></div>
        <div style="display:flex;align-items:flex-end;gap:8px;">
            <button class="btn btn-p" type="submit"><?php echo h(t('adm.apply')); ?></button>
            <a class="btn" href="<?php echo h(site_url('app/admin/clients.php')); ?>"><?php echo h(t('refresh')); ?></a>
        </div>
    </form>
</div>

<div class="ad-card">
    <h2><?php echo tp('adm.clients'); ?> <span class="lg-sm"><?php echo count($rows); ?></span></h2>
    <?php if (!$rows): ?><div class="ad-empty"><?php echo h(t('adm.no_data')); ?></div>
    <?php else: ?>
    <table class="ad-tb">
        <tr><th>ID</th><th><?php echo tp('name'); ?></th><th><?php echo tp('email'); ?></th><th><?php echo tp('company'); ?></th>
            <th><?php echo tp('country'); ?></th><th><?php echo tp('adm.orders'); ?></th><th><?php echo tp('adm.paid_amount'); ?></th>
            <th><?php echo tp('status'); ?></th><th><?php echo tp('created'); ?></th><th><?php echo tp('actions'); ?></th></tr>
        <?php foreach ($rows as $c): ?>
        <tr>
            <td><?php echo (int)$c['id']; ?></td>
            <td><a href="<?php echo h(site_url('app/admin/client.php?id=' . $c['id'])); ?>"><?php echo h($c['name'] ?: '—'); ?></a></td>
            <td><?php echo h($c['email']); ?></td>
            <td><?php echo h($c['company']); ?></td>
            <td><?php echo h($c['country']); ?></td>
            <td><?php echo (int)$c['order_count']; ?></td>
            <td><?php echo h(money($c['paid'])); ?></td>
            <td><span class="ad-tag <?php echo $c['status'] === 'active' ? 'done' : 'closed'; ?>"><?php echo h($c['status']); ?></span></td>
            <td><?php echo h($c['created_at']); ?></td>
            <td><a class="btn btn-s" href="<?php echo h(site_url('app/admin/client.php?id=' . $c['id'])); ?>"><?php echo h(t('view')); ?></a></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>
<?php admin_footer(); ?>
