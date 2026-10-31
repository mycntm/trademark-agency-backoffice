<?php
/**
 * 后台手工建单
 *
 * 场景：客户是邮件 / 电话 / 微信联系我们的，从头到尾没在官网提交过询价。
 * 让人家再回去申请一遍报价既不现实也没必要——我们直接在后台建单，
 * 客户只需要点开登录链接付款就行。
 *
 * 建单后可：① 一步到位登记为已收款（客户已经银行转账了）
 *          ② 给客户发邮件（含 magic link 登录链接，默认 15 天有效）
 */
require_once __DIR__ . '/_layout.php';

$msg = ''; $err = '';

$allClients = db_all('SELECT id,email,name,company,country,phone FROM clients ORDER BY id DESC LIMIT 300');
$svcList    = svc_all();
$groups     = svc_groups();
$rate       = (float)cfg('pricing.cny_per_usd', 6.70);
if ($rate <= 0) $rate = 6.70;

/* 给前端的定价表：切换业务类型时自动带出服务费与官费 */
$svcJson = [];
foreach ($svcList as $k => $it) {
    $svcJson[$k] = [
        'price' => $it['price'],
        'cny'   => (float)($it['official_fee_cny'] ?? 0),
        'mode'  => svc_mode($it),
        'en'    => $it['en'],
        'zh'    => $it['zh'],
        'unit'  => $it['unit_en'] ?? '',
    ];
}

/* 给前端的客户表：选择已有客户时自动带出资料并锁定新客户字段（2026-10-04） */
$clientJson = [];
foreach ($allClients as $cl) {
    $clientJson[(int)$cl['id']] = [
        'email'   => (string)$cl['email'],
        'name'    => (string)($cl['name'] ?? ''),
        'company' => (string)($cl['company'] ?? ''),
        'country' => (string)($cl['country'] ?? ''),
        'phone'   => (string)($cl['phone'] ?? ''),
    ];
}

