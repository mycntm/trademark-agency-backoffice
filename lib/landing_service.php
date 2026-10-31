<?php
require_once __DIR__.'/portal_routes.php';
// ---------------------------------------------------------------------------
// 服务型落地页共享模板（2026-10-01）
//
// 【服务谁】
//   /china-trademark-renewal/  /china-trademark-search/  /china-trademark-opposition/
//   各薄壳 index.php 带一份 $C 配置数组，require 本文件出整页。
//   背景：services.php 的 13 张服务卡逐步拆成独立落地页（每页一个搜索意图），
//   拆分方案与分级见 output/services-landing-plan.md（2026-10-01 调研）。
//
// 【为什么放 app/lib/】
//   nginx 对 /app/lib/ 是 deny all（deploy/baota-rewrite.conf），模板本体
//   永远不会被当 URL 直接访问到；PHP include 走磁盘路径，不受影响。
//
// 【防门页（doorway）设计】
//   多页共用骨架，但每页必须有真实差异化内容。本模板强制校验：
//     - intro / included / steps / fee_rows / docs / faq / links 全部来自薄壳，
//       每页都是该服务的独家内容（法定期限、材料、FAQ），不是 services.php
//       卡片文案的复制。
//     - docs 至少 3 项、faq 至少 4 条、included 至少 4 条（缺了 fail fast）。
//
// 【内容纪律（写薄壳前必读，见项目记忆）】
//   - 只写核实过的法定事实；不确定的东西整段不写。
//   - 官费金额（official_fee_cny）是内部字段，任何页面不单列 —— 全站
//     「报价已含官费」口径（与 faq.php / terms.php / registration 页一致）。
//   - 「会变」的唯一允许写法是时效免责（核对于某日 + 以申请时要求为准）。
//   - 价格只从 pricing.php 取（svc_one），页面与询价表单永不错位。
//
// 【CSS 与类名】
//   .reg-* 系列类名与 /china-trademark-registration/ 完全一致（视觉同一套，
//   FAQPage 抽取正则也复用同一套 .reg-faq-* 选择器）。
//
// 【本文件不是页面】
//   直接 URL 访问不到（nginx deny）；被 require 而没给 $C 时立即 500 退出。
// ---------------------------------------------------------------------------

if (!isset($C) || !is_array($C)) {
    http_response_code(500);
    exit('landing_service.php requires a $C config array');
}

/** 必填字段缺一不可，缺了 fail fast（部署期暴露，不带病上线） */
foreach (['dir', 'title', 'meta', 'svc_key', 'svc_name', 'service_type', 'jsonld_desc',
          'h1_html', 'banner_sub', 'banner_btn', 'facts', 'intro_h2', 'intro',
          'included_h2', 'included', 'included_note', 'steps_h2', 'steps',
          'fee_h2', 'fee_lead', 'fee_rows', 'fee_note', 'effective_note',
          'docs_h2', 'docs_lead', 'docs', 'faq', 'links', 'cta_h2', 'cta_p'] as $__k) {
    if (!isset($C[$__k])) {
        http_response_code(500);
        exit("landing_service.php: missing \$C['{$__k}']");
    }
}
/** 差异化内容量校验：低于下限说明薄壳在偷懒，会滑向门页 */
if (!is_array($C['facts']) || count($C['facts']) !== 4) {
    http_response_code(500);
    exit("landing_service.php: \$C['facts'] must have exactly 4 items");
}
if (!is_array($C['intro']) || count($C['intro']) < 2) {
    http_response_code(500);
    exit("landing_service.php: \$C['intro'] needs at least 2 paragraphs");
}
foreach ([['included', 4], ['steps', 3], ['fee_rows', 2], ['docs', 3], ['faq', 4], ['links', 2]] as [$__k, $__min]) {
    if (!is_array($C[$__k]) || count($C[$__k]) < $__min) {
        http_response_code(500);
        exit("landing_service.php: \$C['{$__k}'] needs at least {$__min} items");
    }
}
foreach ($C['docs'] as $__i => $__d) {
    if (!isset($__d['name'], $__d['desc'])) {
        http_response_code(500);
        exit("landing_service.php: \$C['docs'][{$__i}] needs 'name' and 'desc'");
    }
}
foreach ($C['faq'] as $__i => $__f) {
    if (!isset($__f['q'], $__f['a']) || $__f['q'] === '' || $__f['a'] === '') {
        http_response_code(500);
        exit("landing_service.php: \$C['faq'][{$__i}] needs non-empty 'q' and 'a'");
    }
}

