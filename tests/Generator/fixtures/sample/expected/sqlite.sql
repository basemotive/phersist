DROP TABLE IF EXISTS "forums";
CREATE TABLE "forums" (
	"id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	"name" TEXT NOT NULL,
	"description" TEXT NULL
);

DROP TABLE IF EXISTS "forum_messages";
CREATE TABLE "forum_messages" (
	"id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	"forum_id" INTEGER NOT NULL,
	"parent_message_id" INTEGER NULL,
	"title" TEXT NOT NULL,
	"message_summary" TEXT NOT NULL,
	"created_at" TEXT NOT NULL,
	"modified_at" TEXT NULL,
	"user_id" INTEGER NOT NULL,
	"view_count" INTEGER NOT NULL DEFAULT 0,
	"message_content" TEXT NOT NULL
);
CREATE INDEX "forum_messages_idx_forum" ON "forum_messages" ("forum_id");
CREATE INDEX "forum_messages_idx_parent_message" ON "forum_messages" ("parent_message_id");
CREATE INDEX "forum_messages_idx_user" ON "forum_messages" ("user_id");

DROP TABLE IF EXISTS "forum_message_tags";
CREATE TABLE "forum_message_tags" (
	"forum_message_id" INTEGER NOT NULL,
	"tag_id" INTEGER NOT NULL
);
CREATE INDEX "forum_message_tags_idx_forum_message_id" ON "forum_message_tags" ("forum_message_id");
CREATE INDEX "forum_message_tags_idx_tag_id" ON "forum_message_tags" ("tag_id");

DROP TABLE IF EXISTS "prop_map";
CREATE TABLE "prop_map" (
	"object_type" TEXT NOT NULL,
	"id" INTEGER NOT NULL,
	"key" TEXT NOT NULL,
	"value" TEXT NOT NULL
);
CREATE UNIQUE INDEX "prop_map_uniq_id" ON "prop_map" ("object_type", "id", "key");

DROP TABLE IF EXISTS "tags";
CREATE TABLE "tags" (
	"id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	"tag_name" TEXT NULL
);

DROP TABLE IF EXISTS "users";
CREATE TABLE "users" (
	"id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	"email" TEXT NULL,
	"password" TEXT NULL,
	"name" TEXT NULL
);

