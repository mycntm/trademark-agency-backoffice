<?php
/**
 * 后台账号管理：新建 / 改资料 / 改密码 / 改权限 / 停用 / 删除
 *
 * 只有 owner 能进本页（require_owner）。
 * 三条硬规则，防止把自己锁在门外：
 *   1) 不能停用或删除「自己正在用的账号」；
 *   2) 至少要保留一个 active 的 owner；
 *   3) 不能把唯一的 owner 降级。
 */
require_once __DIR__ . '/_layout.php';
require_owner();

$me         = admin_user();
$meId       = (int)$me['id'];
$editId     = (int)get('id', 0);
$edit       = $editId ? db_one('SELECT * FROM admin_users WHERE id = ?', [$editId]) : null;
$ownerCount = (int)db_val("SELECT COUNT(*) FROM `admin_users` WHERE `role`='owner' AND `status`='active'", [], 0);

$msg = '';
$err = '';
$roles  = ['owner', 'staff', 'viewer'];
$langs  = ['zh', 'en'];

/* ---------- 新建 ---------- */
if (is_post() && post('act') === 'create') {
    if (!csrf_check()) {
        $err = t('enq.err_csrf');
    } else {
        $username = trim((string)post('username'));
        $pw       = (string)post('password');
        $role     = post('role');
        if ($username === '' || strlen($username) < 3) {
            $err = lang() === 'zh' ? '用户名至少 3 个字符。' : 'Username must be at least 3 characters.';
        } elseif (db_val('SELECT id FROM admin_users WHERE username = ?', [$username], 0)) {
            $err = t('adm.username_taken');
        } elseif (strlen($pw) < 8) {
            $err = lang() === 'zh' ? '密码至少 8 位。' : 'Password must be at least 8 characters.';
        } else {
            $newId = (int)db_exec(
                'INSERT INTO admin_users (`username`,`password_hash`,`name`,`role`,`can_view_sensitive`,`lang`,`status`) VALUES (?,?,?,?,?,?,?)',
                [
                    $username,
                    password_hash($pw, PASSWORD_DEFAULT),
                    trim((string)post('name')),
                    in_array($role, $roles, true) ? $role : 'staff',
                    (int)post('can_view_sensitive', 0) ? 1 : 0,
                    in_array(post('lang'), $langs, true) ? post('lang') : 'zh',
                    'active',
                ]
            );
            admin_log_action('create', 'admin_user', $newId, $username . ' / ' . $role);
            $msg = t('adm.saved');
        }
    }
}

/* ---------- 改资料 / 改密码 / 改权限 ---------- */
if (is_post() && post('act') === 'update') {
    if (!csrf_check()) {
        $err = t('enq.err_csrf');
    } else {
        $id    = (int)post('id', 0);
        $row   = db_one('SELECT * FROM admin_users WHERE id = ?', [$id]);
        $role  = post('role');
        $stat  = post('status') === 'disabled' ? 'disabled' : 'active';
        if (!$row) {
            $err = lang() === 'zh' ? '账号不存在。' : 'Account not found.';
        } elseif ($id === $meId && $stat === 'disabled') {
            $err = t('adm.self_edit');                       // 不能停用自己
        } elseif ($id === $meId && $role !== 'owner') {
            $err = t('adm.self_edit');                       // 不能给自己降级
        } elseif ($row['role'] === 'owner' && $role !== 'owner' && $ownerCount <= 1) {
            $err = t('adm.last_owner');                      // 唯一的 owner 不能被降级
        } else {
            $username = trim((string)post('username'));
            if ($username === '' || strlen($username) < 3) {
                $err = lang() === 'zh' ? '用户名至少 3 个字符。' : 'Username must be at least 3 characters.';
            } elseif ((int)db_val('SELECT id FROM admin_users WHERE username = ? AND id <> ?', [$username, $id], 0)) {
                $err = t('adm.username_taken');
            } else {
                $sets = ['`username` = ?', '`name` = ?', '`role` = ?', '`can_view_sensitive` = ?', '`lang` = ?', '`status` = ?'];
                $args = [
                    $username,
                    trim((string)post('name')),
                    in_array($role, $roles, true) ? $role : 'staff',
                    (int)post('can_view_sensitive', 0) ? 1 : 0,
                    in_array(post('lang'), $langs, true) ? post('lang') : 'zh',
                    $stat,
                ];
                $pw = (string)post('password');
                if ($pw !== '') {
                    if (strlen($pw) < 8) {
                        $err = lang() === 'zh' ? '密码至少 8 位。' : 'Password must be at least 8 characters.';
                    } else {
                        $sets[] = '`password_hash` = ?';
                        $args[] = password_hash($pw, PASSWORD_DEFAULT);
                    }
                }
                if (!$err) {
                    $args[] = $id;
                    db_exec('UPDATE `admin_users` SET ' . implode(', ', $sets) . ' WHERE id = ?', $args);
                    admin_log_action('update', 'admin_user', $id, $username . ' / ' . $role . ' / ' . $stat);
                    $msg = t('adm.saved');
                }
            }
        }
    }
}