// ---- 价格：只从 pricing.php 取，单一来源 --------------------------------
$__siteRoot = rtrim(isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : '', '/\\');
if ($__siteRoot === '' || !is_file($__siteRoot . '/includes/header.php')) {
    $__siteRoot = dirname(__DIR__, 2);
}
require_once $__siteRoot . '/app/lib/pricing.php';
require_once $__siteRoot . '/app/lib/quick_check_form.php';

$svcItem  = svc_one($C['svc_key']);
if (!$svcItem) {
    http_response_code(500);
    exit("landing_service.php: unknown svc_key '{$C['svc_key']}'");
}
$svcPrice = ($svcItem['price'] !== null) ? (float) $svcItem['price'] : null;
$svcUnit  = !empty($svcItem['unit_en']) ? $svcItem['unit_en'] : '';

$pageTitle       = $C['title'];
$metaDescription = $C['meta'];
$page            = 'svc-' . $C['dir'];          // 不对应任何导航项 → 无高亮

// canonical：header.php 默认逻辑在子目录下会算错，必须手写
$canonicalUrl = 'https://example.com/' . $C['dir'] . '/';

// Service + Offer：每页独立 @id，provider 挂全站 Organization。
// 无固定价的服务（如 search）不输出 Offer 价格，只给渠道。
$__svcLd = [
    '@context'    => 'https://schema.org',
    '@type'       => 'Service',
    '@id'         => $canonicalUrl . '#service',
    'name'        => $C['svc_name'],
    'serviceType' => $C['service_type'],
    'url'         => $canonicalUrl,
    'description' => $C['jsonld_desc'],
    'provider'    => ['@id' => 'https://example.com/#organization'],
    'areaServed'  => [['@type' => 'Country', 'name' => 'China']],
    'availableChannel' => [
        '@type'             => 'ServiceChannel',
        'serviceUrl'        => portal_enquiry_url($C['svc_key']),
        'availableLanguage' => ['English', 'Chinese'],
    ],
];
if ($svcPrice !== null) {
    $__svcLd['offers'] = [
        '@type'         => 'Offer',
        'price'         => number_format($svcPrice, 0, '.', ''),
        'priceCurrency' => 'USD',
        'description'   => $svcUnit !== '' ? ('Per ' . rtrim($svcUnit, 's') . '.') : 'All-inclusive fee.',
        'url'           => portal_enquiry_url($C['svc_key']),
        'availability'  => 'https://schema.org/InStock',
    ];
}
$jsonLdExtra = '<script type="application/ld+json">' . str_replace('</', '<\/', json_encode($__svcLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</script>';

// 页面 URL 在站点根下一层
$rootPrefix = '../';

// FAQ 渲染后抽取生成 FAQPage（与 faq.php / 注册落地页同一套做法）
ob_start();

require_once $__siteRoot . '/includes/header.php';
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
        font-size: 46px;
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
        max-width: 720px;
        margin: 0 auto;
        line-height: 1.7;
        position: relative;
        z-index: 1;
    }

    /* 价格条 */
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

    /* 关键事实行 */
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

    /* 通用内容排版 */
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

    /* 流程时间线 */
    .reg-steps {
        counter-reset: step;
        margin: 12px 0 24px;
    }

    .reg-step {
        position: relative;
        padding: 0 0 26px 62px;
        counter-increment: step;
    }

    .reg-step::before {
        content: counter(step);
        position: absolute;
        left: 0;
        top: 0;
        width: 42px;
        height: 42px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
        color: white;
        font-weight: 800;
        font-size: 17px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .reg-step::after {
        content: '';
        position: absolute;
        left: 20px;
        top: 46px;
        bottom: 4px;
        width: 2px;
        background: rgba(102, 126, 234, 0.22);
    }

    .reg-step:last-child::after {
        display: none;
    }

    .reg-step .step-title {
        font-size: 17px;
        font-weight: 700;
        color: var(--text-dark);
        margin-bottom: 6px;
    }

    .reg-step .step-desc {
        font-size: 15.5px;
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

    /* 相关页面内链 */
    .reg-links {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 20px;
        margin: 8px 0 0;
    }

    .reg-link-card {
        display: block;
        background: white;
        border-radius: 14px;
        padding: 24px 26px;
        box-shadow: var(--shadow);
        text-decoration: none;
        transition: transform 0.25s ease, box-shadow 0.25s ease;
        border-left: 4px solid var(--primary);
    }

    .reg-link-card:hover {
        transform: translateY(-3px);
        box-shadow: var(--shadow-lg);
    }

    .reg-link-card strong {
        display: block;
        font-size: 16.5px;
        font-weight: 700;
        color: var(--text-dark);
        margin-bottom: 6px;
    }

    .reg-link-card span {
        font-size: 14.5px;
        color: var(--text-gray);
        line-height: 1.65;
    }

    @media (max-width: 900px) {
        .reg-facts {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 768px) {
        .page-banner {
            padding: 110px 0 60px;
        }

        .page-banner h1 {
            font-size: 32px;
        }

        .page-banner p {
            font-size: 16px;
        }

        .reg-price-tag .amount {
            font-size: 34px;
        }

        .reg-included,
        .reg-links {
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
        <h1><?php echo $C['h1_html']; // 薄壳审定的静态 HTML，非用户输入 ?></h1>
        <p><?php echo $C['banner_sub']; ?></p>

        <div class="reg-price-bar">
            <?php if ($svcPrice !== null): ?>
            <div class="reg-price-tag">
                <span class="amount"><?php echo (float)$svcPrice === 0.0 ? 'Free' : '$' . number_format($svcPrice, 0); ?></span>
                <span class="unit"><?php echo htmlspecialchars($svcUnit !== '' ? $svcUnit : 'all-inclusive', ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <?php else: ?>
            <div class="reg-price-tag">
                <span class="amount" style="font-size: 28px;">Quote on request</span>
            </div>
            <?php endif; ?>
            <a href="<?php echo htmlspecialchars(portal_enquiry_url($C['svc_key']), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-primary">
                <i class="fas fa-paper-plane"></i> <?php echo $C['banner_btn']; ?>
            </a>
        </div>

        <div class="reg-facts">
            <?php foreach ($C['facts'] as $__f): ?>
            <div class="reg-fact">
                <div class="fact-value"><?php echo $__f['v']; // 静态英文，含 &ndash; 等实体 ?></div>
                <div class="fact-label"><?php echo $__f['l']; ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Intro -->
<section class="section section-white">
    <div class="reg-container reg-prose">
        <h2><?php echo $C['intro_h2']; ?></h2>
        <?php foreach ($C['intro'] as $__p): ?>
        <p><?php echo $__p; // 静态英文，含 <strong>/<a> 等审定标记 ?></p>
        <?php endforeach; ?>
    </div>
</section>

<!-- What's included -->
<section class="section section-white" style="padding-top: 0;">
    <div class="reg-container">
        <h2 style="font-size: 30px; font-weight: 800; color: var(--text-dark); margin: 0 0 24px;"><?php echo $C['included_h2']; ?></h2>
        <div class="reg-included">
            <?php foreach ($C['included'] as $__it): ?>
            <div class="reg-included-item">
                <i class="fas fa-check-circle"></i>
                <div class="txt">
                    <strong><?php echo $__it[0]; ?></strong>
                    <span><?php echo $__it[1]; ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="reg-note"><?php echo $C['included_note']; ?></div>
    </div>
</section>

<!-- Process -->
<section class="section section-white" style="background: #f8f9fc;">
    <div class="reg-container reg-prose">
        <h2><?php echo $C['steps_h2']; ?></h2>
        <div class="reg-steps">
            <?php foreach ($C['steps'] as $__s): ?>
            <div class="reg-step">
                <div class="step-title"><?php echo $__s[0]; ?></div>
                <div class="step-desc"><?php echo $__s[1]; ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Fees -->
<section class="section section-white">
    <div class="reg-container reg-prose">
        <h2><?php echo $C['fee_h2']; ?></h2>
        <p><?php echo $C['fee_lead']; ?></p>
        <table class="reg-fee-table">
            <thead>
                <tr><th>Item</th><th>Fee</th><th>What it covers</th></tr>
            </thead>
            <tbody>
                <?php foreach ($C['fee_rows'] as $__r): ?>
                <tr>
                    <td class="fee-strong"><?php echo $__r[0]; ?></td>
                    <td><?php echo $__r[1]; ?></td>
                    <td><?php echo $__r[2]; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="reg-note"><?php echo $C['fee_note']; ?></div>
        <?php if (!empty($svcItem['needs_reg_no'])) require __DIR__ . '/class_billing_note.php'; ?>
        <div class="reg-note" style="border-left-color: #94a3b8;"><?php echo $C['effective_note']; // 时效免责（唯一允许的「会变」表述） ?></div>
    </div>
</section>

<!-- Documents -->
<section class="section section-white" style="background: #f8f9fc;">
    <div class="reg-container">
        <h2 style="font-size: 30px; font-weight: 800; color: var(--text-dark); margin: 0 0 12px;"><?php echo $C['docs_h2']; ?></h2>
        <p style="font-size: 16.5px; color: #4b5563; line-height: 1.85; margin: 0 0 24px;"><?php echo $C['docs_lead']; ?></p>
        <div class="reg-included">
            <?php foreach ($C['docs'] as $__d): ?>
            <div class="reg-included-item">
                <i class="fas <?php echo (!empty($__d['tag']) && $__d['tag'] === 'us') ? 'fa-file-signature' : 'fa-arrow-circle-right'; ?>" style="<?php echo (!empty($__d['tag']) && $__d['tag'] === 'us') ? '' : 'color: var(--primary);'; ?>"></i>
                <div class="txt">
                    <strong><?php echo $__d['name']; ?></strong>
                    <span><?php echo $__d['desc']; ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- FAQ -->
<section class="section section-white">
    <div class="reg-container">
        <h2 style="font-size: 30px; font-weight: 800; color: var(--text-dark); margin: 0 0 24px;"><?php echo htmlspecialchars($C['svc_name'], ENT_QUOTES, 'UTF-8'); ?> &mdash; common questions</h2>
        <?php foreach ($C['faq'] as $__f): ?>
        <div class="reg-faq-item">
            <div class="reg-faq-question">
                <span><?php echo $__f['q']; // 静态英文，可含实体 ?></span>
                <i class="fas fa-chevron-down"></i>
            </div>
            <div class="reg-faq-answer">
                <div class="reg-faq-answer-content"><?php echo $__f['a']; // 静态英文，可含 <strong>/<a> ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- Related -->
<section class="section section-white" style="background: #f8f9fc;">
    <div class="reg-container">
        <h2 style="font-size: 30px; font-weight: 800; color: var(--text-dark); margin: 0 0 10px;">Related help</h2>
        <div class="reg-links">
            <?php foreach ($C['links'] as $__l): ?>
            <a class="reg-link-card" href="<?php echo $rootPrefix . $__l['href']; ?>">
                <strong><?php echo $__l['title']; ?></strong>
                <span><?php echo $__l['desc']; ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- CTA -->
<section class="cta-section" id="professional-search-cta">
    <div class="section-container">
        <div class="cta-content">
            <h2><?php echo $C['cta_h2']; ?></h2>
            <p><?php echo $C['cta_p']; ?></p>
            <?php quick_check_form_body($rootPrefix); ?>
            <script>
            (function () {
                var params = new URLSearchParams(window.location.search);
                var mark = params.get('mark');
                var field = document.getElementById('qc-mark');
                if (mark && field && !field.value) field.value = mark.slice(0, 120);
            })();
            </script>
            <p class="cta-alt">Prefer the full form? <a href="<?php echo htmlspecialchars(portal_enquiry_url($C['svc_key']), ENT_QUOTES, 'UTF-8'); ?>">Tell us the details and get a quote</a> &middot; <a href="<?php echo $rootPrefix; ?>faq.php">Read our FAQ</a></p>
        </div>
    </div>
</section>

<script>
    // FAQ 折叠（与注册页同一套交互）
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
