<?php
/**
 * bootstrap.php — single entry point for the back-office.
 *
 * Include from any page or script:
 *
 *     require_once __DIR__ . '/bootstrap.php';
 *
 * Every library is loaded here, so callers never include them directly.
 */

require_once __DIR__ . '/lib/core.php';
require_once __DIR__ . '/lib/pricing.php';
require_once __DIR__ . '/lib/i18n.php';
require_once __DIR__ . '/lib/upload.php';
require_once __DIR__ . '/lib/orders.php';
require_once __DIR__ . '/lib/delete.php';   // hard delete for orders / enquiries / clients
require_once __DIR__ . '/lib/mailer.php';
require_once __DIR__ . '/lib/paypal.php';

// Initialise the locale (reads ?lang= and sets a cookie; the site itself now
// runs English-only, so this mainly keeps legacy call sites working).
lang();

/** Minimal inline CSS for the stacked bilingual label / sub-label pattern. */
if (!function_exists('i18n_css')) {
    function i18n_css()
    {
        return '<style>
.lg-lg{display:block;line-height:1.35}
.lg-sm{display:block;font-size:.78em;opacity:.62;font-weight:400;margin-top:1px;line-height:1.3}
.lg-inline .lg-lg,.lg-inline .lg-sm{display:inline}
.lg-inline .lg-sm{margin-left:.4em}
.lg-sw{display:inline-flex;gap:4px;align-items:center;font-size:12px}
.lg-sw a{padding:2px 7px;border-radius:5px;text-decoration:none;border:1px solid rgba(127,127,127,.35);opacity:.6}
.lg-sw a.on{opacity:1;border-color:#0b3d91;color:#0b3d91;font-weight:600}
</style>';
    }
}

/** Language switcher — retired (English-only site), kept for old call sites. */
if (!function_exists('lang_switcher')) {
    function lang_switcher()
    {
        return '';
    }
}
