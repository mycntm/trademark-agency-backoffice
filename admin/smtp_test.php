<?php
/**
 * SMTP 自检页
 * ① 端口可达性探测  ② 真实发一封测试邮件并打印完整握手过程  ③ 邮件队列状态（解释"邮件去哪了"）
 * ⚠️ 排查完建议删除本文件。
 */
require_once __DIR__ . '/_layout.php';

$log    = [];
$result = null;

if (is_post() && csrf_check()) {
    $act = post('act');

    if ($act === 'send') {
        $to = trim((string)post('to'));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $result = ['ok' => false, 'err' => 'Invalid recipient address: ' . h($to)];
        } else {
            $c       = cfg('smtp');
            $subject = 'SMTP test ' . date('Y-m-d H:i:s') . ' · China Trademark';
            $html    = '<div style="font-family:sans-serif;font-size:14px;line-height:1.7;">'
                     . '<h3 style="margin:0 0 10px;">SMTP self-test message</h3>'
                     . '<p>If you received this email, the sending pipeline (SMTP auth + delivery) is fully working.</p>'
                     . '<p style="color:#6b7280;font-size:13px;">Sent at: ' . date('Y-m-d H:i:s') . '<br>'
                     . 'Host: ' . h($c['host']) . ':' . h((string)$c['port']) . '<br>'
                     . 'From: ' . h($c['from']) . '</p></div>';
            $text    = "SMTP self-test message\nSent at: " . date('Y-m-d H:i:s') . "\nHost: " . $c['host'] . ':' . $c['port'];

            $err = null;
            $t0  = microtime(true);
            try {
                $mime = build_mime($c['from'], $c['from_name'], $to, $subject, $html, $text, $c['reply_to']);
                $ok   = smtp_send($to, $mime, $err, $log);
            } catch (Exception $e) {
                $ok  = false;
                $err = $e->getMessage();
                $log[] = ['E', 'Exception: ' . $e->getMessage()];
            }
            $ms     = round((microtime(true) - $t0) * 1000);
            $result = ['ok' => (bool)$ok, 'err' => $err, 'ms' => $ms, 'to' => $to, 'time' => date('H:i:s')];
        }
    }

    if ($act === 'flush') {
        try {
            $r = flush_mail_queue(20, 5);
            $result = ['flush' => $r];
        } catch (Exception $e) {
            $result = ['flush' => null, 'err' => $e->getMessage()];
        }
    }
}

/* ---------- 端口可达性探测（只对 TCP 层，不发 SMTP 命令） ---------- */
$c       = cfg('smtp');
$cfgPort = (int)$c['port'];
$probe   = [];
foreach ([25, 465, 587] as $p) {
    $en = $es = '';
    $t0 = microtime(true);
    $fp = @stream_socket_client('tcp://' . $c['host'] . ':' . $p, $en, $es, 4, STREAM_CLIENT_CONNECT);
    $ms = round((microtime(true) - $t0) * 1000);
    if ($fp) { fclose($fp); }
    $probe[$p] = ['ok' => (bool)$fp, 'ms' => $ms, 'err' => $fp ? '' : trim($es)];
}

/* ---------- 邮件队列状态 ---------- */
$q = ['queued' => null, 'failed' => null, 'sent' => null, 'recent' => []];
try {
    $q['queued'] = (int)db_val('SELECT COUNT(*) FROM mail_queue WHERE status="queued"');
    $q['failed'] = (int)db_val('SELECT COUNT(*) FROM mail_queue WHERE status="failed"');
    $q['sent']   = (int)db_val('SELECT COUNT(*) FROM mail_queue WHERE status="sent"');
    $q['recent'] = db_all('SELECT id,to_email,subject,status,attempts,last_error,created_at,sent_at FROM mail_queue ORDER BY id DESC LIMIT 10');
} catch (Exception $e) {
    $q['error'] = $e->getMessage();
}

$defaultTo = $c['from'];
admin_header('adm.settings', 'settings');
?>
<div class="ad-card">
    <h2>SMTP configuration summary</h2>
    <table class="ad-tb">
        <tr><th style="width:30%;">Enabled <span class="lg-sm">smtp.enabled</span></th>
            <td><?php echo !empty($c['enabled']) ? '<span class="ad-tag done">enabled</span>' : '<span class="ad-tag new">disabled</span>'; ?></td></tr>
        <tr><th>Host</th><td class="ad-mono"><?php echo h($c['host']); ?></td></tr>
        <tr><th>Port &amp; security</th>
            <td class="ad-mono"><?php echo h((string)$c['port']); ?> / <?php echo h($c['secure'] === '' ? 'none (plaintext)' : $c['secure']); ?></td></tr>
        <tr><th>User</th><td class="ad-mono"><?php echo h($c['user']); ?></td></tr>
        <tr><th>Password</th>
            <td><?php echo ($c['pass'] !== '') ? '<span class="ad-tag done">set</span>' : '<span class="ad-tag new">not set</span>'; ?>
                <div class="lg-sm" style="opacity:.7;">The password is never shown. Most providers require an app-specific password / authorization code, not the login password.</div></td></tr>
        <tr><th>From</th><td class="ad-mono"><?php echo h($c['from']); ?> (<?php echo h($c['from_name']); ?>)</td></tr>
        <tr><th>Timeout</th><td class="ad-mono"><?php echo h((string)($c['timeout'] ?: 15)); ?> s</td></tr>
    </table>
    <?php if (empty($c['enabled']) || $c['pass'] === ''): ?>
        <div class="ad-alert bad" style="margin-top:14px;">
            Configuration incomplete: fill in host / port / secure / user / pass / from in the <code>smtp</code> section of <b>app/config.php</b> and set <code>enabled</code> to true.
        </div>
    <?php endif; ?>
