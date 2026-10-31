<?php
/**
 * 询价工单列表
 */
require_once __DIR__ . '/_layout.php';

$status = get('status', '');
$kw     = get('kw', '');
$view   = get('view', 'active') === 'deleted' ? 'deleted' : 'active';
$page   = max(1, (int)get('p', 1));
$per    = 25;

if (is_post() && post('act') === 'restore') {
    $restoreId = (int)post('id', 0);
    if (!csrf_check()) {
        redirect(site_url('app/admin/enquiries.php?view=deleted&restore_error=csrf'));
    }
    if ($restoreId > 0 && table_has_column('enquiries', 'deleted_at')) {
        db_exec('UPDATE `enquiries` SET `deleted_at` = NULL WHERE id = ? AND `deleted_at` IS NOT NULL', [$restoreId]);
        admin_log_action('restore', 'enquiry', $restoreId, 'restored soft-deleted enquiry');
        redirect(site_url('app/admin/enquiries.php?restored=1'));
    }
    redirect(site_url('app/admin/enquiries.php?view=deleted&restore_error=unavailable'));
}

$where = [];
$args  = [];
// Deleted enquiries (and their quotes) remain available in a dedicated recovery view.
if (table_has_column('enquiries', 'deleted_at')) {
    $where[] = $view === 'deleted' ? '`deleted_at` IS NOT NULL' : '`deleted_at` IS NULL';
}
if ($view === 'active' && $status !== '') { $where[] = 'status = ?'; $args[] = $status; }
if ($kw !== '') {
    $where[] = '(ref LIKE ? OR email LIKE ? OR mark_name LIKE ? OR name LIKE ? OR company LIKE ?)';
    for ($i = 0; $i < 5; $i++) $args[] = '%' . $kw . '%';
}
$wSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$total = (int)db_val("SELECT COUNT(*) FROM enquiries $wSql", $args, 0);
$offset = ($page - 1) * $per;
$rows = db_all("SELECT * FROM enquiries $wSql ORDER BY id DESC LIMIT $per OFFSET $offset", $args);

$statuses = ['new', 'processing', 'quoted', 'confirmed', 'declined', 'closed'];

/* 「新建工单」是主动作，放页头右上角；筛选栏里只留 Apply / Refresh */
admin_header('adm.enquiries', 'enquiries',
    '<a class="btn btn-primary" href="' . h(site_url('app/admin/enquiry_new.php')) . '">'
    . '<i class="fas fa-plus"></i> ' . h(t('adm.new_enquiry')) . '</a>'
);
?>
<div class="ad-card">
    <div class="ad-filter-pills" aria-label="Filter enquiries by stage">
        <?php
        $stageLinks = $view === 'deleted'
            ? ['deleted' => (lang() === 'zh' ? '已删除' : 'Deleted')]
            : array_merge(['' => lang() === 'zh' ? '全部询价' : 'All enquiries'], array_combine($statuses, array_map('enquiry_status_label', $statuses)));
        foreach ($stageLinks as $stage => $label):
            $stageUrl = site_url('app/admin/enquiries.php?' . http_build_query(array_filter(
                ['view' => $view === 'deleted' ? 'deleted' : '', 'status' => $stage, 'kw' => $kw], function ($value) { return $value !== ''; }
            )));
        ?>
            <a class="ad-filter-pill<?php echo ($view === 'deleted' ? $stage === 'deleted' : $status === $stage) ? ' active' : ''; ?>" href="<?php echo h($stageUrl); ?>">
                <?php echo h($label); ?>
            </a>
        <?php endforeach; ?>
        <a class="ad-filter-pill<?php echo $view === 'deleted' ? ' active' : ''; ?>" href="<?php echo h(site_url('app/admin/enquiries.php?view=deleted' . ($kw !== '' ? '&kw=' . rawurlencode($kw) : ''))); ?>">
            <?php echo lang() === 'zh' ? '回收站' : 'Recycle bin'; ?>
        </a>
    </div>
    <form method="get" class="form-row" style="margin-bottom:0;">
        <?php if ($view === 'deleted'): ?><input type="hidden" name="view" value="deleted"><?php endif; ?>
        <input type="hidden" name="status" value="<?php echo h($status); ?>">
        <div>
            <label for="kw"><?php echo tp('search'); ?> <span class="lg-sm">Ref / Email / Mark</span></label>
            <input type="text" name="kw" id="kw" value="<?php echo h($kw); ?>">
        </div>
        <div style="display:flex;align-items:flex-end;gap:8px;">
            <button class="btn btn-p" type="submit"><?php echo h(t('adm.apply')); ?></button>
            <a class="btn" href="<?php echo h(site_url('app/admin/enquiries.php')); ?>"><?php echo h(t('refresh')); ?></a>
        </div>
    </form>