/* ---------- 停用 / 启用 ---------- */
if (is_post() && post('act') === 'status') {
    if (!csrf_check()) {
        $err = t('enq.err_csrf');
    } else {
        $id   = (int)post('id', 0);
        $row  = db_one('SELECT * FROM admin_users WHERE id = ?', [$id]);
        $want = post('status') === 'disabled' ? 'disabled' : 'active';
        if ($id === $meId && $want === 'disabled') {
            $err = t('adm.self_edit');
        } elseif ($row && $row['role'] === 'owner' && $want === 'disabled' && $ownerCount <= 1) {
            $err = t('adm.last_owner');
        } elseif ($row) {
            db_exec('UPDATE `admin_users` SET `status` = ? WHERE id = ?', [$want, $id]);
            admin_log_action($want === 'disabled' ? 'disable' : 'enable', 'admin_user', $id, $row['username']);
            $msg = t('adm.saved');
        }
    }
}

/* ---------- 删除 ---------- */
if (is_post() && post('act') === 'delete') {
    if (!csrf_check()) {
        $err = t('enq.err_csrf');
    } else {
        $id  = (int)post('id', 0);
        $row = db_one('SELECT * FROM admin_users WHERE id = ?', [$id]);
        if ($id === $meId) {
            $err = t('adm.self_edit');
        } elseif (!$row) {
            $err = lang() === 'zh' ? '账号不存在。' : 'Account not found.';
        } elseif ($row['role'] === 'owner' && $ownerCount <= 1) {
            $err = t('adm.last_owner');
        } else {
            db_exec('DELETE FROM `admin_users` WHERE id = ?', [$id]);
            admin_log_action('delete', 'admin_user', $id, $row['username']);
            $msg = lang() === 'zh' ? '账号已删除。' : 'Account deleted.';
        }
    }
}

$users = db_all('SELECT * FROM admin_users ORDER BY FIELD(`role`,"owner","staff","viewer"), `id` ASC');

admin_header('adm.users', 'users');
?>
<?php if ($msg): ?><?php echo ad_flash($msg, 'ok'); ?><?php endif; ?>
<?php if ($err): ?><?php echo ad_flash($err, 'bad'); ?><?php endif; ?>

<div class="ad-card">
    <h2><?php echo tp('adm.users'); ?> <span class="lg-sm" style="font-weight:400;opacity:.6;"><?php echo count($users); ?> <?php echo lang() === 'zh' ? '个账号' : 'accounts'; ?></span></h2>
    <table class="ad-tb">
        <tr><th><?php echo lang() === 'zh' ? '用户名' : 'Username'; ?></th><th><?php echo lang() === 'zh' ? '姓名' : 'Name'; ?></th>
            <th><?php echo tp('adm.role'); ?></th><th><?php echo tp('adm.can_view_sensitive'); ?></th>
            <th><?php echo tp('language'); ?></th><th><?php echo tp('status'); ?></th>
            <th><?php echo lang() === 'zh' ? '最后登录' : 'Last login'; ?></th><th><?php echo tp('actions'); ?></th></tr>
        <?php foreach ($users as $u): ?>
        <tr>
            <td class="ad-mono"><?php echo h($u['username']); ?><?php if ((int)$u['id'] === $meId): ?> <span class="ad-tag done"><?php echo lang() === 'zh' ? '本人' : 'you'; ?></span><?php endif; ?></td>
            <td><?php echo h($u['name']); ?></td>
            <td><span class="ad-tag <?php echo $u['role'] === 'owner' ? 'new' : ''; ?>"><?php echo h(t('adm.role_' . $u['role'])); ?></span></td>
            <td><?php echo $u['can_view_sensitive'] ? h(t('yes')) : h(t('no')); ?></td>
            <td><?php echo h(strtoupper($u['lang'])); ?></td>
            <td><span class="ad-tag <?php echo $u['status'] === 'active' ? 'done' : 'closed'; ?>"><?php echo h($u['status']); ?></span></td>
            <td><?php echo h($u['last_login_at'] ?: '—'); ?></td>
            <td style="white-space:nowrap;">
                <a class="btn btn-s" href="<?php echo h(site_url('app/admin/users.php?id=' . (int)$u['id'])); ?>"><i class="fas fa-pen"></i> <?php echo h(t('edit')); ?></a>
                <?php if ((int)$u['id'] !== $meId): ?>
                <form method="post" style="display:inline;" onsubmit="return confirm('<?php echo h($u['status'] === 'active' ? (lang() === 'zh' ? '停用这个账号？' : 'Disable this account?') : (lang() === 'zh' ? '启用这个账号？' : 'Enable this account?')); ?>');">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="act" value="status">
                    <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                    <input type="hidden" name="status" value="<?php echo $u['status'] === 'active' ? 'disabled' : 'active'; ?>">
                    <button class="btn btn-s" type="submit"><?php echo $u['status'] === 'active' ? h(t('adm.disable')) : h(t('adm.enable')); ?></button>
                </form>
                <form method="post" style="display:inline;" onsubmit="return confirm('<?php echo h(t('adm.confirm_delete')); ?>');">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="act" value="delete">
                    <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                    <button class="btn btn-s btn-d" type="submit"><i class="fas fa-trash"></i></button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <div class="lg-sm" style="margin-top:12px;opacity:.75;font-size:12.5px;">
        <?php echo lang() === 'zh'
            ? '规则：不能停用/删除自己正在用的账号；至少要保留一个启用中的负责人；唯一的负责人不能被降级。'
            : 'Rules: you cannot disable or delete your own account; at least one active owner must remain; the last owner cannot be demoted.'; ?>
    </div>
