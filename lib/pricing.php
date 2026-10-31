<?php
/**
 * 服务价格表 —— 单一数据源
 * 供前台展示（services.php）、询价流程（enquiry.php）、后台报价预填共用。
 *
 * 字段说明：
 *   price            USD 售价；null = 协商定价（Quote on request）
 *   unit_en / zh     计价单位（如 per class），可为空
 *   negotiable       true = 页面需标注"案件复杂者协商定价"
 *   group            所属分组 key
 *   needs_reg_no     true = 该业务需要提供已有商标注册号/申请号
 *   needs_classes    true = 该业务需要选择商标分类与商品项
 *   official_fee_cny ⚠️ 内部成本核算用（CNIPA 官方规费，2019-07-01 起、电子申请标准）
 *                     【用户要求：不得输出到任何前端页面】仅用于内部毛利核算，勿在页面 echo。
 */

if (!function_exists('svc_all')) {
    function svc_all()
    {
        static $list = null;
        if ($list !== null) return $list;

        $list = [
            /* ---------- 注册申请 ---------- */
            'registration' => [
                'en' => 'Trademark Registration Application', 'zh' => '商标注册申请',
                'price' => 199, 'unit_en' => 'per class', 'unit_zh' => '每类',
                'note_en' => 'Includes up to 10 items of goods or services per class; additional items USD 10 each. Each additional class is charged separately.',
                'note_zh' => '每类含 10 个商品/服务项目，超出部分每项 10 美元；每增加一个类别单独计费。',
                'negotiable' => false, 'group' => 'filing', 'needs_reg_no' => false, 'needs_classes' => true,
                'official_fee_cny' => 270,
            ],
            'reduction' => [
                'en' => 'Request to Delete Goods / Services from an Application', 'zh' => '注册申请删减商品/服务',
                'price' => 20, 'unit_en' => '', 'unit_zh' => '',
                'note_en' => '', 'note_zh' => '',
                'negotiable' => false, 'group' => 'filing', 'needs_reg_no' => true, 'needs_classes' => false,
                'official_fee_cny' => 0,
            ],
            'withdraw_reduction' => [
                'en' => 'Withdrawal of Request to Delete Goods / Services', 'zh' => '撤回注册申请删减商品/服务',
                'price' => 20, 'unit_en' => '', 'unit_zh' => '',
                'note_en' => '', 'note_zh' => '',
                'negotiable' => false, 'group' => 'filing', 'needs_reg_no' => true, 'needs_classes' => false,
                'official_fee_cny' => 0,
            ],

            /* ---------- 变更、转让与更正 ---------- */
            'change' => [
                'en' => 'Change of Registrant Name / Address / Management Rules / Member List', 'zh' => '变更名义/地址/管理规则/成员名单申请',
                'price' => 50, 'unit_en' => '', 'unit_zh' => '',
                'note_en' => '', 'note_zh' => '',
                'negotiable' => false, 'group' => 'change', 'needs_reg_no' => true, 'needs_classes' => false,
                'official_fee_cny' => 0,
            ],
            'withdraw_change' => [
                'en' => 'Withdrawal of Application for Change', 'zh' => '撤回变更名义/地址/管理规则/成员名单申请',
                'price' => 20, 'unit_en' => '', 'unit_zh' => '',
                'note_en' => '', 'note_zh' => '',
                'negotiable' => false, 'group' => 'change', 'needs_reg_no' => true, 'needs_classes' => false,
                'official_fee_cny' => 0,
            ],
            'assignment' => [
                'en' => 'Assignment / Transfer of a Registered Trademark', 'zh' => '转让/移转注册商标申请',
                'price' => 199, 'unit_en' => '', 'unit_zh' => '',
                'note_en' => '', 'note_zh' => '',
                'negotiable' => false, 'group' => 'change', 'needs_reg_no' => true, 'needs_classes' => false,
                'official_fee_cny' => 450,
            ],
            'withdraw_assignment' => [
                'en' => 'Withdrawal of Application for Assignment / Transfer', 'zh' => '撤回商标转让/移转申请',
                'price' => 20, 'unit_en' => '', 'unit_zh' => '',
                'note_en' => '', 'note_zh' => '',
                'negotiable' => false, 'group' => 'change', 'needs_reg_no' => true, 'needs_classes' => false,
                'official_fee_cny' => 0,
            ],
            'correction' => [
                'en' => 'Correction of a Trademark Registration', 'zh' => '商标更正申请',
                'price' => 99, 'unit_en' => '', 'unit_zh' => '',
                'note_en' => '', 'note_zh' => '',
                'negotiable' => false, 'group' => 'change', 'needs_reg_no' => true, 'needs_classes' => false,
                'official_fee_cny' => 0,
            ],

            /* ---------- 续展、注销与证明 ---------- */
            'renewal' => [
                'en' => 'Trademark Renewal', 'zh' => '商标续展申请',
                'price' => 199, 'unit_en' => '', 'unit_zh' => '',
                // 续展迟延附加费：CNIPA 法定迟延费 = 250 元/类（纸质）/ 225 元/类（电子）；我方收取 USD 100/类（含货币转换等成本），单独收取、不含在 199 服务费中。
                'note_en' => 'Late renewal (filed within the 6-month grace period after expiry): our service fee is USD 199 per class, PLUS a separate late-renewal surcharge of USD 100 per class. The USD 100 is NOT included in the USD 199 and is charged in addition; it covers the CNIPA late filing fee plus currency-conversion and processing costs.',
                'note_zh' => '宽展期（有效期届满后 6 个月内）办理：我方服务费为每类 199 美元，另加每类 100 美元的迟延续展附加费。该 100 美元附加费不包含在 199 美元服务费中，需额外收取；用于覆盖 CNIPA 迟延规费及货币转换等成本。',
                'negotiable' => false, 'group' => 'maintain', 'needs_reg_no' => true, 'needs_classes' => false,
                'official_fee_cny' => 450,
            ],
            'withdraw_renewal' => [
                'en' => 'Withdrawal of Application for Renewal', 'zh' => '撤回商标续展申请',
                'price' => 20, 'unit_en' => '', 'unit_zh' => '',
                'note_en' => '', 'note_zh' => '',
                'negotiable' => false, 'group' => 'maintain', 'needs_reg_no' => true, 'needs_classes' => false,
                'official_fee_cny' => 0,
            ],
            'cancellation' => [
                'en' => 'Cancellation of a Registered Trademark', 'zh' => '商标注销申请',
                'price' => 50, 'unit_en' => '', 'unit_zh' => '',
                'note_en' => '', 'note_zh' => '',
                'negotiable' => false, 'group' => 'maintain', 'needs_reg_no' => true, 'needs_classes' => false,
                'official_fee_cny' => 0,
            ],
            'withdraw_cancellation' => [
                'en' => 'Withdrawal of Application for Cancellation', 'zh' => '撤回商标注销申请',
                'price' => 20, 'unit_en' => '', 'unit_zh' => '',
                'note_en' => '', 'note_zh' => '',
                'negotiable' => false, 'group' => 'maintain', 'needs_reg_no' => true, 'needs_classes' => false,
                'official_fee_cny' => 0,
            ],
            'cert_reissue' => [
                'en' => 'Formal Reissue of Electronic Trademark Registration Certificate', 'zh' => '补发电子商标注册证申请',
                'price' => 199, 'unit_en' => '', 'unit_zh' => '',
                'note_en' => 'For registrations handled by EXAMPLE IP, we permanently archive the electronic registration certificate and resend it free of charge on request. Contact us before ordering a paid reissue. A paid CNIPA reissue application is for a missing certificate that cannot be retrieved, for example when the former agent is unreachable. CNIPA issues an electronic registration certificate, not a paper replacement.', 'note_zh' => '我司代理注册的电子注册证永久存档，可免费再次发送。付费补发仅适用于无法找回证书的正式申请，补发为电子证书，不是纸质证书。',
                'negotiable' => false, 'group' => 'maintain', 'needs_reg_no' => true, 'needs_classes' => false,
                'official_fee_cny' => 500,
            ],
            'cert_proof' => [
                'en' => 'Reissue of Certificate of Change / Assignment / Renewal', 'zh' => '补发变转续证明申请',
                'price' => 199, 'unit_en' => '', 'unit_zh' => '',
                'note_en' => 'Replacement of an existing change, assignment or renewal certificate; not issuance of an official trademark registration proof.', 'note_zh' => '补发已有的变更、转让或续展证明，不是出具商标注册证明。',
                'negotiable' => false, 'group' => 'maintain', 'needs_reg_no' => true, 'needs_classes' => false,
                'official_fee_cny' => 50,
            ],

            /* ---------- 许可备案与质押 ---------- */
            'license' => [
                'en' => 'Recordal of Trademark Licence', 'zh' => '商标使用许可备案',
                'price' => 99, 'unit_en' => '', 'unit_zh' => '',
                'note_en' => '', 'note_zh' => '',
                'negotiable' => false, 'group' => 'licence', 'needs_reg_no' => true, 'needs_classes' => false,
                'official_fee_cny' => 135,
            ],
            'withdraw_license' => [
                'en' => 'Withdrawal of Trademark Licence Recordal', 'zh' => '撤回商标使用许可备案',
                'price' => 20, 'unit_en' => '', 'unit_zh' => '',
                'note_en' => '', 'note_zh' => '',
                'negotiable' => false, 'group' => 'licence', 'needs_reg_no' => true, 'needs_classes' => false,
                'official_fee_cny' => 0,
            ],
            'license_change' => [
                'en' => 'Recordal of Change of Licensor / Licensee Name', 'zh' => '变更许可人/被许可人名称备案',
                'price' => 99, 'unit_en' => '', 'unit_zh' => '',
                'note_en' => '', 'note_zh' => '',
                'negotiable' => false, 'group' => 'licence', 'needs_reg_no' => true, 'needs_classes' => false,
                'official_fee_cny' => 135,
            ],
            'license_termination' => [
                'en' => 'Recordal of Early Termination of Trademark Licence', 'zh' => '商标使用许可提前终止备案',
                'price' => 99, 'unit_en' => '', 'unit_zh' => '',
                'note_en' => '', 'note_zh' => '',
                'negotiable' => false, 'group' => 'licence', 'needs_reg_no' => true, 'needs_classes' => false,
                'official_fee_cny' => 135,
            ],
            'pledge' => [
                'en' => 'Registration of Trademark Pledge', 'zh' => '商标质押登记申请',
                'price' => 199, 'unit_en' => '', 'unit_zh' => '',
                'note_en' => '', 'note_zh' => '',
                'negotiable' => false, 'group' => 'licence', 'needs_reg_no' => true, 'needs_classes' => false,
                'official_fee_cny' => 0,
            ],
            'pledge_change' => [
                'en' => 'Change of Trademark Pledge', 'zh' => '商标质押变更申请',
                'price' => 199, 'unit_en' => '', 'unit_zh' => '',
                'note_en' => '', 'note_zh' => '',
                'negotiable' => false, 'group' => 'licence', 'needs_reg_no' => true, 'needs_classes' => false,
                'official_fee_cny' => 0,
            ],
            'pledge_reissue' => [
                'en' => 'Re-issue of Trademark Pledge Certificate (paper)', 'zh' => '商标质押证补发申请（纸质）',
                'price' => 199, 'unit_en' => '', 'unit_zh' => '',
                'note_en' => '', 'note_zh' => '',
                'negotiable' => false, 'group' => 'licence', 'needs_reg_no' => true, 'needs_classes' => false,
                'official_fee_cny' => 0,
            ],
            'pledge_cancel' => [
                'en' => 'Cancellation of Trademark Pledge', 'zh' => '商标质押注销申请',
                'price' => 199, 'unit_en' => '', 'unit_zh' => '',
                'note_en' => '', 'note_zh' => '',
                'negotiable' => false, 'group' => 'licence', 'needs_reg_no' => true, 'needs_classes' => false,
                'official_fee_cny' => 0,
            ],

            /* ---------- 异议、复审与撤销 ---------- */
            'opposition' => [
                'en' => 'Notice of Opposition against a Trademark', 'zh' => '商标异议申请',
                'price' => 199, 'unit_en' => '', 'unit_zh' => '',
                'note_en' => 'Cases involving substantial evidence or complex grounds are quoted by agreement.',
                'note_zh' => '案件较为复杂、证据材料较多者，双方协商定价。',
                'negotiable' => true, 'group' => 'challenge', 'needs_reg_no' => true, 'needs_classes' => false,
                'official_fee_cny' => 450,
            ],
            'review_refusal' => [
                'en' => 'Review of Refusal of Trademark Registration', 'zh' => '驳回商标注册申请复审申请',
                'price' => 299, 'unit_en' => '', 'unit_zh' => '',
                'note_en' => 'Cases involving substantial evidence or complex grounds are quoted by agreement.',
                'note_zh' => '案件较为复杂、证据材料较多者，双方协商定价。',
                'negotiable' => true, 'group' => 'challenge', 'needs_reg_no' => true, 'needs_classes' => false,
                'official_fee_cny' => 675,
            ],
            'invalidation' => [
                'en' => 'Invalidation of a Registered Trademark', 'zh' => '注册商标无效宣告申请',
                'price' => 299, 'unit_en' => '', 'unit_zh' => '',
                'note_en' => 'Cases involving substantial evidence or complex grounds are quoted by agreement.',
                'note_zh' => '案件较为复杂、证据材料较多者，双方协商定价。',
                'negotiable' => true, 'group' => 'challenge', 'needs_reg_no' => true, 'needs_classes' => false,
                'official_fee_cny' => 675,
            ],
            'review_nonregistration' => [
                'en' => 'Review of a Decision Refusing Registration', 'zh' => '商标不予注册复审申请',
                'price' => 299, 'unit_en' => '', 'unit_zh' => '',
                'note_en' => 'Cases involving substantial evidence or complex grounds are quoted by agreement.',
                'note_zh' => '案件较为复杂、证据材料较多者，双方协商定价。',
                'negotiable' => true, 'group' => 'challenge', 'needs_reg_no' => true, 'needs_classes' => false,
                'official_fee_cny' => 675,
            ],
            'review_cancellation' => [
                'en' => 'Review of Cancellation of a Registered Trademark', 'zh' => '撤销注册商标复审申请',
                'price' => 299, 'unit_en' => '', 'unit_zh' => '',
                'note_en' => 'Cases involving substantial evidence or complex grounds are quoted by agreement.',
                'note_zh' => '案件较为复杂、证据材料较多者，双方协商定价。',
                'negotiable' => true, 'group' => 'challenge', 'needs_reg_no' => true, 'needs_classes' => false,
                'official_fee_cny' => 675,
            ],
            'review_invalidation' => [
                'en' => 'Review of Invalidation of a Registered Trademark', 'zh' => '注册商标无效宣告复审申请',
                'price' => 299, 'unit_en' => '', 'unit_zh' => '',
                'note_en' => 'Cases involving substantial evidence or complex grounds are quoted by agreement.',
                'note_zh' => '案件较为复杂、证据材料较多者，双方协商定价。',
                'negotiable' => true, 'group' => 'challenge', 'needs_reg_no' => true, 'needs_classes' => false,
                'official_fee_cny' => 675,
            ],
            'cancel_nonuse' => [
                'en' => 'Cancellation of a Registered Trademark for Non-use for Three Consecutive Years', 'zh' => '撤销连续三年不使用注册商标申请',
                'price' => 199, 'unit_en' => '', 'unit_zh' => '',
                'note_en' => '', 'note_zh' => '',
                'negotiable' => true, 'group' => 'challenge', 'needs_reg_no' => true, 'needs_classes' => false,
                'official_fee_cny' => 450,
            ],
            'cancel_generic' => [
                'en' => 'Cancellation of a Registered Trademark that has Become a Generic Name', 'zh' => '撤销成为商品/服务通用名称注册商标申请',
                'price' => 599, 'unit_en' => '', 'unit_zh' => '',
                'note_en' => 'Cases involving substantial evidence or complex grounds are quoted by agreement.',
                'note_zh' => '案件较为复杂、证据材料较多者，双方协商定价。',
                'negotiable' => true, 'group' => 'challenge', 'needs_reg_no' => true, 'needs_classes' => false,
                'official_fee_cny' => 450,
            ],

            /* ---------- 检索、监测、翻译与保护 ---------- */
            'search' => [
                'en' => 'Trademark Search & Analysis', 'zh' => '商标检索与分析',
                'price' => 0, 'unit_en' => '', 'unit_zh' => '',
                'note_en' => 'Free search and objective risk assessment, with no obligation to register.',
                'note_zh' => '免费检索并客观评估风险，不注册也不收费。',
                'negotiable' => false, 'group' => 'other', 'needs_reg_no' => false, 'needs_classes' => true,
                'official_fee_cny' => 0,
            ],
            'monitoring' => [
                'en' => 'Trademark Monitoring / Watch Service', 'zh' => '商标监测服务',
                'price' => null, 'unit_en' => '', 'unit_zh' => '',
                'note_en' => 'Quoted by agreement based on monitoring scope and period.',
                'note_zh' => '根据监测范围与周期协商定价。',
                'negotiable' => true, 'group' => 'other', 'needs_reg_no' => false, 'needs_classes' => false,
                'official_fee_cny' => 0,
            ],
            'translation' => [
                'en' => 'Professional Translation', 'zh' => '专业翻译',
                'price' => 30, 'unit_en' => 'per set of standard documents', 'unit_zh' => '每套标准文件',
                'note_en' => 'Long or highly technical documents are quoted separately.',
                'note_zh' => '超长或高难度文件另报实价。',
                'negotiable' => false, 'group' => 'other', 'needs_reg_no' => false, 'needs_classes' => false,
                'official_fee_cny' => 0,
            ],
            'protection' => [
                'en' => 'Trademark Protection & Enforcement', 'zh' => '商标保护与维权',
                'price' => null, 'unit_en' => '', 'unit_zh' => '',
                'note_en' => 'Quoted by agreement based on case complexity.',
                'note_zh' => '根据案件复杂程度协商定价。',
                'negotiable' => true, 'group' => 'other', 'needs_reg_no' => false, 'needs_classes' => false,
                'official_fee_cny' => 0,
            ],
        ];

        // Subsequent service fees count each trademark/class combination, not numbers alone.
        foreach ($list as &$item) {
            if (!empty($item['needs_reg_no'])) {
                $item['unit_en'] = 'per trademark, per class';
                $item['unit_zh'] = '每件商标每类';
            }
        }
        unset($item);
        return $list;
    }
}

