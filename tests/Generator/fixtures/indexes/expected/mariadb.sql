DROP TABLE IF EXISTS `survey_invitations`;
CREATE TABLE `survey_invitations` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

	`receiver_id` INT UNSIGNED NOT NULL,
	`survey_combo_id` INT UNSIGNED NULL,
	`status` TEXT NULL,
	`code` TEXT NULL,
	`sent_at` DATETIME NULL,
	INDEX `idx_survey_combo` (`survey_combo_id`),
	INDEX `receiver` (`receiver_id`, `survey_combo_id`, `status`(10)),
	INDEX `sent` (`sent_at`),
	UNIQUE INDEX `code` (`code`(32)),

	PRIMARY KEY (`id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

	`role` TEXT NULL,
	`user_type` TEXT NULL,
	`email` TEXT NULL,
	`deleted` INT UNSIGNED NOT NULL DEFAULT 0,
	INDEX `role` (`role`(10)),
	INDEX `user_type` (`user_type`(10)),
	INDEX `email` (`email`(64)),
	INDEX `email_role` (`email`(64), `role`(10)),
	INDEX `active` (`deleted`, `id`),

	PRIMARY KEY (`id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `user_profiles`;
CREATE TABLE `user_profiles` (
	`id` INT UNSIGNED NOT NULL,

	`city` TEXT NULL,
	`manager_id` INT UNSIGNED NULL,
	`stamp` TEXT NULL,
	INDEX `idx_manager` (`manager_id`),
	INDEX `city` (`city`(20), `manager_id`),
	INDEX `stamp` (`stamp`(10), `id`),

	PRIMARY KEY (`id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `benchmark_values`;
CREATE TABLE `benchmark_values` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

	`survey_id` INT UNSIGNED NULL,
	`survey_invitation_id` INT UNSIGNED NULL,
	`name` TEXT NULL,
	`subject_type` VARCHAR(191) NULL,
	`subject_id` INT UNSIGNED NULL,
	INDEX `idx_survey_invitation` (`survey_invitation_id`),
	INDEX `selectlist` (`survey_id`, `survey_invitation_id`, `name`(50)),
	INDEX `subject_name` (`subject_type`, `subject_id`, `name`(20)),

	PRIMARY KEY (`id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

