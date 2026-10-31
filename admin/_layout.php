<?php
/**
 * 后台布局（2026-09-29 起英文单语；tp()/tj() 输出恒为英文）
 *
 * 2026-09-29 换肤：骨架改用 Tabler 1.6.1（本地 app/admin/assets/tabler/，
 * 不依赖第三方 CDN）。业务页面仍在用 .ad-card / .ad-tb / .ad-tag / .btn 这些
 * 旧类名，所以下面有一段「兼容层」把它们映射到 Tabler / Bootstrap 的视觉，
 * 这样 22 个业务页面不用改一行就能吃到新皮肤。
 *
 * 用法：
 *   require_once __DIR__ . '/_layout.php';
 *   admin_header('标题 key', '当前导航 key');
 *   ... 内容 ...
 *   admin_footer();
 */
require_once __DIR__ . '/../bootstrap.php';

require_admin();            // 未登录跳转 app/admin/login.php
admin_guard_readonly();     // viewer（只读账号）禁止一切写操作（2026-09-30）

/** 后台静态资源 URL */
if (!function_exists('admin_asset')) {
    function admin_asset($path)
    {
        return site_url('app/admin/assets/' . ltrim($path, '/'));
    }
}

if (!function_exists('admin_header')) {
    /**
     * @param string $title   标题语言 key
     * @param string $active  当前导航 key
     * @param string $actions 页头右上角操作区 HTML（可选）。
     *                        列表页的「新建」类主动作放这里，不要塞在筛选栏里
     *                        —— 否则会和筛选按钮并列成两个主按钮。
     */
    function admin_header($title = '', $active = '', $actions = '')
    {
        $u = admin_user();
        $nav = [
            'index'     => ['adm.dashboard', 'app/admin/index.php',     'fa-gauge-high'],
            'enquiries' => ['adm.enquiries', 'app/admin/enquiries.php', 'fa-inbox'],
            'orders'    => ['adm.orders',    'app/admin/orders.php',    'fa-file-signature'],
            'messages'  => ['adm.messages',  'app/admin/messages.php',  'fa-comments'],
            'clients'   => ['adm.clients',   'app/admin/clients.php',   'fa-users'],
            'payments'  => ['adm.payments',  'app/admin/payments.php',  'fa-credit-card'],
            'users'     => ['adm.users',     'app/admin/users.php',     'fa-user-shield'],
            'settings'  => ['adm.settings',  'app/admin/settings.php',  'fa-gear'],
        ];
        // 账号管理只给负责人看。员工/只读账号连入口都不该见到。
        if (function_exists('admin_is_owner') && !admin_is_owner()) unset($nav['users']);
        $navGroups = [
            'WORKFLOW' => ['index', 'enquiries', 'orders', 'messages'],
            'RECORDS' => ['clients', 'payments'],
            'ADMINISTRATION' => ['users', 'settings'],
        ];
        $newCount = 0;
        try {
            /* 软删除的工单不计入徽标（与 enquiries.php 列表同一口径；迁移没跑时退化为全量） */
            $enqAlive = enq_alive_sql();
            $newCount = (int)db_val(
                "SELECT COUNT(*) FROM enquiries WHERE status='new'"
                . ($enqAlive !== '' ? " AND {$enqAlive}" : ''),
                [], 0);
        } catch (Exception $e) {}
        /* 待回复留言数：会话里最后一条是客户发的 = 客户在等我们回话 */
        $msgOpen = 0;
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

        $meName  = $u['name'] ?: $u['username'];
        $meRole  = isset($u['role']) ? $u['role'] : '';
        $initial = strtoupper(function_exists('mb_substr') ? mb_substr($meName, 0, 1) : substr($meName, 0, 1));
        ?>
<!doctype html>
<html lang="<?php echo h(lang()); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo $title !== '' ? h(tj($title)) . ' · ' : ''; ?><?php echo h(tj('adm.title')); ?></title>
<link rel="stylesheet" href="<?php echo h(admin_asset('tabler/tabler.min.css')); ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.4.0/css/all.min.css">
<style>
/* ==========================================================================
   兼容层：把业务页面在用的旧类名接到 Tabler 的视觉体系上。
   业务页面不用改，改这里就能整体换肤。
   ========================================================================== */
:root{
    --ad-pri:#0b3d91;
    --ad-radius:8px;
    /* 覆盖 Tabler 默认蓝，对齐站点主色（--tblr-primary 是 Tabler 自己用的变量名，
       派生色 --tblr-primary-lt / -darken / -fg 都由它算出来，改这一处即可整体跟随） */
    --tblr-primary:#0b3d91;
    --tblr-primary-rgb:11,61,145;
    /* 旧皮肤遗留变量名：业务页面的内联 style 仍在引用（--pri / --bad / --bd …），
       这里保留同名别名，否则换肤后这些内联颜色会全部失效。 */
    --pri:#0b3d91;
    --pri-l:#eef2ff;
    --bd:#e5e7eb;
    --border:#e5e7eb;
    --tx:#1f2937;
    --gray:#6b7280;
    --ok:#10b981;
    --warn:#f59e0b;
    --bad:#ef4444;
}

/* light-dark() 兜底：Tabler 1.6.1 的配色依赖 light-dark() / color-mix()
   （Chrome 123+ / Safari 17.5+ / Firefox 120+），且它没有自带降级。
   老浏览器上这些变量会整条失效，卡片底会变成透明、文字变黑底黑字。
   这里给兼容层用到的几个变量补一份固定色，保证最坏情况仍是「浅底深字」。 */
@supports not (background: light-dark(#fff, #000)) {
    :root{
        --tblr-primary:#0b3d91;
        --tblr-body-color:#1f2937;
        --tblr-secondary-color:#6b7280;
        --tblr-bg-surface:#fff;
        --tblr-bg-surface-tertiary:#f5f7f9;
        --tblr-bg-forms:#fff;
        --tblr-border-color:#e5e7eb;
        --tblr-border-color-translucent:rgba(17,24,39,.12);
        --tblr-border-radius:6px;
        --tblr-border-radius-lg:8px;
    }
}

body{font-size:.875rem;line-height:1.55}
/* Tabler 默认页面底色是 near-white，白卡片几乎浮不起来；换成 gray-50 拉开层次 */
.page{background:var(--tblr-bg-surface-tertiary)}

/* --- 裸标签收敛（必须放在具体组件规则之前） ---
   Bootstrap reboot 会把裸 h2 放大到 2rem、给 p/ul 加 1rem 外边距；
   后台页面里有 45 处裸 h2/h3、131 处裸 label，不收敛会明显走形。
   带内联 style 的元素优先级更高，不受这里影响。 */
.page-body h1,.page-body h2,.page-body h3,.page-body h4{
    margin:0 0 .75rem;
    font-weight:600;
    line-height:1.35;
    color:var(--tblr-body-color);
}
.page-body h1{font-size:1.375rem}
.page-body h2{font-size:1rem}
.page-body h3{font-size:.9375rem}
.page-body h4{font-size:.875rem}
/* 后台 131 处裸 <label> 都不带 form-label，这里统一接管；
   包着复选框/单选的那几个（client.php 1 处、order_new.php 2 处）用 :has() 拉回行内。 */
.page-body label{display:block;font-size:.75rem;font-weight:600;color:var(--tblr-secondary-color);margin-bottom:.25rem}
.page-body label:has(input[type=checkbox]),.page-body label:has(input[type=radio]){
    display:inline-flex;align-items:center;gap:.35rem;margin-bottom:0;font-weight:400;font-size:.8125rem;color:var(--tblr-body-color)
}
.page-body p{margin-top:0;margin-bottom:.75rem}
.page-body ul,.page-body ol{margin:0 0 .75rem;padding-left:1.25rem}
.page-body hr{border:0;border-top:1px solid var(--tblr-border-color-translucent);margin:1rem 0}
/* 排除 .btn：否则这里的颜色会盖掉按钮自身的文字色（.btn-p 是白字深蓝底） */
.page-body a:not(.btn){color:var(--tblr-primary);text-decoration:none}
.page-body a:not(.btn):hover{text-decoration:underline}

/* --- 卡片：对齐 .card --- */
.ad-card{
    background:var(--tblr-bg-surface);
    border:1px solid var(--tblr-border-color-translucent);
    border-radius:var(--tblr-border-radius-lg);
    box-shadow:0 2px 8px rgba(16,24,40,.045);
    padding:1.5rem;
    margin-bottom:1.5rem;
}
.ad-card h2{
    font-size:.9375rem;
    font-weight:600;
    margin:0 0 1.125rem;
    padding-bottom:.875rem;
    border-bottom:1px solid var(--tblr-border-color-translucent);
}
.ad-card h2 .lg-sm{font-weight:400;color:var(--tblr-secondary-color)}
.ad-card > *:last-child{margin-bottom:0}

/* --- 表格 --- */
table.ad-tb{width:100%;border-collapse:collapse;font-size:.8125rem}
table.ad-tb th{
    text-align:left;
    color:var(--tblr-secondary-color);
    font-weight:600;
    font-size:.6875rem;
    text-transform:uppercase;
    letter-spacing:.04em;
    padding:.7rem .9rem;
    border-bottom:1px solid var(--tblr-border-color);
    white-space:nowrap;
}
table.ad-tb th .lg-sm{font-weight:400;text-transform:none;letter-spacing:0}
table.ad-tb td{
    padding:.9rem;
    border-bottom:1px solid var(--tblr-border-color-translucent);
    vertical-align:top;
    line-height:1.55;
}
table.ad-tb tbody tr:hover td{background:var(--tblr-bg-surface-tertiary)}
table.ad-tb tbody tr:last-child td{border-bottom:0}
table.ad-tb td .btn{margin:0 .25rem .25rem 0}
table.ad-tb td .btn:last-child{margin-right:0}
/* 短字段（REF / 申请号 / 金额）防折行：加 .nw 整块换行，不会拆成多行 */
table.ad-tb td.nw,table.ad-tb th.nw{white-space:nowrap}

/* --- 状态徽章：对齐 .badge --- */
.ad-tag{
    display:inline-flex;
    align-items:center;
    gap:.25rem;
    padding:.15rem .5rem;
    border-radius:var(--tblr-border-radius-pill);
    font-size:.6875rem;
    font-weight:600;
    line-height:1.4;
    white-space:nowrap;
    background:var(--tblr-bg-surface-tertiary);
    color:var(--tblr-secondary-color);
}
/* 状态色板：四档语义色 —— 待处理=琥珀 / 进行中=蓝 / 完成=绿 / 异常=红，中性=灰。
   订单状态有 11 种、工单 5 种、付款/邮件队列若干，漏掉的种类会掉成默认灰，
   而状态列是后台列表最需要一眼扫出来的，所以这里按枚举全量铺满。 */
.ad-tag.new,.ad-tag.pending,.ad-tag.pending_payment,
.ad-tag.queued,.ad-tag.warn{background:#fff9db;color:#8a6100}

.ad-tag.quoted,.ad-tag.filed,.ad-tag.examining,.ad-tag.published,
.ad-tag.processing,.ad-tag.active{background:#dbeafe;color:#1e40af}

.ad-tag.confirmed,.ad-tag.paid,.ad-tag.done,.ad-tag.registered,
.ad-tag.completed,.ad-tag.sent,.ad-tag.success,.ad-tag.ok{background:#d3f9d8;color:#0f5132}

.ad-tag.closed,.ad-tag.refunded,.ad-tag.inactive,.ad-tag.note{background:#f1f3f5;color:#495057}

.ad-tag.declined,.ad-tag.refused,.ad-tag.withdrawn,.ad-tag.opposed,
.ad-tag.failed,.ad-tag.error,.ad-tag.bad,
.ad-tag.void,.ad-tag.voided,.ad-tag.cancelled{background:#ffe3e3;color:#a61e1e}

/* --- 按钮 ---
   注意：这些规则必须带 .page-body 作用域。裸 .btn 的特异度和 Tabler 的
   .btn-primary 相同、但位置更靠后，会把原生主按钮染成白底，
   页头里就再也放不了 .btn-primary 了。 */
.page-body .btn{
    display:inline-flex;
    align-items:center;
    gap:.375rem;
    padding:.375rem .75rem;
    border:1px solid var(--tblr-border-color);
    border-radius:var(--tblr-border-radius);
    background:var(--tblr-bg-surface);
    color:var(--tblr-body-color);
    font-size:.8125rem;
    font-weight:500;
    line-height:1.5;
    text-decoration:none;
    cursor:pointer;
    transition:background .15s,border-color .15s;
}
.page-body .btn:hover{border-color:var(--tblr-primary);color:var(--tblr-primary);text-decoration:none}
.page-body .btn-p{
    background:var(--ad-pri);
    border-color:var(--ad-pri);
    color:#fff;
}
.page-body .btn-p:hover{background:#093070;border-color:#093070;color:#fff}
.page-body .btn-d{color:#d63939;border-color:#f5c2c7}
.page-body .btn-d:hover{background:#fff5f5;border-color:#d63939;color:#d63939}
.page-body .btn-s{padding:.25rem .5rem;font-size:.75rem}

/* --- 表单 --- */
.form-row{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(200px,1fr));
    gap:1.125rem 1.25rem;
    margin-bottom:1.25rem;
}
.form-row label{
    display:block;
    font-size:.75rem;
    font-weight:600;
    margin-bottom:.4rem;
    color:var(--tblr-secondary-color);
}
.form-row label .lg-sm{font-weight:400;text-transform:none}
.form-row input,.form-row select,.form-row textarea{
    width:100%;
    padding:.4375rem .75rem;
    border:1px solid var(--tblr-border-color);
    border-radius:var(--tblr-border-radius);
    background:var(--tblr-bg-forms);
    color:var(--tblr-body-color);
    font-size:.8125rem;
    font-family:inherit;
    line-height:1.5;
}
.form-row input:focus,.form-row select:focus,.form-row textarea:focus{
    outline:0;
    border-color:var(--tblr-primary);
    box-shadow:0 0 0 3px rgba(11,61,145,.12);
}
/* --- 后台长表单的分组版式（2026-10-04） ---
   默认 .form-row 是 auto-fit，每列最小 200px：字段一多（建单/建工单/客户资料
   都有 6-8 个字段）就被挤成一行，输入框窄得只剩一个字宽。需要呼吸感的表单
   显式加 .cols3 —— 固定三列、加大行距，并可用：
     .fsec   分区标题（如「客户 Client」「商标与类别」）
     .span2  宽字段跨两列
     .full   独占整行（备注等多行文本）
   只对显式加类的表单生效，其它沿用 auto-fit 的页面不受影响。 */
.form-row.cols3{
    grid-template-columns:repeat(3,minmax(0,1fr));
    column-gap:1.5rem;
    row-gap:1.35rem;
    margin-bottom:1.5rem;
}
.form-row .span2{grid-column:span 2}
.form-row .full{grid-column:1/-1}
.fsec{
    font-size:.72rem;
    font-weight:700;
    letter-spacing:.07em;
    text-transform:uppercase;
    color:var(--tblr-secondary-color);
    margin:1.5rem 0 .75rem;
}
.fsec:first-child{margin-top:.25rem}
.form-actions{
    margin-top:1.5rem;
    display:flex;
    gap:.5rem;
    flex-wrap:wrap;
    align-items:center;
}
@media (max-width:992px){
    .form-row.cols3{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media (max-width:576px){
    .form-row.cols3{grid-template-columns:1fr}
    .form-row .span2{grid-column:auto}
}
/* 裸写的表单控件也一起美化（业务页有 130+ 处不带 form-control，
   分布在卡片、筛选栏、行内表单里，所以作用域取 .page-body 而不是 .ad-card） */
.page-body input[type=text]:not(.form-control),
.page-body input[type=number]:not(.form-control),
.page-body input[type=email]:not(.form-control),
.page-body input[type=password]:not(.form-control),
.page-body input[type=date]:not(.form-control),
.page-body input[type=search]:not(.form-control),
.page-body select:not(.form-control):not(.form-select),
.page-body textarea:not(.form-control){
    padding:.4375rem .75rem;
    border:1px solid var(--tblr-border-color);
    border-radius:var(--tblr-border-radius);
    background:var(--tblr-bg-forms);
    color:var(--tblr-body-color);
    font-size:.8125rem;
    font-family:inherit;
    line-height:1.5;
    max-width:100%;
}
.page-body input[type=text]:focus:not(.form-control),
.page-body input[type=number]:focus:not(.form-control),
.page-body input[type=email]:focus:not(.form-control),
.page-body input[type=password]:focus:not(.form-control),
.page-body input[type=date]:focus:not(.form-control),
.page-body input[type=search]:focus:not(.form-control),
.page-body select:focus:not(.form-control):not(.form-select),
.page-body textarea:focus:not(.form-control){
    outline:0;
    border-color:var(--tblr-primary);
    box-shadow:0 0 0 3px rgba(11,61,145,.12);
}
/* 复选框/单选：全局 3 处，只做尺寸对齐，不要套边框 */
.page-body input[type=checkbox]:not(.form-check-input),
.page-body input[type=radio]:not(.form-check-input){width:1rem;height:1rem;accent-color:var(--tblr-primary);margin:0}
/* 裸 textarea 浏览器默认只有 ~20 字符宽，在卡片里会显得塌陷；卡片内统一拉满。
   （裸 input 不给 width:100%：旧皮肤只在 .form-row 里撑满，筛选栏那种
     输入框 + 下拉 + 按钮同行排布的场景一旦全局拉满就会挤成一团。） */
.ad-card textarea:not(.form-control){width:100%}

/* --- 提示条 --- */
.ad-alert{
    padding:.75rem 1rem;
    border-radius:var(--tblr-border-radius);
    font-size:.8125rem;
    margin-bottom:1rem;
    border:1px solid transparent;
}
.ad-alert.ok{background:#ebfbee;color:#0f5132;border-color:#b2f2bb}
.ad-alert.bad{background:#fff5f5;color:#a61e1e;border-color:#ffc9c9}
.ad-alert.warn{background:#fff9db;color:#8a6100;border-color:#ffec99}

/* --- 统计卡 --- */
.ad-stats{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(180px,1fr));
    gap:1.125rem;
    margin-bottom:1.5rem;
}
.ad-work-actions{
    display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1rem;margin:0 0 1.5rem;
}
.ad-work-action{
    min-width:0;display:flex;align-items:center;gap:.85rem;padding:1rem 1.1rem;
    color:var(--tblr-body-color)!important;text-decoration:none!important;
    background:var(--tblr-bg-surface);border:1px solid var(--tblr-border-color-translucent);
    border-radius:var(--tblr-border-radius-lg);box-shadow:0 1px 3px rgba(16,24,40,.04);
    transition:border-color .15s,box-shadow .15s,transform .15s;
}
.ad-work-action:hover{border-color:rgba(11,61,145,.35);box-shadow:0 5px 16px rgba(16,24,40,.08);transform:translateY(-1px)}
.ad-work-action.primary{border-color:rgba(11,61,145,.22);background:linear-gradient(135deg,#fff,#f5f8ff)}
.ad-work-action .icon{width:2.25rem;height:2.25rem;flex:0 0 2.25rem;display:grid;place-items:center;border-radius:9px;background:#edf2ff;color:var(--tblr-primary)}
.ad-work-action > span:nth-child(2){min-width:0;display:flex;flex-direction:column;gap:.15rem}
.ad-work-action b{font-size:.82rem;line-height:1.35}
.ad-work-action small{color:var(--tblr-secondary-color);font-size:.72rem;line-height:1.4}
.ad-work-action .arrow{margin-left:auto;color:var(--tblr-secondary-color);font-size:.75rem}
.ad-filter-pills{display:flex;gap:.45rem;overflow-x:auto;padding:.1rem .1rem .75rem;margin-bottom:.85rem}
.ad-filter-pill{
    flex:0 0 auto;display:inline-flex;align-items:center;padding:.42rem .72rem;
    border:1px solid var(--tblr-border-color);border-radius:999px;background:#fff;
    color:var(--tblr-secondary-color)!important;font-size:.75rem;font-weight:600;
    text-decoration:none!important;transition:all .15s;
}
.ad-filter-pill:hover{border-color:var(--tblr-primary);color:var(--tblr-primary)!important}
.ad-filter-pill.active{background:var(--tblr-primary);border-color:var(--tblr-primary);color:#fff!important}
.ad-stat{
    background:var(--tblr-bg-surface);
    border:1px solid var(--tblr-border-color-translucent);
    border-radius:var(--tblr-border-radius-lg);
    box-shadow:0 1px 2px rgba(16,24,40,.04);
    padding:1rem 1.25rem;
}
.ad-stat .n{font-size:1.5rem;font-weight:700;color:var(--ad-pri);line-height:1.2}
.ad-stat .l{font-size:.75rem;color:var(--tblr-secondary-color);margin-top:.25rem}

/* --- 其它 --- */
.ad-empty{
    padding:2.5rem 1rem;
    text-align:center;
    color:var(--tblr-secondary-color);
    font-size:.8125rem;
}
.ad-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.75rem}

/* 「是否邮件通知客户」勾选条（ad_notify_field()）
   用 div 包裹而不是让 label 直接包 input：包 input 会命中上面
   .page-body label:has(input[type=checkbox]) 那条规则，把整条拉成行内。
   选择器带 .page-body 是为了压过 .page-body label 的字号/颜色。 */
.ad-notify{
    display:flex;
    align-items:flex-start;
    gap:.5rem;
    margin-top:.875rem;
    padding:.625rem .75rem;
    background:var(--tblr-bg-surface-tertiary);
    border:1px solid var(--tblr-border-color-translucent);
    border-radius:var(--tblr-border-radius);
}
.ad-notify input[type=checkbox]{margin-top:.2rem;flex-shrink:0}
.page-body .ad-notify label{
    margin:0;
    font-size:.8125rem;
    font-weight:500;
    color:var(--tblr-body-color);
    cursor:pointer;
}
.page-body .ad-notify label .lg-sm{margin-top:.2rem;font-weight:400}
/* Tabler/Bootstrap 默认把 <code> 染成粉红，后台里 <code>app/schema.sql</code>
   这类路径提示用粉红很扎眼 —— 改成浅灰底 + 正文色。
   提示条里的 code 要跟着提示条自己的文字色走，所以再让它们 inherit 回来。 */
.page-body code{
    background:var(--tblr-bg-surface-tertiary);
    color:var(--tblr-body-color);
    padding:.1rem .35rem;
    border-radius:4px;
    font-size:.8125rem;
}
.ad-alert code{color:inherit;background:rgba(127,127,127,.12)}

/* 「只读链接 + 复制」组合（后台展示客户登录链接用） */
.ad-linkbox{display:flex;gap:.5rem;margin-top:.875rem;flex-wrap:wrap}
.ad-linkbox input[type=text]{
    flex:1 1 320px;
    font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
    font-size:.75rem;
    background:var(--tblr-bg-surface-tertiary);
}

/* 侧栏导航里的图标统一宽度，避免 Font Awesome 与 Tabler 图标混排时错位 */
.navbar-vertical .nav-link-icon{width:1.5rem;text-align:center;font-size:.9375rem}
.navbar-vertical .navbar-brand{font-weight:700;letter-spacing:.02em}
.navbar-vertical .nav-section-title{
    padding:1rem 1rem .35rem;color:rgba(255,255,255,.48);font-size:.625rem;
    font-weight:700;letter-spacing:.1em;list-style:none;
}
.navbar-vertical .nav-link{margin:.12rem .45rem;border-radius:6px}
.navbar-vertical .nav-link.active{background:rgba(255,255,255,.12)}

/* 打印时收掉侧栏 */
@media print{.navbar-vertical,.page-header{display:none!important}}

/* --- 内容区宽度自适应（2026-10-15） ---
   Tabler 的 .container-xl 上限 1320px，宽屏两侧大量留白、表格被挤。
   后台改为流式：占满侧栏之外的整个空间，只保留舒适内边距；
   超宽屏（≥1600px）略微加大左右留白避免行太长难读。 */
.page-wrapper .container-xl{
    max-width:100%;
    padding-left:1.75rem;
    padding-right:1.75rem;
}
@media (min-width:1600px){
    .page-wrapper .container-xl{padding-left:2.75rem;padding-right:2.75rem}
}
.page-header .container-xl{padding-top:.5rem;padding-bottom:.5rem}
.page-header .btn-list{display:flex;flex-wrap:wrap;gap:.5rem;justify-content:flex-end}
.page-body{padding-top:1.5rem!important;padding-bottom:2rem!important}
.page-body > .container-xl > .ad-card:last-child{margin-bottom:0}
.ad-workspace-nav{
    display:flex;gap:.4rem;overflow-x:auto;white-space:nowrap;
    padding:.35rem;margin:0 0 1.25rem;
    background:var(--tblr-bg-surface);border:1px solid var(--tblr-border-color-translucent);
    border-radius:var(--tblr-border-radius-lg);box-shadow:0 1px 2px rgba(16,24,40,.04);
}
.ad-workspace-tab{
    display:inline-flex;align-items:center;gap:.45rem;flex:0 0 auto;
    padding:.65rem .9rem;border:0;border-radius:6px;background:transparent;
    color:var(--tblr-secondary-color);font:inherit;font-size:.8125rem;font-weight:600;cursor:pointer;
}
.ad-workspace-tab:hover{background:var(--tblr-bg-surface-tertiary);color:var(--tblr-primary)}
.ad-workspace-tab[aria-pressed="true"]{background:var(--tblr-primary);color:#fff}
.ad-workspace-tab .count{opacity:.75;font-size:.72rem}
.ad-card[hidden]{display:none!important}
@media (max-width:767.98px){
    .page-wrapper .container-xl{padding-left:1rem;padding-right:1rem}
    .page-body{padding-top:1rem!important}
    .ad-card{padding:1rem;margin-bottom:1rem}
    .page-header .btn-list{justify-content:flex-start}
    table.ad-tb th,table.ad-tb td{padding:.65rem .7rem}
    .ad-work-actions{grid-template-columns:1fr;gap:.65rem;margin-bottom:1rem}
}
/* 表格类内容（Orders/Enquiries 等长表格）在流式布局下自然受益；
   但 .card 内文本表单不需要无限宽 —— 限制阅读类页面正文行长，
   这里不做全局限制，各页已有 .form-row.cols3 / ad-card 自行控制。 */
</style>
</head>
<body>
<div class="page">
  <aside class="navbar navbar-vertical navbar-expand-lg" data-bs-theme="dark">
    <div class="container-fluid">
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#adSidebar"
              aria-controls="adSidebar" aria-expanded="false" aria-label="Toggle navigation">
        <span class="navbar-toggler-icon"></span>
      </button>
      <div class="navbar-brand navbar-brand-autodark">
        <a href="<?php echo h(site_url('app/admin/index.php')); ?>">EXAMPLE IP</a>
      </div>
      <div class="navbar-footer">
        <ul class="navbar-nav">
          <li class="nav-item dropup">
            <a href="#" class="nav-link" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Account menu">
              <span class="avatar avatar-sm"><?php echo h($initial); ?></span>
              <div class="nav-link-title">
                <?php echo h($meName); ?>
                <?php if ($meRole !== ''): ?><div class="small text-secondary"><?php echo h($meRole); ?></div><?php endif; ?>
              </div>
            </a>
            <div class="dropdown-menu dropdown-menu-end">
              <?php if (function_exists('admin_is_owner') && admin_is_owner()): ?>
                <a class="dropdown-item" href="<?php echo h(site_url('app/admin/users.php')); ?>">
                  <i class="fas fa-user-shield me-2"></i><?php echo tp('adm.users'); ?>
                </a>
                <div class="dropdown-divider"></div>
              <?php endif; ?>
              <a class="dropdown-item" href="<?php echo h(site_url('app/admin/logout.php')); ?>">
                <i class="fas fa-arrow-right-from-bracket me-2"></i><?php echo tj('adm.logout'); ?>
              </a>
            </div>
          </li>
        </ul>
      </div>
      <div class="collapse navbar-collapse" id="adSidebar">
        <ul class="navbar-nav pt-lg-3">
          <?php foreach ($navGroups as $groupLabel => $groupKeys): ?>
            <?php $hasGroupItems = false; foreach ($groupKeys as $groupKey) { if (isset($nav[$groupKey])) { $hasGroupItems = true; break; } } if (!$hasGroupItems) continue; ?>
            <li class="nav-section-title"><?php echo h($groupLabel); ?></li>
            <?php foreach ($groupKeys as $k): if (!isset($nav[$k])) continue; $item = $nav[$k]; ?>
            <li class="nav-item<?php echo $active === $k ? ' active' : ''; ?>">
              <a class="nav-link<?php echo $active === $k ? ' active' : ''; ?>" href="<?php echo h(site_url($item[1])); ?>">
                <span class="nav-link-icon"><i class="fas <?php echo $item[2]; ?>"></i></span>
                <span class="nav-link-title"><?php echo tp($item[0]); ?></span>
                <?php if ($k === 'enquiries' && $newCount > 0): ?><span class="badge bg-red ms-auto"><?php echo $newCount; ?></span><?php endif; ?>
                <?php if ($k === 'messages' && $msgOpen > 0): ?><span class="badge bg-red ms-auto"><?php echo $msgOpen; ?></span><?php endif; ?>
              </a>
            </li>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </aside>

  <div class="page-wrapper">
    <div class="page-header d-print-none">
      <div class="container-xl">
        <div class="row g-2 align-items-center">
          <div class="col">
            <div class="page-pretitle"><?php echo h(cfg('site_name_en')); ?></div>
            <h1 class="page-title"><?php echo $title !== '' ? tp($title) : tp('adm.title'); ?></h1>
          </div>
          <?php if ($actions !== ''): ?>
          <div class="col-auto ms-auto d-print-none">
            <div class="btn-list"><?php echo $actions; ?></div>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <main id="content" class="page-body">
      <div class="container-xl">
<?php
    }
}

if (!function_exists('admin_footer')) {
    function admin_footer()
    {
        ?>
      </div>
    </main>
    <footer class="footer footer-transparent d-print-none">
      <div class="container-xl">
        <div class="row text-center align-items-center flex-row-reverse">
          <div class="col-12">
            EXAMPLE IP Order System · <?php echo date('Y-m-d H:i'); ?> · <?php echo h(cfg('site_url')); ?>
          </div>
        </div>
      </div>
    </footer>
  </div>
</div>
<script src="<?php echo h(admin_asset('tabler/tabler.min.js')); ?>"></script>
<script>
/* 表格横向滚动：22 个页面里的 .ad-tb 都是裸 <table>（没有 table-responsive 包裹），
   窄窗口 / 手机上会顶破布局。这里统一套一层滚动容器，页面代码一行都不用改。
   放在 DOMContentLoaded 里执行，晚于各页自己的内联脚本，不影响已有的事件绑定。 */
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('table.ad-tb').forEach(function (tb) {
        var p = tb.parentNode;
        if (!p || p.classList.contains('table-responsive')) return;
        var w = document.createElement('div');
        w.className = 'table-responsive';
        p.insertBefore(w, tb);
        w.appendChild(tb);
    });

    /* 询价 / 订单详情是工作台，不把所有表单和历史记录一股脑堆在同一屏。
       通过 data-workspace-tab 标记分区，JS 未加载时仍保留完整页面内容。 */
    var panels = Array.prototype.slice.call(document.querySelectorAll(
        '.page-body > .container-xl > .ad-card[data-workspace-tab]'));
    if (!panels.length) return;
    var tabs = [];
    panels.forEach(function (panel) {
        var key = panel.dataset.workspaceTab;
        var found = tabs.find(function (tab) { return tab.key === key; });
        if (!found) tabs.push({key:key, label:panel.dataset.workspaceLabel || key, count:0});
        tabs.find(function (tab) { return tab.key === key; }).count++;
    });
    var nav = document.createElement('nav');
    nav.className = 'ad-workspace-nav';
    nav.setAttribute('aria-label', 'Record sections');
    var storageKey = 'admin-workspace:' + location.pathname + location.search;
    var active = tabs[0].key;
    try {
        var saved = sessionStorage.getItem(storageKey);
        if (saved && tabs.some(function (tab) { return tab.key === saved; })) active = saved;
    } catch (e) {}
    tabs.forEach(function (tab) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'ad-workspace-tab';
        button.setAttribute('aria-pressed', tab.key === active ? 'true' : 'false');
        button.textContent = tab.label;
        button.addEventListener('click', function () {
            active = tab.key;
            try { sessionStorage.setItem(storageKey, active); } catch (e) {}
            renderTabs();
        });
        nav.appendChild(button);
    });
    var anchor = document.querySelector('[data-workspace-tabs-anchor]');
    if (anchor) anchor.parentNode.insertBefore(nav, anchor);
    else panels[0].parentNode.insertBefore(nav, panels[0]);
    function renderTabs() {
        panels.forEach(function (panel) { panel.hidden = panel.dataset.workspaceTab !== active; });
        Array.prototype.forEach.call(nav.querySelectorAll('.ad-workspace-tab'), function (button, index) {
            button.setAttribute('aria-pressed', tabs[index].key === active ? 'true' : 'false');
        });
    }
    renderTabs();
});
</script>
</body>
</html>
<?php
    }
}

/**
 * 「是否邮件通知客户」勾选框。
 *
 * 后台凡是会给客户发邮件的动作（发报价、建单开通账号、加进度、回复留言）都要带上，
 * 默认勾选；取消勾选 = 静默处理，只写库不发信。
 * 用途：有些内部补录 / 例行更新不想打扰客户（2026-09-29）。
 *
 * @param bool   $checked 默认是否勾选
 * @param string $id      同一页有多个表单时需要不同 id，避免 label for 串台
 */
if (!function_exists('ad_notify_field')) {
    function ad_notify_field($checked = true, $id = 'notify_client')
    {
        return '<div class="ad-notify">'
            . '<input type="checkbox" name="notify_client" value="1" id="' . h($id) . '"'
            . ($checked ? ' checked' : '') . '>'
            . '<label for="' . h($id) . '">'
            . h(tj('adm.notify_client'))
            . '<span class="lg-sm">' . h(tj('adm.notify_client_hint')) . '</span>'
            . '</label>'
            . '</div>';
    }
}

/** 读取上面那个勾选框；未勾选返回 false */
if (!function_exists('ad_wants_notify')) {
    function ad_wants_notify()
    {
        return post('notify_client') === '1';
    }
}

/**
 * 「客户登录链接」卡片（后台共用组件）
 *
 * 生成一个登录链接（默认 15 天有效，见 token_ttl_minutes()；有效期内可反复打开，
 * 见 token_is_reusable()）并显示出来，
 * 供我们复制后在自己写的邮件 / WhatsApp 里手动发给客户 —— 不必让系统去发信。
 * 已在 admin/client.php（客户详情）和 admin/order.php（订单详情）两处使用。
 *
 * @param array $client clients 表一行；为空则不输出
 */
if (!function_exists('ad_login_link_card')) {
    /**
     * @param array $client  clients 表一行；为空则不输出
     * @param int   $orderId 给了就生成「直达这一单」的链接（订单页用，2026-09-30）：
     *                       客户点开自动登录并直接落在这张订单上，付款按钮就在眼前。
     *                       不给则是普通登录链接（客户详情页用），行为与以前一致。
     */
    function ad_login_link_card($client, $orderId = 0)
    {
        if (!$client) return '';
        $orderId = (int)$orderId;

        $link = '';
        $err  = '';
        if (is_post() && post('act') === 'gen_login') {
            if (!csrf_check()) {
                $err = t('enq.err_csrf');
            } else {
                try {
                    $link = $orderId
                        ? order_deeplink((int)$client['id'], $orderId)
                        : token_url(issue_token((int)$client['id'], 'login'), 'login');
                    admin_log_action('login_link', 'client', (int)$client['id'], $orderId
                        ? 'deep link to order #' . $orderId . ' generated for manual delivery'
                        : 'sign-in link generated for manual delivery');
                } catch (Throwable $e) {
                    $err = 'Failed: ' . $e->getMessage();
                    app_log('gen_login failed: ' . $e->getMessage(), 'ERROR');
                }
            }
        }

        /* 有效期与「能用几次」都由 token_link_note() 出，改配置不用改文案
         * （2026-09-30 起默认有效期内可反复打开，防止被邮件安全网关的自动扫描点掉） */
        $note = $orderId
            ? 'Generates a link that signs the client in and opens <b>this order</b> directly — ' . h(token_link_note('en')) . ' '
              . 'Hand it over yourself (WhatsApp, a personal email) when you want them to land straight on the payment button.'
            : 'Generates a sign-in link — ' . h(token_link_note('en')) . ' '
              . 'Use it when you want to hand the link to the client yourself (a personal email, WhatsApp, etc.) '
              . 'instead of letting the system email it.';
        $html = '<div class="ad-card">'
              . '<h2><i class="fas fa-key" style="color:var(--tblr-secondary-color);"></i> '
              . ($orderId ? 'Direct link to this order' : 'Client sign-in link') . '</h2>'
              . '<p style="margin-bottom:1rem;color:var(--tblr-secondary-color);">' . $note . '</p>';
        if ($err !== '') {
            $html .= ad_flash($err, 'bad');
        }
        $html .= '<form method="post">' . csrf_field()
               . '<input type="hidden" name="act" value="gen_login">'
               . '<button class="btn btn-p" type="submit"><i class="fas fa-key"></i> '
               . ($orderId ? 'Generate direct link' : 'Generate new link') . '</button>'
               . '</form>';
        if ($link !== '') {
            $html .= '<div class="ad-linkbox">'
                   . '<input type="text" id="loginLink" readonly value="' . h($link) . '" onclick="this.select();">'
                   . '<button class="btn" type="button" id="copyLoginLink"><i class="fas fa-copy"></i> Copy</button>'
                   . '</div>';
        }
        $html .= '</div>';
        return $html;
    }
}

/** 上面那个卡片配套的复制脚本；每个页面只需在 </script> 前 echo 一次 */
if (!function_exists('ad_copy_link_js')) {
    function ad_copy_link_js()
    {
        return <<<'JS'
/* 复制登录链接：优先 clipboard API（需 https 或 localhost），失败降级 execCommand */
(function () {
    var btn = document.getElementById('copyLoginLink');
    var inp = document.getElementById('loginLink');
    if (!btn || !inp) return;
    btn.addEventListener('click', function () {
        var done = function () {
            var old = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-check"></i> Copied';
            setTimeout(function () { btn.innerHTML = old; }, 1600);
        };
        var fallback = function () {
            inp.select();
            try { document.execCommand('copy'); done(); } catch (e) { inp.select(); }
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(inp.value).then(done, fallback);
        } else { fallback(); }
    });
})();
JS;
    }
}

/** 后台 flash 消息 */
if (!function_exists('ad_flash')) {
    function ad_flash($msg, $type = 'ok')
    {
        if ($msg === '') return '';
        return '<div class="ad-alert ' . h($type) . '">' . h($msg) . '</div>';
    }
}
