DROP TABLE IF EXISTS "accounts";
CREATE TABLE "accounts" (
	"account_id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	"name" TEXT NULL
);

DROP TABLE IF EXISTS "settings";
CREATE TABLE "settings" (
	"owner_type" TEXT NOT NULL,
	"owner_id" INTEGER NOT NULL,
	"section" TEXT NOT NULL,
	"name" TEXT NOT NULL,
	"value" TEXT NOT NULL
);
CREATE UNIQUE INDEX "settings_uniq_owner_id" ON "settings" ("owner_type", "owner_id", "section", "name");

DROP TABLE IF EXISTS "account_matrix";
CREATE TABLE "account_matrix" (
	"acc" INTEGER NOT NULL,
	"a" TEXT NOT NULL,
	"b" TEXT NOT NULL,
	"c" TEXT NOT NULL,
	"d" TEXT NOT NULL,
	"e" TEXT NOT NULL,
	"v" TEXT NOT NULL
);
CREATE UNIQUE INDEX "account_matrix_uniq_acc" ON "account_matrix" ("acc", "a", "b", "c", "d", "e");

DROP TABLE IF EXISTS "account_flags";
CREATE TABLE "account_flags" (
	"account_id" INTEGER NOT NULL,
	"flag" TEXT NOT NULL,
	"state" TEXT NOT NULL
);
CREATE UNIQUE INDEX "account_flags_uniq_account_id" ON "account_flags" ("account_id", "flag");

DROP TABLE IF EXISTS "account_labels";
CREATE TABLE "account_labels" (
	"owner_type" TEXT NOT NULL,
	"account_id" INTEGER NOT NULL,
	"a" TEXT NOT NULL,
	"b" TEXT NOT NULL,
	"c" TEXT NOT NULL,
	"d" TEXT NOT NULL,
	"label" TEXT NOT NULL
);
CREATE UNIQUE INDEX "account_labels_uniq_account_id" ON "account_labels" ("owner_type", "account_id", "a", "b", "c", "d");

DROP TABLE IF EXISTS "teams";
CREATE TABLE "teams" (
	"team_id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT
);

