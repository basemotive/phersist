DROP TABLE IF EXISTS `items`;
CREATE TABLE `items` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

	`title` TEXT NOT NULL DEFAULT ('It''s a \\ ''test'' */'),
	`notes` TEXT NULL,
	`count` INT NULL DEFAULT -3,
	`stock` INT UNSIGNED NOT NULL DEFAULT 0,
	`ratio` DOUBLE NULL DEFAULT 1.5,
	`price` DECIMAL(12,4) NULL DEFAULT 9.9900,
	`amount` DECIMAL(10,2) NULL,
	`active` TINYINT UNSIGNED NOT NULL DEFAULT 1,
	`hidden` TINYINT UNSIGNED NULL DEFAULT 0,
	`birthday` DATE NULL,
	`created_at` DATETIME NOT NULL,
	`modified_at` DATETIME NULL,
	`stamp` DATETIME NULL,
	`custom_stamp` TEXT NULL,
	`owner_ref` INT UNSIGNED NULL,
	`creator_id` INT UNSIGNED NOT NULL,
	`subject_type` VARCHAR(191) NULL,
	`subject_id` INT UNSIGNED NULL,
	`target_class` VARCHAR(191) NULL,
	`target_ref` INT UNSIGNED NULL,
	`external_id` INT UNSIGNED NULL,
	INDEX `idx_owner` (`owner_ref`),
	INDEX `idx_creator` (`creator_id`),
	INDEX `idx_subject` (`subject_type`, `subject_id`),
	INDEX `idx_target` (`target_class`, `target_ref`),
	INDEX `idx_external` (`external_id`),

	PRIMARY KEY (`id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=latin1;

DROP TABLE IF EXISTS `owners`;
CREATE TABLE `owners` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

	`name` TEXT NULL,

	PRIMARY KEY (`id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=latin1;

