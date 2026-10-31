<?php
/**
 * 登录 / 安装这类「不走 _layout.php」的独立页面共用外观（2026-09-29）
 *
 * 换肤后如果只有后台内部是 Tabler、第一屏登录还是老皮肤，断层会很扎眼，
 * 所以这两个独立页也套 Tabler 的 page-center + card-md 结构。
 *
 * 用法：
 *   require_once __DIR__ . '/_auth.php';
 *   auth_head('Sign in');      // 输出 <head> + 打开页面容器
 *   ... 内容（写在 .card-body 里） ...
 *   auth_foot();               // 关闭容器 + 输出脚本
 *
 * auth_head() 的第二参数 $wide 为 true 时用 card 而不是 card-md（安装页的说明文字更长）。
 */
require_once __DIR__ . '/../bootstrap.php';

if (!function_exists('admin_asset')) {
    function admin_asset($path)
    {
        return site_url('app/admin/assets/' . ltrim($path, '/'));
    }
}

if (!function_exists('auth_head')) {
    function auth_head($title = '', $wide = false)
    {
        ?>
<!doctype html>
<html lang="<?php echo h(lang()); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo h($title !== '' ? $title : tj('adm.title')); ?> · EXAMPLE IP</title>
<link rel="stylesheet" href="<?php echo h(admin_asset('tabler/tabler.min.css')); ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.4.0/css/all.min.css">
<?php echo i18n_css(); ?>
<style>
/* 覆盖 Tabler 默认蓝，对齐站点主色 #0b3d91 */
:root{--tblr-primary:#0b3d91;--tblr-primary-rgb:11,61,145}
/* light-dark() 兜底（同 _layout.php）：老浏览器上 Tabler 的配色变量会整条失效 */
@supports not (background: light-dark(#fff, #000)) {
    :root{
        --tblr-body-color:#1f2937;
        --tblr-secondary-color:#6b7280;
        --tblr-bg-surface:#fff;
        --tblr-bg-surface-tertiary:#f5f7f9;
        --tblr-border-color:#e5e7eb;
    }
}
body{font-size:.875rem}
.auth-brand{font-weight:700;letter-spacing:.02em;font-size:1.25rem;color:var(--tblr-primary);text-decoration:none}
.auth-note{font-size:.8125rem;color:var(--tblr-secondary-color);line-height:1.7}
.auth-note ul{margin:.5rem 0 0;padding-left:1.1rem}
.auth-note li{margin-bottom:.25rem}
.auth-note code{
    font-size:.8125rem;
    color:var(--tblr-body-color);
    background:var(--tblr-bg-surface-tertiary);
    padding:.1rem .35rem;
    border-radius:4px;
}
</style>
</head>
<body>
<div class="page page-center">
  <div class="container container-tight py-4">
    <div class="text-center mb-4">
      <a href="<?php echo h(site_url('app/admin/login.php')); ?>" class="auth-brand">
        <i class="fas fa-shield-halved me-1"></i>EXAMPLE IP
      </a>
      <div class="auth-note mt-1"><?php echo h(tj('adm.title')); ?></div>
    </div>
    <div class="card <?php echo $wide ? '' : 'card-md'; ?>">
      <div class="card-body">
<?php
    }
}

if (!function_exists('auth_foot')) {
    function auth_foot()
    {
        ?>
      </div>
    </div>
  </div>
</div>
<script src="<?php echo h(admin_asset('tabler/tabler.min.js')); ?>"></script>
</body>
</html>
<?php
    }
}
