-- securelink_schema_prod.sql
-- StrongSwan monitoring DB schema
-- Schema version: 1
-- Engine: MySQL/MariaDB
-- NOTE: creates a separate DB "securelink" and does NOT touch "radius"

-- 1) Create dedicated database for monitoring (if not exists)
CREATE DATABASE IF NOT EXISTS `securelink`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

-- 2) Use this database
USE `securelink`;

-- 3) Tables

-- 3.1 profiles
CREATE TABLE IF NOT EXISTS `profiles` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `status` enum('Active','Inactive','New') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Active',
  `note` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- 3.2 tunnel_stats
CREATE TABLE IF NOT EXISTS `tunnel_stats` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tunnel_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `bytes_in` bigint unsigned NOT NULL,
  `bytes_out` bigint unsigned NOT NULL,
  `ts` int unsigned NOT NULL,
  `child_name` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `client_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_tunnel_time` (`tunnel_name`,`ts`),
  KEY `idx_client` (`tunnel_name`,`child_name`,`client_id`,`ts`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- 3.3 user_tunnels
CREATE TABLE IF NOT EXISTS `user_tunnels` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `profile_id` int unsigned NOT NULL,
  `tunnel_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_profile` (`user_id`,`profile_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- 3.4 users
CREATE TABLE IF NOT EXISTS `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` enum('admin','user') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
