-- =====================================================================
--  005_enquiry_processing.sql —— 询价工单新增「处理中」状态
--
--  背景：工单只有「发送报价 / 生成订单 / 手动改状态」才会离开 new，
--        日常处理动作（保存报价草稿、写内部备注）不触发流转，
--        导致侧栏 New 徽标永远有数。新增 processing 状态：
--        管理员做过任一处理动作即自动 new → processing。
--
--  ★ 幂等：ENUM 已含 processing 时跳过，可放心重复执行。
--    在宝塔 → 数据库 → phpMyAdmin / SQL 里整体执行即可。
-- =====================================================================

SET NAMES utf8mb4;
SET @db = DATABASE();

-- enquiries.status 扩展 ENUM（按字符串值映射转换，原有数据不受影响）
SET @t = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA=@db AND TABLE_NAME='enquiries' AND COLUMN_NAME='status');
SET @s = IF(@t LIKE '%processing%',
  'SELECT 1',
  'ALTER TABLE `enquiries` MODIFY `status` ENUM(''new'',''processing'',''quoted'',''confirmed'',''declined'',''closed'') NOT NULL DEFAULT ''new''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
