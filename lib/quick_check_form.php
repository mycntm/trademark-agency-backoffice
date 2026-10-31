<?php
// ---------------------------------------------------------------------------
// 快速可用性查询表单 —— 首页（index.php）与五国落地页
// （app/lib/landing_country.php）共用同一份渲染（2026-10-01）。
//
// 【为什么抽出来，而不是各写一份】
//   同一张表单跑在两条渲染路径上，字段名 / handler / 校验 / 观感必须一致。
//   各写一份就会长出「首页能提交、落地页 404」这类只有真机才暴露的差异
//   （本仓已两次栽在「同类写法不一致」上）。
//
// 【两个函数】
//   quick_check_form_css()             —— 输出 CSS 规则（**不含 `<style>` 标签**），
//                                          调用方在自己的 `<style>` 末尾调用。
//   quick_check_form_body($rootPrefix)  —— 输出表单 HTML + 提交脚本。
//
// 【$rootPrefix 必传】
//   表单提交到站点根目录的 availability-handler.php。首页（根）传 ''，
//   子目录落地页传 '../'（landing_country.php 已定义 $rootPrefix = '../'）。
//   裸写相对路径会让 /china-trademark-us/ 下的表单提交到
//   /china-trademark-us/availability-handler.php → 404。
//   脚本内也用 form.getAttribute('action') 而不是写死路径，同一个原因。
//
// 【为什么表单不走 PHP / 不引 bootstrap】
//   app/lib/core.php 无条件 session_start()，一引入，每位访客都会拿到
//   CNTMSID cookie。首页是「零 cookie」的（与 Matomo 无 cookie 的隐私口径配套），
//   不值得为一道 CSRF 校验破坏它。所以这里没有 csrf_field()、没有时间戳隐藏域，
//   蜜罐是纯 HTML 字段。真正的防护都在 availability-handler.php：
//   同源检查 + 蜜罐 + rate_limit('quick_check', 8) + spam_gate。
// ---------------------------------------------------------------------------

