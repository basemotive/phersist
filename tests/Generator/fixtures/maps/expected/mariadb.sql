DROP TABLE IF EXISTS `accounts`;
CREATE TABLE `accounts` (
	`account_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

	`name` TEXT NULL,

	PRIMARY KEY (`account_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
	`owner_type` VARCHAR(191) NOT NULL,
	`owner_id` INT UNSIGNED NOT NULL,
	`section` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
	`name` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
	`value` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
	UNIQUE INDEX `uniq_owner_id` (`owner_type`, `owner_id`, `section`, `name`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `account_matrix`;
CREATE TABLE `account_matrix` (
	`acc` INT UNSIGNED NOT NULL,
	`a` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
	`b` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
	`c` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
	`d` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
	`e` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
	`v` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
	INDEX `idx_acc` (`acc`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `account_flags`;
CREATE TABLE `account_flags` (
	`account_id` INT UNSIGNED NOT NULL,
	`flag` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
	`state` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
	UNIQUE INDEX `uniq_account_id` (`account_id`, `flag`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `account_labels`;
CREATE TABLE `account_labels` (
	`owner_type` VARCHAR(191) NOT NULL,
	`account_id` INT UNSIGNED NOT NULL,
	`a` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
	`b` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
	`c` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
	`d` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
	`label` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
	INDEX `idx_account_id` (`owner_type`, `account_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `teams`;
CREATE TABLE `teams` (
	`team_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,


	PRIMARY KEY (`team_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