if (is_post() && post('act') === 'create') {
    if (!csrf_check()) {
        $err = t('enq.err_csrf');
    } elseif (!idem_check('order_new_create')) {
        // 一次性令牌（2026-09-30）：建单页重复提交会同时造出「第二个订单 + 第二笔收款」，
        // 而且 create_order_manual 不像 create_order_from_enquiry 那样有询价防重，
        // 所以这里的危害比 mark_paid 更大 —— 必须挡住。
        $err = idem_dup_message();
    } else {
        $clientId = (int)post('client_id', 0);
        $newEmail = strtolower(trim((string)post('new_email')));

        if (!$clientId && $newEmail === '') {
            $err = lang() === 'zh' ? '请选择客户，或填写新客户邮箱。' : 'Pick a client, or enter a new client email.';
        } elseif (!$clientId && !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $err = lang() === 'zh' ? '新客户邮箱格式不正确。' : 'That new client email is not valid.';
        } else {
            $svcKey = (string)post('service_type', 'registration');
            $svc    = svc_one($svcKey);
            if (!$svc) $svcKey = 'registration';

            // 类别：后台只填类别号即可，不需要像前台那样挑商品项
            $classesRaw = trim((string)post('classes'));
            $classes    = [];
            if ($classesRaw !== '') {
                foreach (preg_split('/[,，\s]+/', $classesRaw) as $cn) {
                    $cn = preg_replace('/\D/', '', $cn);
                    if ($cn === '') continue;
                    $classes[] = ['class' => (int)$cn, 'heading' => '', 'items' => []];
                }
            }

            // 一口价口径：后台只填一个「全包总价」，与客户看到的一致。
            // orders 表的 official_fee/service_fee/translation_fee 三列保留做结构兼容：
            // 总价全额记入 service_fee，official/translation 记 0（2026-09-29，同报价口径）
            $totalAll = round((float)post('total_fee', 0), 2);

            try {
                $orderId = create_order_manual([
                    'client_id'       => $clientId,
                    'email'           => $newEmail,
                    'name'            => (string)post('new_name'),
                    'company'         => (string)post('new_company'),
                    'country'         => (string)post('new_country'),
                    'phone'           => (string)post('new_phone'),
                    'service_type'    => $svcKey,
                    'mark_name'       => (string)post('mark_name'),
                    'existing_reg_no' => (string)post('existing_reg_no'),
                    'mark_type'       => (string)post('mark_type', 'word'),
                    'registrant_name' => (string)post('registrant_name'),
                    'registrant_addr' => (string)post('registrant_addr'),
                    'registrant_type' => (string)post('registrant_type', 'company'),
                    'classes_json'    => $classes ? json_encode($classes, JSON_UNESCAPED_UNICODE) : null,
                    'official_fee'    => 0.0,
                    'service_fee'     => $totalAll,
                    'translation_fee' => 0.0,
                    'total'           => $totalAll,
                    'currency'        => 'USD',
                ]);

                $order  = db_one('SELECT * FROM orders WHERE id = ?', [$orderId]);
                $client = db_one('SELECT * FROM clients WHERE id = ?', [$order['client_id']]);

                // 可选：一步到位登记为已收款
                // 通知开关跟着页面上的 ad_wants_notify() 走，避免「不勾通知却还是收到了付款邮件」
                if ((int)post('mark_paid', 0) === 1 && (float)$order['total'] > 0) {
                    /* 一步到位登记收款时，第六参传 false：不再单独发「付款已收到」。
                     * 本页的通知勾选框（notify_new_order）是两个动作共用的，
                     * 没有「只跳过付款邮件」的粒度；而下面那封 account_ready
                     * 会按 paid_amount 自动切到已付清版文案（标题就是
                     * "confirmed — payment received"，明细里还有 Status: Paid），
                     * 再加一封付款邮件就是两封重复。2026-09-30 */
                    apply_payment($orderId, (float)$order['total'], 'bank_transfer', 'MANUAL-' . date('YmdHis'), '', false);
                }

                // 可选：发邮件 + 登录链接（统一用 ad_wants_notify()，与其它三处一致）
                $mailed = false;
                if (ad_wants_notify() && $client) {
                    try {
                        // 直达这一单的链接：客户点开即登录并直接落在订单页（2026-09-30）
                        $orderUrl = order_deeplink($client['id'], $order['id']);
                        mail_account_ready($order, $client, $orderUrl);
                        $mailed = true;
                    } catch (Throwable $e) {
                        app_log('order_new mail failed: ' . $e->getMessage(), 'ERROR');
                    }
                }

                admin_log_action('create', 'order', $orderId,
                    'manual order: ' . $svcKey . ' for ' . ($client['email'] ?? '') . ($mailed ? ' (notified)' : ''));

                $msg = (lang() === 'zh' ? '订单已创建：' : 'Order created: ')
                     . '<a href="' . h(site_url('app/admin/order.php?id=' . $orderId)) . '">' . h($order['ref']) . '</a>'
                     . ($mailed ? (lang() === 'zh' ? '（已发送邮件）' : ' (email sent)') : '');
                $msg = '<div class="ad-alert ok">' . $msg . '</div>';
            } catch (Throwable $e) {
                app_log('create_order_manual failed: ' . $e->getMessage(), 'ERROR');
                $err = (lang() === 'zh' ? '建单失败：' : 'Could not create order: ') . $e->getMessage();
            }
        }
    }
}

admin_header('adm.new_order', 'orders');
?>
<?php if ($msg): ?><?php echo $msg; ?><?php endif; ?>
<?php if ($err): ?><?php echo ad_flash($err, 'bad'); ?><?php endif; ?>