/** 分组定义 */
if (!function_exists('svc_groups')) {
    function svc_groups()
    {
        return [
            'filing'    => ['en' => 'Filing & Registration', 'zh' => '注册申请'],
            'change'    => ['en' => 'Changes, Assignment & Correction', 'zh' => '变更、转让与更正'],
            'maintain'  => ['en' => 'Renewal, Cancellation & Certificates', 'zh' => '续展、注销与证明'],
            'licence'   => ['en' => 'Licence Recordal & Pledge', 'zh' => '许可备案与质押'],
            'challenge' => ['en' => 'Opposition, Review & Cancellation Actions', 'zh' => '异议、复审与撤销'],
            'other'     => ['en' => 'Search, Monitoring, Translation & Protection', 'zh' => '检索、监测、翻译与保护'],
        ];
    }
}

/** 取单项；不存在返回 null */
if (!function_exists('svc_one')) {
    function svc_one($key)
    {
        $all = svc_all();
        return isset($all[$key]) ? $all[$key] : null;
    }
}

/** 取某分组下的全部项（保持定义顺序） */
if (!function_exists('svc_by_group')) {
    function svc_by_group($group)
    {
        $out = [];
        foreach (svc_all() as $key => $item) {
            if ($item['group'] === $group) $out[$key] = $item;
        }
        return $out;
    }
}

