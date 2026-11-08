DROP TABLE IF EXISTS "articles";
CREATE TABLE "articles" (
	"id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	"title" TEXT NULL,
	"author_id" INTEGER NOT NULL
);
CREATE INDEX "articles_idx_author" ON "articles" ("author_id");

DROP TABLE IF EXISTS "article_tags";
CREATE TABLE "article_tags" (
	"article_id" INTEGER NOT NULL,
	"tag_id" INTEGER NOT NULL,
	"position" INTEGER NOT NULL
);
CREATE INDEX "article_tags_idx_article_id" ON "article_tags" ("article_id");
CREATE INDEX "article_tags_idx_tag_id" ON "article_tags" ("tag_id");

DROP TABLE IF EXISTS "likes";
CREATE TABLE "likes" (
	"liked_id" INTEGER NOT NULL,
	"liked_type" TEXT NOT NULL,
	"author_id" INTEGER NOT NULL
);
CREATE INDEX "likes_idx_liked_id" ON "likes" ("liked_id", "liked_type");
CREATE INDEX "likes_idx_author_id" ON "likes" ("author_id");

DROP TABLE IF EXISTS "tags";
CREATE TABLE "tags" (
	"id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	"name" TEXT NULL
);

DROP TABLE IF EXISTS "photos";
CREATE TABLE "photos" (
	"id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	"url" TEXT NULL
);

DROP TABLE IF EXISTS "comments";
CREATE TABLE "comments" (
	"id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	"body" TEXT NULL,
	"subject_type" TEXT NOT NULL,
	"subject_id" INTEGER NOT NULL
);
CREATE INDEX "comments_idx_subject" ON "comments" ("subject_type", "subject_id");

DROP TABLE IF EXISTS "authors";
CREATE TABLE "authors" (
	"id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	"name" TEXT NULL
);

DROP TABLE IF EXISTS "friendships";
CREATE TABLE "friendships" (
	"author_id" INTEGER NOT NULL,
	"friend_id" INTEGER NOT NULL
);
CREATE INDEX "friendships_idx_author_id" ON "friendships" ("author_id");
CREATE INDEX "friendships_idx_friend_id" ON "friendships" ("friend_id");