</div>

<div class="ad-card">
    <h2>① Port reachability</h2>
    <p style="font-size:13.5px;color:#4b5563;line-height:1.8;margin-bottom:12px;">
        Tests whether this server can reach <b><?php echo h($c['host']); ?></b> outbound. TCP layer only. Common mail providers use 465 (SSL) or 587 (STARTTLS).
    </p>
    <table class="ad-tb">
        <tr><th style="width:20%;">Port</th><th style="width:22%;">Result</th><th>Detail</th></tr>
        <?php foreach ($probe as $p => $r): ?>
        <tr>
            <td class="ad-mono"><?php echo (int)$p; ?><?php echo $p === $cfgPort ? ' <span class="ad-tag done">current</span>' : ''; ?></td>
            <td><?php echo $r['ok']
                ? '<span class="ad-tag done">reachable · ' . (int)$r['ms'] . ' ms</span>'
                : '<span class="ad-tag new">unreachable</span>'; ?></td>
            <td style="font-size:12.5px;color:#6b7280;">
                <?php echo $r['ok'] ? 'TCP connection established' : h($r['err'] ?: 'connection refused / timed out'); ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php if (isset($probe[$cfgPort]) && !$probe[$cfgPort]['ok']): ?>
        <div class="ad-alert bad" style="margin-top:12px;">
            The configured port <b><?php echo (int)$cfgPort; ?></b> is unreachable — the most common cause of failed delivery: shared hosts and many VPS providers block outbound 25/465.
            Use <b>587</b> (secure=tls) instead, or ask your host to unblock the port.
        </div>
    <?php endif; ?>
</div>

