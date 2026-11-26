<?php

/**
 * The foreign keys in the generated schema: which references get one, with
 * which rule, and their names. The foreignkeys fixture has the complete
 * output; these tests say what matters in it. See
 * docs/creating-model-from-xml.md, "Foreign keys".
 */

use PHersist\Generator\Schema\ForeignKey;
use PHersist\Generator\Schema\SchemaBuilder;
use PHersist\Generator\XMLLoader;
use Tests\Support\Generate;

const FOREIGN_KEYS_XML = __DIR__.'/fixtures/foreignkeys/model.xml';

/**
 * The foreign keys of the fixture, as the MySQL schema has them:
 * [ 'table.column' => 'referenced_table.column RULE' ].
 *
 * @return array<string, string>
 */
function fixtureForeignKeys(string $dialect = 'mysql') : array {
	$keys = [];
	$table = null;
	foreach (explode("\n", Generate::schema(XMLLoader::load(FOREIGN_KEYS_XML), $dialect)) as $line) {
		if (preg_match('/^ALTER TABLE `(\w+)`$/', $line, $match))
			$table = $match[1];
		elseif (preg_match('/^\tADD CONSTRAINT `\w+` FOREIGN KEY \(`(\w+)`\) REFERENCES `(\w+)` \(`(\w+)`\) ON DELETE ([A-Z ]+)[,;]$/', $line, $match))
			$keys["{$table}.{$match[1]}"] = "{$match[2]}.{$match[3]} {$match[4]}";
		elseif (str_contains($line, 'FOREIGN KEY'))
			throw new LogicException("Unexpected foreign key line: {$line}");
	}
	return $keys;
}

/**
 * Generates the schema of a model with the given <project> content.
 */
function schemaOf(string $content, string $dialect = 'mysql') : string {
	$dir = tempModelDir();
	file_put_contents("{$dir}/model.xml", "<project database=\"main\" tablestyle=\"SnakeCase\">{$content}</project>");
	return Generate::schema(XMLLoader::load("{$dir}/model.xml"), $dialect);
}

const FOREIGN_KEYS_CLASSES = <<<'XML'
<class name="User"/>
<class name="Post">
	<dataset><property name="author" type="Class" class="User"/></dataset>
</class>
XML;

it('follows on_remote_delete for a Class property', function () {
	$keys = fixtureForeignKeys();

	expect($keys['users.survey_set_id'])->toBe('survey_sets.id SET NULL');
	expect($keys['surveys.owner_id'])->toBe('users.id RESTRICT');
	expect($keys['surveys.reviewer_id'])->toBe('users.id RESTRICT');
	expect($keys['survey_sets.survey_id'])->toBe('surveys.id CASCADE');
});

it('lets a table refer to itself', function () {
	expect(fixtureForeignKeys()['groups.parent_id'])->toBe('groups.id SET NULL');
});

it('deletes the rows of a join table with either side, with one key per column', function () {
	$keys = fixtureForeignKeys();

	// user_groups is defined by both User.groups and Group.users
	expect($keys['user_groups.user_id'])->toBe('users.id CASCADE');
	expect($keys['user_groups.group_id'])->toBe('groups.id CASCADE');
	expect($keys['friendships.user_id'])->toBe('users.id CASCADE');
	expect($keys['friendships.friend_id'])->toBe('users.id CASCADE');
});

it('has the key of a derived relation once, from the Class property', function () {
	$sql = Generate::schema(XMLLoader::load(FOREIGN_KEYS_XML), 'mysql');

	expect(substr_count($sql, 'FOREIGN KEY (`owner_id`)'))->toBe(1);
});

it('deletes the rows of dataset and map tables with their object', function () {
	$keys = fixtureForeignKeys();

	expect($keys['user_profiles.id'])->toBe('users.id CASCADE');
	expect($keys['user_settings.user_id'])->toBe('users.id CASCADE');
});

it('has no key where the id is that of several classes, or of another database', function () {
	$keys = fixtureForeignKeys();

	// A DynamicClass, a local_type, a map type, and a join table with two related classes
	expect($keys)->not->toHaveKeys([ 'uploads.subject_id', 'likes.liked_id', 'notes.owner_id', 'taggings.item_id', 'taggings.tagged_id' ]);
	// To and from the class in the database 'audit'
	expect($keys)->not->toHaveKeys([ 'audit_entries.user_id', 'audit_readers.last_entry_id' ]);
	expect($keys['audit_entries.previous_id'])->toBe('audit_entries.id SET NULL');
	// The other column of those join tables does have one
	expect($keys['likes.user_id'])->toBe('users.id CASCADE');
});

it('has no cascade key for a softdelete class, which keeps its reference', function () {
	$keys = fixtureForeignKeys();

	expect($keys)->not->toHaveKey('invitations.survey_id');
	expect($keys['invitations.sender_id'])->toBe('users.id SET NULL');
	expect($keys['invitations.receiver_id'])->toBe('users.id RESTRICT');
});

