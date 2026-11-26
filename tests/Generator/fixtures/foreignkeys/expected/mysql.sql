SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

	`name` TEXT NULL,
	`survey_set_id` INT UNSIGNED NULL,
	INDEX `idx_survey_set` (`survey_set_id`),

	PRIMARY KEY (`id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `user_profiles`;
CREATE TABLE `user_profiles` (
	`id` INT UNSIGNED NOT NULL,

	`bio` TEXT NULL,
	`avatar_id` INT UNSIGNED NULL,
	INDEX `idx_avatar` (`avatar_id`),

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

DROP TABLE IF EXISTS `friendships`;
CREATE TABLE `friendships` (
	`user_id` INT UNSIGNED NOT NULL,
	`friend_id` INT UNSIGNED NOT NULL,
	INDEX `idx_user_id` (`user_id`),
	INDEX `idx_friend_id` (`friend_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `user_settings`;
CREATE TABLE `user_settings` (
	`user_id` INT UNSIGNED NOT NULL,
	`name` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
	`value` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
	UNIQUE INDEX `uniq_user_id` (`user_id`, `name`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `notes`;
CREATE TABLE `notes` (
	`owner_type` VARCHAR(191) NOT NULL,
	`owner_id` INT UNSIGNED NOT NULL,
	`name` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
	`value` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
	UNIQUE INDEX `uniq_owner_id` (`owner_type`, `owner_id`, `name`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `groups`;
CREATE TABLE `groups` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

	`name` TEXT NULL,
	`parent_id` INT UNSIGNED NULL,
	INDEX `idx_parent` (`parent_id`),

	PRIMARY KEY (`id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `survey_sets`;
CREATE TABLE `survey_sets` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

	`survey_id` INT UNSIGNED NULL,
	INDEX `idx_survey` (`survey_id`),

	PRIMARY KEY (`id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `surveys`;
CREATE TABLE `surveys` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

	`owner_id` INT UNSIGNED NOT NULL,
	`reviewer_id` INT UNSIGNED NULL,
	`status` TEXT NULL,
	INDEX `idx_owner` (`owner_id`),
	INDEX `reviewer_status` (`reviewer_id`, `status`(10)),

	PRIMARY KEY (`id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `likes`;
CREATE TABLE `likes` (
	`liked_id` INT UNSIGNED NOT NULL,
	`liked_type` VARCHAR(191) NOT NULL,
	`user_id` INT UNSIGNED NOT NULL,
	INDEX `idx_liked_id` (`liked_id`, `liked_type`),
	INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `uploads`;
CREATE TABLE `uploads` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

	`subject_type` VARCHAR(191) NULL,
	`subject_id` INT UNSIGNED NULL,
	INDEX `idx_subject` (`subject_type`, `subject_id`),

	PRIMARY KEY (`id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `taggings`;
CREATE TABLE `taggings` (
	`item_id` INT UNSIGNED NOT NULL,
	`item_type` VARCHAR(191) NOT NULL,
	`tagged_id` INT UNSIGNED NOT NULL,
	INDEX `idx_item_id` (`item_id`, `item_type`),
	INDEX `idx_tagged_id` (`tagged_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `reports`;
CREATE TABLE `reports` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,


	PRIMARY KEY (`id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `invitations`;
CREATE TABLE `invitations` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

	`survey_id` INT UNSIGNED NULL,
	`sender_id` INT UNSIGNED NULL,
	`receiver_id` INT UNSIGNED NULL,
	`deleted` INT UNSIGNED NOT NULL DEFAULT 0,
	INDEX `idx_survey` (`survey_id`),
	INDEX `idx_sender` (`sender_id`),
	INDEX `idx_receiver` (`receiver_id`),

	PRIMARY KEY (`id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `audit_entries`;
CREATE TABLE `audit_entries` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

	`user_id` INT UNSIGNED NULL,
	`previous_id` INT UNSIGNED NULL,
	INDEX `idx_user` (`user_id`),
	INDEX `idx_previous` (`previous_id`),

	PRIMARY KEY (`id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `audit_readers`;
CREATE TABLE `audit_readers` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

	`last_entry_id` INT UNSIGNED NULL,
	INDEX `idx_last_entry` (`last_entry_id`),

	PRIMARY KEY (`id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `survey_participation_reminder_schedules`;
CREATE TABLE `survey_participation_reminder_schedules` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

	`originating_survey_set_template_id` INT UNSIGNED NULL,
	INDEX `idx_originating_survey_set_template` (`originating_survey_set_template_id`),

	PRIMARY KEY (`id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `a_b`;
CREATE TABLE `a_b` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

	`c` INT UNSIGNED NULL,
	INDEX `idx_user` (`c`),

	PRIMARY KEY (`id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `a`;
CREATE TABLE `a` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

	`b_c` INT UNSIGNED NULL,
	INDEX `idx_user` (`b_c`),

	PRIMARY KEY (`id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;

ALTER TABLE `users`
	ADD CONSTRAINT `fk_users_survey_set_id` FOREIGN KEY (`survey_set_id`) REFERENCES `survey_sets` (`id`) ON DELETE SET NULL;

ALTER TABLE `user_profiles`
	ADD CONSTRAINT `fk_user_profiles_id` FOREIGN KEY (`id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
	ADD CONSTRAINT `fk_user_profiles_avatar_id` FOREIGN KEY (`avatar_id`) REFERENCES `uploads` (`id`) ON DELETE SET NULL;

ALTER TABLE `user_groups`
	ADD CONSTRAINT `fk_user_groups_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
	ADD CONSTRAINT `fk_user_groups_group_id` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE;

ALTER TABLE `friendships`
	ADD CONSTRAINT `fk_friendships_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
	ADD CONSTRAINT `fk_friendships_friend_id` FOREIGN KEY (`friend_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `user_settings`
	ADD CONSTRAINT `fk_user_settings_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `groups`
	ADD CONSTRAINT `fk_groups_parent_id` FOREIGN KEY (`parent_id`) REFERENCES `groups` (`id`) ON DELETE SET NULL;

ALTER TABLE `survey_sets`
	ADD CONSTRAINT `fk_survey_sets_survey_id` FOREIGN KEY (`survey_id`) REFERENCES `surveys` (`id`) ON DELETE CASCADE;

ALTER TABLE `surveys`
	ADD CONSTRAINT `fk_surveys_owner_id` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
	ADD CONSTRAINT `fk_surveys_reviewer_id` FOREIGN KEY (`reviewer_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT;

ALTER TABLE `likes`
	ADD CONSTRAINT `fk_likes_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `invitations`
	ADD CONSTRAINT `fk_invitations_sender_id` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
	ADD CONSTRAINT `fk_invitations_receiver_id` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT;

ALTER TABLE `audit_entries`
	ADD CONSTRAINT `fk_audit_entries_previous_id` FOREIGN KEY (`previous_id`) REFERENCES `audit_entries` (`id`) ON DELETE SET NULL;

ALTER TABLE `survey_participation_reminder_schedules`
	ADD CONSTRAINT `fk_survey_participation_reminder_schedules_originating__8a6dd9ce` FOREIGN KEY (`originating_survey_set_template_id`) REFERENCES `survey_sets` (`id`) ON DELETE SET NULL;

ALTER TABLE `a_b`
	ADD CONSTRAINT `fk_a_b_c_e279a010` FOREIGN KEY (`c`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `a`
	ADD CONSTRAINT `fk_a_b_c_8c9527d0` FOREIGN KEY (`b_c`) REFERENCES `users` (`id`) ON DELETE SET NULL;

