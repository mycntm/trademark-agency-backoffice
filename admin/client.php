<?php
/**
 * 客户详情 / 新建客户
 *
 * 一屏聚合这个客户名下的：订单、询价工单、付款、留言、附件。
 * 以前后台只能搜到客户邮箱，看不到他到底跟我们发生过什么——邮件来的客户
 * 尤其吃亏，因为他们的单子是我们在后台手工建的，没有线索可循。
 *
 * 约定：id=0 或 ?new=1 为新建模式。
 */
require_once __DIR__ . '/_layout.php';

$id  = (int)get('id', 0);
$msg = ''; $err = '';

/* ---------- 保存（新建 / 编辑） ---------- */
if (is_post() && post('act') === 'save') {
    if (!csrf_check()) {
        $err = t('enq.err_csrf');
    } else {
        $email   = strtolower(trim((string)post('email')));
        $name    = trim((string)post('name'));
        $company = trim((string)post('company'));
        $country = trim((string)post('country'));
        $phone   = trim((string)post('phone'));
        $langx   = post('lang', 'en');
        $status  = post('status', 'active');
        $note    = trim((string)post('note'));

        // 语言/状态只做归一化，不因为非法值就中断保存
        if (!in_array($langx, ['en', 'zh'], true))   $langx  = 'en';
        if (!in_array($status, ['active', 'disabled'], true)) $status = 'active';

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = lang() === 'zh' ? '请填写有效的邮箱地址。' : 'Please enter a valid email address.';
        } else {
            // 邮箱唯一：换邮箱时若撞上别人，直接拒绝
            $dup = db_val('SELECT id FROM clients WHERE email = ? AND id <> ?', [$email, $id], null);
            if ($dup) {
                $err = lang() === 'zh' ? '该邮箱已被其它客户占用。' : 'That email is already used by another client.';
            } else {
                $cols = ['email', 'name', 'company', 'country', 'phone', 'lang', 'status'];
                $vals = [$email, $name, $company, $country, $phone, $langx, $status];
                if (table_has_column('clients', 'note')) { $cols[] = 'note'; $vals[] = $note; }

                if ($id > 0) {
                    $sets = [];
                    foreach ($cols as $i => $col) $sets[] = '`' . $col . '` = ?';
                    $vals[] = $id;
                    db_exec('UPDATE clients SET ' . implode(',', $sets) . ' WHERE id = ?', $vals);
                    admin_log_action('update', 'client', $id, 'profile updated');
                    $msg = t('adm.saved');
                } else {
                    $ph = implode(',', array_fill(0, count($cols), '?'));
                    $newId = (int)db_exec(
                        'INSERT INTO clients (`' . implode('`,`', $cols) . '`) VALUES (' . $ph . ')',
                        $vals
                    );
                    admin_log_action('create', 'client', $newId, 'created manually: ' . $email);
                    redirect(site_url('app/admin/client.php?id=' . $newId . '&created=1'));
                }
            }
        }
    }
}

/* ---------- 彻底删除客户（物理删除，不可逆） ---------- */
if (is_post() && post('act') === 'hard_delete') {
    if (!csrf_check()) {
        $err = t('enq.err_csrf');
    } elseif (!admin_can_hard_delete()) {
        $err = lang() === 'zh' ? '权限不足：只读账号不能执行物理删除。' : 'Permission denied: viewer accounts cannot delete records.';
    } else {
        $cid = (int)post('id', 0);
        $target = db_one('SELECT * FROM clients WHERE id = ?', [$cid]);
        if (!$target) {
            $err = lang() === 'zh' ? '客户不存在。' : 'Client not found.';
        } elseif (trim((string)post('confirm_text')) !== (string)$target['email']) {
            // 二次确认：必须原样输入客户邮箱
            $err = lang() === 'zh' ? '确认邮箱不匹配，删除已取消（需原样输入 ' . $target['email'] . '）。'
                                   : 'Confirmation text does not match — deletion cancelled (type the email exactly: ' . $target['email'] . ').';
        } else {
            $r = hard_delete_client($cid, (int)post('with_orders', 0) === 1);
            if ($r['ok']) {
                $st = $r['stats'];
                redirect(site_url('app/admin/clients.php?hard_deleted=' . rawurlencode($st['email'])
                    . '&o=' . (int)$st['orders'] . '&e=' . (int)$st['enquiries'] . '&doc=' . (int)$st['docs']));
            } elseif ($r['error'] === 'has_data') {
                $err = lang() === 'zh'
                    ? '该客户名下还有 ' . $r['orders'] . ' 个订单、' . $r['enquiries'] . ' 个工单。要删除请先勾选「连同订单和工单一起删除」。'
                    : 'This client still has ' . $r['orders'] . ' order(s) and ' . $r['enquiries'] . ' enquiry(ies). Tick "delete orders and enquiries too" if you really want to remove everything.';
            } else {
                $err = lang() === 'zh' ? '删除失败：' . $r['error'] : 'Delete failed: ' . $r['error'];
            }
        }
    }
}

