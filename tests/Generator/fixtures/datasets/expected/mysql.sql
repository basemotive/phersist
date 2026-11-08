DROP TABLE IF EXISTS `persons`;
CREATE TABLE `persons` (
	`person_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

	`name` TEXT NOT NULL,
	`deleted` INT UNSIGNED NOT NULL DEFAULT 0,

	PRIMARY KEY (`person_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `person_details`;
CREATE TABLE `person_details` (
	`person_id` INT UNSIGNED NOT NULL,

	`bio` TEXT NULL,
	`age` INT NULL,

	PRIMARY KEY (`person_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `person_extras`;
CREATE TABLE `person_extras` (
	`person_id` INT UNSIGNED NOT NULL,

	`nickname` TEXT NULL,

	PRIMARY KEY (`person_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `firms`;
CREATE TABLE `firms` (
	`firm_no` INT UNSIGNED NOT NULL AUTO_INCREMENT,

	`title` TEXT NULL,
	`boss_id` INT UNSIGNED NULL,
	INDEX `idx_boss` (`boss_id`),

	PRIMARY KEY (`firm_no`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `markers`;
CREATE TABLE `markers` (
	`marker_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,


	PRIMARY KEY (`marker_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

