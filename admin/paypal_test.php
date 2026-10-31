<?php
/**
 * PayPal 连通性自检
 * 作用：在开放收款之前，确认 ① 凭证能否换成 token ② 能否成功建单（建单不扣款）
 * ⚠️ 排查完建议删除本文件，避免暴露配置细节。
 */
require_once __DIR__ . '/_layout.php';

$result = null;

if (is_post() && !empty($_POST['act']) && csrf_check()) {
    $act = post('act');

    if ($act === 'token') {
        $t0  = microtime(true);
        $err = null;
        $tok = paypal_token($err);
        $ms  = round((microtime(true) - $t0) * 1000);
        $result = [
            'title' => 'Step 1 · OAuth token',
            'ok'    => (bool)$tok,
            'ms'    => $ms,
            'lines' => $tok
                ? ['Access token obtained', substr($tok, 0, 12) . '… (length ' . strlen($tok) . ')']
                : ['Failed: ' . ($err ?: 'unknown error')],
        ];
    }

    if ($act === 'create') {
        $err   = null;
        $fake  = [
            'ref'       => 'TEST-' . date('YmdHis'),
            'mark_name' => 'Connectivity test mark',
            'total'     => 1.00,
            'currency'  => cfg('paypal.currency', 'USD'),
        ];
        $res = paypal_create_order($fake, site_url('account.php'), site_url('account.php'), $err);
        $result = [
            'title' => 'Step 2 · Create order (no money moves)',
            'ok'    => (bool)$res,
            'lines' => $res
                ? [
                    'PayPal order id: ' . $res['id'],
                    'Status: ' . $res['status'],
                    'Approve URL generated: ' . (($res['approve_url'] !== '') ? 'yes' : 'NO — check return_url config'),
                ]
                : ['Failed: ' . ($err ?: 'unknown error')],
        ];
        if ($res && $res['approve_url']) {
            $result['url'] = $res['approve_url'];
        }
    }
}

// 清掉缓存的 token，确保每次都真的去换一次
@unlink(dirname(__DIR__) . '/storage/paypal_token.json');

$cfgOk   = paypal_enabled();
$sandbox = !empty(cfg('paypal.sandbox'));

admin_header('adm.payments', 'payments');
?>
<div class="ad-card">
    <h2>PayPal configuration summary</h2>
    <table class="ad-tb">
        <tr><th style="width:32%;">Config switch <span class="lg-sm">paypal.enabled</span></th>
            <td><?php echo $cfgOk ? '<span class="ad-tag done">enabled</span>' : '<span class="ad-tag new">disabled</span>'; ?>
                <div class="lg-sm" style="opacity:.7;">In app/config.php; when off, the PayPal button is hidden on the site</div></td></tr>
        <tr><th>Operations switch <span class="lg-sm">settings.paypal_enabled</span></th>
            <td><?php echo setting('paypal_enabled', '0') === '1' ? '<span class="ad-tag done">on · 1</span>' : '<span class="ad-tag new">off · 0</span>'; ?>
                <div class="lg-sm" style="opacity:.7;">On the admin Settings page; both switches must be on</div></td></tr>
        <tr><th>Mode</th>
            <td><?php echo $sandbox ? '<b>Sandbox</b> (api-m.sandbox.paypal.com)' : '<b>Live</b> (api-m.paypal.com)'; ?></td></tr>
        <tr><th>Client ID</th><td class="ad-mono"><?php echo h(cfg('paypal.client_id') ? substr(cfg('paypal.client_id'), 0, 12) . '…' : 'not set'); ?></td></tr>
        <tr><th>Client Secret</th><td><?php echo cfg('paypal.client_secret') ? '<span class="ad-tag done">set</span>' : '<span class="ad-tag new">not set</span>'; ?></td></tr>
        <tr><th>Webhook ID</th><td class="ad-mono"><?php echo h(cfg('paypal.webhook_id') ?: 'not set'); ?></td></tr>
        <tr><th>Webhook URL <span class="lg-sm">paste into the PayPal console</span></th>
            <td class="ad-mono"><?php echo h(site_url('app/webhook_paypal.php')); ?></td></tr>
        <tr><th>Currency</th><td><?php echo h(setting('currency', cfg('paypal.currency', 'USD'))); ?></td></tr>
    </table>

    <?php if (!$cfgOk): ?>
        <div class="ad-alert bad" style="margin-top:14px;">
            Not enabled yet: fill in client_id / client_secret in <b>app/config.php</b> and set <code>enabled</code> to true.
        </div>
    <?php endif; ?>
    <?php if ($sandbox && cfg('paypal.client_id') && strpos(cfg('paypal.client_id'), 'A') !== 0): ?>
        <div class="ad-alert warn" style="margin-top:14px;">
            Note: this Client ID does not look like a sandbox credential (sandbox IDs usually start with <b>A</b>). If <code>sandbox=true</code>, make sure you copied the keys from the Sandbox tab, not the Live ones.
        </div>
    <?php endif; ?>
