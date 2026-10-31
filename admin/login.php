<?php
/**
 * 后台登录
 *
 * 2026-09-29：改用 Tabler 外观（app/admin/_auth.php），与后台内部皮肤统一。
 */
require_once __DIR__ . '/_auth.php';
/* 全站英文（2026-09-29）：不再按 cookie 初始化后台语言，lang() 恒返 en */

if (admin_id()) redirect(site_url('app/admin/index.php'));

$err = '';
$needInstall = false;
try {
    $hasAdmin = (int)db_val('SELECT COUNT(*) FROM admin_users', [], 0);
    $needInstall = ($hasAdmin === 0);
} catch (Exception $e) {
    $needInstall = true;
}

if (is_post() && !$needInstall) {
    if (!csrf_check()) {
        $err = t('enq.err_csrf');
    } elseif (honeypot_tripped()) {
        // 静默
    } elseif (!rate_limit('login')) {
        $err = t('enq.err_rate');
    } else {
        $u = post('username');
        $p = post('password');
        $row = db_one('SELECT * FROM admin_users WHERE username = ? AND status = "active"', [$u]);
        if ($row && password_verify($p, $row['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int)$row['id'];
            db_exec('UPDATE admin_users SET last_login_at = NOW() WHERE id = ?', [$row['id']]);
            redirect(site_url('app/admin/index.php'));
        }
        $err = t('adm.bad_login');
        app_log('failed admin login: ' . $u, 'WARN');
    }
}
?>
<?php auth_head('Sign in'); ?>
        <h2 class="h2 text-center mb-4"><?php echo tp('adm.login'); ?></h2>

        <?php if ($needInstall): ?>
            <div class="alert alert-important alert-warning" role="alert">
                <div class="d-flex">
                    <div><i class="fas fa-triangle-exclamation me-2"></i></div>
                    <div>
                        Not initialised yet. Please run
                        <a href="install.php" class="alert-link">install.php</a>
                        to create the tables and the first admin account.
                    </div>
                </div>
            </div>
        <?php else: ?>
            <?php if ($err): ?>
                <div class="alert alert-important alert-danger" role="alert"><?php echo h($err); ?></div>
            <?php endif; ?>
            <form method="post" autocomplete="on">
                <?php echo csrf_field(); ?>
                <input type="text" name="website" value="" style="display:none" tabindex="-1" autocomplete="off">
                <div class="mb-3">
                    <label class="form-label" for="username"><?php echo tp('adm.username'); ?></label>
                    <input type="text" class="form-control" id="username" name="username"
                           autocomplete="username" placeholder="admin" required autofocus>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password"><?php echo tp('adm.password'); ?></label>
                    <input type="password" class="form-control" id="password" name="password"
                           autocomplete="current-password" placeholder="••••••••" required>
                </div>
                <div class="form-footer">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-right-to-bracket me-1"></i><?php echo tj('adm.signin'); ?>
                    </button>
                </div>
            </form>
        <?php endif; ?>
<?php auth_foot(); ?>