<div class="ad-card">
    <h2><?php echo tp('adm.create_order_admin'); ?></h2>
    <form method="post" id="ordNew">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="act" value="create">
        <?php echo idem_field('order_new_create'); ?>

        <!-- 客户 -->
        <div class="fsec"><?php echo lang() === 'zh' ? '客户 Client' : 'Client'; ?></div>
        <div class="form-row cols3">
            <div class="full">
                <label for="client_id"><?php echo tp('client'); ?> <span class="lg-sm"><?php echo lang() === 'zh' ? '已有客户' : 'existing'; ?></span></label>
                <select name="client_id" id="client_id">
                    <option value="0"><?php echo lang() === 'zh' ? '— 新建客户（填下面）—' : '— new client (fill below) —'; ?></option>
                    <?php foreach ($allClients as $cl): ?>
                        <option value="<?php echo (int)$cl['id']; ?>" <?php echo (int)get('client_id', 0) === (int)$cl['id'] ? 'selected' : ''; ?>>
                            <?php echo h(($cl['name'] ?: $cl['email']) . ' · ' . $cl['email'] . ($cl['company'] ? ' · ' . $cl['company'] : '')); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <!-- 新客户资料：选择已有客户时由 JS 带出资料并锁定本块 -->
        <div id="newClientBlock">
            <div class="form-row cols3">
                <div><label for="new_email" id="new_email_label"><?php echo lang() === 'zh' ? '新客户邮箱 Email *' : 'New client email *'; ?></label>
                    <input type="email" id="new_email" name="new_email" autocomplete="off"></div>
                <div><label for="new_name"><?php echo tp('name'); ?></label>
                    <input type="text" id="new_name" name="new_name"></div>
                <div><label for="new_company"><?php echo tp('company'); ?></label>
                    <input type="text" id="new_company" name="new_company"></div>
            </div>
            <div class="form-row cols3">
                <div><label for="new_country"><?php echo tp('country'); ?></label>
                    <input type="text" id="new_country" name="new_country"></div>
                <div><label for="new_phone"><?php echo tp('phone'); ?></label>
                    <input type="text" id="new_phone" name="new_phone"></div>
            </div>
        </div>

        <!-- 业务 -->
        <div class="fsec"><?php echo lang() === 'zh' ? '商标与类别 Mark & classes' : 'Mark &amp; classes'; ?></div>
        <div class="form-row cols3">
            <div class="span2">
                <label for="service_type"><?php echo tp('adm.service_type'); ?></label>
                <select name="service_type" id="service_type">
                    <?php foreach ($groups as $gk => $g): ?>
                        <optgroup label="<?php echo h(lang() === 'zh' ? $g['zh'] : $g['en']); ?>">
                        <?php foreach ($svcList as $k => $it): if ($it['group'] !== $gk) continue; ?>
                            <option value="<?php echo h($k); ?>"><?php echo h(lang() === 'zh' ? $it['zh'] : $it['en']); ?></option>
                        <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label for="mark_type">Mark type</label>
                <select id="mark_type" name="mark_type">
                    <option value="word">word</option><option value="device">device</option>
                    <option value="combined">combined</option><option value="sound">sound</option>
                    <option value="other">other</option>
                </select></div>
        </div>
        <div class="form-row cols3">
            <div class="span2" id="wrap_mark">
                <label for="mark_name"><?php echo tp('adm.mark_name'); ?></label>
                <input type="text" id="mark_name" name="mark_name">
            </div>
            <div class="span2" id="wrap_regno" style="display:none;">
                <label for="existing_reg_no"><?php echo tp('adm.reg_no'); ?></label>
                <input type="text" id="existing_reg_no" name="existing_reg_no">
            </div>
            <div id="wrap_classes" style="display:none;">
                <label for="classes"><?php echo lang() === 'zh' ? '类别号（逗号分隔，如 9,25,35）' : 'Class numbers (comma separated, e.g. 9,25,35)'; ?></label>
                <input type="text" id="classes" name="classes" placeholder="9,25,35">
            </div>
        </div>

        <!-- 注册人 -->
        <div class="fsec"><?php echo lang() === 'zh' ? '注册人 Registrant' : 'Registrant'; ?></div>
        <div class="form-row cols3">
            <div><label for="registrant_name"><?php echo tp('acc.registrant'); ?> <span class="lg-sm">Registrant</span></label>
                <input type="text" id="registrant_name" name="registrant_name"></div>
            <div><label for="registrant_addr"><?php echo lang() === 'zh' ? '注册人地址 Address' : 'Registrant address'; ?></label>
                <input type="text" id="registrant_addr" name="registrant_addr"></div>
            <div><label for="registrant_type">Registrant type</label>
                <select id="registrant_type" name="registrant_type">
                    <option value="company">company</option><option value="individual">individual</option><option value="other">other</option>
                </select></div>
        </div>

        <!-- 金额：一口价 -->
        <div class="fsec"><?php echo lang() === 'zh' ? '费用 Fee' : 'Fee'; ?></div>
        <div class="form-row cols3">
            <div><label for="total_fee"><?php echo lang() === 'zh' ? '全包总价 All-inclusive total（USD）' : 'All-inclusive total (USD)'; ?></label>
                <input type="number" step="0.01" min="0" id="total_fee" name="total_fee" value="0"></div>
            <div class="span2" style="align-self:end;padding-bottom:8px;">
                <span class="lg-sm" style="opacity:.7;">
                    <?php echo lang() === 'zh'
                        ? '客户只会看到一个「全部包含」的价格：官费、服务费、翻译都含在内，不再分项。'
                        : 'The client sees one all-inclusive figure — official fee, service and translation are all inside.'; ?>
                </span>
            </div>
        </div>

        <div class="form-row cols3">
            <div style="display:flex;gap:18px;align-items:center;flex-wrap:wrap;">
                <label style="display:flex;gap:6px;align-items:center;font-weight:600;">
                    <input type="checkbox" name="mark_paid" value="1" style="width:auto;">
                    <?php echo h(t('adm.mark_paid_now')); ?></label>
            </div>
        </div>

        <?php echo ad_notify_field(true, 'notify_new_order'); ?>

        <div class="form-actions">
            <button class="btn btn-p" type="submit"><i class="fas fa-plus"></i> <?php echo h(t('adm.new_order')); ?></button>
            <a class="btn" href="<?php echo h(site_url('app/admin/orders.php')); ?>"><?php echo h(t('back')); ?></a>
        </div>
    </form>
