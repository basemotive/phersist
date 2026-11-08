DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

	`name` TEXT NOT NULL,

	PRIMARY KEY (`id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `user_groups`;
CREATE TABLE `user_groups` (
	`user_id` INT UNSIGNED NOT NULL,
	`group_id` INT UNSIGNED NOT NULL,
	INDEX `idx_user_id` (`user_id`),
	INDEX `idx_group_id` (`group_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `groups`;
CREATE TABLE `groups` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

	`name` TEXT NOT NULL,

	PRIMARY KEY (`id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

