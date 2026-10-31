-- =====================================================================
--  004_order_details.sql —— 订单详情增强
--
--  背景：用户要求订单信息至少包含——
--    商标名称 / 商标图样 / 申请号 / 注册号 / 申请人 / 申请人地址 /
--    商品和服务（goods_services：默认留空，后台手工输入）
--
--  申请号 cnipa_app_no 建表就有；本迁移补两列：
--    1) orders.cnipa_reg_no    注册号（核准注册后有正式注册号，与申请号不同）
--    2) orders.goods_services  商品和服务（Nice Classification 项目清单；
--                              不自动从询价带出，留空由后台手工输入）
--
--  ★ 全部语句幂等：列已存在时自动跳过，可放心重复执行。
--    在宝塔 → 数据库 → phpMyAdmin / SQL 里整体执行即可。
--    不跑本迁移页面也不 500（代码侧全部用 table_has_column 降级）。
-- =====================================================================

SET NAMES utf8mb4;
SET @db = DATABASE();

-- ---------------------------------------------------------------------
-- 1. orders.cnipa_reg_no 注册号
-- ---------------------------------------------------------------------
SET @e = (SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA=@db AND TABLE_NAME='orders' AND COLUMN_NAME='cnipa_reg_no');
SET @s = IF(@e=0,
  'ALTER TABLE `orders` ADD COLUMN `cnipa_reg_no` VARCHAR(60) NOT NULL DEFAULT '''' AFTER `cnipa_app_no`, ADD KEY `ix_order_regno` (`cnipa_reg_no`)',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------
-- 2. orders.goods_services 商品和服务（后台手工输入，可留空）
-- ---------------------------------------------------------------------
SET @e = (SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA=@db AND TABLE_NAME='orders' AND COLUMN_NAME='goods_services');
SET @s = IF(@e=0,
  'ALTER TABLE `orders` ADD COLUMN `goods_services` MEDIUMTEXT NULL AFTER `classes_json`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