</div>

<style>
/* 建单页底部的呼吸感（2026-09-30）
   .ad-notify 本身只有 margin-top 没有 margin-bottom，通知勾选条会直接贴住提交按钮，
   看起来像按钮长在提示条上。这里只在 #ordNew 作用域内补开：
   上方（与「Also record payment now」之间）、下方（与按钮行之间）各留一档。
   三列栅格 / 分区标题 / 按钮行已抽到 _layout.php 的 .form-row.cols3、.fsec、
   .form-actions 共享类（2026-10-04），本页不再重复定义。 */
#ordNew .ad-notify{margin-top:1.25rem;margin-bottom:1.25rem}
/* 客户联动（2026-10-04）：选中已有客户后新客户字段块带出资料并锁定，
   整块淡显 + 禁用输入框的默认灰字，让「这是系统带出的」一眼可辨 */
#newClientBlock.is-linked{opacity:.72}
#newClientBlock.is-linked input{background:#f1f3f5}
</style>

<script>
(function () {
    var SVC     = <?php echo json_encode($svcJson, JSON_UNESCAPED_UNICODE); ?>;
    var CLIENTS = <?php echo json_encode($clientJson, JSON_UNESCAPED_UNICODE); ?>;
    var RATE    = <?php echo json_encode($rate); ?>;

    var sel    = document.getElementById('service_type');
    var fTotal = document.getElementById('total_fee');

    var wrapMark   = document.getElementById('wrap_mark');
    var wrapRegno  = document.getElementById('wrap_regno');
    var wrapClass  = document.getElementById('wrap_classes');

    /* —— 客户联动：选已有客户 → 带出资料并锁定；选「新建客户」→ 清空解锁 —— */
    var selClient = document.getElementById('client_id');
    var ncBlock   = document.getElementById('newClientBlock');
    var fEmail    = document.getElementById('new_email');
    var fName     = document.getElementById('new_name');
    var fCompany  = document.getElementById('new_company');
    var fCountry  = document.getElementById('new_country');
    var fPhone    = document.getElementById('new_phone');
    var emailLbl  = document.getElementById('new_email_label');
    var LBL_NEW   = emailLbl.textContent;
    var LBL_LINKED = <?php echo json_encode(lang() === 'zh' ? '邮箱 Email（已选客户，随客户带出）' : 'Email (from selected client)'); ?>;

    var ncFields = [fEmail, fName, fCompany, fCountry, fPhone];

    function applyClient() {
        var c = CLIENTS[parseInt(selClient.value, 10) || 0];
        if (c) {
            // 已有客户：带出资料、锁定输入（disabled 字段不提交，服务端走 client_id）
            fEmail.value   = c.email;
            fName.value    = c.name;
            fCompany.value = c.company;
            fCountry.value = c.country;
            fPhone.value   = c.phone;
            ncFields.forEach(function (f) { f.disabled = true; });
            ncBlock.classList.add('is-linked');
            emailLbl.textContent = LBL_LINKED;
        } else {
            // 新建客户：清空、解锁、恢复必填星号
            ncFields.forEach(function (f) { f.disabled = false; f.value = ''; });
            ncBlock.classList.remove('is-linked');
            emailLbl.textContent = LBL_NEW;
        }
    }
    selClient.addEventListener('change', applyClient);

    function money(n) { return Math.round(n * 100) / 100; }

    function applySvc() {
        var it = SVC[sel.value];
        if (!it) return;
        // 表单形态跟着业务类型变：新申请要商标名+类别，后续业务要注册号
        wrapMark.style.display  = (it.mode === 'regno') ? 'none' : '';
        wrapRegno.style.display = (it.mode === 'regno') ? '' : 'none';
        wrapClass.style.display = (it.mode === 'classes') ? '' : 'none';

        // 建议一口价 = 我方服务费 + 官费（人民币按汇率折算、向上取整到整数）。
        // 翻译费已含在全包口径里，不再单列；运营可手工改总价。
        var suggested = (it.price !== null ? (parseFloat(it.price) || 0) : 0)
                      + (it.cny > 0 ? Math.ceil(it.cny / RATE) : 0);
        fTotal.value = money(suggested);
    }

    sel.addEventListener('change', applySvc);
    applySvc();
    applyClient(); // 支持 ?client_id= 预选时加载即带出资料
})();
</script>
<?php admin_footer(); ?>
