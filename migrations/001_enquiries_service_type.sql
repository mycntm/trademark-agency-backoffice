-- =====================================================================
--  迁移 001：询价工单支持「业务类型」与「已有商标号」
--  适用于 schema.sql 已执行过的线上库（新建库直接用 app/schema.sql 即可）
--  执行方式：phpMyAdmin → SQL，或 mysql -u用户 -p 库名 < 本文件
--  幂等：MySQL 不支持 ADD COLUMN IF NOT EXISTS，重复执行会报 1060，
--        报「Duplicate column name」即说明已加过，忽略即可。
-- =====================================================================

ALTER TABLE `enquiries`
  ADD COLUMN `service_type`    VARCHAR(40) NOT NULL DEFAULT '' AFTER `ref`,
  ADD COLUMN `existing_reg_no` VARCHAR(40) NOT NULL DEFAULT '' AFTER `mark_name`,
  ADD KEY `ix_enq_service` (`service_type`);

-- 可选：把历史工单默认标记为新注册，便于后台筛选
UPDATE `enquiries` SET `service_type` = 'registration' WHERE `service_type` = '';
