<?php
/**
 * System settings: quote defaults and payment channel switches.
 */
require_once __DIR__ . '/_layout.php';

$keys = [
    'paypal_enabled', 'bank_transfer_enabled',
    'translation_fee', 'quote_valid_days', 'currency',
];

$vals = [];
foreach ($keys as $k) $vals[$k] = setting($k, '');

$msg = '';
if (is_post() && post('act') === 'save') {
    if (!csrf_check()) {
        $msg = t('enq.err_csrf');
    } else {
        $changed = [];
        foreach ($keys as $k) {
            $v = (string)($_POST[$k] ?? '');
            if ((string)$vals[$k] !== $v) $changed[] = $k;
            db_exec('INSERT INTO settings (`k`,`v`) VALUES (?,?) ON DUPLICATE KEY UPDATE `v`=VALUES(`v`)', [$k, $v]);
        }
        // 改设置也算动钱：留一条审计，谁在什么时候改了哪几项（2026-09-30）
        admin_log_action('update', 'settings', null, $changed ? implode(',', $changed) : 'no change');
        $msg = t('adm.saved');
    }
}

admin_header('adm.settings', 'settings');
?>
<?php if ($msg): ?><?php echo ad_flash($msg, 'ok'); ?><?php endif; ?>
<form method="post">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="act" value="save">

    <div class="ad-card">
        <h2>Quote defaults</h2>
        <div class="form-row">
            <div><label for="translation_fee">Translation fee (USD)</label>
                <input type="number" step="0.01" id="translation_fee" name="translation_fee" value="<?php echo h($vals['translation_fee']); ?>"></div>
            <div><label for="quote_valid_days">Quote valid days</label>
                <input type="number" id="quote_valid_days" name="quote_valid_days" value="<?php echo h($vals['quote_valid_days']); ?>"></div>
            <div><label for="currency">Currency</label>
                <input type="text" id="currency" name="currency" value="<?php echo h($vals['currency']); ?>" maxlength="3"></div>
        </div>
    </div>

    <div class="ad-card">
        <h2>Payment channels</h2>
        <div class="form-row">
            <div><label for="paypal_enabled">PayPal</label>
                <select name="paypal_enabled" id="paypal_enabled">
                    <option value="0" <?php echo $vals['paypal_enabled'] === '0' ? 'selected' : ''; ?>>No — disabled</option>
                    <option value="1" <?php echo $vals['paypal_enabled'] === '1' ? 'selected' : ''; ?>>Yes — enabled</option>
                </select></div>
            <div><label for="bank_transfer_enabled">Bank transfer</label>
                <select name="bank_transfer_enabled" id="bank_transfer_enabled">
                    <option value="0" <?php echo $vals['bank_transfer_enabled'] === '0' ? 'selected' : ''; ?>><?php echo h(t('no')); ?></option>
                    <option value="1" <?php echo $vals['bank_transfer_enabled'] !== '0' ? 'selected' : ''; ?>><?php echo h(t('yes')); ?></option>
                </select></div>
        </div>
        <div class="lg-sm" style="opacity:.8;">
            <?php echo lang() === 'zh'
                ? '注意：PayPal 的 Client ID / Secret / Webhook ID 在 app/config.php 中配置（属密钥，不放数据库）。此处开关为 0 时，前台自动回落银行转账。'
                : 'Note: PayPal Client ID / Secret / Webhook ID live in app/config.php (secrets are not stored in the database). When this switch is 0, the checkout page falls back to bank transfer.'; ?>
        </div>
    </div>

    <button class="btn btn-p" type="submit"><?php echo h(t('save')); ?></button>
</form>

<div class="ad-card">
    <h2>Diagnostics</h2>
    <p style="font-size:13.5px;color:#4b5563;line-height:1.8;margin-bottom:12px;">
        Self-test entry points for the two external links (email &amp; payments). Once troubleshooting is done, delete these two files (<code>smtp_test.php</code>, <code>paypal_test.php</code>) so they don't expose configuration details.
    </p>
    <p>
        <a class="btn btn-s" href="<?php echo h(site_url('app/admin/smtp_test.php')); ?>">
            <i class="fas fa-envelope"></i> SMTP self-test <span class="lg-sm">send test mail / view queue</span>
        </a>
        <a class="btn btn-s" href="<?php echo h(site_url('app/admin/paypal_test.php')); ?>">
            <i class="fab fa-paypal"></i> PayPal self-test <span class="lg-sm">get token / create order</span>
        </a>
    </p>
</div>
<?php admin_footer(); ?>
