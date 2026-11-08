DROP TABLE IF EXISTS `articles`;
CREATE TABLE `articles` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

	`title` TEXT NULL,
	`author_id` INT UNSIGNED NOT NULL,
	INDEX `idx_author` (`author_id`),

	PRIMARY KEY (`id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `article_tags`;
CREATE TABLE `article_tags` (
	`article_id` INT UNSIGNED NOT NULL,
	`tag_id` INT UNSIGNED NOT NULL,
	`position` INT UNSIGNED NOT NULL,
	INDEX `idx_article_id` (`article_id`),
	INDEX `idx_tag_id` (`tag_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `likes`;
CREATE TABLE `likes` (
	`liked_id` INT UNSIGNED NOT NULL,
	`liked_type` VARCHAR(191) NOT NULL,
	`author_id` INT UNSIGNED NOT NULL,
	INDEX `idx_liked_id` (`liked_id`, `liked_type`),
	INDEX `idx_author_id` (`author_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `tags`;
CREATE TABLE `tags` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

	`name` TEXT NULL,

	PRIMARY KEY (`id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `photos`;
CREATE TABLE `photos` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

	`url` TEXT NULL,

	PRIMARY KEY (`id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `comments`;
CREATE TABLE `comments` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

	`body` TEXT NULL,
	`subject_type` VARCHAR(191) NOT NULL,
	`subject_id` INT UNSIGNED NOT NULL,
	INDEX `idx_subject` (`subject_type`, `subject_id`),

	PRIMARY KEY (`id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `authors`;
CREATE TABLE `authors` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

	`name` TEXT NULL,

	PRIMARY KEY (`id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `friendships`;
CREATE TABLE `friendships` (
	`author_id` INT UNSIGNED NOT NULL,
	`friend_id` INT UNSIGNED NOT NULL,
	INDEX `idx_author_id` (`author_id`),
	INDEX `idx_friend_id` (`friend_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

