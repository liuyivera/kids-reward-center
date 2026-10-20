-- ============================================================
-- Family Points — 完整建库脚本
-- 适用于全新部署（Hostgator / 本地 MAMP / 任何 MySQL 5.7+）
-- 导入方式：phpMyAdmin → 选中数据库 → Import → 选此文件
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- 1. families：家庭（租户根）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `families` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_families_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- 2. parent_users：家长账号
--    family_id 允许 NULL，首次登录后再绑定家庭
--    is_creator 标识应用创建者（跨家庭维护者）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `parent_users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `family_id` int unsigned DEFAULT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `is_creator` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `idx_parent_users_family_id` (`family_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- 3. children：孩子
--    access_code 给孩子免登录访问商店用
--    theme 决定前端加载哪个 css（minecraft / music / default）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `children` (
  `id` int NOT NULL AUTO_INCREMENT,
  `family_id` int unsigned DEFAULT NULL,
  `access_code` varchar(32) DEFAULT NULL,
  `access_code_expires_at` datetime DEFAULT NULL,
  `name` varchar(50) NOT NULL,
  `theme` varchar(30) NOT NULL DEFAULT 'default',
  `normal_balance` int NOT NULL DEFAULT '0',
  `daily_points_today` int NOT NULL DEFAULT '0',
  `last_score_date` date DEFAULT NULL,
  `quarterly_pool` int NOT NULL DEFAULT '0',
  `daily_cap` int NOT NULL DEFAULT '25',
  `quarterly_goal` int NOT NULL DEFAULT '150',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_children_family_id` (`family_id`),
  KEY `idx_children_access_code` (`access_code`),
  KEY `idx_children_access_expires` (`access_code_expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- 4. behaviors：好事定义（每个孩子一套）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `behaviors` (
  `id` int NOT NULL AUTO_INCREMENT,
  `child_id` int NOT NULL,
  `label` varchar(100) NOT NULL,
  `emoji` varchar(10) DEFAULT '⭐',
  `base_points` int NOT NULL,
  `daily_limit` int NOT NULL DEFAULT '1',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `child_id` (`child_id`),
  CONSTRAINT `behaviors_ibfk_1` FOREIGN KEY (`child_id`) REFERENCES `children` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- 5. rewards：奖励（每个孩子一套商城）
--    emoji 默认值 = 🎁 (UTF-8: F0 9F 8E 81)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rewards` (
  `id` int NOT NULL AUTO_INCREMENT,
  `child_id` int NOT NULL,
  `title` varchar(100) NOT NULL,
  `emoji` varchar(10) DEFAULT '🎁',
  `cost` int NOT NULL,
  `requires_parent_confirmation` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `child_id` (`child_id`),
  CONSTRAINT `rewards_ibfk_1` FOREIGN KEY (`child_id`) REFERENCES `children` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- 6. redemptions：兑换申请 / 审批记录
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `redemptions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `child_id` int NOT NULL,
  `reward_id` int NOT NULL,
  `cost` int NOT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `requested_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `approved_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `child_id` (`child_id`),
  KEY `reward_id` (`reward_id`),
  CONSTRAINT `redemptions_ibfk_1` FOREIGN KEY (`child_id`) REFERENCES `children` (`id`) ON DELETE CASCADE,
  CONSTRAINT `redemptions_ibfk_2` FOREIGN KEY (`reward_id`) REFERENCES `rewards` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- 7. point_events：积分事件流水
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `point_events` (
  `id` int NOT NULL AUTO_INCREMENT,
  `child_id` int NOT NULL,
  `behavior_id` int DEFAULT NULL,
  `behavior_label` varchar(100) NOT NULL,
  `base_points` int NOT NULL,
  `awarded_points` int NOT NULL,
  `overflow_points` int NOT NULL DEFAULT '0',
  `completion_type` varchar(30) NOT NULL,
  `event_date` date NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `child_id` (`child_id`),
  CONSTRAINT `point_events_ibfk_1` FOREIGN KEY (`child_id`) REFERENCES `children` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- 首个家长账号：系统没有注册页面，必须手动插入。
-- 步骤：
--   1. 本地终端运行（生成密码 hash）：
--      php -r "echo password_hash('你的密码', PASSWORD_DEFAULT), PHP_EOL;"
--   2. 复制输出的 $2y$... 字符串
--   3. 执行下面的 INSERT（替换 <hash> 和用户名）：
--
-- INSERT INTO families (name) VALUES ('我的家庭');
-- SET @fid = LAST_INSERT_ID();
-- INSERT INTO parent_users (family_id, username, password_hash, is_creator)
--   VALUES (@fid, 'admin', '<hash>', 1);
--
-- is_creator = 1 表示这是应用创建者，能访问 creator-admin.php。
-- 之后朋友注册的家庭设 is_creator = 0。
-- ============================================================