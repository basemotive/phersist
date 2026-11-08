DROP TABLE IF EXISTS `posts`;
CREATE TABLE `posts` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

	`title` TEXT NULL,
	`author_id` INT UNSIGNED NOT NULL,
	`editor_id` INT UNSIGNED NULL,
	INDEX `idx_author` (`author_id`),
	INDEX `idx_editor` (`editor_id`),

	PRIMARY KEY (`id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `post_likes`;
CREATE TABLE `post_likes` (
	`post_id` INT UNSIGNED NOT NULL,
	`user_id` INT UNSIGNED NOT NULL,
	INDEX `idx_post_id` (`post_id`),
	INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `comments`;
CREATE TABLE `comments` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

	`post_id` INT UNSIGNED NULL,
	`subject_type` VARCHAR(191) NULL,
	`subject_id` INT UNSIGNED NULL,
	INDEX `idx_post` (`post_id`),
	INDEX `idx_subject` (`subject_type`, `subject_id`),

	PRIMARY KEY (`id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

