DROP TABLE IF EXISTS "items";
CREATE TABLE "items" (
	"id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	"title" TEXT NOT NULL DEFAULT 'It''s a \ ''test'' */',
	"notes" TEXT NULL,
	"count" INTEGER NULL DEFAULT -3,
	"stock" INTEGER NOT NULL DEFAULT 0,
	"ratio" REAL NULL DEFAULT 1.5,
	"price" DECIMAL(12,4) NULL DEFAULT 9.9900,
	"amount" DECIMAL(10,2) NULL,
	"active" INTEGER NOT NULL DEFAULT 1,
	"hidden" INTEGER NULL DEFAULT 0,
	"birthday" TEXT NULL,
	"created_at" TEXT NOT NULL,
	"modified_at" TEXT NULL,
	"stamp" TEXT NULL,
	"custom_stamp" TEXT NULL,
	"owner_ref" INTEGER NULL,
	"creator_id" INTEGER NOT NULL,
	"subject_type" TEXT NULL,
	"subject_id" INTEGER NULL,
	"target_class" TEXT NULL,
	"target_ref" INTEGER NULL,
	"external_id" INTEGER NULL
);
CREATE INDEX "items_idx_owner" ON "items" ("owner_ref");
CREATE INDEX "items_idx_creator" ON "items" ("creator_id");
CREATE INDEX "items_idx_subject" ON "items" ("subject_type", "subject_id");
CREATE INDEX "items_idx_target" ON "items" ("target_class", "target_ref");
CREATE INDEX "items_idx_external" ON "items" ("external_id");

DROP TABLE IF EXISTS "owners";
CREATE TABLE "owners" (
	"id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	"name" TEXT NULL
);

