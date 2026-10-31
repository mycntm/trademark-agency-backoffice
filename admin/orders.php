<?php
/**
 * 订单列表
 */
require_once __DIR__ . '/_layout.php';

$status = get('status', '');
$kw     = get('kw', '');
$view   = in_array(get('view', 'all'), ['all', 'active', 'voided'], true) ? get('view', 'all') : 'all';
$page   = max(1, (int)get('p', 1));
$per    = 25;

$where = []; $args = [];
if (table_has_column('orders', 'voided_at')) {
    if ($view === 'active') $where[] = 'o.voided_at IS NULL';
    if ($view === 'voided') $where[] = 'o.voided_at IS NOT NULL';
}
if ($status !== '') { $where[] = 'o.status = ?'; $args[] = $status; }
if ($kw !== '') {
    $where[] = '(o.ref LIKE ? OR o.mark_name LIKE ? OR o.registrant_name LIKE ? OR c.email LIKE ? OR o.cnipa_app_no LIKE ?)';
    for ($i = 0; $i < 5; $i++) $args[] = '%' . $kw . '%';
}
$wSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$total  = (int)db_val("SELECT COUNT(*) FROM orders o LEFT JOIN clients c ON c.id=o.client_id $wSql", $args, 0);
$offset = ($page - 1) * $per;
$rows   = db_all("SELECT o.*, c.email, c.name AS client_name FROM orders o LEFT JOIN clients c ON c.id=o.client_id
                  $wSql ORDER BY o.id DESC LIMIT $per OFFSET $offset", $args);

$statuses = ['pending_payment','paid','filed','examining','published','opposed','registered','refused','withdrawn','refunded'];

/* 「新建订单」是主动作，放页头右上角；筛选栏里只留 Apply / Refresh */
admin_header('adm.orders', 'orders',
    '<a class="btn btn-primary" href="' . h(site_url('app/admin/order_new.php')) . '">'
    . '<i class="fas fa-plus"></i> ' . h(t('adm.new_order')) . '</a>'
);
?>
<?php if (get('hard_deleted') !== ''): ?>
<?php
$hdRef = (string)get('hard_deleted');
$hdTxt = lang() === 'zh'
    ? '已彻底删除订单 ' . $hdRef . '（节点 ' . (int)get('ev') . ' / 付款 ' . (int)get('pay') . ' / 留言 ' . (int)get('msg') . ' / 附件 ' . (int)get('doc') . ' 条，磁盘文件一并清除）。'
    : 'Order ' . $hdRef . ' permanently deleted (events:' . (int)get('ev') . ' payments:' . (int)get('pay') . ' messages:' . (int)get('msg') . ' docs:' . (int)get('doc') . ', files removed).';
?>
<?php echo ad_flash(h($hdTxt), 'ok'); ?>
<?php endif; ?>
<div class="ad-card">
    <div class="ad-filter-pills" aria-label="Filter orders">
        <?php
        $viewLabels = ['all' => (lang() === 'zh' ? '全部订单' : 'All orders'), 'active' => (lang() === 'zh' ? '有效订单' : 'Active'), 'voided' => (lang() === 'zh' ? '作废订单' : 'Voided')];
        foreach ($viewLabels as $key => $label):
            $url = site_url('app/admin/orders.php?' . http_build_query(array_filter([
                'view' => $key === 'all' ? '' : $key, 'status' => $status, 'kw' => $kw
            ], function ($value) { return $value !== ''; })));
        ?>
        <a class="ad-filter-pill<?php echo $view === $key ? ' active' : ''; ?>" href="<?php echo h($url); ?>"><?php echo h($label); ?></a>
        <?php endforeach; ?>
    </div>
    <form method="get" class="form-row" style="margin-bottom:0;">
        <?php if ($view !== 'all'): ?><input type="hidden" name="view" value="<?php echo h($view); ?>"><?php endif; ?>
        <div><label for="status"><?php echo tp('adm.filter_status'); ?></label>
            <select name="status" id="status">
                <option value=""><?php echo h(t('all')); ?></option>
                <?php foreach ($statuses as $s): ?>
                    <option value="<?php echo h($s); ?>" <?php echo $status === $s ? 'selected' : ''; ?>><?php echo h(order_status_label($s)); ?></option>
                <?php endforeach; ?>
            </select></div>
        <div><label for="kw"><?php echo tp('search'); ?> <span class="lg-sm">Ref / Mark / Registrant / Email</span></label>
            <input type="text" name="kw" id="kw" value="<?php echo h($kw); ?>"></div>
        <div style="display:flex;align-items:flex-end;gap:8px;">
            <button class="btn btn-p" type="submit"><?php echo h(t('adm.apply')); ?></button>
            <a class="btn" href="<?php echo h(site_url('app/admin/orders.php')); ?>"><?php echo h(t('refresh')); ?></a>
        </div>
    </form>
</div>

<div class="ad-card">
    <h2><?php echo tp('adm.orders'); ?> <span class="lg-sm"><?php echo $total; ?> total</span></h2>
    <?php if (!$rows): ?>
        <div class="ad-empty"><?php echo h(t('adm.no_data')); ?></div>
    <?php else: ?>
    <table class="ad-tb">
        <tr>
            <th><?php echo tp('adm.ref'); ?></th>
            <th><?php echo tp('adm.mark_name'); ?></th>
            <th><?php echo tp('client'); ?></th>
            <th><?php echo tp('acc.registrant'); ?></th>
            <th><?php echo tp('adm.total_due'); ?></th>
            <th><?php echo tp('adm.paid_amount'); ?></th>
            <th><?php echo tp('status'); ?></th>
            <th><?php echo tp('acc.app_no'); ?></th>
            <th><?php echo tp('actions'); ?></th>
        </tr>
        <?php foreach ($rows as $o): ?>
        <tr>
            <td class="ad-mono nw"><?php echo h($o['ref']); ?></td>
            <td><b><?php echo h($o['mark_name']); ?></b></td>
            <td><?php echo h($o['client_name']); ?><div class="lg-sm" style="opacity:.6;"><?php echo h($o['email']); ?></div></td>
            <td><?php echo h($o['registrant_name']); ?></td>
            <td class="nw"><?php echo h(money($o['total'], $o['currency'])); ?></td>
            <td class="nw"><?php echo h(money($o['paid_amount'], $o['currency'])); ?></td>
            <td><span class="ad-tag <?php echo in_array($o['status'], ['paid','registered'], true) ? 'done' : ''; ?>"><?php echo h(order_status_label($o['status'])); ?></span>
                <?php if (!empty($o['voided_at'])): ?><div class="lg-sm" style="color:var(--bad);margin-top:4px;"><?php echo lang() === 'zh' ? '已作废' : 'Voided'; ?> · <?php echo h($o['voided_at']); ?></div><?php endif; ?>
            </td>
            <td class="ad-mono nw"><?php echo h($o['cnipa_app_no']); ?></td>
            <td>
                <a class="btn btn-s" href="<?php echo h(site_url('app/admin/order.php?id=' . $o['id'])); ?>"><?php echo h(t('view')); ?></a>
                <?php
                $__atok = admin_deeplink_token($o['id']);
                $__contractUrl = site_url('contract.php?order=' . rawurlencode($o['ref']) . '&atok=' . $__atok);
                $__invoiceUrl  = site_url('invoice.php?oid=' . (int)$o['id'] . '&atok=' . $__atok);
                ?>
                <a class="btn btn-s" href="<?php echo h($__contractUrl); ?>" target="_blank" rel="noopener">Contract</a>
                <a class="btn btn-s" href="<?php echo h($__invoiceUrl); ?>" target="_blank" rel="noopener">Invoice</a>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>
<?php admin_footer(); ?>