</div>
<?php if (get('deleted') === '1'): ?><?php echo ad_flash(lang() === 'zh' ? '工单已删除。' : 'Enquiry deleted.', 'ok'); ?><?php endif; ?>
<?php if (get('restored') === '1'): ?><?php echo ad_flash(lang() === 'zh' ? '工单已恢复，关联报价也可重新访问。' : 'Enquiry restored; its quotation is available again.', 'ok'); ?><?php endif; ?>
<?php if (get('restore_error') !== ''): ?><?php echo ad_flash(lang() === 'zh' ? '恢复失败，请刷新后重试。' : 'Restore failed. Refresh and try again.', 'bad'); ?><?php endif; ?>
<?php if (get('hard_deleted') !== ''): ?>
<?php echo ad_flash(h(lang() === 'zh'
    ? '已彻底删除工单 #' . (int)get('hard_deleted') . '（附件 ' . (int)get('doc') . ' / 留言 ' . (int)get('msg') . ' / 报价 ' . (int)get('q') . ' 条，磁盘文件一并清除）。'
    : 'Enquiry #' . (int)get('hard_deleted') . ' permanently deleted (docs:' . (int)get('doc') . ' messages:' . (int)get('msg') . ' quotes:' . (int)get('q') . ', files removed).'), 'ok'); ?>
<?php endif; ?>

<div class="ad-card">
    <h2><?php echo tp('adm.enquiries'); ?> <span class="lg-sm"><?php echo $total; ?> total</span></h2>
    <?php if (!$rows): ?>
        <div class="ad-empty"><?php echo h(t('adm.no_data')); ?></div>
    <?php else: ?>
    <table class="ad-tb">
        <tr>
            <th><?php echo tp('adm.ref'); ?></th>
            <th><?php echo tp('adm.service_type'); ?></th>
            <th><?php echo tp('adm.mark_name'); ?></th>
            <th><?php echo tp('client'); ?></th>
            <th><?php echo tp('adm.classes'); ?></th>
            <th><?php echo tp('adm.est_price'); ?></th>
            <th><?php echo tp('status'); ?></th>
            <th><?php echo tp('adm.submitted'); ?></th>
            <th><?php echo tp('actions'); ?></th>
        </tr>
        <?php foreach ($rows as $e): ?>
        <tr>
            <td class="ad-mono"><?php echo h($e['ref']); ?></td>
            <td><?php
                $si = svc_one(isset($e['service_type']) ? (string)$e['service_type'] : '');
                echo h($si ? (lang() === 'zh' ? $si['zh'] : $si['en']) : t('adm.not_specified'));
                ?></td>
            <td><?php
                $isReg = $si && !empty($si['needs_reg_no']) && empty($si['needs_classes']);
                if ($isReg): ?>
                    <b class="ad-mono"><?php echo h($e['existing_reg_no'] ?? '—'); ?></b>
                    <div class="lg-sm" style="opacity:.6;"><?php echo h(t('adm.reg_no')); ?></div>
                <?php else: ?>
                    <b><?php echo h($e['mark_name']); ?></b>
                    <div class="lg-sm" style="opacity:.6;"><?php echo h($e['mark_type']); ?></div>
                <?php endif; ?></td>
            <td><?php echo h($e['name']); ?><div class="lg-sm" style="opacity:.6;"><?php echo h($e['email']); ?></div>
                <?php if ($e['company']): ?><div class="lg-sm" style="opacity:.6;"><?php echo h($e['company']); ?></div><?php endif; ?></td>
            <td><?php echo (int)$e['class_count']; ?> / <?php echo (int)$e['items_count']; ?></td>
            <td><?php echo h(money($e['est_price'], $e['currency'])); ?></td>
            <td><span class="ad-tag <?php echo h($e['status']); ?>"><?php echo h(enquiry_status_label($e['status'])); ?></span></td>
            <td><?php echo h($e['created_at']); ?></td>
            <td>
                <?php if ($view === 'deleted'): ?>
                <form method="post" style="display:inline;">
                    <?php echo csrf_field(); ?><input type="hidden" name="act" value="restore"><input type="hidden" name="id" value="<?php echo (int)$e['id']; ?>">
                    <button class="btn btn-s" type="submit"><?php echo lang() === 'zh' ? '恢复' : 'Restore'; ?></button>
                </form>
                <?php endif; ?>
                <a class="btn btn-s" href="<?php echo h(site_url('app/admin/enquiry.php?id=' . $e['id'])); ?>"><?php echo h(t('view')); ?></a>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>

    <?php if ($total > $per): ?>
    <div style="margin-top:14px;display:flex;gap:6px;align-items:center;font-size:13px;">
        <?php
        $pages = (int)ceil($total / $per);
        $qs = http_build_query(['view' => $view === 'deleted' ? 'deleted' : '', 'status' => $view === 'active' ? $status : '', 'kw' => $kw]);
        for ($i = 1; $i <= $pages; $i++): ?>
            <a class="btn btn-s" style="<?php echo $i === $page ? 'background:var(--pri);color:#fff;' : ''; ?>"
               href="?<?php echo h($qs); ?>&p=<?php echo $i; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
<?php admin_footer(); ?>
