-- =====================================================================
--  example.com · Quote-to-Order schema (MySQL 5.7+ / MariaDB 10.2+)
--  Charset: utf8mb4  Collation: utf8mb4_unicode_ci
--  Engine: InnoDB
--  设计原则：enquiries（询价，可变）与 orders（订单，固化）分表。
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. clients  客户账号（由我们用客户邮箱创建，客户不自行注册）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `clients` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`          VARCHAR(190) NOT NULL,
  `name`           VARCHAR(120) NOT NULL DEFAULT '',
  `company`        VARCHAR(190) NOT NULL DEFAULT '',
  `country`        VARCHAR(80)  NOT NULL DEFAULT '',
  `phone`          VARCHAR(50)  NOT NULL DEFAULT '',
  `lang`           ENUM('en','zh') NOT NULL DEFAULT 'en',
  `password_hash`  VARCHAR(255) DEFAULT NULL,          -- 可空：支持无密码 magic link 登录
  `status`         ENUM('active','disabled') NOT NULL DEFAULT 'active',
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_clients_email` (`email`),
  KEY `ix_clients_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. enquiries  询价工单（成交前，信息会反复变动）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `enquiries` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ref`            VARCHAR(24)  NOT NULL,               -- 工单号 EN-20260929-0007
  `service_type`   VARCHAR(40)  NOT NULL DEFAULT '',    -- 业务类型（对应 app/lib/pricing.php 的 key）
  `client_id`      INT UNSIGNED DEFAULT NULL,           -- 已建档后回填
  `email`          VARCHAR(190) NOT NULL,
  `name`           VARCHAR(120) NOT NULL DEFAULT '',
  `company`        VARCHAR(190) NOT NULL DEFAULT '',
  `country`        VARCHAR(80)  NOT NULL DEFAULT '',
  `phone`          VARCHAR(50)  NOT NULL DEFAULT '',
  `mark_name`      VARCHAR(255) NOT NULL DEFAULT '',    -- 商标名称（文字部分）
  `existing_reg_no` VARCHAR(40) NOT NULL DEFAULT '',    -- 已有商标的注册号 / 申请号（后续业务）
  `mark_type`      ENUM('word','device','combined','sound','other') NOT NULL DEFAULT 'word',
  `device_file_id` INT UNSIGNED DEFAULT NULL,           -- 图样 -> documents.id
  `applicant_type` ENUM('individual','company','other') NOT NULL DEFAULT 'company',
  `classes_json`   LONGTEXT,                            -- JSON: [{class:9, heading:"...", items:[{code,name,en}]}]
  `items_count`    INT UNSIGNED NOT NULL DEFAULT 0,
  `class_count`    INT UNSIGNED NOT NULL DEFAULT 0,
  `est_price`      DECIMAL(10,2) NOT NULL DEFAULT 0.00, -- 前端估价（USD），仅为估算
  `currency`       CHAR(3) NOT NULL DEFAULT 'USD',
  `note`           TEXT,
  `lang`           ENUM('en','zh') NOT NULL DEFAULT 'en',
  `consent_terms`  TINYINT(1) NOT NULL DEFAULT 0,       -- 是否勾选同意条款
  `consent_at`     DATETIME DEFAULT NULL,               -- 同意时间（法律效力关键）
  `status`         ENUM('new','processing','quoted','confirmed','declined','closed') NOT NULL DEFAULT 'new',
  `source`         ENUM('web','email','phone','admin') NOT NULL DEFAULT 'web',
  `ip`             VARCHAR(45)  NOT NULL DEFAULT '',
  `ua`             VARCHAR(255) NOT NULL DEFAULT '',
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_enq_ref` (`ref`),
  KEY `ix_enq_email` (`email`),
  KEY `ix_enq_service` (`service_type`),
  KEY `ix_enq_status` (`status`),
  KEY `ix_enq_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. quotes  报价（我们出具，客户确认后转订单）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `quotes` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `enquiry_id`     INT UNSIGNED NOT NULL,
  `ref`            VARCHAR(24)  NOT NULL,               -- Q-20260929-0007
  `official_fee`   DECIMAL(10,2) NOT NULL DEFAULT 0.00, -- CNIPA 官费（代收，成败不退）
  `service_fee`    DECIMAL(10,2) NOT NULL DEFAULT 0.00, -- 我方服务费
  `translation_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00,-- 翻译/制文（默认 USD 30/套）
  `extras_json`    LONGTEXT,                            -- 附加服务 [{label, amount}]
  `total`          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `currency`       CHAR(3) NOT NULL DEFAULT 'USD',
  `valid_until`    DATE DEFAULT NULL,
  `token`          VARCHAR(64)  NOT NULL,               -- 客户查看/确认报价的无登录令牌
  `note`           TEXT,
  `status`         ENUM('draft','sent','accepted','declined','expired') NOT NULL DEFAULT 'draft',
  `accepted_at`    DATETIME DEFAULT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_quote_ref` (`ref`),
  UNIQUE KEY `uq_quote_token` (`token`),
  KEY `ix_quote_enquiry` (`enquiry_id`),
  KEY `ix_quote_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. orders  订单（四项要素固化后生成，价格不再变动）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `orders` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ref`            VARCHAR(24)  NOT NULL,               -- ORD-20260929-0007
  `client_id`      INT UNSIGNED NOT NULL,
  `enquiry_id`     INT UNSIGNED DEFAULT NULL,
  `quote_id`       INT UNSIGNED DEFAULT NULL,
  `service_type`   VARCHAR(40)  DEFAULT NULL,            -- 办理事项（pricing key，如 renewal / opposition）
  -- 四项要素（成交确认后固化）
  `mark_name`      VARCHAR(255) NOT NULL DEFAULT '',
  `existing_reg_no` VARCHAR(40) NOT NULL DEFAULT '',     -- 已注册商标号 / 申请号（后续业务用，新申请为空）
  `mark_type`      ENUM('word','device','combined','sound','other') NOT NULL DEFAULT 'word',
  `device_file_id` INT UNSIGNED DEFAULT NULL,
  `registrant_name` VARCHAR(190) NOT NULL DEFAULT '',   -- 注册人
  `registrant_addr` TEXT,
  `registrant_type` ENUM('individual','company','other') NOT NULL DEFAULT 'company',
  `classes_json`   LONGTEXT,
  `goods_services` MEDIUMTEXT NULL,                 -- 商品和服务（Nice 项目清单；留空后台手工输入，004）
  `poa_file_id`    INT UNSIGNED DEFAULT NULL,           -- 委托书
  -- 金额
  `official_fee`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `service_fee`    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `translation_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total`          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `currency`       CHAR(3) NOT NULL DEFAULT 'USD',
  `paid_amount`    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  -- CNIPA
  `cnipa_app_no`   VARCHAR(60)  NOT NULL DEFAULT '',    -- 申请号
  `cnipa_reg_no`   VARCHAR(60)  NOT NULL DEFAULT '',    -- 注册号（核准注册后，004）
  `filed_at`       DATETIME DEFAULT NULL,
  -- 状态：成交前 pending_payment -> paid；办理中 filed -> examining -> published -> registered
  `status`         ENUM('pending_payment','paid','filed','examining','published','opposed','registered','refused','withdrawn','refunded') NOT NULL DEFAULT 'pending_payment',
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_ref` (`ref`),
  KEY `ix_order_client` (`client_id`),
  KEY `ix_order_status` (`status`),
  KEY `ix_order_appno` (`cnipa_app_no`),
  KEY `ix_order_service` (`service_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. order_events  进度时间线（人工更新，客户可见）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `order_events` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`  INT UNSIGNED NOT NULL,
  `stage`     ENUM('pending_payment','paid','filed','examining','published','opposed','registered','refused','withdrawn','refunded','note') NOT NULL DEFAULT 'note',
  `title_en`  VARCHAR(255) NOT NULL DEFAULT '',
  `title_zh`  VARCHAR(255) NOT NULL DEFAULT '',
  `note`      TEXT,
  `visible`   TINYINT(1) NOT NULL DEFAULT 1,            -- 0 = 内部备注，客户不可见
  `admin_id`  INT UNSIGNED DEFAULT NULL,                -- 谁加的这条（系统自动产生为 0/NULL）
  `deleted_at` DATETIME DEFAULT NULL,                  -- 软删除：客户看过的时间线不做物理删除
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_evt_order` (`order_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. payments  付款（PayPal 为主，银行转账兜底）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payments` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`     INT UNSIGNED NOT NULL,
  `amount`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `currency`     CHAR(3) NOT NULL DEFAULT 'USD',
  `method`       ENUM('paypal','bank_transfer','other') NOT NULL DEFAULT 'paypal',
  `provider`     VARCHAR(40)  NOT NULL DEFAULT '',       -- 'paypal'
  `provider_ref` VARCHAR(120) NOT NULL DEFAULT '',       -- PayPal order id / capture id
  `provider_json` LONGTEXT,                              -- 原始回调 payload（对账用）
  `status`       ENUM('created','pending','completed','failed','refunded') NOT NULL DEFAULT 'created',
  `paid_at`      DATETIME DEFAULT NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_pay_order` (`order_id`),
  KEY `ix_pay_ref` (`provider_ref`),
  KEY `ix_pay_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. documents  文件（图样 / 委托书 / 身份证件 / 通知书 / 证书）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `documents` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `owner_type`   ENUM('enquiry','order','client') NOT NULL DEFAULT 'enquiry',
  `owner_id`     INT UNSIGNED NOT NULL DEFAULT 0,
  `kind`         ENUM('device','poa','id_doc','notice','certificate','other') NOT NULL DEFAULT 'other',
  `path`         VARCHAR(255) NOT NULL,                 -- 相对 STORAGE_PATH，不暴露真实路径
  `stored_name`  VARCHAR(190) NOT NULL DEFAULT '',
  `original_name` VARCHAR(190) NOT NULL DEFAULT '',
  `mime`         VARCHAR(100) NOT NULL DEFAULT '',
  `size`         INT UNSIGNED NOT NULL DEFAULT 0,
  `sensitive`    TINYINT(1) NOT NULL DEFAULT 0,         -- 1 = 敏感（证件/委托书），访问需鉴权+留日志
  `uploaded_by`  ENUM('client','admin','system') NOT NULL DEFAULT 'client',
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_doc_owner` (`owner_type`, `owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. document_access_log  敏感文件访问日志（合规）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `document_access_log` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `document_id` INT UNSIGNED NOT NULL,
  `actor_type`  ENUM('admin','client','system') NOT NULL DEFAULT 'system',
  `actor_id`    INT UNSIGNED NOT NULL DEFAULT 0,
  `ip`          VARCHAR(45) NOT NULL DEFAULT '',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_dal_doc` (`document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 9. messages  站内留言（邮件为主，站内为辅）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `messages` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`   INT UNSIGNED DEFAULT NULL,
  `enquiry_id` INT UNSIGNED DEFAULT NULL,
  `sender_role` ENUM('client','admin','system') NOT NULL DEFAULT 'system',
  `sender_id`  INT UNSIGNED DEFAULT NULL,
  `body`       TEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_msg_order` (`order_id`),
  KEY `ix_msg_enquiry` (`enquiry_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 10. admin_users  后台账号
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_users` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(60) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `name`          VARCHAR(120) NOT NULL DEFAULT '',
  `role`          ENUM('owner','staff','viewer') NOT NULL DEFAULT 'staff',
  `can_view_sensitive` TINYINT(1) NOT NULL DEFAULT 0,  -- 可查看证件/委托书
  `lang`          ENUM('en','zh') NOT NULL DEFAULT 'zh',
  `status`        ENUM('active','disabled') NOT NULL DEFAULT 'active',
  `last_login_at` DATETIME DEFAULT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 11. auth_tokens  无密码登录 magic link（30 分钟一次性）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `auth_tokens` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id`  INT UNSIGNED NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `purpose`    ENUM('login','quote_view','order_view') NOT NULL DEFAULT 'login',
  `target_id`  INT UNSIGNED DEFAULT NULL,               -- quote_id / order_id
  `expires_at` DATETIME NOT NULL,
  `used_at`    DATETIME DEFAULT NULL,
  `ip`         VARCHAR(45) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token_hash` (`token_hash`),
  KEY `ix_token_client` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 12. mail_queue  发信队列与日志（SMTP 失败可重投）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mail_queue` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `to_email`    VARCHAR(190) NOT NULL,
  `to_name`     VARCHAR(120) NOT NULL DEFAULT '',
  `subject`     VARCHAR(255) NOT NULL DEFAULT '',
  `body_html`   LONGTEXT,
  `body_text`   LONGTEXT,
  `lang`        ENUM('en','zh') NOT NULL DEFAULT 'en',
  `status`      ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
  `attempts`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `last_error`  VARCHAR(255) NOT NULL DEFAULT '',
  `related_type` ENUM('enquiry','quote','order','payment','other') NOT NULL DEFAULT 'other',
  `related_id`  INT UNSIGNED DEFAULT NULL,
  `sent_at`     DATETIME DEFAULT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_mail_status` (`status`, `created_at`),
  KEY `ix_mail_related` (`related_type`, `related_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 13. webhook_events  PayPal webhook 去重与验签留痕
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `webhook_events` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider`     VARCHAR(40) NOT NULL DEFAULT 'paypal',
  `event_id`     VARCHAR(120) NOT NULL,                 -- PayPal WH-xxxx id，用于幂等
  `event_type`   VARCHAR(120) NOT NULL DEFAULT '',
  `payload_json` LONGTEXT,
  `verified`     TINYINT(1) NOT NULL DEFAULT 0,
  `processed`    TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_webhook_event` (`provider`, `event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 14. settings  KV 配置（后台可改，如报价默认值、支付渠道开关）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
  `k` VARCHAR(80) NOT NULL,
  `v` LONGTEXT,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`k`,`v`) VALUES
  ('paypal_enabled',      '0'),
  ('bank_transfer_enabled','1'),
  ('translation_fee',     '30'),
  ('quote_valid_days',    '30'),
  ('currency',            'USD');

-- ---------------------------------------------------------------------
-- 15. rate_limits  提交频率限制
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rate_limits` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bucket`     VARCHAR(60) NOT NULL,
  `ip`         VARCHAR(45) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_rl_bucket` (`bucket`, `ip`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 16. contracts  服务合同（自动生成 + 客户在线手写签署，2026-10-15）
--   一个订单一份合同（uq_contract_order）。客户在 contract.php 手写签名后，
--   签名 PNG 落在 documents（kind='other'，本表的 signature_doc_id 指向它），
--   合同随即变为 signed —— 页面把签名合成进合同正文，可打印/存 PDF。
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `contracts` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`         INT UNSIGNED NOT NULL,
  `client_id`        INT UNSIGNED NOT NULL,
  `status`           ENUM('draft','signed') NOT NULL DEFAULT 'draft',
  `signed_name`      VARCHAR(190) NOT NULL DEFAULT '',    -- 签署人印刷体姓名（客户填）
  `signature_doc_id` INT UNSIGNED DEFAULT NULL,           -- 手写签名 PNG -> documents.id
  `signed_at`        DATETIME DEFAULT NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_contract_order` (`order_id`),
  KEY `ix_contract_client` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
