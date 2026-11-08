DROP TABLE IF EXISTS "posts";
CREATE TABLE "posts" (
	"id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	"title" TEXT NULL,
	"author_id" INTEGER NOT NULL,
	"editor_id" INTEGER NULL
);
CREATE INDEX "posts_idx_author" ON "posts" ("author_id");
CREATE INDEX "posts_idx_editor" ON "posts" ("editor_id");

DROP TABLE IF EXISTS "post_likes";
CREATE TABLE "post_likes" (
	"post_id" INTEGER NOT NULL,
	"user_id" INTEGER NOT NULL
);
CREATE INDEX "post_likes_idx_post_id" ON "post_likes" ("post_id");
CREATE INDEX "post_likes_idx_user_id" ON "post_likes" ("user_id");

DROP TABLE IF EXISTS "comments";
CREATE TABLE "comments" (
	"id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	"post_id" INTEGER NULL,
	"subject_type" TEXT NULL,
	"subject_id" INTEGER NULL
);
CREATE INDEX "comments_idx_post" ON "comments" ("post_id");
CREATE INDEX "comments_idx_subject" ON "comments" ("subject_type", "subject_id");

