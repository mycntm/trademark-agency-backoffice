<?php
/**
 * 时区自检页（只读，不改任何数据）
 *
 * 回答一个问题：PHP 的时间和 MySQL 的时间是不是同一套？
 * 全站 18 处用 SQL 的 NOW() 写 created_at / paid_at / sent_at，页面又是把库里的
 * datetime 原样打印出来的。只要 MySQL 跑在别的时区（宝塔装的 MySQL 是 UTC 的情况
 * 并不少见），客户看到的「付款时间」就会比真实时间早 8 小时。
 *
 * 本页并排打印两边的时间与差值；如果 MySQL 确实是 UTC，页面底部会给出
 * 一次性修正历史数据的 SQL（只在确认有偏差时才该执行）。
 * ⚠️ 排查完建议删除本文件。
 */
require_once __DIR__ . '/_layout.php';

$phpTz    = date_default_timezone_get();
$phpNow   = date('Y-m-d H:i:s');
$sqlTzOff = sql_tz_offset();

$myGlobal = $mySession = $myNow = $myUtc = '(query failed)';
$err      = '';
try {
    $r         = db_one('SELECT @@global.time_zone AS g, @@session.time_zone AS s, NOW() AS n, UTC_TIMESTAMP() AS u');
    $myGlobal  = (string)$r['g'];
    $mySession = (string)$r['s'];
    $myNow     = (string)$r['n'];
    $myUtc     = (string)$r['u'];
} catch (Throwable $e) {
    $err = $e->getMessage();
}

/* PHP 与 MySQL 的时差（分钟）：用 UTC_TIMESTAMP 做基准，避免字符串解析时区歧义 */
$driftMin = null;
if ($myUtc !== '(query failed)') {
    $driftMin = round((strtotime($myNow) - strtotime($myUtc)) / 60);
}
$aligned = ($driftMin !== null && abs($driftMin - 8 * 60) <= 1);   // 目标：+8 小时（中国时间）

admin_header('Time zone check', 'settings');
?>

<div class="ad-card">
    <h2>Time zone check</h2>
    <p style="color:var(--tblr-secondary-color);margin-bottom:1rem;">
        PHP and MySQL must agree on the clock. Every timestamp on the site
        (<code>created_at</code>, <code>paid_at</code>, <code>sent_at</code>…) is written by MySQL's
        <code>NOW()</code> and printed back verbatim, so a mismatch shows up directly to the client.
    </p>

    <table class="ad-tb">
        <tr><th style="width:220px;">PHP timezone</th><td class="ad-mono"><?php echo h($phpTz); ?></td></tr>
        <tr><th>PHP now</th><td class="ad-mono"><?php echo h($phpNow); ?></td></tr>
        <tr><th>Offset sent to MySQL</th><td class="ad-mono"><?php echo h($sqlTzOff); ?></td></tr>
        <tr><th>MySQL global time_zone</th><td class="ad-mono"><?php echo h($myGlobal); ?></td></tr>
        <tr><th>MySQL session time_zone</th><td class="ad-mono"><?php echo h($mySession); ?></td></tr>
        <tr><th>MySQL NOW()</th><td class="ad-mono"><?php echo h($myNow); ?></td></tr>
        <tr><th>MySQL UTC_TIMESTAMP()</th><td class="ad-mono"><?php echo h($myUtc); ?></td></tr>
        <tr>
            <th>NOW() − UTC (should be +8h)</th>
            <td class="ad-mono">
                <?php if ($driftMin === null): ?>
                    —
                <?php else: ?>
                    <?php echo h(sprintf('%+d min (%+.1f h)', $driftMin, $driftMin / 60)); ?>
                    <span class="ad-tag <?php echo $aligned ? 'done' : 'bad'; ?>" style="margin-left:8px;">
                        <?php echo $aligned ? 'Aligned (China time)' : 'MISMATCH'; ?>
                    </span>
                <?php endif; ?>
            </td>
        </tr>
    </table>

    <?php if ($err !== ''): ?>
        <?php echo ad_flash('Query failed: ' . h($err), 'bad'); ?>
    <?php endif; ?>
