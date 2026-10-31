<?php
// ---------------------------------------------------------------------------
// 国家型落地页共享模板（2026-09-30）
//
// 【服务谁】
//   /china-trademark-us/  /china-trademark-uk/  /china-trademark-eu/
//   /china-trademark-japan/  /china-trademark-australia/
//   五个薄壳 index.php 各带一份 $C 配置数组，require 本文件出整页。
//
// 【为什么放 app/lib/】
//   nginx 对 /app/lib/ 是 deny all（deploy/baota-rewrite.conf），模板本体
//   永远不会被当 URL 直接访问到；PHP include 走磁盘路径，不受影响。
//   这和 pricing.php 的处境一致。
//
// 【防门页（doorway）设计】
//   五页共用骨架，但每页必须有真实的差异化内容，否则就是 Google 明令
//   打击的门页。本模板靠 $C 的这些字段制造差异：
//     office_short/office_long —— 各自的商标局（USPTO/UKIPO/EUIPO/JPO/IP Australia）
//     属地原则段落、优先权起算局、马德里路径的来源局都跟着变
//     audience / h1_suffix / example —— 人群与举例完全按国家改写
//     priority_q / madrid_q —— 各国 FAQ 里两条国别问题
//   骨架段（包含内容/费用/通用 FAQ）与 /china-trademark-registration/
//   保持同一口径，价格全部走 svc_one('registration') 单一来源。
//
// 【本文件不是页面】
//   直接用 URL 访问不到（nginx deny）；就算被 PHP 直接 require 而没给 $C，
//   下面第一件事就是报错退出，不会吐半页。
// ---------------------------------------------------------------------------

if (!isset($C) || !is_array($C)) {
    http_response_code(500);
    exit('landing_country.php requires a $C config array');
}

/** 必填字段缺一不可，缺了 fail fast（部署期就会暴露，不会带病上线） */
foreach (['dir', 'h1_suffix', 'title', 'meta', 'audience', 'audience_h1', 'audience_short',
          'from_country', 'office_short', 'office_long', 'home_rights', 'example',
          'home_cover', 'priority_q', 'priority_a', 'madrid_q', 'madrid_a',
          'docs_lead', 'docs_list', 'docs_notary_txt'] as $__k) {
    if (!isset($C[$__k])) {
        http_response_code(500);
        exit("landing_country.php: missing \$C['{$__k}']");
    }
}

// 2026-09-30：材料清单板块的国别差异校验。
// 【为什么必须校验】五页共用骨架，若 docs_list 内容一致，就是 Google 明令
// 打击的门页（doorway）——相同内容换国名。本板块恰恰是差异化的主力段，
// 所以这里强制：每个清单至少 3 项、且每项都要有 name 与 desc。
// 只做结构校验，不做跨页文本比对（跨页比对放在部署前的自查清单里）。
if (!is_array($C['docs_list']) || count($C['docs_list']) < 3) {
    http_response_code(500);
    exit("landing_country.php: \$C['docs_list'] must be an array of at least 3 items");
}
foreach ($C['docs_list'] as $__i => $__d) {
    if (!isset($__d['name'], $__d['desc'])) {
        http_response_code(500);
        exit("landing_country.php: \$C['docs_list'][{$__i}] needs 'name' and 'desc'");
    }
}

// docs_notary_ok 缺省为 true（主流市场口径）；中东等要求认证的市场显式传 false。
// docs_notary_title 允许薄壳页覆盖标题：日本信源冲突未定论（见 DOCS-REQUIRED-BY-COUNTRY.md
// 注 1），标题就不能写死 "No notarization"，改用温和表述，由正文补充我方代查承诺。
$docsNotaryOk = !isset($C['docs_notary_ok']) || (bool) $C['docs_notary_ok'];
$docsNotaryTitle = $C['docs_notary_title'] ?? ($docsNotaryOk
    ? 'No notarization or consular legalization'
    : 'Notarization and legalization are required');

$pageTitle       = $C['title'];
$metaDescription = $C['meta'];
$page            = 'registration-' . $C['dir'];   // 不对应任何导航项 → 无高亮

// canonical：header.php 默认逻辑在子目录下会算错，必须手写
$canonicalUrl = 'https://example.com/' . $C['dir'] . '/';