<div class="ad-card">
    <h2>② Send a test email</h2>
    <p style="font-size:13.5px;color:#4b5563;line-height:1.8;margin-bottom:12px;">
        Performs a real SMTP handshake and delivers one message. <b>Every server response</b> is printed below (credentials are masked automatically).
        If the connection hangs, the page waits until the timeout (<?php echo h((string)($c['timeout'] ?: 15)); ?> s) before returning — that is normal.
    </p>
    <form method="post" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="act" value="send">
        <input type="email" name="to" required style="min-width:280px;" placeholder="recipient@example.com" value="<?php echo h(post('to') ?: $defaultTo); ?>">
        <button class="btn btn-p" type="submit"><i class="fas fa-paper-plane"></i> Send test email</button>
    </form>

    <?php if ($result && !isset($result['flush'])): ?>
        <div class="ad-alert <?php echo $result['ok'] ? 'ok' : 'bad'; ?>" style="margin-top:16px;">
            <b><?php echo $result['ok'] ? 'SUCCESS' : 'FAILED'; ?></b>
            <?php if (isset($result['ms'])): ?> <span class="lg-sm">(<?php echo (int)$result['ms']; ?> ms)</span><?php endif; ?>
            <div style="margin-top:5px;">Recipient: <?php echo h($result['to']); ?></div>
            <?php if (!$result['ok'] && !empty($result['err'])): ?>
                <div style="margin-top:5px;">Error: <?php echo h($result['err']); ?></div>
            <?php endif; ?>
            <?php if ($result['ok']): ?>
                <div style="margin-top:5px;">Delivered to the SMTP server. If it has not arrived within 1–2 minutes, check <b>spam</b>; if still nothing, configure SPF/DKIM/DMARC (see below).</div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($log): ?>
        <div style="margin-top:14px;">
            <div style="font-weight:700;font-size:14px;margin-bottom:8px;">SMTP conversation</div>
            <table class="ad-tb" style="font-size:12.5px;">
                <?php foreach ($log as $row):
                    $dir = $row[0]; $txt = $row[1];
                    $label = $dir === 'C' ? 'sent →' : ($dir === 'S' ? 'recv ←' : ($dir === 'E' ? 'error ✕' : 'info ·'));
                    $color = $dir === 'C' ? '#2563eb' : ($dir === 'S' ? '#0f766e' : ($dir === 'E' ? '#b91c1c' : '#6b7280'));
                ?>
                <tr>
                    <td style="width:74px;color:<?php echo $color; ?>;font-weight:600;"><?php echo $label; ?></td>
                    <td class="ad-mono" style="color:<?php echo $color; ?>;white-space:pre-wrap;word-break:break-all;"><?php echo h($txt); ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="ad-card">
    <h2>③ Mail queue</h2>
    <?php if (isset($q['error'])): ?>
        <div class="ad-alert bad">Failed to read mail_queue: <?php echo h($q['error']); ?></div>
    <?php else: ?>
        <p style="font-size:13.5px;color:#4b5563;line-height:1.8;margin-bottom:12px;">
            On submission the system <b>tries to send immediately</b>; only failures stay in the queue for the cron retry.
            So — <b>a failed row here = sending failed</b> (see last_error); <b>a sent row that never arrived = spam folder or rejected by the recipient</b>.
        </p>
        <div style="display:flex;gap:18px;flex-wrap:wrap;margin-bottom:14px;font-size:14px;">
            <span>Queued: <b><?php echo (int)$q['queued']; ?></b></span>
            <span style="color:#b91c1c;">Failed: <b><?php echo (int)$q['failed']; ?></b></span>
            <span style="color:#0f766e;">Sent: <b><?php echo (int)$q['sent']; ?></b></span>
        </div>
        <form method="post" style="margin-bottom:14px;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="act" value="flush">
            <button class="btn btn-s" type="submit"><i class="fas fa-rotate-right"></i> Retry failed / queued mail (up to 20)</button>
            <span class="lg-sm" style="margin-left:8px;opacity:.7;">Rows that failed more than 5 times are not retried automatically</span>
        </form>
        <?php if (isset($result['flush'])): ?>
            <div class="ad-alert <?php echo (!empty($result['flush']['sent'])) ? 'ok' : 'warn'; ?>" style="margin-bottom:14px;">
                <?php if ($result['flush'] === null): ?>
                    Retry failed: <?php echo h($result['err'] ?? ''); ?>
                <?php else: ?>
                    Attempted <?php echo (int)$result['flush']['attempted']; ?>, sent <?php echo (int)$result['flush']['sent']; ?>.
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($q['recent']): ?>
        <table class="ad-tb" style="font-size:13px;">
            <tr><th style="width:52px;">ID</th><th style="width:22%;">Recipient</th><th>Subject</th><th style="width:96px;">Status</th><th style="width:56px;">Tries</th><th>Last error / sent at</th></tr>
            <?php foreach ($q['recent'] as $m): ?>
            <tr>
                <td class="ad-mono"><?php echo (int)$m['id']; ?></td>
                <td class="ad-mono" style="word-break:break-all;"><?php echo h($m['to_email']); ?></td>
                <td><?php echo h($m['subject']); ?></td>
                <td>
                    <?php
                    $cls = $m['status'] === 'sent' ? 'done' : ($m['status'] === 'failed' ? 'new' : 'warn');
                    echo '<span class="ad-tag ' . $cls . '">' . h($m['status']) . '</span>';
                    ?>
                </td>
                <td class="ad-mono"><?php echo (int)$m['attempts']; ?></td>
                <td style="font-size:12px;color:#6b7280;word-break:break-all;">
                    <?php echo $m['status'] === 'sent' ? h($m['sent_at']) : h($m['last_error']); ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php else: ?>
            <div style="font-size:13px;color:#6b7280;">The queue is empty (no mail records yet).</div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div class="ad-card">
    <h2>Troubleshooting</h2>
    <div style="font-size:13px;line-height:1.9;color:#4b5563;">
        <div><b>connect failed / timeout</b> — the host blocks outbound ports. Many VPS providers block <b>25</b>, some block 465; switch to <b>587 + secure=tls</b>, or ask the host to unblock.</div>
        <div><b>535 / auth failed</b> — use an <b>app-specific password / authorization code</b>, not the web login password (true for Tencent Exmail, Aliyun Mail, Gmail, etc.).</div>
        <div><b>553 / MAIL FROM rejected</b> — the <code>smtp.from</code> address must belong to the authenticated mailbox account.</div>
        <div><b>554 / body rejected</b> — spam filtering triggered; review the subject and body, avoid short links and sensitive words.</div>
        <div><b>Sent but never received</b> — check spam; the long-term fix is <b>SPF / DKIM / DMARC</b> on the domain, otherwise Gmail/Outlook will likely junk it, which directly hurts conversion.</div>
        <div style="margin-top:8px;">All sending errors are also written to <code>app/storage/app.log</code>.</div>
    </div>
</div>

<p>
    <a class="btn btn-s" href="<?php echo h(site_url('app/admin/settings.php')); ?>">→ Settings</a>
    <a class="btn btn-s" href="<?php echo h(site_url('app/admin/index.php')); ?>">→ Dashboard</a>
</p>
<?php admin_footer(); ?>
