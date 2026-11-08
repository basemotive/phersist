DROP TABLE IF EXISTS "survey_invitations";
CREATE TABLE "survey_invitations" (
	"id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	"receiver_id" INTEGER NOT NULL,
	"survey_combo_id" INTEGER NULL,
	"status" TEXT NULL,
	"code" TEXT NULL,
	"sent_at" TEXT NULL
);
CREATE INDEX "survey_invitations_idx_survey_combo" ON "survey_invitations" ("survey_combo_id");
CREATE INDEX "survey_invitations_receiver" ON "survey_invitations" ("receiver_id", "survey_combo_id", "status");
CREATE UNIQUE INDEX "survey_invitations_code" ON "survey_invitations" ("code");
CREATE INDEX "survey_invitations_sent" ON "survey_invitations" ("sent_at");

DROP TABLE IF EXISTS "users";
CREATE TABLE "users" (
	"id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	"role" TEXT NULL,
	"user_type" TEXT NULL,
	"email" TEXT NULL,
	"deleted" INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX "users_role" ON "users" ("role");
CREATE INDEX "users_user_type" ON "users" ("user_type");
CREATE INDEX "users_email" ON "users" ("email");
CREATE INDEX "users_email_role" ON "users" ("email", "role");
CREATE INDEX "users_active" ON "users" ("deleted", "id");

DROP TABLE IF EXISTS "user_profiles";
CREATE TABLE "user_profiles" (
	"id" INTEGER NOT NULL PRIMARY KEY,
	"city" TEXT NULL,
	"manager_id" INTEGER NULL,
	"stamp" TEXT NULL
);
CREATE INDEX "user_profiles_idx_manager" ON "user_profiles" ("manager_id");
CREATE INDEX "user_profiles_city" ON "user_profiles" ("city", "manager_id");
CREATE INDEX "user_profiles_stamp" ON "user_profiles" ("stamp", "id");

DROP TABLE IF EXISTS "benchmark_values";
CREATE TABLE "benchmark_values" (
	"id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	"survey_id" INTEGER NULL,
	"survey_invitation_id" INTEGER NULL,
	"name" TEXT NULL,
	"subject_type" TEXT NULL,
	"subject_id" INTEGER NULL
);
CREATE INDEX "benchmark_values_idx_survey_invitation" ON "benchmark_values" ("survey_invitation_id");
CREATE INDEX "benchmark_values_selectlist" ON "benchmark_values" ("survey_id", "survey_invitation_id", "name");
CREATE INDEX "benchmark_values_subject_name" ON "benchmark_values" ("subject_type", "subject_id", "name");

