DROP TABLE IF EXISTS `forums`;
CREATE TABLE `forums` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

	`name` TEXT NOT NULL,
	`description` TEXT NULL,

	PRIMARY KEY (`id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `forum_messages`;
CREATE TABLE `forum_messages` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

	`forum_id` INT UNSIGNED NOT NULL,
	`parent_message_id` INT UNSIGNED NULL,
	`title` TEXT NOT NULL,
	`message_summary` TEXT NOT NULL,
	`created_at` DATETIME NOT NULL,
	`modified_at` DATETIME NULL,
	`user_id` INT UNSIGNED NOT NULL,
	`view_count` INT UNSIGNED NOT NULL DEFAULT 0,
	`message_content` TEXT NOT NULL,
	INDEX `idx_forum` (`forum_id`),
	INDEX `idx_parent_message` (`parent_message_id`),
	INDEX `idx_user` (`user_id`),

	PRIMARY KEY (`id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `forum_message_tags`;
CREATE TABLE `forum_message_tags` (
	`forum_message_id` INT UNSIGNED NOT NULL,
	`tag_id` INT UNSIGNED NOT NULL,
	INDEX `idx_forum_message_id` (`forum_message_id`),
	INDEX `idx_tag_id` (`tag_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `prop_map`;
CREATE TABLE `prop_map` (
	`object_type` VARCHAR(191) NOT NULL,
	`id` INT UNSIGNED NOT NULL,
	`key` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
	`value` TEXT NOT NULL,
	UNIQUE INDEX `uniq_id` (`object_type`, `id`, `key`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `tags`;
CREATE TABLE `tags` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

	`tag_name` TEXT NULL,

	PRIMARY KEY (`id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

	`email` TEXT NULL,
	`password` TEXT NULL,
	`name` TEXT NULL,

	PRIMARY KEY (`id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

