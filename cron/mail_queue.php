<?php
/**
 * 邮件队列重投（建议每 10 分钟跑一次）
 *
 * crontab 示例（宝塔「计划任务」里填），路径按实际站点根目录改。
 * 注意：这里不用块注释写 crontab，因为 "星号斜杠" 会提前闭合注释。
 */
// */10 * * * * /usr/bin/php /www/wwwroot/example.com/app/cron/mail_queue.php
/*
 * 也可以直接在浏览器访问排查（config.php 顶层 'cron_key' 设一个只有你知道的值）：
 *   https://example.com/app/cron/mail_queue.php?key=你的 cron_key
 * 未配置 cron_key 时，浏览器访问只对服务器本机（127.0.0.1）放行；CLI 不受限制。
 * 2026-09-29：加了这层限制，避免任何人都能从公网触发发信。
 */
require_once __DIR__ . '/../bootstrap.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    header('Content-Type: text/plain; charset=UTF-8');

    $key      = (string)cfg('cron_key', '');
    $givenKey = (string)get('key');
    $isLocal  = in_array((string)($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true);
    if (!($key !== '' && hash_equals($key, $givenKey)) && !$isLocal) {
        http_response_code(403);
        exit('Forbidden');
    }
}

/* 防重叠：SMTP 一旦变慢，上一次可能还没跑完就被下一次追上，会重复发同一封。
   拿不到锁就跳过本次（等下个周期），锁在连接关闭时自动释放。 */
try {
    // GET_LOCK 返回 1=拿到 / 0=别人在跑 / NULL=出错
    $lock = db_val('SELECT GET_LOCK("mail_queue_flush", 0)', [], null);
    if ($lock !== null && (int)$lock === 0) {
        echo "skip: another run is in progress\n";
        exit(0);
    }
    // null = 拿锁出错，放行继续跑：status/attempts 的查重防线还在，最多偶发重试
} catch (Throwable $e) {
    app_log('mail_queue cron: GET_LOCK failed: ' . $e->getMessage(), 'WARN');
}

$limit = 20;
if (!$isCli) {                                   // 浏览器排查时可以 ?limit=50 临时加大，最多 100
    $l = (int)get('limit', 0);
    if ($l > 0 && $l <= 100) $limit = $l;
}
$res = flush_mail_queue($limit, 5);

$out = sprintf("attempted=%d sent=%d\n", $res['attempted'], $res['sent']);
if (function_exists('app_log') && ($res['attempted'] > 0 || $res['sent'] > 0)) {
    app_log('mail_queue cron: ' . trim($out), 'INFO');
}
echo $out;
