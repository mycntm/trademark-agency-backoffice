<?php
/**
 * 后台手工新建询价工单
 *
 * 场景：客户在邮件 / 电话里只是问了问，还没到报价阶段；或者老客户直接发邮件
 * 说「帮我看看这个能不能注册」。我们先建个工单把线索存下来，后续再报价、转订单。
 */
require_once __DIR__ . '/_layout.php';

$msg = ''; $err = '';

$allClients = db_all('SELECT id,email,name,company,country,phone FROM clients ORDER BY id DESC LIMIT 300');
$svcList    = svc_all();
$groups     = svc_groups();
$rate       = (float)cfg('pricing.cny_per_usd', 6.70);
if ($rate <= 0) $rate = 6.70;

$svcJson = [];
foreach ($svcList as $k => $it) {
    $svcJson[$k] = [
        'price' => $it['price'], 'cny' => (float)($it['official_fee_cny'] ?? 0),
        'mode'  => svc_mode($it),
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
    } else {
        $clientId = (int)post('client_id', 0);
        $email    = strtolower(trim((string)post('new_email')));

        if ($clientId) {
            $cl = db_one('SELECT * FROM clients WHERE id = ?', [$clientId]);
            if ($cl) $email = $cl['email'];
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = lang() === 'zh' ? '请选择客户，或填写有效的新客户邮箱。' : 'Pick a client, or enter a valid new client email.';
        } else {
            $svcKey = (string)post('service_type', 'registration');
            if (!svc_one($svcKey)) $svcKey = 'registration';

            $classesRaw = trim((string)post('classes'));
            $classes    = [];
            $items      = 0;
            if ($classesRaw !== '') {
                foreach (preg_split('/[,，\s]+/', $classesRaw) as $cn) {
                    $cn = preg_replace('/\D/', '', $cn);
                    if ($cn === '') continue;
                    $classes[] = ['class' => (int)$cn, 'heading' => '', 'items' => []];
                }
            }

            $cl = $clientId ? db_one('SELECT * FROM clients WHERE id = ?', [$clientId]) : null;
            // 选了已有客户时前端的身份字段是锁定态（不提交），这里回填客户档案值，
            // 否则工单里的 company/country/phone 会被写成空串（2026-10-04）
            $name    = trim((string)post('name'));
            $company = trim((string)post('company'));
            $country = trim((string)post('country'));
            $phone   = trim((string)post('phone'));
            if ($cl) {
                if ($name    === '') $name    = (string)($cl['name'] ?? '');
                if ($company === '') $company = (string)($cl['company'] ?? '');
                if ($country === '') $country = (string)($cl['country'] ?? '');
                if ($phone   === '') $phone   = (string)($cl['phone'] ?? '');
            }

            $ref = next_ref('enquiries', 'EN');

            $cols = ['ref','service_type','client_id','email','name','company','country','phone',
                     'mark_name','mark_type','applicant_type','classes_json','items_count','class_count',
                     'est_price','currency','note','lang','status','source'];
            $vals = [
                $ref, $svcKey, $clientId ?: null, $email, $name,
                $company, $country, $phone,
                (string)post('mark_name'),
                in_array(post('mark_type','word'), ['word','device','combined','sound','other'], true) ? post('mark_type') : 'word',
                in_array(post('applicant_type','company'), ['individual','company','other'], true) ? post('applicant_type') : 'company',
                $classes ? json_encode($classes, JSON_UNESCAPED_UNICODE) : null,
                $items, count($classes),
                (float)post('est_price', 0), 'USD',
                (string)post('note'),
                in_array(post('lang','en'), ['en','zh'], true) ? post('lang') : 'en',
                'new',
                in_array(post('source','admin'), ['web','email','phone','admin'], true) ? post('source') : 'admin',
            ];
            if (table_has_column('enquiries', 'existing_reg_no')) {
                $cols[] = 'existing_reg_no';
                $vals[] = mb_substr((string)post('existing_reg_no'), 0, 40);
            }
            if (table_has_column('enquiries', 'ip')) { $cols[] = 'ip'; $vals[] = client_ip(); }

            try {
                $newId = (int)db_exec(
                    'INSERT INTO enquiries (`' . implode('`,`', $cols) . '`) VALUES ('
                    . implode(',', array_fill(0, count($cols), '?')) . ')',
                    $vals
                );
                admin_log_action('create', 'enquiry', $newId, 'created manually: ' . $email);
                redirect(site_url('app/admin/enquiry.php?id=' . $newId));
            } catch (Throwable $e) {
                app_log('enquiry_new failed: ' . $e->getMessage(), 'ERROR');
                $err = (lang() === 'zh' ? '建单失败：' : 'Could not create enquiry: ') . $e->getMessage();
            }
        }
    }
}

