DROP TABLE IF NOT EXISTS `forums`
CREATE TABLE `forums` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

	`name` TEXT NOT NULL,
	`description` TEXT,

	PRIMARY KEY (`id`)
);

DROP TABLE IF NOT EXISTS `forum_messages`
CREATE TABLE `forum_messages` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

	`forum_id` INT UNSIGNED NOT NULL,
	`parent_message_id` INT UNSIGNED,
	`title` TEXT NOT NULL,
	`message_summary` TEXT NOT NULL,
	`created_at` DATETIME NOT NULL,
	`modified_at` DATETIME,
	`user_id` INT UNSIGNED NOT NULL,
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

	`message_content` TEXT NOT NULL,

	PRIMARY KEY (`id`)
);

DROP TABLE IF NOT EXISTS `forum_message_tags`
CREATE TABLE `forum_message_tags` (
	`forum_message_id` INT UNSIGNED NOT NULL,
	`tag_id` INT UNSIGNED NOT NULL
);

DROP TABLE IF NOT EXISTS `prop_map`
CREATE TABLE `prop_map` (
	`object_type` TEXT NOT NULL,
	`id` INT UNSIGNED NOT NULL,
	`key` TEXT NOT NULL,
	`value` TEXT NOT NULL
);

DROP TABLE IF NOT EXISTS `tags`
CREATE TABLE `tags` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

	`tag_name` TEXT,

	PRIMARY KEY (`id`)
);

DROP TABLE IF NOT EXISTS `users`
CREATE TABLE `users` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

	`email` TEXT,
	`password` TEXT,
	`name` TEXT,

	PRIMARY KEY (`id`)
);