</div>

<div class="ad-card">
    <h2>What this means</h2>
    <?php if ($aligned): ?>
        <div class="ad-alert ok">
            PHP and MySQL are both on China time (UTC+8). New records will be stamped correctly.
        </div>
        <?php if ($myGlobal !== '+08:00' && $myGlobal !== 'SYSTEM' && strpos($myGlobal, '+08') !== 0): ?>
            <div class="ad-alert warn" style="margin-top:.75rem;">
                Note: MySQL's <b>global</b> time zone is <code><?php echo h($myGlobal); ?></code>.
                It only works because the app pins the session to <code><?php echo h($sqlTzOff); ?></code>
                on every connection. If any other tool (phpMyAdmin, a manual SQL script, another app
                sharing this database) writes timestamps, those will still use the global zone.
                Consider setting <code>default-time-zone = '+08:00'</code> in MySQL's config for good measure.
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="ad-alert bad">
            MySQL is not on China time. Records written <b>before</b> this fix were stamped in the
            wrong zone, and they will not correct themselves — <code>DATETIME</code> columns store
            the literal value.
        </div>
    <?php endif; ?>
</div>

<div class="ad-card">
    <h2>Fixing historical rows <span class="lg-sm">（only if the check above reports a mismatch）</span></h2>
    <p style="color:var(--tblr-secondary-color);">
        All timestamp columns in this schema are <code>DATETIME</code>, which does <b>not</b> convert
        on read. Back up first, then shift the existing rows by the difference.
        Run this in phpMyAdmin (replace <code>8</code> with the drift shown above if different):
    </p>
    <pre style="background:var(--tblr-bg-surface-tertiary);padding:.875rem 1rem;border-radius:var(--tblr-border-radius);
                overflow:auto;font-size:.75rem;line-height:1.7;margin:0;"><?php
$tables = [
    'clients' => ['created_at', 'updated_at'],
    'enquiries' => ['created_at', 'updated_at', 'consent_at', 'deleted_at'],
    'quotes' => ['created_at', 'updated_at', 'accepted_at'],
    'orders' => ['created_at', 'updated_at', 'filed_at', 'voided_at'],
    'order_events' => ['created_at', 'deleted_at'],
    'payments' => ['created_at', 'paid_at', 'refunded_at'],
    'documents' => ['created_at', 'deleted_at'],
    'messages' => ['created_at'],
    'mail_queue' => ['created_at', 'sent_at'],
    'auth_tokens' => ['created_at', 'expires_at', 'used_at'],
    'admin_users' => ['created_at', 'last_login_at'],
    'webhook_events' => ['created_at'],
];
$out = "-- 0) BACK UP FIRST\n";
$out .= "SELECT NOW(), UTC_TIMESTAMP();   -- confirm the drift is really 8 hours\n\n";
foreach ($tables as $t => $cols) {
    $sets = [];
    foreach ($cols as $c) {
        $sets[] = "`$c` = `$c` + INTERVAL 8 HOUR";
    }
    $out .= sprintf("UPDATE `%s` SET %s;\n", $t, implode(', ', $sets));
}
echo h($out);
?></pre>
    <div class="ad-alert warn" style="margin-top:.875rem;">
        Every one of these columns is nullable or has a default, so adding 8 hours to a
        <code>NULL</code> is harmless (<code>NULL + INTERVAL</code> stays <code>NULL</code>).
        Run it once only — running it twice shifts everything by 16 hours.
    </div>
</div>

<p>
    <a class="btn btn-s" href="<?php echo h(site_url('app/admin/settings.php')); ?>">→ Settings</a>
    <a class="btn btn-s" href="<?php echo h(site_url('app/admin/index.php')); ?>">→ Dashboard</a>
</p>
<?php admin_footer(); ?>