if (!function_exists('quick_check_form_css')) {

    /**
     * 表单样式。调用点必须在自己的 <style> 标签内（放在末尾，便于覆盖共享 CSS）。
     */
    function quick_check_form_css()
    {
        ?>
    /* ---------------------------------------------------------------
     * 快速可用性查询表单（2026-10-01）
     *
     * 与 contact.php 的 .form-* 同一套观感，但这里落在紫色 CTA 区块上，
     * 所以用白卡片承载。本段由 app/lib/quick_check_form.php 输出，
     * 首页与五国落地页共用 —— 改这里就是两处一起改。
     * --------------------------------------------------------------- */
    .quick-check {
        max-width: 560px;
        margin: 0 auto;
        padding: 28px 28px 22px;
        background: #fff;
        border-radius: 20px;
        box-shadow: 0 20px 50px rgba(0, 0, 0, 0.18);
        text-align: left;
    }

    .qc-group {
        display: flex;
        flex-direction: column;
        margin-bottom: 16px;
    }

    .qc-group label {
        font-size: 14px;
        font-weight: 700;
        color: var(--text-dark);
        margin-bottom: 8px;
    }

    .qc-group input,
    .qc-group textarea {
        border: 1.5px solid #e2e8f0;
        border-radius: 12px;
        padding: 12px 14px;
        font-size: 15px;
        font-family: inherit;
        background: #fafbff;
        transition: border-color .2s ease, box-shadow .2s ease;
    }

    .qc-group input:focus,
    .qc-group textarea:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(102, 126, 234, .15);
        background: #fff;
    }

    .qc-group textarea {
        resize: vertical;
    }

    .qc-group .err {
        color: #e53e3e;
        font-size: 12.5px;
        margin-top: 6px;
        min-height: 14px;
    }

    .quick-check .hp {
        position: absolute;
        left: -9999px;
        width: 1px;
        height: 1px;
        overflow: hidden;
    }

    .qc-submit {
        width: 100%;
        border: none;
        cursor: pointer;
        background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
        color: #fff;
        font-size: 16px;
        font-weight: 700;
        padding: 14px;
        border-radius: 12px;
        transition: transform .2s ease, box-shadow .2s ease;
    }

    .qc-submit:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(102, 126, 234, .3);
    }

    .qc-submit:disabled {
        opacity: .6;
        cursor: not-allowed;
        transform: none;
    }

    /* 下面两条用 p.qc-* / p.cta-alt 是为了压过共享 CSS 的 .cta-section p（特异性 0,0,1,1） */
    .cta-section p.qc-note {
        font-size: 13px;
        color: #64748b;
        margin: 12px 0 0;
        max-width: none;
    }

    .cta-section p.cta-alt {
        font-size: 15px;
        color: rgba(255, 255, 255, .92);
        margin: 22px auto 0;
    }

    .cta-section p.cta-alt a {
        color: #fff;
        text-decoration: underline;
        font-weight: 700;
    }

    .qc-msg {
        display: none;
        margin: 12px 0 0;
        font-size: 14px;
        font-weight: 600;
    }

    .qc-msg.ok {
        display: block;
        color: #2f855a;
    }

    .qc-msg.bad {
        display: block;
        color: #e53e3e;
    }

    @media (max-width: 768px) {
        .quick-check {
            padding: 20px 18px 18px;
            border-radius: 16px;
        }

        /* iOS 上字号 <16px 会触发聚焦自动缩放，输入框一并调到 16px */
        .qc-group input,
        .qc-group textarea {
            font-size: 16px;
        }
    }
        <?php
    }

    /**
     * 表单本体 + 提交脚本。放在 CTA 区块的 .cta-content 内。
     *
     * @param string $rootPrefix 站点根前缀：主站 ''，子目录页 '../'
     */
    function quick_check_form_body($rootPrefix = '')
    {
        ?>
            <form id="quickCheckForm" class="quick-check" action="<?php echo $rootPrefix; ?>portal-submit.php" method="POST" novalidate>
                <?php require dirname(__DIR__,2).'/includes/confidentiality-notice.php';?>
<div class="qc-group">
                    <label for="qc-mark">Brand name *</label>
                    <input type="text" id="qc-mark" name="mark" placeholder="e.g. APPLE" autocomplete="off" maxlength="120">
                    <span class="err" data-for="mark"></span>
                </div>
                <div class="qc-group">
                    <label for="qc-goods">Goods / Services *</label>
                    <textarea id="qc-goods" name="goods" rows="3" placeholder="e.g. clothing, footwear and headwear" maxlength="500"></textarea>
                    <span class="err" data-for="goods"></span>
                </div>
                <div class="qc-group">
                    <label for="qc-email">Email *</label>
                    <input type="email" id="qc-email" name="email" placeholder="you@company.com" autocomplete="email" maxlength="190">
                    <span class="err" data-for="email"></span>
                </div>
                <div class="hp" aria-hidden="true">
                    <label>Leave this field empty
                        <input type="text" name="website" tabindex="-1" autocomplete="off">
                    </label>
                </div>
                <input type="hidden" name="portal_kind" value="quick"><label style="display:flex;gap:10px;align-items:flex-start;margin:16px 0;font-size:12px;color:#334155"><input type="checkbox" name="consent" value="1" style="width:auto;margin-top:4px"><span>I agree to the <a href="https://portal.example.com/?page=privacy" target="_blank" rel="noopener">Privacy Policy</a> and <a href="https://portal.example.com/?page=terms" target="_blank" rel="noopener">Terms of Service</a>. My request will be handled through the client portal.</span></label><span class="err" data-for="consent"></span><button type="submit" class="qc-submit">Check My Trademark</button>
                <p class="qc-note">Free preliminary search · No payment required</p>
                <div id="qc-msg" class="qc-msg" role="alert"></div>
            </form>
            <script>
            (function () {
                var form = document.getElementById('quickCheckForm');
                if (!form) return;
                var msg = document.getElementById('qc-msg');
                /* 从 form 的 action 取地址，别写死路径：子目录页（/china-trademark-us/）
                   下写死 'availability-handler.php' 会解析成子目录内路径 → 404。 */
                var action = form.getAttribute('action');

                function setErr(name, text) {
                    var el = form.querySelector('.err[data-for="' + name + '"]');
                    if (el) el.textContent = text || '';
                }

                function validate() {
                    var mark = form.elements['mark'].value.trim();
                    var goods = form.elements['goods'].value.trim();
                    var email = form.elements['email'].value.trim();
                    setErr('mark', '');
                    setErr('goods', '');
                    setErr('email', '');
                    var ok = true;
                    if (!mark) { setErr('mark', 'Please enter the brand name you want to check'); ok = false; }
                    if (!goods) { setErr('goods', 'Please describe the goods or services'); ok = false; }
                    if (!email) { setErr('email', 'Please enter your email'); ok = false; }
                    else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { setErr('email', 'Invalid email format'); ok = false; }
                    setErr('consent','');if(!form.elements['consent'].checked){setErr('consent','Please agree to the privacy policy and terms.');ok=false;}
                    return ok;
                }

                form.addEventListener('submit', function (e) {
                    e.preventDefault();
                    msg.className = 'qc-msg';
                    msg.textContent = '';
                    if (!validate()) return;

                    var btn = form.querySelector('.qc-submit');
                    var label = btn.textContent;
                    btn.disabled = true;
                    btn.textContent = 'Checking…';

                    fetch(action, { method: 'POST', body: new FormData(form) })
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            btn.disabled = false;
                            btn.textContent = label;
                            if (res.ok) {
                                form.reset();
                                msg.className = 'qc-msg ok';
                                msg.textContent = res.message || 'Thank you! We have received your details and will reply with the search result within one business day.';
                                // Count confirmed leads only; never send the mark, goods or email to analytics.
                                if (window._paq && typeof window._paq.push === 'function') {
                                    window._paq.push(['trackEvent', 'Lead', 'Availability check submitted']);
                                }
                                if (typeof window.gtag === 'function') {
                                    window.gtag('event', 'generate_lead', { method: 'availability_check' });
                                }
                            } else {
                                msg.className = 'qc-msg bad';
                                msg.textContent = res.message || 'Something went wrong. Please email us directly at hello@example.com';
                            }
                        })
                        .catch(function () {
                            btn.disabled = false;
                            btn.textContent = label;
                            msg.className = 'qc-msg bad';
                            msg.textContent = 'Network error. Please email us at hello@example.com';
                        });
                });
            })();
            </script>
        <?php
    }
}
