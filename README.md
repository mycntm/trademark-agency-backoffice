# China Trademark Agency Back-Office

A working **PHP + MySQL back-office for a trademark agency**: enquiries in, quotes out, orders, invoices, payments (bank transfer and PayPal), document collection, and a client-facing status timeline — all bilingual (English / Chinese) and built to run without a framework.

Extracted from the production system that ran **[china-trademark.net](https://china-trademark.net/)** (a CNIPA-registered Chinese trademark agency) between 2024 and 2026, and published here as a reference implementation after that portal was replaced. Credentials, bank details, company identity and customer data have been removed.

```
PHP 8.1+ · MySQL 8 · no framework, no build step · MIT
```

---

## What it does

**Intake**
- Public enquiry form with silent spam filtering and per-IP rate limiting
- Goods/services picker backed by the Nice classification (45 classes, sub-classes)
- Free availability-check flow that opens a client record automatically

**Orders and money**
- Enquiry → quote → order, with prices frozen at order time
- Multi-currency bank transfer instructions (per-bank, per-currency accounts, optional correspondent banks)
- PayPal create/return/webhook with signature verification and event de-duplication
- Receipt upload, payment matching, manual billing notes

**Documents**
- Upload with MIME allow-list and size limits, stored outside the document root
- Per-document access log for sensitive files
- Contract generation with online handwritten signature capture

**Admin**
- Dashboard, clients, enquiries, orders, payments, messages, documents
- Role flags including "may view sensitive data"
- Manual status timeline that clients can see
- Settings stored in a key/value table, editable without deploying

**Operations**
- Mail queue with retry and error logging (SMTP), optional HTTP trigger
- Magic-link client login (no passwords), configurable TTL
- Soft delete everywhere, with a separate hard-delete path
- English / Chinese UI strings in `lang/`

## Requirements

| | |
|---|---|
| PHP | 8.1 or newer, extensions: `pdo_mysql`, `curl`, `mbstring`, `openssl`, `fileinfo` |
| Database | MySQL 8.0+ / MariaDB 10.5+ |
| Web server | Apache or nginx; PHP must be able to write to `storage/` |
| Mail | Any SMTP provider (the original used Resend) |

## Quick start

```bash
git clone https://github.com/mycntm/trademark-agency-backoffice.git
cd trademark-agency-backoffice

cp config.example.php config.php     # then edit db / smtp / security.csrf_secret
mysql -u root -p -e "CREATE DATABASE trademark CHARACTER SET utf8mb4"
mysql -u root -p trademark < schema.sql

# optional: demo admin account
php -r 'echo password_hash("change-me-now", PASSWORD_DEFAULT), PHP_EOL;'
#   insert into admin_users (username, password_hash, name, role, status)
#   values ("admin", "<hash>", "Admin", "owner", "active");

mkdir -p storage/uploads && chmod 775 storage/uploads
php -S 127.0.0.1:8080      # or point your vhost at this directory
```

Optional: contract stamping reads `seal/company-seal.png` (no seal image is shipped — add your own; the directory ships with a deny-all `.htaccess`).

Then open `/admin/login.php`. Clients do not register — the admin creates a client record, and the client receives a magic link.

`migrations/` holds incremental schema changes applied after the initial import; run them in numeric order on an existing install.

## Directory map

| Path | Role |
|---|---|
| `bootstrap.php` | Single include that wires up every library |
| `config.example.php` | Configuration template (copy to `config.php`) |
| `lib/core.php` | Config loading, PDO helpers, CSRF, tokens, rate limits, logging |
| `lib/orders.php` | Order lifecycle, numbering, totals, status transitions |
| `lib/pricing.php` | Fee calculation, class/translation add-ons, FX conversion |
| `lib/upload.php` | Validated uploads, MIME sniffing, storage paths |
| `lib/mailer.php` | Templated mail, queue writer, retry worker |
| `lib/paypal.php` | OAuth, order creation, capture, webhook verification |
| `lib/portal_bridge.php` | Optional hand-off of enquiries to an external client portal |
| `lib/landing_country.php`, `lib/landing_service.php` | Programmatic landing-page renderers |
| `actions/` | Form and callback endpoints (enquiry, contract signing, receipts, PayPal) |
| `admin/` | Back-office UI, one file per screen, plus diagnostic pages |
| `cron/mail_queue.php` | Queue worker, CLI or HTTP with `cron_key` |
| `lang/en.php`, `lang/zh.php` | UI strings |
| `schema.sql`, `migrations/` | Database structure and incremental changes |
| `storage/`, `seal/` | Writable and protected directories (`.htaccess` deny-all included) |

## Data model

16 tables: `clients`, `enquiries`, `quotes`, `orders`, `order_events`, `payments`, `documents`, `document_access_log`, `messages`, `admin_users`, `auth_tokens`, `mail_queue`, `webhook_events`, `settings`, `rate_limits`, `contracts`.

The design decision worth copying: an enquiry is mutable and cheap, an order is immutable and expensive. Everything after `orders` is append-only history — status changes go to `order_events`, money goes to `payments`, files go to `documents` with an access log.

## Security notes

Built in: prepared statements throughout, `password_hash` for admin accounts, CSRF tokens signed with `security.csrf_secret`, single-purpose magic-link tokens with TTL, per-IP rate limits, MIME sniffing on uploads, uploads stored outside the web root, webhook signature verification, and silent bot filtering on public forms (a blocked bot sees a normal success page, so it does not retry).

Removed before publication:

- `config.php` and `config.local.php` (database passwords, SMTP key, PayPal client secret, API keys)
- Company seal image and all bank account / SWIFT / beneficiary details
- Company name, addresses and e-mail addresses (replaced with `example.com` placeholders)
- Customer records, uploaded documents and mail logs

If you deploy this, generate a fresh `csrf_secret` and rotate every credential you put in `config.php`.

## Known limitations

- Single-tenant, single agency. No multi-organisation support.
- No automated test suite — this was an internal tool, tested by hand.
- The landing-page renderers carry the copy structure of the original site; treat them as examples, not a CMS.
- Some admin screens (diagnostics, PayPal test) assume you know what you are doing. They are read-mostly but not sandboxed.

## 中文简介

这是一套**商标代理机构业务后台**，PHP 8.1 + MySQL，无需框架、无需构建步骤。覆盖「询价 → 报价 → 订单 → 收款（银行转账 / PayPal）→ 文件收集 → 进度时间线」全流程，中英双语界面，含管理后台、发信队列、限流与静默反垃圾。

代码取自 china-trademark.net 的生产系统，该门户已被新系统替代后整理开源。数据库口令、银行账户、公司公章、客户数据均已移除，配置项改为 `example.com` 占位符。

## License

MIT — see [LICENSE](LICENSE). Bundled third-party assets: see [THIRD_PARTY.md](THIRD_PARTY.md).

Originally built for [china-trademark.net](https://china-trademark.net/) · [45-class Chinese–English classification dataset](https://github.com/mycntm/china-trademark-registration-guide)
