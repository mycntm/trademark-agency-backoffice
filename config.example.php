<?php
/**
 * Configuration template.
 *
 * Copy this file to config.php and fill in your own values:
 *
 *     cp config.example.php config.php
 *
 * config.php and config.local.php are git-ignored. Never commit real
 * credentials. If config.php is missing the app falls back to this file and
 * will not work with the placeholder values — that is intentional.
 *
 * config.local.php is loaded after config.php and its keys override this array,
 * which is a convenient place to keep secrets outside version control.
 */

return [
    'debug'        => false,
    'timezone'     => 'Asia/Shanghai',
    'site_url'     => 'https://example.com',
    'site_name_en' => 'Example IP Agency Co., Ltd.',
    'company_email'=> 'hello@example.com',

    // Primary database: clients, enquiries, orders, payments, documents.
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'trademark',
        'user'    => 'trademark',
        'pass'    => 'CHANGE_ME_DB_PASSWORD',
        'charset' => 'utf8mb4',
    ],

    // Optional second database holding the Nice classification catalogue used
    // by the class finder and the goods/services picker. Point it at 'db' if
    // you keep everything in one schema.
    'cls_db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'cntm_classes',
        'user'    => 'cntm_classes',
        'pass'    => 'CHANGE_ME_CLASSES_DB_PASSWORD',
        'charset' => 'utf8mb4',
    ],

    // Optional third-party trademark preview API. Blank disables the feature.
    'ruseo_api_key' => '',

    // Secret that allows triggering the mail queue worker over HTTP:
    //   https://your-domain/app/cron/mail_queue.php?key=<this value>
    // Leave blank to disable HTTP triggering (CLI cron still works).
    'cron_key' => '',

    'smtp' => [
        'enabled'   => true,
        'host'      => 'smtp.example.com',
        'port'      => 465,
        'secure'    => 'ssl',          // 'ssl' (port 465) or 'tls' (port 587)
        'user'      => 'smtp-user',
        'pass'      => 'CHANGE_ME_SMTP_PASSWORD',
        'from'      => 'noreply@example.com',
        'from_name' => 'Example IP | China Trademark Specialist',
        'reply_to'  => 'hello@example.com',
        'timeout'   => 15,
    ],

    'paypal' => [
        'enabled'       => false,      // optional: bank transfer works without it
        'sandbox'       => true,       // set to false in production
        'client_id'     => 'CHANGE_ME_PAYPAL_CLIENT_ID',
        'client_secret' => 'CHANGE_ME_PAYPAL_CLIENT_SECRET',
        'webhook_id'    => 'CHANGE_ME_WEBHOOK_ID',
        'currency'      => 'USD',
        'brand_name'    => 'EXAMPLE IP',
    ],

    /**
     * Bank transfer details rendered on the payment page.
     *
     * One entry per receiving bank:
     *   - accounts     => ['USD' => '1234...', 'EUR' => '1234...']
     *   - intermediary => ['USD' => [['name' => ..., 'swift' => ...], ...]]
     *                     correspondent banks; usually only needed for smaller
     *                     or internet-only banks on cross-border transfers
     *   - primary      => the bank to show first
     *
     * An empty array hides bank transfer entirely.
     */
    'banks' => [
        [
            'primary'     => true,
            'name'        => 'BANK OF EXAMPLE',
            'address'     => '1 EXAMPLE ROAD, SHANGHAI, CHINA',
            'swift'       => 'EXAMPLE0XXX',
            'beneficiary' => 'Example IP Agency Co., Ltd.',
            'ben_addr'    => '1 Example Road, Suite 100, Shanghai, China',
            'accounts'    => [
                'USD' => '0000000000000000',
                'EUR' => '0000000000000000',
                'GBP' => '0000000000000000',
                'HKD' => '0000000000000000',
                'CNY' => '0000000000000000',
            ],
            'intermediary' => [
                'EUR' => [
                    ['name' => 'EXAMPLE CORRESPONDENT BANK, FRANKFURT', 'swift' => 'EXAMPLE2XXX'],
                ],
            ],
        ],
    ],

    'security' => [
        // Signing key for CSRF tokens and magic links. Generate one with:
        //   php -r "echo bin2hex(random_bytes(32));"
        'csrf_secret'    => 'CHANGE-ME-TO-A-RANDOM-64-CHAR-STRING',
        'token_ttl_min'  => 21600,   // magic link lifetime in minutes (15 days)
        'token_reusable' => true,    // allow re-opening the same link within TTL

        // Requests allowed per window, per IP.
        'rate_limit' => [
            'enquiry' => 6,
            'login'   => 10,
            'message' => 20,
        ],

        /**
         * Silent spam filter for public forms. A submission judged to be a bot
         * gets a normal "thanks" response and is logged, never an error — telling
         * a bot it was blocked only invites a retry with a different payload.
         */
        'spam' => [
            'enabled'       => true,
            'min_seconds'   => 4,      // faster than this from render to submit = bot
            'max_age_hours' => 24,     // stale form tokens expire
            'max_links'     => 3,
            'max_len'       => 8000,
            'keywords'      => [
                'casino', 'viagra', 'cialis', 'porn', 'crypto airdrop', 'bitcoin doubling',
                'seo backlink', 'backlinks', 'guest post', 'link building', 'buy followers',
                'cheap followers', 'instagram followers', 'tiktok followers', 'forex signal',
                'loan offer', 'website redesign', 'web design service', 'rank your site',
                'google ranking', 'paid traffic', 'escort', 'dating site', 'pills', 'weight loss',
            ],
        ],
    ],

    'storage' => [
        'path'          => __DIR__ . '/storage/uploads',
        // Keep in sync with PHP's upload_max_filesize / post_max_size.
        'max_upload_mb' => 16,
        'allowed_mime'  => [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif',
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ],
    ],

    'pricing' => [
        'currency'        => 'USD',
        'translation_fee' => 30,
        // CNIPA official fees are in CNY; used when quoting an order manually.
        'cny_per_usd'     => 7.10,
    ],
];
