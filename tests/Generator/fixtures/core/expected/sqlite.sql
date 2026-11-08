DROP TABLE IF EXISTS "users";
CREATE TABLE "users" (
	"id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	"name" TEXT NOT NULL
);

DROP TABLE IF EXISTS "user_groups";
CREATE TABLE "user_groups" (
	"user_id" INTEGER NOT NULL,
	"group_id" INTEGER NOT NULL
);
CREATE INDEX "user_groups_idx_user_id" ON "user_groups" ("user_id");
CREATE INDEX "user_groups_idx_group_id" ON "user_groups" ("group_id");

DROP TABLE IF EXISTS "groups";
CREATE TABLE "groups" (
	"id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	"name" TEXT NOT NULL
);