it('has the same keys for MariaDB', function () {
	expect(fixtureForeignKeys('mariadb'))->toBe(fixtureForeignKeys('mysql'));
});

it('adds the keys after the tables, which are dropped and created without the checks', function () {
	$sql = Generate::schema(XMLLoader::load(FOREIGN_KEYS_XML), 'mysql');

	expect($sql)->toStartWith("SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS = 0;\n");
	$checksOn = strpos($sql, "\nSET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;\n");
	expect($checksOn)->toBeGreaterThan((int)strrpos($sql, 'CREATE TABLE'));
	expect($checksOn)->toBeLessThan((int)strpos($sql, 'ALTER TABLE'));
	// Not inline
	expect(strpos($sql, 'FOREIGN KEY'))->toBeGreaterThan((int)$checksOn);
});

it('only generates them when the model asks for it, per dialect', function () {
	expect(schemaOf(FOREIGN_KEYS_CLASSES))->not->toContain('FOREIGN');
	expect(schemaOf('<schema dialect="mysql" foreign_keys="false"/>'.FOREIGN_KEYS_CLASSES))->not->toContain('FOREIGN');

	$model = '<schema dialect="mysql" foreign_keys="true"/>'.FOREIGN_KEYS_CLASSES;
	expect(schemaOf($model))->toContain(
		"ALTER TABLE `posts`\n\tADD CONSTRAINT `fk_posts_author_id` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;\n");
	expect(schemaOf($model, 'mariadb'))->not->toContain('FOREIGN');
	expect(schemaOf($model, 'sqlite'))->not->toContain('FOREIGN');
});

it('doesn\'t change the rest of the schema', function () {
	$with = schemaOf('<schema dialect="mysql" foreign_keys="true"/>'.FOREIGN_KEYS_CLASSES);
	$without = schemaOf(FOREIGN_KEYS_CLASSES);

	expect($with)->toContain($without);
});

it('has an index that starts with the column of every key', function (string $xmlFile) {
	// Otherwise InnoDB adds one, and the database differs from the schema.
	// SchemaLoadTest checks this on the database; here for every fixture, with
	// the keys forced on.
	$project = XMLLoader::load($xmlFile);
	$sql = Generate::schema($project, 'mysql', true);

	foreach (SchemaBuilder::build($project)->tables() as $table) {
		preg_match('/^CREATE TABLE `'.preg_quote($table->name, '/').'` \(\n(.*?)^\)/ms', $sql, $match);
		foreach ($table->foreignKeys as $key)
			expect($match[1])->toMatch('/(INDEX `\w+` \(`'.$key->column.'`[,)]|PRIMARY KEY \(`'.$key->column.'`\))/',
				"No index for {$table->name}.{$key->column}");
	}
})->with(array_map(fn ($xmlFile) => [ $xmlFile ], array_combine(
	array_map(fn ($xmlFile) => basename(dirname($xmlFile)), glob(__DIR__.'/fixtures/*/model.xml') ?: []),
	glob(__DIR__.'/fixtures/*/model.xml') ?: [])));

describe('the name', function () {
	it('is fk_<table>_<column>', function () {
		expect(ForeignKey::name('posts', 'author_id'))->toBe('fk_posts_author_id');
	});

	it('is cut off with a hash of table.column when it is longer than 64 characters', function () {
		$table = 'survey_participation_reminder_schedules';
		$column = 'originating_survey_set_template_id';
		$name = ForeignKey::name($table, $column);

		expect($name)->toBe(substr("fk_{$table}_{$column}", 0, 55).'_'.substr(md5("{$table}.{$column}"), 0, 8));
		expect(strlen($name))->toBe(64);
		expect(strlen(ForeignKey::name(str_repeat('t', 30), str_repeat('c', 30))))->toBe(64);
		expect(ForeignKey::name(str_repeat('t', 30), str_repeat('c', 31)))->toEndWith('_'.substr(md5(str_repeat('t', 30).'.'.str_repeat('c', 31)), 0, 8));
	});

	it('gets a hash when another key has the same name', function () {
		$sql = Generate::schema(XMLLoader::load(FOREIGN_KEYS_XML), 'mysql');

		expect($sql)->toContain('`fk_a_b_c_'.substr(md5('a_b.c'), 0, 8).'` FOREIGN KEY (`c`)');
		expect($sql)->toContain('`fk_a_b_c_'.substr(md5('a.b_c'), 0, 8).'` FOREIGN KEY (`b_c`)');
		expect($sql)->not->toContain('`fk_a_b_c`');
	});

	it('is unique in the schema', function () {
		preg_match_all('/ADD CONSTRAINT `(\w+)`/', Generate::schema(XMLLoader::load(FOREIGN_KEYS_XML), 'mysql'), $matches);

		expect($matches[1])->toHaveCount(19);
		expect(array_unique($matches[1]))->toHaveCount(count($matches[1]));
	});
});