admin_header('adm.new_enquiry', 'enquiries');
?>
<?php if ($err): ?><?php echo ad_flash($err, 'bad'); ?><?php endif; ?>

<div class="ad-card">
    <h2><?php echo lang() === 'zh' ? '手工新建询价工单' : 'Create enquiry manually'; ?></h2>
    <form method="post" id="enqNew">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="act" value="create">

        <div class="fsec"><?php echo lang() === 'zh' ? '客户 Client' : 'Client'; ?></div>
        <div class="form-row cols3">
            <div class="full">
                <label for="client_id"><?php echo tp('client'); ?> <span class="lg-sm"><?php echo lang() === 'zh' ? '已有客户' : 'existing'; ?></span></label>
                <select name="client_id" id="client_id">
                    <option value="0"><?php echo lang() === 'zh' ? '— 新客户（填下面）—' : '— new client (fill below) —'; ?></option>
                    <?php foreach ($allClients as $cl): ?>
                        <option value="<?php echo (int)$cl['id']; ?>" <?php echo (int)get('client_id', 0) === (int)$cl['id'] ? 'selected' : ''; ?>>
                            <?php echo h(($cl['name'] ?: $cl['email']) . ' · ' . $cl['email']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <!-- 客户身份字段（.nc）：选择已有客户时由 JS 带出资料并逐字段锁定。
             source 与 country/phone 同行但不加 .nc —— 来源是我们自己的判断，永远可改。 -->
        <div class="form-row cols3">
            <div class="nc"><label for="new_email" id="new_email_label"><?php echo tp('email'); ?> *</label>
                <input type="email" id="new_email" name="new_email" autocomplete="off"></div>
            <div class="nc"><label for="name"><?php echo tp('name'); ?></label>
                <input type="text" id="name" name="name"></div>
            <div class="nc"><label for="company"><?php echo tp('company'); ?></label>
                <input type="text" id="company" name="company"></div>
        </div>
        <div class="form-row cols3">
            <div class="nc"><label for="country"><?php echo tp('country'); ?></label>
                <input type="text" id="country" name="country"></div>
            <div class="nc"><label for="phone"><?php echo tp('phone'); ?></label>
                <input type="text" id="phone" name="phone"></div>
            <div><label for="source"><?php echo tp('adm.order_source'); ?></label>
                <select id="source" name="source">
                    <option value="admin">admin</option><option value="email">email</option>
                    <option value="phone">phone</option><option value="web">web</option>
                </select></div>
            <!-- Language 字段已移除（2026-09-29 全站英文，enquiries.lang 固定写 en） -->
        </div>

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
                <label for="classes"><?php echo lang() === 'zh' ? '类别号（逗号分隔，如 9,25,35）' : 'Classes (comma separated, e.g. 9,25,35)'; ?></label>
                <input type="text" id="classes" name="classes" placeholder="9,25,35">
            </div>
        </div>
        <div class="form-row cols3">
            <div><label for="applicant_type"><?php echo tp('enq.applicant_type'); ?></label>
                <select id="applicant_type" name="applicant_type">
                    <option value="company">company</option><option value="individual">individual</option><option value="other">other</option>
                </select></div>
            <div><label for="est_price"><?php echo tp('adm.est_price'); ?> <span class="lg-sm">USD</span></label>
                <input type="number" step="0.01" id="est_price" name="est_price" value="0"></div>
        </div>

        <div class="fsec"><?php echo lang() === 'zh' ? '备注 Note' : 'Note'; ?></div>
        <div class="form-row cols3">
            <div class="full"><label for="note"><?php echo tp('note'); ?></label>
                <textarea id="note" name="note" rows="3"
                          placeholder="<?php echo lang() === 'zh' ? '客户在邮件里说了什么 / 我们的初步判断…' : 'What the client asked, our first assessment…'; ?>"></textarea></div>
        </div>

        <div class="form-actions">
            <button class="btn btn-p" type="submit"><i class="fas fa-plus"></i> <?php echo h(t('adm.new_enquiry')); ?></button>
            <a class="btn" href="<?php echo h(site_url('app/admin/enquiries.php')); ?>"><?php echo h(t('back')); ?></a>
        </div>
    </form>
</div>

<style>
/* 客户联动（2026-10-04）：选中已有客户后身份字段（.nc）带出资料并锁定，
   逐字段淡显 + 灰底，与 order_new 的客户联动行为一致；
   同行的 source 不受影响。 */
#enqNew .nc.is-linked{opacity:.72}
#enqNew .nc.is-linked input{background:#f1f3f5}
</style>

<script>
(function () {
    var SVC     = <?php echo json_encode($svcJson, JSON_UNESCAPED_UNICODE); ?>;
    var CLIENTS = <?php echo json_encode($clientJson, JSON_UNESCAPED_UNICODE); ?>;
    var RATE    = <?php echo json_encode($rate); ?>;

    var sel = document.getElementById('service_type');
    var fEst = document.getElementById('est_price');
    var wrapMark = document.getElementById('wrap_mark');
    var wrapRegno = document.getElementById('wrap_regno');
    var wrapClass = document.getElementById('wrap_classes');

    /* —— 客户联动：选已有客户 → 带出资料并锁定；选「新建客户」→ 清空解锁 —— */
    var selClient = document.getElementById('client_id');
    var fEmail    = document.getElementById('new_email');
    var fName     = document.getElementById('name');
    var fCompany  = document.getElementById('company');
    var fCountry  = document.getElementById('country');
    var fPhone    = document.getElementById('phone');
    var emailLbl  = document.getElementById('new_email_label');
    var LBL_NEW   = emailLbl.textContent;
    var LBL_LINKED = <?php echo json_encode(lang() === 'zh' ? '邮箱 Email（已选客户，随客户带出）' : 'Email (from selected client)'); ?>;

    var ncFields = [fEmail, fName, fCompany, fCountry, fPhone];

    function applyClient() {
        var c = CLIENTS[parseInt(selClient.value, 10) || 0];
        if (c) {
            // 已有客户：带出资料、锁定输入（disabled 不提交；服务端按 client_id 回填档案值）
            fEmail.value   = c.email;
            fName.value    = c.name;
            fCompany.value = c.company;
            fCountry.value = c.country;
            fPhone.value   = c.phone;
            ncFields.forEach(function (f) {
                f.disabled = true;
                f.parentNode.classList.add('is-linked');
            });
            emailLbl.textContent = LBL_LINKED;
        } else {
            // 新建客户：清空、解锁、恢复必填星号
            ncFields.forEach(function (f) {
                f.disabled = false; f.value = '';
                f.parentNode.classList.remove('is-linked');
            });
            emailLbl.textContent = LBL_NEW;
        }
    }
    selClient.addEventListener('change', applyClient);

    function apply() {
        var it = SVC[sel.value];
        if (!it) return;
        wrapMark.style.display  = (it.mode === 'regno') ? 'none' : '';
        wrapRegno.style.display = (it.mode === 'regno') ? '' : 'none';
        wrapClass.style.display = (it.mode === 'classes') ? '' : 'none';
        // 估价 = 服务费 + 官费（人民币折算）
        var off = it.cny > 0 ? Math.ceil(it.cny / RATE) : 0;
        fEst.value = Math.round(((it.price || 0) + off) * 100) / 100;
    }
    sel.addEventListener('change', apply);
    apply();
    applyClient(); // 支持 ?client_id= 预选时加载即带出资料
})();
</script>
<?php admin_footer(); ?>