/* ---------- 数据 ---------- */
$c = null;
if ($id > 0) {
    $c = db_one('SELECT * FROM clients WHERE id = ?', [$id]);
    if (!$c) abort404('Client not found');
}

if ($c) {
    $orders    = db_all('SELECT * FROM orders WHERE client_id=? ORDER BY id DESC', [$id]);
    $enquiries = db_all('SELECT * FROM enquiries WHERE client_id=? OR (client_id IS NULL AND email=?) ORDER BY id DESC LIMIT 50',
        [$id, $c['email']]);
    $pays      = db_all('SELECT p.*, o.ref AS order_ref FROM payments p
                         LEFT JOIN orders o ON o.id = p.order_id
                         WHERE o.client_id = ? ORDER BY p.id DESC LIMIT 50', [$id]);
    $msgs      = db_all('SELECT m.*, o.ref AS order_ref, e.ref AS enquiry_ref
                         FROM messages m
                         LEFT JOIN orders o    ON o.id = m.order_id
                         LEFT JOIN enquiries e ON e.id = m.enquiry_id
                         WHERE o.client_id = ? OR e.client_id = ? OR (e.client_id IS NULL AND e.email = ?)
                         ORDER BY m.id DESC LIMIT 50', [$id, $id, $c['email']]);
    // 软删的附件不出现在客户档案里（迁移没跑时没有该列，条件整条跳过）
    $docAlive  = table_has_column('documents', 'deleted_at') ? ' AND d.`deleted_at` IS NULL' : '';
    $docs      = db_all('SELECT d.*, o.ref AS order_ref FROM documents d
                         LEFT JOIN orders o ON o.id = d.owner_id AND d.owner_type = "order"
                         WHERE ((d.owner_type = "client" AND d.owner_id = ?)
                            OR (d.owner_type = "order" AND d.owner_id IN (SELECT id FROM orders WHERE client_id = ?)))
                           ' . $docAlive . '
                         ORDER BY d.id DESC LIMIT 50', [$id, $id]);
    $logs      = admin_log_recent('client', $id, 15);
    $paidSum   = (float)db_val('SELECT IFNULL(SUM(paid_amount),0) FROM orders WHERE client_id = ?', [$id], 0);
}

admin_header('adm.clients', 'clients');
?>
<?php if ($msg): ?><?php echo ad_flash($msg, 'ok'); ?><?php endif; ?>
<?php if ($err): ?><?php echo ad_flash($err, 'bad'); ?><?php endif; ?>
<?php if (get('created') === '1'): ?><?php echo ad_flash(lang() === 'zh' ? '客户已创建。' : 'Client created.', 'ok'); ?><?php endif; ?>

<!-- 资料 -->
<div class="ad-card">
    <h2><?php echo $c ? tp('adm.client_detail') : tp('adm.new_client'); ?>
        <?php if ($c): ?><span class="lg-sm" style="font-weight:400;opacity:.6;">#<?php echo (int)$c['id']; ?></span><?php endif; ?>
    </h2>
    <form method="post">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="act" value="save">
        <input type="hidden" name="lang" value="en">
        <div class="form-row cols3">
            <div><label for="email"><?php echo tp('email'); ?> *</label>
                <input type="email" id="email" name="email" required value="<?php echo h($c['email'] ?? ''); ?>"></div>
            <div><label for="name"><?php echo tp('name'); ?></label>
                <input type="text" id="name" name="name" value="<?php echo h($c['name'] ?? ''); ?>"></div>
            <div><label for="company"><?php echo tp('company'); ?></label>
                <input type="text" id="company" name="company" value="<?php echo h($c['company'] ?? ''); ?>"></div>
        </div>
        <div class="form-row cols3">
            <div><label for="country"><?php echo tp('country'); ?></label>
                <input type="text" id="country" name="country" value="<?php echo h($c['country'] ?? ''); ?>"></div>
            <div><label for="phone"><?php echo tp('phone'); ?></label>
                <input type="text" id="phone" name="phone" value="<?php echo h($c['phone'] ?? ''); ?>"></div>
            <div><label for="status"><?php echo tp('status'); ?></label>
                <select id="status" name="status">
                    <option value="active"   <?php echo ($c['status'] ?? 'active') === 'active'   ? 'selected' : ''; ?>>active</option>
                    <option value="disabled" <?php echo ($c['status'] ?? '') === 'disabled' ? 'selected' : ''; ?>>disabled</option>
                </select></div>
        </div>
        <?php if (table_has_column('clients', 'note')): ?>
        <div class="form-row cols3">
            <div class="full"><label for="note"><?php echo tp('adm.client_note'); ?></label>
                <textarea id="note" name="note" rows="2"><?php echo h($c['note'] ?? ''); ?></textarea></div>
        </div>
        <?php endif; ?>
        <div class="form-actions">
            <button class="btn btn-p" type="submit"><?php echo h(t('save')); ?></button>
        </div>
    </form>

    <?php if ($c): ?>
    <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;">
        <a class="btn btn-s" href="<?php echo h(site_url('app/admin/order_new.php?client_id=' . $id)); ?>">
            <i class="fas fa-plus"></i> <?php echo h(t('adm.new_order')); ?></a>
        <a class="btn btn-s" href="<?php echo h(site_url('app/admin/enquiry_new.php?client_id=' . $id)); ?>">
            <i class="fas fa-plus"></i> <?php echo h(t('adm.new_enquiry')); ?></a>
        <a class="btn btn-s" href="<?php echo h(site_url('app/admin/orders.php?kw=' . urlencode($c['email']))); ?>">
            <?php echo h(t('adm.orders')); ?></a>
        <a class="btn btn-s" href="<?php echo h(site_url('app/admin/enquiries.php?kw=' . urlencode($c['email']))); ?>">
            <?php echo h(t('adm.enquiries')); ?></a>
    </div>
    <?php endif; ?>
</div>

<?php if (!$c): ?>
<p><a class="btn btn-s" href="<?php echo h(site_url('app/admin/clients.php')); ?>">← <?php echo h(t('back')); ?></a></p>
<?php admin_footer(); exit; endif; ?>

<?php echo ad_login_link_card($c); ?>
<script>
<?php echo ad_copy_link_js(); ?>
</script>

<!-- 汇总 -->
<div class="ad-stats">
    <div class="ad-stat"><div class="n"><?php echo count($orders); ?></div><div class="l"><?php echo tp('adm.orders'); ?></div></div>
    <div class="ad-stat"><div class="n"><?php echo count($enquiries); ?></div><div class="l"><?php echo tp('adm.enquiries'); ?></div></div>
    <div class="ad-stat"><div class="n"><?php echo h(money($paidSum)); ?></div><div class="l"><?php echo tp('adm.paid_amount'); ?></div></div>
    <div class="ad-stat"><div class="n"><?php echo count($docs); ?></div><div class="l"><?php echo tp('adm.attach'); ?></div></div>
</div>

<div data-workspace-tabs-anchor></div>

<!-- 订单 -->
<div class="ad-card" data-workspace-tab="orders" data-workspace-label="Orders">
    <h2><?php echo tp('adm.orders'); ?> <span class="lg-sm"><?php echo count($orders); ?></span></h2>
    <?php if (!$orders): ?><div class="ad-empty"><?php echo h(t('adm.no_data')); ?></div>
    <?php else: ?>
    <table class="ad-tb">
        <tr><th><?php echo tp('adm.ref'); ?></th><th><?php echo tp('adm.service_type'); ?></th><th><?php echo tp('adm.mark_name'); ?></th>
            <th><?php echo tp('status'); ?></th><th><?php echo tp('adm.total_due'); ?></th><th><?php echo tp('adm.paid_amount'); ?></th>
            <th><?php echo tp('date'); ?></th><th><?php echo tp('actions'); ?></th></tr>
        <?php foreach ($orders as $o): ?>
        <tr>
            <td class="ad-mono"><?php echo h($o['ref']); ?></td>
            <td><?php $si = svc_one((string)($o['service_type'] ?? '')); echo h($si ? (lang() === 'zh' ? $si['zh'] : $si['en']) : '—'); ?></td>
            <td><?php echo h($o['mark_name'] ?: ($o['existing_reg_no'] ?? '')); ?></td>
            <td><span class="ad-tag <?php echo in_array($o['status'], ['paid','registered'], true) ? 'done' : ''; ?>"><?php echo h(order_status_label($o['status'])); ?></span></td>
            <td><?php echo h(money($o['total'], $o['currency'])); ?></td>
            <td><?php echo h(money($o['paid_amount'], $o['currency'])); ?></td>
            <td><?php echo h(substr($o['created_at'], 0, 10)); ?></td>
            <td><a class="btn btn-s" href="<?php echo h(site_url('app/admin/order.php?id=' . $o['id'])); ?>"><?php echo h(t('view')); ?></a></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>

<!-- 询价工单 -->
<div class="ad-card" data-workspace-tab="enquiries" data-workspace-label="Enquiries">
    <h2><?php echo tp('adm.enquiries'); ?> <span class="lg-sm"><?php echo count($enquiries); ?></span></h2>
    <?php if (!$enquiries): ?><div class="ad-empty"><?php echo h(t('adm.no_data')); ?></div>
    <?php else: ?>
    <table class="ad-tb">
        <tr><th><?php echo tp('adm.ref'); ?></th><th><?php echo tp('adm.service_type'); ?></th><th><?php echo tp('adm.mark_name'); ?></th>
            <th><?php echo tp('adm.est_price'); ?></th><th><?php echo tp('status'); ?></th><th><?php echo tp('date'); ?></th><th><?php echo tp('actions'); ?></th></tr>
        <?php foreach ($enquiries as $e): ?>
        <tr>
            <td class="ad-mono"><?php echo h($e['ref']); ?></td>
            <td><?php $si = svc_one((string)($e['service_type'] ?? '')); echo h($si ? (lang() === 'zh' ? $si['zh'] : $si['en']) : '—'); ?></td>
            <td><?php echo h($e['mark_name'] ?: ($e['existing_reg_no'] ?? '')); ?></td>
            <td><?php echo h(money($e['est_price'], $e['currency'])); ?></td>
            <td><span class="ad-tag <?php echo h($e['status']); ?>"><?php echo h(enquiry_status_label($e['status'])); ?></span></td>
            <td><?php echo h(substr($e['created_at'], 0, 10)); ?></td>
            <td><a class="btn btn-s" href="<?php echo h(site_url('app/admin/enquiry.php?id=' . $e['id'])); ?>"><?php echo h(t('view')); ?></a></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>

<!-- 付款 -->
<div class="ad-card" data-workspace-tab="payments" data-workspace-label="Payments">
    <h2><?php echo tp('adm.payments'); ?></h2>
    <?php if (!$pays): ?><div class="ad-empty"><?php echo h(t('adm.no_data')); ?></div>
    <?php else: ?>
    <table class="ad-tb">
        <tr><th><?php echo tp('adm.ref'); ?></th><th><?php echo tp('amount'); ?></th><th><?php echo tp('adm.payment_method'); ?></th>
            <th><?php echo tp('status'); ?></th><th><?php echo tp('adm.payment_ref'); ?></th><th><?php echo tp('date'); ?></th></tr>
        <?php foreach ($pays as $p): ?>
        <tr>
            <td class="ad-mono"><?php echo h($p['order_ref']); ?></td>
            <td><?php echo h(money($p['amount'], $p['currency'])); ?></td>
            <td><?php echo h($p['method']); ?></td>
            <td><span class="ad-tag <?php echo $p['status'] === 'completed' ? 'done' : ''; ?>"><?php echo h($p['status']); ?></span></td>
            <td class="ad-mono" style="font-size:12px;"><?php echo h($p['provider_ref']); ?></td>
            <td><?php echo h($p['paid_at'] ?: $p['created_at']); ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>

<!-- 留言 -->
<div class="ad-card" data-workspace-tab="messages" data-workspace-label="Messages">
    <h2><?php echo tp('adm.messages'); ?> <span class="lg-sm"><?php echo count($msgs); ?></span></h2>
    <?php if (!$msgs): ?><div class="ad-empty"><?php echo h(t('adm.no_data')); ?></div>
    <?php else: ?>
    <?php foreach ($msgs as $m): ?>
        <div style="border-left:3px solid <?php echo $m['sender_role'] === 'client' ? '#f59e0b' : '#10b981'; ?>;padding:8px 12px;margin-bottom:8px;font-size:13px;background:<?php echo $m['sender_role'] === 'client' ? '#fffdf7' : '#f8fafc'; ?>;">
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <span class="ad-tag <?php echo $m['sender_role'] === 'client' ? 'new' : 'done'; ?>"><?php echo h($m['sender_role']); ?></span>
                <span class="ad-mono" style="font-size:12px;opacity:.7;"><?php echo h($m['order_ref'] ?: $m['enquiry_ref']); ?></span>
                <span class="lg-sm" style="margin-left:auto;opacity:.6;"><?php echo h($m['created_at']); ?></span>
            </div>
            <div style="white-space:pre-wrap;margin-top:4px;"><?php echo h($m['body']); ?></div>
        </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- 附件 -->
<div class="ad-card" data-workspace-tab="documents" data-workspace-label="Documents">
    <h2><?php echo tp('adm.attach'); ?> <span class="lg-sm"><?php echo count($docs); ?></span></h2>
    <?php if (!$docs): ?><div class="ad-empty"><?php echo h(t('adm.no_data')); ?></div>
    <?php else: ?>
    <table class="ad-tb">
        <tr><th><?php echo tp('detail'); ?></th><th><?php echo tp('adm.ref'); ?></th><th>Type</th><th>Size</th><th><?php echo tp('date'); ?></th><th><?php echo tp('actions'); ?></th></tr>
        <?php foreach ($docs as $d): ?>
        <tr>
            <td><?php echo h($d['title'] ?: $d['original_name']); ?>
                <div class="lg-sm" style="opacity:.55;"><?php echo h($d['original_name']); ?></div></td>
            <td class="ad-mono" style="font-size:12px;"><?php echo h($d['order_ref'] ?? '—'); ?></td>
            <td><?php echo h($d['kind']); ?></td>
            <td><?php echo h(round($d['size'] / 1024, 1)); ?> KB</td>
            <td><?php echo h($d['created_at']); ?></td>
            <td><a class="btn btn-s" href="<?php echo h(site_url('app/admin/file.php?id=' . $d['id'])); ?>"><i class="fas fa-download"></i></a></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>

<!-- 操作留痕 -->
<?php if ($logs): ?>
<div class="ad-card" data-workspace-tab="activity" data-workspace-label="Activity">
    <h2><?php echo tp('adm.activity'); ?></h2>
    <table class="ad-tb">
        <tr><th><?php echo tp('adm.by'); ?></th><th>Action</th><th><?php echo tp('detail'); ?></th><th><?php echo tp('date'); ?></th></tr>
        <?php foreach ($logs as $l): ?>
        <tr>
            <td><?php echo h($l['admin_name'] ?: $l['username'] ?: '—'); ?></td>
            <td><span class="ad-tag"><?php echo h($l['action']); ?></span></td>
            <td><?php echo h($l['detail']); ?></td>
            <td><?php echo h($l['created_at']); ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php endif; ?>

<!-- 彻底删除客户（物理删除，不可逆） -->
<?php if ($c && admin_can_hard_delete()): ?>
<?php $cdc = client_data_counts((int)$c['id']); ?>
<div class="ad-card" data-workspace-tab="danger" data-workspace-label="Delete" style="border-color:#fecaca;">
    <h2 style="color:#b91c1c;"><i class="fas fa-radiation"></i> <?php echo lang() === 'zh' ? '彻底删除客户' : 'Permanently delete this client'; ?></h2>
    <div class="lg-sm" style="opacity:.8;line-height:1.8;margin-bottom:10px;">
        <?php echo lang() === 'zh'
            ? '物理删除该客户及其登录令牌、名下的附件（含磁盘文件），<b>不可恢复</b>。'
            : 'Physically deletes this client, their sign-in tokens and their attachments (files on disk included). <b>Cannot be undone.</b>'; ?>
        <br>
        <?php echo lang() === 'zh'
            ? '当前名下：' . $cdc['orders'] . ' 个订单、' . $cdc['enquiries'] . ' 个工单。'
            : 'Currently: ' . $cdc['orders'] . ' order(s), ' . $cdc['enquiries'] . ' enquiry(ies).'; ?>
    </div>
    <form method="post" onsubmit="return confirmHardDeleteClient(this, <?php echo json_encode($c['email']); ?>, <?php echo (int)$cdc['orders']; ?>, <?php echo (int)$cdc['enquiries']; ?>);">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="act" value="hard_delete">
        <input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>">
        <?php if ($cdc['orders'] > 0 || $cdc['enquiries'] > 0): ?>
        <label style="display:flex;gap:7px;align-items:flex-start;font-weight:600;color:#b91c1c;margin-bottom:10px;">
            <input type="checkbox" name="with_orders" value="1" style="width:auto;margin-top:2px;">
            <span><?php echo lang() === 'zh'
                ? '连同名下的订单和工单一并删除（这些订单的进度节点、付款记录、附件也会一起清除）'
                : 'Also delete their orders and enquiries (progress events, payments and attachments are removed too)'; ?></span>
        </label>
        <?php endif; ?>
        <div class="form-row">
            <div><label for="confirm_text"><?php echo lang() === 'zh' ? '请输入客户邮箱以确认' : 'Type the client email to confirm'; ?> <span class="ad-mono" style="color:#b91c1c;"><?php echo h($c['email']); ?></span></label>
                <input type="text" id="confirm_text" name="confirm_text" autocomplete="off" placeholder="<?php echo h($c['email']); ?>"></div>
        </div>
        <button class="btn btn-d" type="submit"><i class="fas fa-trash-can"></i> <?php echo lang() === 'zh' ? '我确认，永久删除' : 'Yes, delete permanently'; ?></button>
    </form>
</div>
<script>
function confirmHardDeleteClient(form, email, nOrders, nEnq) {
    var zh = <?php echo json_encode(lang() === 'zh'); ?>;
    var v = (form.querySelector('[name="confirm_text"]').value || '').trim();
    if (v !== email) {
        alert((zh ? '请输入客户邮箱 ' : 'Please type the client email ') + email + (zh ? ' 才能删除。' : ' to confirm deletion.'));
        return false;
    }
    var extra = '';
    if ((nOrders > 0 || nEnq > 0) && !form.querySelector('[name="with_orders"]').checked) {
        extra = zh
            ? '\n\n注意：该客户名下还有 ' + nOrders + ' 个订单、' + nEnq + ' 个工单，未勾选「一并删除」时系统会拒绝删除。'
            : '\n\nNote: this client still has ' + nOrders + ' order(s) and ' + nEnq + ' enquiry(ies); deletion will be rejected unless you tick "delete too".';
    }
    return confirm(zh
        ? '确认永久删除客户 ' + email + '？此操作不可恢复。' + extra
        : 'Permanently delete client ' + email + '? This cannot be undone.' + extra);
}
</script>
<?php elseif ($c): ?>
<div class="ad-card">
    <div class="lg-sm" style="opacity:.7;">
        <?php echo lang() === 'zh' ? '当前账号（只读）无权执行物理删除。' : 'Your account (viewer) is not allowed to delete records.'; ?>
    </div>
</div>
<?php endif; ?>

<p><a class="btn btn-s" href="<?php echo h(site_url('app/admin/clients.php')); ?>">← <?php echo h(t('back')); ?></a></p>
<?php admin_footer(); ?>
