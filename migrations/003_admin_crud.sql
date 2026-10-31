-- =====================================================================
--  003_admin_crud.sql —— 后台全流程 CRUD + 节点附件
--
--  覆盖：
--    1) documents  挂到进度节点（event_id）/ 标题 / 备注 / 客户可见 / 软删除
--    2) orders     作废（voided_at / void_reason）
--    3) order_events 记录操作人（admin_id）+ 节点软删除（deleted_at）
--    4) payments   退款登记（refund_amount / refunded_at / refund_note）+ 操作人
--    5) enquiries  合并重复（merged_into）
--    6) clients    内部备注（note）
--    7) admin_log  后台操作留痕（新建表）
--
--  ★ 全部语句幂等：列已存在时自动跳过，可放心重复执行。
--    在宝塔 → 数据库 → phpMyAdmin / SQL 里整体执行即可。
-- =====================================================================

SET NAMES utf8mb4;
SET @db = DATABASE();

-- ---------------------------------------------------------------------
-- 1. documents：节点附件
-- ---------------------------------------------------------------------
-- event_id：附件挂在哪个进度节点上（NULL = 只属于订单，不属于某个节点）
SET @e = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='documents' AND COLUMN_NAME='event_id');
SET @s = IF(@e=0,
  'ALTER TABLE `documents` ADD COLUMN `event_id` INT UNSIGNED DEFAULT NULL AFTER `owner_id`, ADD KEY `ix_doc_event` (`event_id`)',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- title：给客户看的标题，如「受理通知书 Acceptance Notice」
SET @e = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='documents' AND COLUMN_NAME='title');
SET @s = IF(@e=0, 'ALTER TABLE `documents` ADD COLUMN `title` VARCHAR(255) NOT NULL DEFAULT '''' AFTER `kind`', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @e = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='documents' AND COLUMN_NAME='note');
SET @s = IF(@e=0, 'ALTER TABLE `documents` ADD COLUMN `note` TEXT NULL AFTER `title`', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- visible：0 = 仅内部可见（客户在 account.php 看不到）
SET @e = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='documents' AND COLUMN_NAME='visible');
SET @s = IF(@e=0, 'ALTER TABLE `documents` ADD COLUMN `visible` TINYINT(1) NOT NULL DEFAULT 1 AFTER `note`', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- deleted_at：软删除。法律业务记录不做物理删除，删了也留痕、可恢复
SET @e = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='documents' AND COLUMN_NAME='deleted_at');
SET @s = IF(@e=0, 'ALTER TABLE `documents` ADD COLUMN `deleted_at` DATETIME DEFAULT NULL AFTER `uploaded_by`', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------
-- 2. orders：作废
-- ---------------------------------------------------------------------
SET @e = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='orders' AND COLUMN_NAME='voided_at');
SET @s = IF(@e=0, 'ALTER TABLE `orders` ADD COLUMN `voided_at` DATETIME DEFAULT NULL AFTER `filed_at`', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @e = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='orders' AND COLUMN_NAME='void_reason');
SET @s = IF(@e=0, 'ALTER TABLE `orders` ADD COLUMN `void_reason` TEXT NULL AFTER `voided_at`', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------
-- 3. order_events：谁加的这个节点 + 软删除
-- ---------------------------------------------------------------------
SET @e = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='order_events' AND COLUMN_NAME='admin_id');
SET @s = IF(@e=0, 'ALTER TABLE `order_events` ADD COLUMN `admin_id` INT UNSIGNED DEFAULT NULL AFTER `visible`', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- deleted_at：进度节点软删除。
-- 节点是发给客户看过的时间线，录错了可以删，但不能凭空消失——
-- 客户两年后拿着截图来问「这条怎么没了」，我们得答得上来。所以同样软删 + 可恢复。
SET @e = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='order_events' AND COLUMN_NAME='deleted_at');
SET @s = IF(@e=0, 'ALTER TABLE `order_events` ADD COLUMN `deleted_at` DATETIME DEFAULT NULL AFTER `admin_id`', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------
-- 4. payments：退款登记
-- ---------------------------------------------------------------------
SET @e = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='payments' AND COLUMN_NAME='refund_amount');
SET @s = IF(@e=0, 'ALTER TABLE `payments` ADD COLUMN `refund_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `paid_at`', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @e = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='payments' AND COLUMN_NAME='refunded_at');
SET @s = IF(@e=0, 'ALTER TABLE `payments` ADD COLUMN `refunded_at` DATETIME DEFAULT NULL AFTER `refund_amount`', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @e = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='payments' AND COLUMN_NAME='refund_note');
SET @s = IF(@e=0, 'ALTER TABLE `payments` ADD COLUMN `refund_note` TEXT NULL AFTER `refunded_at`', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @e = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='payments' AND COLUMN_NAME='admin_id');
SET @s = IF(@e=0, 'ALTER TABLE `payments` ADD COLUMN `admin_id` INT UNSIGNED DEFAULT NULL AFTER `refund_note`', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @e = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='payments' AND COLUMN_NAME='note');
SET @s = IF(@e=0, 'ALTER TABLE `payments` ADD COLUMN `note` TEXT NULL AFTER `admin_id`', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- status 枚举补一个 partially_refunded：只退了一部分时，别拿「已退款」糊弄过去。
-- 幂等判断：看 COLUMN_TYPE 里是否已经含这个枚举值，含了就跳过。
SET @e = (SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA=@db AND TABLE_NAME='payments' AND COLUMN_NAME='status'
            AND COLUMN_TYPE LIKE '%partially_refunded%');
SET @s = IF(@e=0,
  'ALTER TABLE `payments` MODIFY COLUMN `status` ENUM(''created'',''pending'',''completed'',''failed'',''refunded'',''partially_refunded'') NOT NULL DEFAULT ''created''',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------
-- 5. enquiries：合并重复工单
-- ---------------------------------------------------------------------
SET @e = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='enquiries' AND COLUMN_NAME='merged_into');
SET @s = IF(@e=0, 'ALTER TABLE `enquiries` ADD COLUMN `merged_into` INT UNSIGNED DEFAULT NULL AFTER `source`', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- deleted_at：询价工单的软删除。
-- 询价是「成交前」的信息，严格说不是法律凭证，但删了就断了线索——
-- 客户三个月后回来说「我当初问过这个」，我们得还能翻出来。所以统一软删。
SET @e = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='enquiries' AND COLUMN_NAME='deleted_at');
SET @s = IF(@e=0, 'ALTER TABLE `enquiries` ADD COLUMN `deleted_at` DATETIME DEFAULT NULL AFTER `merged_into`', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------
-- 6. clients：内部备注
-- ---------------------------------------------------------------------
SET @e = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='clients' AND COLUMN_NAME='note');
SET @s = IF(@e=0, 'ALTER TABLE `clients` ADD COLUMN `note` TEXT NULL AFTER `status`', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------
-- 7. admin_log：后台操作留痕
--    「若干年后客户回来找文件」——谁在什么时候改了什么，必须查得到。
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_log` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`    INT UNSIGNED NOT NULL DEFAULT 0,
  `action`      VARCHAR(60)  NOT NULL DEFAULT '',   -- create / update / delete / void / refund / upload / login
  `target_type` VARCHAR(40)  NOT NULL DEFAULT '',   -- order / enquiry / client / payment / document / admin_user
  `target_id`   INT UNSIGNED DEFAULT NULL,
  `detail`      TEXT,
  `ip`          VARCHAR(45)  NOT NULL DEFAULT '',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_alog_admin` (`admin_id`),
  KEY `ix_alog_target` (`target_type`, `target_id`),
  KEY `ix_alog_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. 放宽上传白名单里需要用到的 MIME —— 见 config.php 的 storage.allowed_mime
--    （Word：application/msword、application/vnd.openxmlformats-officedocument.wordprocessingml.document）
--    数据库侧无需改动，这里只是提醒：改完 config.php 记得同步上传。
-- ---------------------------------------------------------------------

SELECT '003_admin_crud.sql done' AS result;