/** 价格文案：$199 / class；无价则 Quote on request */
if (!function_exists('svc_price_text')) {
    function svc_price_text($item)
    {
        if ($item['price'] === null) return 'Quote on request';
        if ((float)$item['price'] === 0.0) return 'Free';
        $t = 'USD ' . number_format((float)$item['price'], 0);
        if (!empty($item['unit_en'])) $t .= ' / ' . $item['unit_en'];
        return $t;
    }
}

/**
 * 询价表单形态（决定客户要填什么）
 *   classes = 新申请类：需要选类别 / 商品项，并填商标名称、类型、图样
 *   regno   = 后续业务：已有商标，只需注册号（或申请号）+ 联系方式
 *   simple  = 既无在先号码也无类别：只需商标名称 + 联系方式
 */
if (!function_exists('svc_mode')) {
    function svc_mode($item)
    {
        if (!$item) return 'classes';
        if (!empty($item['needs_classes'])) return 'classes';
        if (!empty($item['needs_reg_no']))  return 'regno';
        return 'simple';
    }
}

/**
 * 按服务计算预估价（USD）。返回 null 表示"协商定价 / 待报价"。
 * $byClassCounts = ['09' => 12, '25' => 8] 仅 classes 形态使用。
 */
if (!function_exists('svc_estimate')) {
    function svc_estimate($item, $byClassCounts = [])
    {
        if (!$item || $item['price'] === null) return null;
        if (svc_mode($item) !== 'classes') return (float)$item['price'];

        $total = 0.0;
        foreach ($byClassCounts as $n) {
            $total += (float)$item['price'] + max(0, (int)$n - 10) * 10;
        }
        return count($byClassCounts) ? $total : null;
    }
}

