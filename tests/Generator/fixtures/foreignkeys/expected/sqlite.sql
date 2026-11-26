DROP TABLE IF EXISTS "users";
CREATE TABLE "users" (
	"id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	"name" TEXT NULL,
	"survey_set_id" INTEGER NULL
);
CREATE INDEX "users_idx_survey_set" ON "users" ("survey_set_id");

DROP TABLE IF EXISTS "user_profiles";
CREATE TABLE "user_profiles" (
	"id" INTEGER NOT NULL PRIMARY KEY,
	"bio" TEXT NULL,
	"avatar_id" INTEGER NULL
);
CREATE INDEX "user_profiles_idx_avatar" ON "user_profiles" ("avatar_id");

DROP TABLE IF EXISTS "user_groups";
CREATE TABLE "user_groups" (
	"user_id" INTEGER NOT NULL,
	"group_id" INTEGER NOT NULL
);
CREATE INDEX "user_groups_idx_user_id" ON "user_groups" ("user_id");
CREATE INDEX "user_groups_idx_group_id" ON "user_groups" ("group_id");

DROP TABLE IF EXISTS "friendships";
CREATE TABLE "friendships" (
	"user_id" INTEGER NOT NULL,
	"friend_id" INTEGER NOT NULL
);
CREATE INDEX "friendships_idx_user_id" ON "friendships" ("user_id");
CREATE INDEX "friendships_idx_friend_id" ON "friendships" ("friend_id");

DROP TABLE IF EXISTS "user_settings";
CREATE TABLE "user_settings" (
	"user_id" INTEGER NOT NULL,
	"name" TEXT NOT NULL,
	"value" TEXT NOT NULL
);
CREATE UNIQUE INDEX "user_settings_uniq_user_id" ON "user_settings" ("user_id", "name");

DROP TABLE IF EXISTS "notes";
CREATE TABLE "notes" (
	"owner_type" TEXT NOT NULL,
	"owner_id" INTEGER NOT NULL,
	"name" TEXT NOT NULL,
	"value" TEXT NOT NULL
);
CREATE UNIQUE INDEX "notes_uniq_owner_id" ON "notes" ("owner_type", "owner_id", "name");

DROP TABLE IF EXISTS "groups";
CREATE TABLE "groups" (
	"id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	"name" TEXT NULL,
	"parent_id" INTEGER NULL
);
CREATE INDEX "groups_idx_parent" ON "groups" ("parent_id");

DROP TABLE IF EXISTS "survey_sets";
CREATE TABLE "survey_sets" (
	"id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	"survey_id" INTEGER NULL
);
CREATE INDEX "survey_sets_idx_survey" ON "survey_sets" ("survey_id");

DROP TABLE IF EXISTS "surveys";
CREATE TABLE "surveys" (
	"id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	"owner_id" INTEGER NOT NULL,
	"reviewer_id" INTEGER NULL,
	"status" TEXT NULL
);
CREATE INDEX "surveys_idx_owner" ON "surveys" ("owner_id");
CREATE INDEX "surveys_reviewer_status" ON "surveys" ("reviewer_id", "status");

DROP TABLE IF EXISTS "likes";
CREATE TABLE "likes" (
	"liked_id" INTEGER NOT NULL,
	"liked_type" TEXT NOT NULL,
	"user_id" INTEGER NOT NULL
);
CREATE INDEX "likes_idx_liked_id" ON "likes" ("liked_id", "liked_type");
CREATE INDEX "likes_idx_user_id" ON "likes" ("user_id");

DROP TABLE IF EXISTS "uploads";
CREATE TABLE "uploads" (
	"id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	"subject_type" TEXT NULL,
	"subject_id" INTEGER NULL
);
CREATE INDEX "uploads_idx_subject" ON "uploads" ("subject_type", "subject_id");

DROP TABLE IF EXISTS "taggings";
CREATE TABLE "taggings" (
	"item_id" INTEGER NOT NULL,
	"item_type" TEXT NOT NULL,
	"tagged_id" INTEGER NOT NULL
);
CREATE INDEX "taggings_idx_item_id" ON "taggings" ("item_id", "item_type");
CREATE INDEX "taggings_idx_tagged_id" ON "taggings" ("tagged_id");

DROP TABLE IF EXISTS "reports";
CREATE TABLE "reports" (
	"id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT
);

DROP TABLE IF EXISTS "invitations";
CREATE TABLE "invitations" (
	"id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	"survey_id" INTEGER NULL,
	"sender_id" INTEGER NULL,
	"receiver_id" INTEGER NULL,
	"deleted" INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX "invitations_idx_survey" ON "invitations" ("survey_id");
CREATE INDEX "invitations_idx_sender" ON "invitations" ("sender_id");
CREATE INDEX "invitations_idx_receiver" ON "invitations" ("receiver_id");

DROP TABLE IF EXISTS "audit_entries";
CREATE TABLE "audit_entries" (
	"id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	"user_id" INTEGER NULL,
	"previous_id" INTEGER NULL
);
CREATE INDEX "audit_entries_idx_user" ON "audit_entries" ("user_id");
CREATE INDEX "audit_entries_idx_previous" ON "audit_entries" ("previous_id");

DROP TABLE IF EXISTS "audit_readers";
CREATE TABLE "audit_readers" (
	"id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	"last_entry_id" INTEGER NULL
);
CREATE INDEX "audit_readers_idx_last_entry" ON "audit_readers" ("last_entry_id");

DROP TABLE IF EXISTS "survey_participation_reminder_schedules";
CREATE TABLE "survey_participation_reminder_schedules" (
	"id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	"originating_survey_set_template_id" INTEGER NULL
);
CREATE INDEX "survey_participation_reminder_schedules_idx_originating_survey_set_template" ON "survey_participation_reminder_schedules" ("originating_survey_set_template_id");

DROP TABLE IF EXISTS "a_b";
CREATE TABLE "a_b" (
	"id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	"c" INTEGER NULL
);
CREATE INDEX "a_b_idx_user" ON "a_b" ("c");

DROP TABLE IF EXISTS "a";
CREATE TABLE "a" (
	"id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	"b_c" INTEGER NULL
);
CREATE INDEX "a_idx_user" ON "a" ("b_c");