</div>

<div class="ad-card">
    <h2><?php echo $edit ? (lang() === 'zh' ? '编辑账号' : 'Edit account') . ' · ' . h($edit['username']) : tp('adm.new_user'); ?></h2>
    <form method="post">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="act" value="<?php echo $edit ? 'update' : 'create'; ?>">
        <?php if ($edit): ?><input type="hidden" name="id" value="<?php echo (int)$edit['id']; ?>"><?php endif; ?>
        <div class="form-row">
            <div><label for="username"><?php echo lang() === 'zh' ? '用户名' : 'Username'; ?></label>
                <input type="text" id="username" name="username" value="<?php echo h($edit['username'] ?? ''); ?>" autocomplete="off" required></div>
            <div><label for="name"><?php echo lang() === 'zh' ? '姓名' : 'Name'; ?></label>
                <input type="text" id="name" name="name" value="<?php echo h($edit['name'] ?? ''); ?>"></div>
            <div><label for="password"><?php echo tp('adm.new_password'); ?></label>
                <input type="password" id="password" name="password" autocomplete="new-password" <?php echo $edit ? '' : 'required'; ?>>
                <?php if ($edit): ?><div class="lg-sm" style="font-size:12px;opacity:.7;margin-top:4px;"><?php echo h(t('adm.keep_password')); ?></div><?php endif; ?></div>
            <div><label for="role"><?php echo tp('adm.role'); ?></label>
                <select name="role" id="role">
                    <?php foreach ($roles as $r): ?>
                        <option value="<?php echo h($r); ?>" <?php echo ($edit['role'] ?? 'staff') === $r ? 'selected' : ''; ?>><?php echo h(t('adm.role_' . $r)); ?></option>
                    <?php endforeach; ?>
                </select></div>
            <!-- Language 字段已移除（2026-09-29 全站英文，admin_users.lang 固定 en） -->
            <input type="hidden" name="lang" value="en">
            <?php if ($edit): ?>
            <div><label for="status"><?php echo tp('status'); ?></label>
                <select name="status" id="status">
                    <option value="active" <?php echo $edit['status'] === 'active' ? 'selected' : ''; ?>>active</option>
                    <option value="disabled" <?php echo $edit['status'] === 'disabled' ? 'selected' : ''; ?>>disabled</option>
                </select></div>
            <?php endif; ?>
            <div><label for="can_view_sensitive"><?php echo tp('adm.can_view_sensitive'); ?></label>
                <select name="can_view_sensitive" id="can_view_sensitive">
                    <option value="0" <?php echo empty($edit['can_view_sensitive']) ? 'selected' : ''; ?>><?php echo h(t('no')); ?></option>
                    <option value="1" <?php echo !empty($edit['can_view_sensitive']) ? 'selected' : ''; ?>><?php echo h(t('yes')); ?></option>
                </select></div>
        </div>
        <button class="btn btn-p" type="submit"><i class="fas fa-floppy-disk"></i> <?php echo h(t('save')); ?></button>
        <?php if ($edit): ?>
            <a class="btn" href="<?php echo h(site_url('app/admin/users.php')); ?>"><?php echo $edit ? h(t('cancel')) : ''; ?></a>
        <?php endif; ?>
    </form>
    <div class="lg-sm" style="margin-top:12px;opacity:.75;font-size:12.5px;">
        <?php echo lang() === 'zh'
            ? '「只读」是服务端强制的：Viewer 账号发起的任何写入请求（POST）都会在后台统一入口被拦下并返回 403，不是只靠隐藏按钮。敏感文件（委托书、身份证件）仍然只有勾选了权限的人能下载。'
            : '“Viewer” is enforced on the server: any write request (POST) from a Viewer account is rejected with 403 at the shared admin entry point — it is not just hidden buttons. Sensitive files (POA, ID documents) remain downloadable only by accounts granted that right.'; ?>
    </div>
</div>
<?php admin_footer(); ?>