</div>

<div class="ad-card">
    <h2>Connectivity self-test</h2>
    <p style="font-size:13.5px;color:#4b5563;line-height:1.8;margin-bottom:14px;">
        Two checks: <b>① can the credentials be exchanged for an access token</b> (validates keys and network); <b>② can an order be created</b>.<br>
        Creating an order <b>moves no money</b> — only capture collects payment, so it is safe to run.
    </p>

    <form method="post" style="display:inline-block;margin-right:8px;">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="act" value="token">
        <button class="btn btn-p" type="submit">① Test token</button>
    </form>
    <form method="post" style="display:inline-block;">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="act" value="create">
        <button class="btn" type="submit">② Test order ($1)</button>
    </form>

    <?php if ($result): ?>
        <div class="ad-alert <?php echo $result['ok'] ? 'ok' : 'bad'; ?>" style="margin-top:16px;">
            <b><?php echo h($result['title']); ?> — <?php echo $result['ok'] ? 'SUCCESS' : 'FAILED'; ?></b>
            <?php if (isset($result['ms'])): ?> <span class="lg-sm">(<?php echo (int)$result['ms']; ?> ms)</span><?php endif; ?>
            <?php foreach ($result['lines'] as $l): ?>
                <div style="margin-top:5px;"><?php echo h($l); ?></div>
            <?php endforeach; ?>
            <?php if (!empty($result['url'])): ?>
                <div style="margin-top:10px;">
                    <a class="btn btn-s" target="_blank" rel="noopener" href="<?php echo h($result['url']); ?>">
                        Open the PayPal approval page
                    </a>
                    <div class="lg-sm" style="margin-top:6px;opacity:.8;">
                        This is a $1 test order. In sandbox mode sign in with a sandbox buyer account; <b>do not actually pay in Live mode</b>.
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<div class="ad-card">
    <h2>Troubleshooting</h2>
    <div style="font-size:13px;line-height:1.9;color:#4b5563;">
        <div><b>cURL error 28 / timeout</b> — outbound traffic from the server to PayPal is blocked or slow. Change server region or use a proxy; the most common issue for mainland-China hosts talking to PayPal.</div>
        <div><b>HTTP 401 unauthorized</b> — wrong credentials, or the <code>sandbox</code> switch does not match the key set (Sandbox-tab keys require <code>sandbox=true</code>).</div>
        <div><b>HTTP 400 / INVALID_REQUEST</b> — unsupported currency or zero amount. Ensure <code>currency=USD</code> and an amount &gt; 0.</div>
        <div><b>Token OK but order creation fails</b> — usually missing App permissions; in the PayPal developer console confirm the App has <i>Transactions</i> / <i>Accept payments</i>.</div>
        <div style="margin-top:8px;">All errors are also written to <code>app/storage/app.log</code> for the raw messages.</div>
    </div>
</div>

<p><a class="btn btn-s" href="<?php echo h(site_url('app/admin/payments.php')); ?>">→ <?php echo h(t('adm.payments')); ?></a></p>
<?php admin_footer(); ?>