/**
 * 把 classes_json 的两种历史结构归一化成 [类别号 => [商品项, ...]]（2026-09-29）。
 *
 * 存在两种写入方、两种结构：
 *  A) 前台询价（enquiry_submit.php），扁平——每个元素是一个商品项：
 *     [{cls:"009", grp:"...", code:"...", name:"电动汽车", en:"Electric vehicles"}, ...]
 *  B) 后台手工建单/建工单（order_new.php / enquiry_new.php），嵌套——每个元素是一个类别：
 *     [{class:9, heading:"...", items:[{code,name,en} | "商品名", ...]}, ...]
 *
 * 此前三处消费点（account.php / admin/order.php / admin/enquiry.php）各认一种键名，
 * 导致前台询价的数据在订单详情里全部显示 "Class 0"。
 *
 * @return array [ 9 => [['name'=>'电动汽车','en'=>'Electric vehicles'], ...], ... ] 按类别号升序
 */
if (!function_exists('classes_normalize')) {
    function classes_normalize($raw)
    {
        $data = is_array($raw) ? $raw : json_decode((string)$raw, true);
        if (!is_array($data)) return [];

        $out = [];
        foreach ($data as $c) {
            if (!is_array($c)) continue;

            if (array_key_exists('cls', $c)) {
                // 结构 A：扁平商品项
                $cls = (int)preg_replace('/\D/', '', (string)$c['cls']);
                if ($cls <= 0) continue;
                $nm = trim((string)($c['name'] ?? ''));
                if ($nm === '' && trim((string)($c['en'] ?? '')) === '') continue;
                $out[$cls][] = [
                    'grp' => trim((string)($c['grp'] ?? $c['group'] ?? '')),
                    'grp_name' => trim((string)($c['grp_name'] ?? $c['group_name'] ?? '')),
                    'code' => trim((string)($c['code'] ?? '')),
                    'name' => $nm,
                    'en' => trim((string)($c['en'] ?? '')),
                ];
            } else {
                // 结构 B：类别 + 嵌套商品
                $cls = (int)preg_replace('/\D/', '', (string)($c['class'] ?? ''));
                if ($cls <= 0) continue;
                if (!empty($c['items']) && is_array($c['items'])) {
                    foreach ($c['items'] as $it) {
                        if (is_array($it)) {
                            $nm = trim((string)($it['name'] ?? ''));
                            $en = trim((string)($it['en'] ?? ''));
                            $grp = trim((string)($it['grp'] ?? $it['group'] ?? ''));
                            $grpName = trim((string)($it['grp_name'] ?? $it['group_name'] ?? ''));
                            $code = trim((string)($it['code'] ?? ''));
                        } else {
                            $nm = trim((string)$it);
                            $en = '';
                            $grp = $grpName = $code = '';
                        }
                        if ($nm !== '' || $en !== '') $out[$cls][] = [
                            'grp' => $grp, 'grp_name' => $grpName, 'code' => $code,
                            'name' => $nm, 'en' => $en,
                        ];
                    }
                }
                if (empty($out[$cls])) {
                    $hd = trim((string)($c['heading'] ?? ''));
                    if ($hd !== '') $out[$cls][] = ['grp' => '', 'grp_name' => '', 'code' => '', 'name' => $hd, 'en' => ''];
                }
            }
        }
        foreach ($out as &$items) {
            $hasGroups = false;
            foreach ($items as $item) {
                if ($item['grp'] !== '') { $hasGroups = true; break; }
            }
            if ($hasGroups) {
                usort($items, function ($a, $b) {
                    return strnatcmp($a['grp'], $b['grp'])
                        ?: strnatcmp($a['code'], $b['code'])
                        ?: strcmp($a['name'], $b['name']);
                });
            }
        }
        unset($items);
        ksort($out);
        return $out;
    }
}
