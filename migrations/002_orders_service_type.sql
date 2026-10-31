-- =====================================================================
--  迁移 002：订单保留「办理事项」与「已有商标号」
--
--  背景：工单（enquiries）已有 service_type / existing_reg_no，
--        但转订单时这两个字段没带过去，导致 ——
--        (1) 客户中心无从显示办的是什么业务；
--        (2) 后续业务（续展/转让/异议等 29 项）工单的 mark_name 本就为空，
--            转订单后订单标题出现空白。
--
--  适用：schema.sql 已执行过的线上库（新建库直接用 app/schema.sql）
--  执行：phpMyAdmin → SQL，或 mysql -u用户 -p 库名 < 本文件
--  幂等：MySQL 无 ADD COLUMN IF NOT EXISTS；重复执行报 1060
--        「Duplicate column name」即说明已加过，忽略即可。
-- =====================================================================

ALTER TABLE `orders`
  ADD COLUMN `service_type`    VARCHAR(40) DEFAULT NULL AFTER `quote_id`,
  ADD COLUMN `existing_reg_no` VARCHAR(40) NOT NULL DEFAULT '' AFTER `mark_name`,
  ADD KEY `ix_order_service` (`service_type`);

-- 回填历史订单：从关联工单把办理事项与商标号带过来
UPDATE `orders` o
  JOIN `enquiries` e ON e.`id` = o.`enquiry_id`
   SET o.`service_type`    = NULLIF(e.`service_type`, ''),
       o.`existing_reg_no` = IFNULL(e.`existing_reg_no`, '')
 WHERE o.`enquiry_id` IS NOT NULL;

-- 仍为空的历史订单（多半是最早的新注册单）默认标记为新注册
UPDATE `orders` SET `service_type` = 'registration' WHERE `service_type` IS NULL;
