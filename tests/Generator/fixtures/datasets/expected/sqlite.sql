DROP TABLE IF EXISTS "persons";
CREATE TABLE "persons" (
	"person_id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	"name" TEXT NOT NULL,
	"deleted" INTEGER NOT NULL DEFAULT 0
);

DROP TABLE IF EXISTS "person_details";
CREATE TABLE "person_details" (
	"person_id" INTEGER NOT NULL PRIMARY KEY,
	"bio" TEXT NULL,
	"age" INTEGER NULL
);

DROP TABLE IF EXISTS "person_extras";
CREATE TABLE "person_extras" (
	"person_id" INTEGER NOT NULL PRIMARY KEY,
	"nickname" TEXT NULL
);

DROP TABLE IF EXISTS "firms";
CREATE TABLE "firms" (
	"firm_no" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	"title" TEXT NULL,
	"boss_id" INTEGER NULL
);
CREATE INDEX "firms_idx_boss" ON "firms" ("boss_id");

DROP TABLE IF EXISTS "markers";
CREATE TABLE "markers" (
	"marker_id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT
);