// Service + Offer：每页独立 @id，provider 挂全站 Organization
$jsonLdExtra = '<script type="application/ld+json">' . str_replace('</', '<\/', json_encode([
    '@context'    => 'https://schema.org',
    '@type'       => 'Service',
    '@id'         => 'https://example.com/' . $C['dir'] . '/#service',
    'name'        => 'China Trademark Registration ' . $C['h1_suffix'],
    'serviceType' => 'Trademark registration filing with the China National Intellectual Property Administration (CNIPA)',
    'url'         => $canonicalUrl,
    'description' => 'Filing a China trademark application for ' . $C['audience'] . ', handled in English by a CNIPA-registered agent.',
    'provider'    => ['@id' => 'https://example.com/#organization'],
    'areaServed'  => [['@type' => 'Country', 'name' => 'China']],
    'availableChannel' => [
        '@type'             => 'ServiceChannel',
        'serviceUrl'        => 'https://portal.example.com/?page=support&intent=quote&service=registration',
        'availableLanguage' => ['English', 'Chinese'],
    ],
    'offers' => [
        '@type'         => 'Offer',
        'price'         => '199',
        'priceCurrency' => 'USD',
        'description'   => 'Per class, including up to 10 items of goods or services.',
        'url'           => 'https://portal.example.com/?page=support&intent=quote&service=registration',
        'availability'  => 'https://schema.org/InStock',
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</script>';

// 页面 URL 在站点根下一层，与 /china-trademark-registration/ 相同
$rootPrefix = '../';

// FAQ 渲染后抽取生成 FAQPage，与 faq.php / 注册落地页同一套做法
ob_start();

// 2026-09-30：三处 require 一律走绝对路径。__DIR__ 拼接里的 `..` 会被
// 宝塔 open_basedir 在规范化前按字符串前缀拦掉（详见各薄壳页顶部说明）。
// 本模板位于 app/lib/ 下，站点根 = dirname(__DIR__, 2)。
$__siteRoot = rtrim(isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : '', '/\\');
if ($__siteRoot === '' || !is_file($__siteRoot . '/includes/header.php')) {
    $__siteRoot = dirname(__DIR__, 2);
}

// 2026-10-14：hreflang 双向标注（仅在该页存在西语版本时输出）。
// 薄壳页可选传 $C['es_url']（西语页绝对地址）；不传则不输出任何标签，
// 现有各国页的 <head> 一个字节都不会变。西语页那边也各自声明了 en/es/x-default。
if (!empty($C['es_url'])) {
    $__esUrl = htmlspecialchars((string) $C['es_url'], ENT_QUOTES, 'UTF-8');
    $headExtra = (isset($headExtra) ? $headExtra : '')
        . '<link rel="alternate" hreflang="es" href="' . $__esUrl . '">' . "\n"
        . '<link rel="alternate" hreflang="en" href="' . $canonicalUrl . '">' . "\n"
        . '<link rel="alternate" hreflang="x-default" href="' . $canonicalUrl . '">' . "\n";
}

require_once $__siteRoot . '/includes/header.php';
require_once $__siteRoot . '/app/lib/pricing.php';

// 快速可用性查询表单：五国落地页与首页共用同一份渲染与提交脚本。
require_once $__siteRoot . '/app/lib/quick_check_form.php';

$regItem  = svc_one('registration');
$regPrice = ($regItem && $regItem['price'] !== null) ? (float) $regItem['price'] : 199;
$regUnit  = ($regItem && !empty($regItem['unit_en'])) ? $regItem['unit_en'] : 'per class';
$regNote  = ($regItem && !empty($regItem['note_en'])) ? $regItem['note_en'] : '';
// 2026-09-30：删掉 $regFee。官费（official_fee_cny）是内部成本核算字段，
// 按 pricing.php 的口径【不得输出到任何前端页面】，此前本页把它渲染成了
// "CNY 270 per class"（含费用表行与 FAQ 答案），与全站"全包价、官费不另列"的
// 对外口径直接矛盾（faq.php:231 / terms.php:302,327,448 / mailer.php:369 /
// lang/en.php:134 均为全包口径）。此处一并对齐，不再输出官费金额。

/**
 * 国别切换器数据（本页自身不出现）。
 * 2026-10-13：加入西班牙与危地马拉 —— 用户给出真实客源分布为
 * 美国 / 西班牙 / 危地马拉，这两国此前无专页。切换器同时也是各国页之间的互链入口。
 */
$SIBLINGS = [
    ['dir' => 'china-trademark-us',         'name' => 'United States', 'flag' => 'US'],
    ['dir' => 'china-trademark-spain',      'name' => 'Spain',         'flag' => 'ES'],
    ['dir' => 'china-trademark-guatemala',  'name' => 'Guatemala',     'flag' => 'GT'],
    ['dir' => 'china-trademark-uk',         'name' => 'United Kingdom', 'flag' => 'UK'],
    ['dir' => 'china-trademark-eu',         'name' => 'European Union', 'flag' => 'EU'],
    ['dir' => 'china-trademark-japan',      'name' => 'Japan', 'flag' => 'JP'],
    ['dir' => 'china-trademark-australia',  'name' => 'Australia', 'flag' => 'AU'],
];
?>
<style>
    .page-banner {
        padding: 140px 0 80px;
        text-align: center;
        position: relative;
        overflow: hidden;
        background: linear-gradient(135deg, rgba(102, 126, 234, 0.08) 0%, rgba(118, 75, 162, 0.05) 100%);
    }

    .page-banner::before {
        content: '';
        position: absolute;
        top: -30%;
        right: -20%;
        width: 600px;
        height: 600px;
        background: radial-gradient(circle, rgba(102, 126, 234, 0.15) 0%, transparent 60%);
        border-radius: 50%;
    }

    .page-banner h1 {
        font-size: 44px;
        font-weight: 800;
        color: var(--text-dark);
        margin-bottom: 16px;
        line-height: 1.25;
        position: relative;
        z-index: 1;
    }

    .page-banner h1 .gradient-text {
        background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    .page-banner p {
        font-size: 18px;
        color: var(--text-gray);
        max-width: 760px;
        margin: 0 auto;
        line-height: 1.7;
        position: relative;
        z-index: 1;
    }

    .reg-price-bar {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 28px;
        flex-wrap: wrap;
        margin-top: 32px;
        position: relative;
        z-index: 1;
    }

    .reg-price-tag {
        display: flex;
        align-items: baseline;
        gap: 8px;
    }

    .reg-price-tag .amount {
        font-size: 42px;
        font-weight: 800;
        color: var(--primary);
        line-height: 1;
    }

    .reg-price-tag .unit {
        font-size: 15px;
        color: var(--text-gray);
        font-weight: 600;
    }

    .reg-facts {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 20px;
        max-width: 980px;
        margin: 40px auto 0;
        position: relative;
        z-index: 1;
    }

    .reg-fact {
        background: white;
        border-radius: 14px;
        padding: 22px 16px;
        box-shadow: var(--shadow);
        text-align: center;
    }

    .reg-fact .fact-value {
        font-size: 22px;
        font-weight: 800;
        color: var(--text-dark);
        margin-bottom: 6px;
    }

    .reg-fact .fact-label {
        font-size: 13px;
        color: var(--text-gray);
        line-height: 1.5;
    }

    .reg-container {
        max-width: 900px;
        margin: 0 auto;
        padding: 0 24px;
    }

    .reg-prose h2 {
        font-size: 30px;
        font-weight: 800;
        color: var(--text-dark);
        margin: 0 0 20px;
        line-height: 1.3;
    }

    .reg-prose h3 {
        font-size: 20px;
        font-weight: 700;
        color: var(--text-dark);
        margin: 32px 0 12px;
    }

    .reg-prose p {
        font-size: 16.5px;
        color: #4b5563;
        line-height: 1.85;
        margin: 0 0 18px;
    }

    .reg-prose ul {
        margin: 0 0 18px;
        padding-left: 22px;
    }

    .reg-prose li {
        font-size: 16.5px;
        color: #4b5563;
        line-height: 1.85;
        margin-bottom: 10px;
    }

    .reg-prose a {
        color: var(--primary);
        font-weight: 600;
        text-decoration: none;
        border-bottom: 1px solid rgba(102, 126, 234, 0.35);
    }

    .reg-prose a:hover {
        border-bottom-color: var(--primary);
    }

    /* 两条申请路线对比卡 */
    .route-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 20px;
        margin: 8px 0 24px;
    }

    .route-card {
        background: white;
        border-radius: 14px;
        padding: 26px;
        box-shadow: var(--shadow);
        position: relative;
    }

    .route-card.recommended {
        border-top: 3px solid var(--primary);
    }

    .route-card h3 {
        font-size: 18px;
        font-weight: 800;
        color: var(--text-dark);
        margin: 0 0 6px;
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }

    .route-badge {
        font-size: 11.5px;
        font-weight: 700;
        color: white;
        background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
        border-radius: 999px;
        padding: 3px 10px;
    }

    .route-card .route-sub {
        font-size: 14px;
        color: var(--text-gray);
        margin: 0 0 14px;
    }

    .route-card ul {
        margin: 0;
        padding-left: 20px;
        list-style: none;
    }

    .route-card li {
        font-size: 15px;
        color: #4b5563;
        line-height: 1.75;
        margin-bottom: 9px;
        padding-left: 4px;
        position: relative;
    }

    .route-card li::before {
        content: '';
        position: absolute;
        left: -16px;
        top: 9px;
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: rgba(102, 126, 234, 0.55);
    }

    /* 包含内容清单 */
    .reg-included {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 18px;
        margin: 8px 0 24px;
    }

    .reg-included-item {
        display: flex;
        gap: 14px;
        align-items: flex-start;
        background: white;
        border-radius: 14px;
        padding: 20px 22px;
        box-shadow: var(--shadow);
    }

    .reg-included-item i {
        color: #16A34A;
        font-size: 17px;
        margin-top: 3px;
        flex-shrink: 0;
    }

    .reg-included-item .txt strong {
        display: block;
        font-size: 16px;
        color: var(--text-dark);
        margin-bottom: 4px;
    }

    .reg-included-item .txt span {
        font-size: 14.5px;
        color: var(--text-gray);
        line-height: 1.65;
    }

    .reg-note {
        font-size: 14px;
        color: var(--text-gray);
        line-height: 1.7;
        background: #f8f9fc;
        border-left: 3px solid var(--primary);
        border-radius: 0 10px 10px 0;
        padding: 16px 20px;
        margin-bottom: 24px;
    }

    /* 材料清单（Documents you need to provide） */
    .reg-docs-intro {
        font-size: 16.5px;
        color: #4b5563;
        line-height: 1.85;
        margin: 0 0 22px;
    }

    .reg-docs {
        display: flex;
        flex-direction: column;
        gap: 14px;
        margin: 0 0 20px;
    }

    .reg-doc {
        display: flex;
        gap: 16px;
        align-items: flex-start;
        background: white;
        border-radius: 14px;
        padding: 20px 24px;
        box-shadow: var(--shadow);
    }

    .reg-doc .doc-num {
        flex-shrink: 0;
        width: 30px;
        height: 30px;
        border-radius: 9px;
        background: linear-gradient(135deg, rgba(102, 126, 234, 0.14) 0%, rgba(118, 75, 162, 0.1) 100%);
        color: var(--primary);
        font-size: 14px;
        font-weight: 800;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-top: 2px;
    }

    .reg-doc .doc-body {
        flex: 1;
        min-width: 0;
    }

    .reg-doc .doc-name {
        font-size: 16px;
        font-weight: 700;
        color: var(--text-dark);
        margin: 0 0 4px;
        line-height: 1.5;
    }

    .reg-doc .doc-name .doc-alt {
        font-weight: 600;
        color: var(--text-gray);
        font-size: 14.5px;
    }

    .reg-doc .doc-desc {
        font-size: 15px;
        color: var(--text-gray);
        line-height: 1.7;
        margin: 0;
    }

    .reg-doc .doc-tag {
        display: inline-block;
        font-size: 11.5px;
        font-weight: 700;
        border-radius: 999px;
        padding: 2px 9px;
        margin-left: 8px;
        vertical-align: 2px;
        white-space: nowrap;
    }

    .doc-tag.tag-us {
        background: rgba(22, 163, 74, 0.12);
        color: #15803D;
    }

    .doc-tag.tag-them {
        background: rgba(102, 126, 234, 0.14);
        color: var(--primary);
    }

    .reg-docs-notary {
        display: flex;
        gap: 14px;
        align-items: flex-start;
        background: white;
        border-radius: 14px;
        border-left: 3px solid #16A34A;
        padding: 20px 24px;
        box-shadow: var(--shadow);
        margin-bottom: 24px;
    }

    .reg-docs-notary.doc-warn {
        border-left-color: #D97706;
    }

    .reg-docs-notary i {
        color: #16A34A;
        font-size: 18px;
        margin-top: 2px;
        flex-shrink: 0;
    }

    .reg-docs-notary.doc-warn i {
        color: #D97706;
    }

    .reg-docs-notary .notary-body > strong {
        display: block;
        font-size: 16px;
        color: var(--text-dark);
        margin-bottom: 6px;
    }

    .reg-docs-notary .notary-body span {
        font-size: 15px;
        color: var(--text-gray);
        line-height: 1.75;
    }

    /* 费用表 */
    .reg-fee-table {
        width: 100%;
        border-collapse: collapse;
        background: white;
        border-radius: 14px;
        overflow: hidden;
        box-shadow: var(--shadow);
        margin: 8px 0 20px;
    }

    .reg-fee-table th,
    .reg-fee-table td {
        padding: 16px 20px;
        text-align: left;
        font-size: 15.5px;
        border-bottom: 1px solid #eef1f8;
    }

    .reg-fee-table th {
        background: #f8f9fc;
        font-weight: 700;
        color: var(--text-dark);
    }

    .reg-fee-table td {
        color: #4b5563;
    }

    .reg-fee-table tr:last-child td {
        border-bottom: none;
    }

    .reg-fee-table .fee-strong {
        font-weight: 700;
        color: var(--text-dark);
    }

    /* FAQ */
    .reg-faq-item {
        background: white;
        border-radius: 14px;
        margin-bottom: 16px;
        box-shadow: var(--shadow);
        overflow: hidden;
    }

    .reg-faq-question {
        padding: 22px 28px;
        cursor: pointer;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 17px;
        font-weight: 700;
        color: var(--text-dark);
        gap: 16px;
    }

    .reg-faq-question i {
        color: var(--primary);
        font-size: 14px;
        transition: transform 0.3s ease;
        flex-shrink: 0;
    }

    .reg-faq-item.active .reg-faq-question i {
        transform: rotate(180deg);
    }

    .reg-faq-answer {
        max-height: 0;
        overflow: hidden;
        transition: max-height 0.35s ease;
    }

    .reg-faq-item.active .reg-faq-answer {
        max-height: 700px;
    }

    .reg-faq-answer-content {
        padding: 0 28px 24px;
        font-size: 15.5px;
        color: #4b5563;
        line-height: 1.85;
    }

    /* 国家切换器 */
    .country-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 18px;
        margin: 8px 0 0;
    }

    .country-card {
        display: flex;
        align-items: center;
        gap: 14px;
        background: white;
        border-radius: 14px;
        padding: 18px 20px;
        box-shadow: var(--shadow);
        text-decoration: none;
        transition: transform 0.25s ease, box-shadow 0.25s ease;
    }

    .country-card:hover {
        transform: translateY(-3px);
        box-shadow: var(--shadow-lg);
    }

    .country-flag {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        background: linear-gradient(135deg, rgba(102, 126, 234, 0.14) 0%, rgba(118, 75, 162, 0.1) 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 13px;
        font-weight: 800;
        color: var(--primary);
        flex-shrink: 0;
    }

    .country-card strong {
        display: block;
        font-size: 15.5px;
        font-weight: 700;
        color: var(--text-dark);
    }

    .country-card span {
        font-size: 13px;
        color: var(--text-gray);
    }

    @media (max-width: 900px) {
        .reg-facts {
            grid-template-columns: repeat(2, 1fr);
        }

        .country-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 768px) {
        .page-banner {
            padding: 110px 0 60px;
        }

        .page-banner h1 {
            font-size: 30px;
        }

        .page-banner p {
            font-size: 16px;
        }

        .reg-price-tag .amount {
            font-size: 34px;
        }

        .reg-included,
        .route-grid {
            grid-template-columns: 1fr;
        }

        .country-grid {
            grid-template-columns: 1fr;
        }

        .reg-prose h2 {
            font-size: 24px;
        }

        .reg-fee-table th,
        .reg-fee-table td {
            padding: 13px 14px;
            font-size: 14.5px;
        }
    }
    <?php quick_check_form_css(); ?>
</style>

<!-- Page Banner -->
<section class="page-banner">
    <div class="section-container">
        <h1>China Trademark Registration <span class="gradient-text"><?php echo htmlspecialchars($C['h1_suffix'], ENT_QUOTES, 'UTF-8'); ?></span></h1>
        <p>File your China trademark from <?php echo htmlspecialchars($C['from_country'] ?? 'abroad', ENT_QUOTES, 'UTF-8'); ?> &mdash; in English, at a fixed price, with no Chinese entity required and a CNIPA-registered agent handling everything.</p>

        <div class="reg-price-bar">
            <div class="reg-price-tag">
                <span class="amount">$<?php echo number_format($regPrice, 0); ?></span>
                <span class="unit"><?php echo htmlspecialchars($regUnit, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <a href="https://portal.example.com/?page=new&amp;service=registration" class="btn btn-primary">
                <i class="fas fa-paper-plane"></i> Start My Application
            </a>
        </div>

        <div class="reg-facts">
            <div class="reg-fact">
                <div class="fact-value">1 day</div>
                <div class="fact-label">To file once you approve</div>
            </div>
            <div class="reg-fact">
                <div class="fact-value">7&ndash;9 mo</div>
                <div class="fact-label">Typical time to registration; statutory max 12&ndash;15 mo</div>
            </div>
            <div class="reg-fact">
                <div class="fact-value">10 years</div>
                <div class="fact-label">Protection from the registration date</div>
            </div>
            <div class="reg-fact">
                <div class="fact-value">No entity</div>
                <div class="fact-label">No Chinese company or address needed</div>
            </div>
        </div>
    </div>
</section>

<!-- Why file from <country> -->
<section class="section section-white">
    <div class="reg-container reg-prose">
        <h2>Why <?php echo htmlspecialchars($C['audience_h1'] ?? $C['audience'], ENT_QUOTES, 'UTF-8'); ?> still need a Chinese registration</h2>
        <p><?php echo $C['home_rights']; // 模板内含 <strong>，由各薄壳页审定的静态 HTML ?></p>
        <p>On top of that, China is a <strong>first-to-file</strong> jurisdiction: the registration generally goes to whoever files first with the China National Intellectual Property Administration (CNIPA), regardless of who used the mark first elsewhere. <?php echo $C['example']; ?></p>
        <p>Foreign applicants cannot file directly &mdash; the application must go through a CNIPA-registered agent, which is what we are. You do not need a Chinese company, a Chinese address or a Chinese bank account. Everything on your side stays in English; we prepare the Chinese filing.</p>
    </div>
</section>

<!-- Two routes -->
<section class="section section-white" style="background: #f8f9fc;">
    <div class="reg-container reg-prose">
        <h2>Two routes to a China trademark from <?php echo htmlspecialchars($C['from_country'] ?? 'abroad', ENT_QUOTES, 'UTF-8'); ?></h2>
        <div class="route-grid">
            <div class="route-card recommended">
                <h3><i class="fas fa-file-signature" style="color: var(--primary);"></i> Direct national filing <span class="route-badge">What we handle</span></h3>
                <p class="route-sub">We file with the CNIPA on your behalf as your registered agent.</p>
                <ul>
                    <li><strong>$<?php echo number_format($regPrice, 0); ?> flat per class</strong>, including the availability check, drafting, filing and follow-up</li>
                    <li>Goods and services wording is drafted freely within the Nice class &mdash; not locked to another application</li>
                    <li>No dependency on any other mark: nothing that happens to a home registration can touch it</li>
                    <li>One point of contact in English for the whole file</li>
                </ul>
            </div>
            <div class="route-card">
                <h3><i class="fas fa-globe" style="color: var(--text-gray);"></i> Madrid Protocol <span class="route-badge" style="background: #94A3B8;">Possible, with trade-offs</span></h3>
                <p class="route-sub">Extend an international registration to China via <?php echo htmlspecialchars($C['office_short'], ENT_QUOTES, 'UTF-8'); ?> and WIPO.</p>
                <ul>
                    <li>Requires an existing application or registration at <?php echo htmlspecialchars($C['office_short'], ENT_QUOTES, 'UTF-8'); ?> for the <em>same</em> mark and goods</li>
                    <li>For five years the China right depends on the home mark &mdash; if it fails, the international registration collapses (central attack)</li>
                    <li>If the CNIPA issues a provisional refusal, you need a local agent to answer it anyway</li>
                    <li>Fees go to WIPO plus an individual designation fee, and are harder to predict per country</li>
                </ul>
            </div>
        </div>
        <p>Both routes end at the same register. Direct filing is usually the simpler choice when China is the priority market; Madrid earns its keep when you are extending one mark across many countries at once. If you are unsure which fits, tell us your markets in the enquiry and we will say so in writing before anything is payable.</p>
    </div>
</section>

<!-- Priority claim -->
<section class="section section-white">
    <div class="reg-container reg-prose">
        <h2>Six-month Paris priority from your <?php echo htmlspecialchars($C['office_short'], ENT_QUOTES, 'UTF-8'); ?> filing</h2>
        <p>If you have already filed at <?php echo htmlspecialchars($C['office_long'], ENT_QUOTES, 'UTF-8'); ?> (or at any other Paris Convention or WTO member office), you can claim <strong>priority</strong> for the Chinese application: file in China within <strong>six months</strong> of the first filing, and the Chinese application is treated as if it were filed on the same day as the original.</p>
        <ul>
            <li>The priority claim is declared when the Chinese application is filed, and the certified copy of the first filing follows within three months</li>
            <li>Priority matters most where a squatter risk is live: it moves your effective filing date back before third-party applications</li>
            <li>The claim covers what the first filing covers &mdash; goods or classes added later are not covered by it</li>
        </ul>
        <p>Mention your <?php echo htmlspecialchars($C['office_short'], ENT_QUOTES, 'UTF-8'); ?> filing date in the enquiry and we will flag whether priority is worth claiming in your case.</p>
    </div>
</section>

<?php /* 2026-10-13：可选的「本国视角深谈」区块（国家页去重用）。
   五页共享模板此前贡献了 82–84% 的词汇（实测），每页只有 16–18% 独有内容。
   各页通过 $C['country_deep'] 传入本国独有的实质内容（h2 + 2–4 段），
   不传该键时本区块整段不渲染，五页现有输出一个字不变。 */ ?>
<?php if (!empty($C['country_deep'])): ?>
<section class="section section-white">
    <div class="reg-container reg-prose">
        <h2><?php echo $C['country_deep']['h2']; ?></h2>
        <?php foreach ($C['country_deep']['paras'] as $__p): ?>
        <p><?php echo $__p; ?></p>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<!-- What's included -->
<section class="section section-white" style="background: #f8f9fc;">
    <div class="reg-container">
        <h2 style="font-size: 30px; font-weight: 800; color: var(--text-dark); margin: 0 0 24px;">What the $<?php echo number_format($regPrice, 0); ?> fee covers</h2>
        <div class="reg-included">
            <div class="reg-included-item">
                <i class="fas fa-check-circle"></i>
                <div class="txt">
                    <strong>Availability check before filing</strong>
                    <span>We search the CNIPA register for identical and confusingly similar marks in your class, so you are not filing into a known conflict.</span>
                </div>
            </div>
            <div class="reg-included-item">
                <i class="fas fa-check-circle"></i>
                <div class="txt">
                    <strong>Application drafted and filed</strong>
                    <span>The application form, the goods or services wording and the mark specimen, prepared in Chinese and submitted in your name.</span>
                </div>
            </div>
            <div class="reg-included-item">
                <i class="fas fa-check-circle"></i>
                <div class="txt">
                    <strong>Up to 10 goods or services items</strong>
                    <span>Per class. Additional items are charged at USD 10 each, and each extra class is charged separately.</span>
                </div>
            </div>
            <div class="reg-included-item">
                <i class="fas fa-check-circle"></i>
                <div class="txt">
                    <strong>Follow-up until a decision</strong>
                    <span>We track the file through formality examination, substantive examination and publication, and report each stage to you.</span>
                </div>
            </div>
            <div class="reg-included-item">
                <i class="fas fa-check-circle"></i>
                <div class="txt">
                    <strong>Electronic registration certificate</strong>
                    <span>Delivered to you once the CNIPA issues it. We also send the official filing receipt and application number as soon as they are available.</span>
                </div>
            </div>
            <div class="reg-included-item">
                <i class="fas fa-check-circle"></i>
                <div class="txt">
                    <strong>English-language handling</strong>
                    <span>One point of contact, in English, for the whole file. No need to brief a local translator or chase a Chinese intermediary.</span>
                </div>
            </div>
        </div>
        <div class="reg-note">
            <?php if ($regNote !== ''): ?>
            <?php echo htmlspecialchars($regNote, ENT_QUOTES, 'UTF-8'); ?> The price is all-inclusive &mdash; the CNIPA official fee, our professional fee, document preparation and translation are all inside it, and nothing is billed separately.
            <?php else: ?>
            The fee is per class and is all-inclusive &mdash; the CNIPA official fee is already inside it and is not billed separately.
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- Documents you need to provide -->
<section class="section section-white" id="documents-required">
    <div class="reg-container">
        <h2 style="font-size: 30px; font-weight: 800; color: var(--text-dark); margin: 0 0 10px;">Documents you need to provide</h2>
        <p class="reg-docs-intro"><?php echo $C['docs_lead']; ?></p>

        <div class="reg-docs">
            <?php foreach ($C['docs_list'] as $i => $d): ?>
            <div class="reg-doc">
                <span class="doc-num"><?php echo $i + 1; ?></span>
                <div class="doc-body">
                    <p class="doc-name">
                        <?php echo $d['name']; // 各薄壳页审定的静态英文，含 <span class="doc-alt"> 时保持原样 ?>
                        <?php if (!empty($d['tag'])): ?>
                        <span class="doc-tag <?php echo ($d['tag'] === 'us') ? 'tag-us' : 'tag-them'; ?>"><?php echo htmlspecialchars($d['tag_txt'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                    </p>
                    <p class="doc-desc"><?php echo $d['desc']; ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="reg-docs-notary<?php echo $docsNotaryOk ? '' : ' doc-warn'; ?>">
            <i class="fas <?php echo $docsNotaryOk ? 'fa-shield-alt' : 'fa-exclamation-triangle'; ?>"></i>
            <div class="notary-body">
                <strong><?php echo htmlspecialchars($docsNotaryTitle, ENT_QUOTES, 'UTF-8'); ?></strong>
                <span><?php echo $C['docs_notary_txt']; ?></span>
            </div>
        </div>
    </div>
</section>

<!-- Fees -->
<section class="section section-white">
    <div class="reg-container reg-prose">
        <h2>Fees</h2>
        <table class="reg-fee-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>China trademark registration &mdash; first class</td>
                    <td class="fee-strong">USD <?php echo number_format($regPrice, 0); ?> / class</td>
                </tr>
                <tr>
                    <td>Each additional class in the same application</td>
                    <td class="fee-strong">USD <?php echo number_format($regPrice, 0); ?> / class</td>
                </tr>
                <tr>
                    <td>Additional goods or services items beyond 10 per class</td>
                    <td class="fee-strong">USD 10 / item</td>
                </tr>
                <tr>
                    <td>Availability search and written quotation</td>
                    <td class="fee-strong">Free</td>
                </tr>
            </tbody>
        </table>
        <p>Fees are quoted and payable in US dollars and are all-inclusive &mdash; the CNIPA official fee is already inside the price and is never billed separately. Full terms are in our <a href="<?php echo $rootPrefix; ?>terms.php">Terms of Service</a>; the complete fee schedule for every other service is on the <a href="<?php echo $rootPrefix; ?>services.php">services page</a>.</p>
    </div>
</section>

<!-- FAQ -->
<section class="section section-white" style="background: #f8f9fc;">
    <div class="reg-container">
        <h2 style="font-size: 30px; font-weight: 800; color: var(--text-dark); margin: 0 0 24px;">China trademarks for <?php echo htmlspecialchars($C['audience_short'] ?? $C['audience'], ENT_QUOTES, 'UTF-8'); ?> &mdash; common questions</h2>

        <div class="reg-faq-item">
            <div class="reg-faq-question">
                <span>Can I register a China trademark without a Chinese entity?</span>
                <i class="fas fa-chevron-down"></i>
            </div>
            <div class="reg-faq-answer">
                <div class="reg-faq-answer-content">
                    Yes. A foreign company or individual can own a China trademark without incorporating anything in China. The only requirement is that the application is filed through a CNIPA-registered agent, which we are. Your home address is used as the applicant address on the register.
                </div>
            </div>
        </div>

        <div class="reg-faq-item">
            <div class="reg-faq-question">
                <span><?php echo $C['priority_q']; // 薄壳页审定的静态英文 ?></span>
                <i class="fas fa-chevron-down"></i>
            </div>
            <div class="reg-faq-answer">
                <div class="reg-faq-answer-content">
                    <?php echo $C['priority_a']; ?>
                </div>
            </div>
        </div>

        <div class="reg-faq-item">
            <div class="reg-faq-question">
                <span><?php echo $C['madrid_q']; ?></span>
                <i class="fas fa-chevron-down"></i>
            </div>
            <div class="reg-faq-answer">
                <div class="reg-faq-answer-content">
                    <?php echo $C['madrid_a']; ?>
                </div>
            </div>
        </div>

        <div class="reg-faq-item">
            <div class="reg-faq-question">
                <span>How long does it take to register a trademark in China?</span>
                <i class="fas fa-chevron-down"></i>
            </div>
            <div class="reg-faq-answer">
                <div class="reg-faq-answer-content">
                    Two timelines run here &mdash; the legal deadline and what usually happens in practice. By law, CNIPA must finish substantive examination within nine months of receiving the application, and the opposition window after publication is three months (it shortens to two months from 1 January 2027). In practice it is faster: you normally receive the acceptance notice within about fifteen days of filing, substantive examination then takes about three to four months, and once the mark is published the opposition window is three months (two months from 2027) before the certificate issues. So a straightforward case usually takes about seven to nine months in practice, against the statutory ceiling of up to twelve to fifteen months if every stage ran to its limit. If the application is refused and you do not file a refusal re-examination (&#39564;&#21457;&#22797;&#23457;), the matter ends there; filing a re-examination is a separate service and normally takes six to twelve months for a result.
                </div>
            </div>
        </div>

        <div class="reg-faq-item">
            <div class="reg-faq-question">
                <span>How much does it cost?</span>
                <i class="fas fa-chevron-down"></i>
            </div>
            <div class="reg-faq-answer">
                <div class="reg-faq-answer-content">
                    Our fee is USD <?php echo number_format($regPrice, 0); ?> per class, including up to ten items of goods or services. That figure is all-inclusive: the CNIPA official fee is already inside it and is never billed on top. Additional items are USD 10 each, and each further class is charged separately. The availability search and written quotation are free.
                </div>
            </div>
        </div>

        <div class="reg-faq-item">
            <div class="reg-faq-question">
                <span>Does my existing home registration protect me in China?</span>
                <i class="fas fa-chevron-down"></i>
            </div>
            <div class="reg-faq-answer">
                <div class="reg-faq-answer-content">
                    No. Trademark rights are territorial, so a registration that covers <?php echo htmlspecialchars($C['home_cover'], ENT_QUOTES, 'UTF-8'); ?> gives no rights inside China. Only a Chinese registration &mdash; through the route above, or through a Madrid extension &mdash; is enforceable against squatters, copycats and marketplaces in China.
                </div>
            </div>
        </div>

        <div class="reg-faq-item">
            <div class="reg-faq-question">
                <span>What happens if my application is refused?</span>
                <i class="fas fa-chevron-down"></i>
            </div>
            <div class="reg-faq-answer">
                <div class="reg-faq-answer-content">
                    The CNIPA issues a refusal notice with reasons, most commonly a conflict with an earlier mark or a distinctiveness objection. You can respond by requesting a review within the deadline, arguing the mark should proceed. This is a separate service with its own fee; we report the refusal to you with the deadline and the options before anything is spent.
                </div>
            </div>
        </div>

        <div class="reg-faq-item">
            <div class="reg-faq-question">
                <span>Do you need a Power of Attorney?</span>
                <i class="fas fa-chevron-down"></i>
            </div>
            <div class="reg-faq-answer">
                <div class="reg-faq-answer-content">
                    Yes. A signed Power of Attorney is required to file on your behalf. We send you the form to sign after the quote is approved, so nothing has to be prepared up front. A simple signed scan is normally enough &mdash; there is no need to have it notarized, legalized or consularized before you send it. The full list of documents is in <a href="#documents-required">Documents you need to provide</a> above.
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Country switcher -->
<section class="section section-white">
    <div class="reg-container">
        <h2 style="font-size: 30px; font-weight: 800; color: var(--text-dark); margin: 0 0 10px;">Filing from another country</h2>
        <p style="font-size: 16.5px; color: var(--text-gray); line-height: 1.8; margin: 0 0 24px;">The process is the same worldwide; these pages cover the country-specific questions &mdash; priority from your home office and the Madrid route from it.</p>
        <div class="country-grid">
            <?php foreach ($SIBLINGS as $s): ?>
            <?php if ($s['dir'] === $C['dir']) continue; ?>
            <a class="country-card" href="<?php echo $rootPrefix . htmlspecialchars($s['dir'], ENT_QUOTES, 'UTF-8'); ?>/">
                <span class="country-flag"><?php echo htmlspecialchars($s['flag'], ENT_QUOTES, 'UTF-8'); ?></span>
                <span>
                    <strong><?php echo htmlspecialchars($s['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    <span>China trademark from <?php echo htmlspecialchars($s['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                </span>
            </a>
            <?php endforeach; ?>
            <a class="country-card" href="<?php echo $rootPrefix; ?>china-trademark-registration/">
                <span class="country-flag"><i class="fas fa-globe"></i></span>
                <span>
                    <strong>General guide</strong>
                    <span>The full registration walkthrough</span>
                </span>
            </a>
        </div>
    </div>
</section>

<!-- CTA -->
<section class="cta-section">
    <div class="section-container">
        <div class="cta-content">
            <h2>Check your mark before you file from <?php echo htmlspecialchars($C['from_country'] ?? 'abroad', ENT_QUOTES, 'UTF-8'); ?></h2>
            <p>Send us the mark and the goods or services you will use it on. We search it against earlier filings in China and reply in writing with what we found, together with the fee. Nothing is payable until you confirm.</p>
            <?php quick_check_form_body($rootPrefix); ?>
            <p class="cta-alt">Prefer the full form? <a href="https://portal.example.com/?page=support&amp;intent=quote&amp;service=registration">Tell us your classes and get a quote</a> &middot; <a href="<?php echo $rootPrefix; ?>faq.php">Read our FAQ</a></p>
        </div>
    </div>
</section>

<script>
    // FAQ 折叠
    document.querySelectorAll('.reg-faq-question').forEach(function (q) {
        q.addEventListener('click', function () {
            q.parentElement.classList.toggle('active');
        });
    });
</script>

<?php
// ---------------------------------------------------------------------------
// FAQPage 结构化数据：渲染后抽取（与 faq.php / 注册落地页同一套思路）。
// 先剔 <style>/<script> 再匹配 .reg-faq-*；抓不到问答就不输出空结构。
// ---------------------------------------------------------------------------
$__html = ob_get_contents();
$__html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $__html);
$__html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $__html);

$__items = [];
if (preg_match_all(
    '/<div class="reg-faq-item">.*?<div class="reg-faq-question">\s*<span>(.*?)<\/span>.*?'
    . '<div class="reg-faq-answer-content">(.*?)<\/div>/s',
    $__html,
    $__m,
    PREG_SET_ORDER
)) {
    foreach ($__m as $__one) {
        $__q = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($__one[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $__a = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($__one[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($__q === '' || $__a === '') {
            continue;
        }
        $__items[] = [
            '@type'          => 'Question',
            'name'           => $__q,
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $__a],
        ];
    }
}
if ($__items) {
    echo '<script type="application/ld+json">'
        . str_replace('</', '<\/', json_encode([
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            '@id'        => $canonicalUrl . '#faq',
            'url'        => $canonicalUrl,
            'inLanguage' => 'en',
            'mainEntity' => $__items,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
        . '</script>';
}
ob_end_flush();

require_once $__siteRoot . '/includes/footer.php';
?>
