# Using the model in PHP

This guide explains how to use PHersist-generated classes in application code:
- connect a database
- create/update/delete objects
- query with `ObjectFinder`
- work with relations and maps
- apply practical runtime patterns

If you have not generated model classes yet, start with [Getting started with PHersist](getting-started.md) and [Creating a model from an XML file](creating-model-from-xml.md).

---

## 1) Runtime setup

Before using generated classes, create and register the database connection through `DBConnectionManager` using the same database identifier defined in your model XML.

Example (`<project database="myapp" ...>`) using MySQL:

    <?php

    use PHersist\DB\DBConnectionManager;

    DBConnectionManager::newMySQLConnection(
        'myapp',
        '127.0.0.1',
        'db_user',
        'db_password',
        'myapp',
        'UTF8'
    );

For other backends, use `DBConnectionManager::newSQLiteConnection(...)` or `DBConnectionManager::newSQLSrvLConnection(...)`.

---

## 2) Working with generated objects

Every generated model class extends `\PHersist\ActiveRecord`.

### Create and insert

    <?php

    use MyApp\Model\User;

    $user = new User();
    $user->email = 'joe@example.org';
    $user->name = 'Joe Example';
    $user->commit();

After `commit()`, the object receives its primary key (`$user->id`).

### Load and update

    <?php

    $user = new User(123);   // id-based object
    $user->name = 'Joseph Example';
    $user->commit();

### Delete

    <?php

    $user->delete();

If the class uses `softdelete="true"`, this sets `deleted = 1` instead of removing the row.

### Check existence

    <?php

    if ($user->exists()) {
        // row is present
    }

### Extending generated objects with Traits

PHersist can automatically extend generated model classes with trait methods.

Automatic behavior:
- if a trait named `ClassNameTrait` exists in the same namespace as the generated class, it is automatically added during generation
- for example, `ForumMessage` automatically picks up `ForumMessageTrait` when that trait exists

This keeps custom behavior out of generated files while still making methods available directly on objects.

Example using `ForumMessageTrait::createdAtRelative()`:

    <?php

    use Babble\Model\ForumMessage;

    $message = new ForumMessage(123);
    echo $message->createdAtRelative() . PHP_EOL;

You can also set an explicit trait in model XML with the class `trait` attribute when needed.

---

## 3) Dataset behavior at runtime

Dataset design affects performance and query volume:

- fields in `autoload="true"` datasets are restored automatically in full finder loads
- fields in non-autoload datasets are restored on demand
- touching one field in a lazy dataset restores the whole dataset

Use this to keep list views light while loading heavy fields only when needed.

---

## 4) Querying with `ObjectFinder`

Use `ObjectFinder::create(...)` for fluent query building.

When `ObjectCache` is enabled, repeated access to the same objects across successive finder queries can reuse already-loaded in-memory instances, which can reduce repeated SQL queries.

Common methods:

- `where($property, $operator, $value)`
- `addAnd()`, `addOr()` for grouped conditions
- `end()` to close the current group and return to the parent level
- `orderBy($property, ObjectFinder::DIRECTION_ASC|ObjectFinder::DIRECTION_DESC)`
- `fetch($limit = '')`
- `fetchOne()`
- `count()`
- `includeDeletedRecords(true|false)` for soft-delete classes

Supported operators include:
`=`, `IS`, `>`, `<`, `>=`, `<=`, `!=`, `LIKE`, `NOT LIKE`.

### Simple lookup

    <?php

    use PHersist\ObjectFinder;
    use MyApp\Model\User;

    $user = ObjectFinder::create(User::class)
        ->where('email', '=', 'joe@example.org')
        ->fetchOne();

### Filter + ordering + limit

    <?php

    use PHersist\ObjectFinder;
    use MyApp\Model\ForumMessage;

    $messages = ObjectFinder::create(ForumMessage::class)
        ->where('title', 'LIKE', '%release%')
        ->orderBy('createdAt', ObjectFinder::DIRECTION_DESC)
        ->fetch(20);

### Count rows

    <?php

    $count = ObjectFinder::create(User::class)
        ->where('name', 'LIKE', 'A%')
        ->count();

### Query through references

You can dereference object properties in conditions:

    <?php

    $messages = ObjectFinder::create(ForumMessage::class)
        ->where('user->email', '=', 'joe@example.org')
        ->fetch();

---

## 5) Chainability and query flow

A clean pattern for most queries:

1. `ObjectFinder::create(Class::class)`
2. add one or more `where(...)`
3. optionally create grouped conditions with `addAnd()` / `addOr()`
4. if you need to continue at the parent level after a group, call `end()`
5. optionally add `orderBy(...)`
6. finish with `fetch()`, `fetchOne()`, or `count()`

When you enter a grouped expression with `addAnd()` or `addOr()`, subsequent `where(...)` calls are added to that group.  
Use `end()` when you want to return to the parent expression and add more parent-level conditions.

You do not need to call `end()` if you are done building conditions at the grouped level, because grouped expressions pass through methods like `orderBy(...)`, `fetch(...)`, `fetchOne()`, and `count()` to the `ObjectFinder`.

Example with practical `OR` grouping and a parent-level condition after `end()`:

    <?php

    $messages = ObjectFinder::create(ForumMessage::class)
        ->where('forum', '=', $forum)
        ->addOr()
            ->where('title', 'LIKE', '%release%')
            ->where('messageSummary', 'LIKE', '%release%')
        ->end()
        ->where('user->email', 'LIKE', '%@example.org')
        ->orderBy('createdAt', ObjectFinder::DIRECTION_DESC)
        ->fetch(20);

Because methods are chainable, you can keep complex query logic readable and close to business intent.

---

## 6) Full vs non-full finder loading

`ObjectFinder::create($class, $full)` supports two retrieval styles:

- `false` (default): lightweight objects, lazy property restoration
- `true`: autoload dataset fields are hydrated in the main fetch query

Use `full = true` when you know you will immediately use autoload fields for many returned objects.

---

## 7) Working with relations

Relations behave as list-like properties.

### Read relation values

    <?php

    foreach ($forum->messages as $message) {
        echo $message->title . PHP_EOL;
    }

### Write owned N-N relations

    <?php

    $message->tags = [$tag1, $tag2];
    $message->commit();

For `table_owner="true"` relations, commit replaces relation rows for that owning object.  
For derived relations (`table_owner="false"`), treat them as read-only views.

With `ObjectCache` enabled, dereferencing relations/properties that point to objects already loaded earlier in the same runtime can also reduce repeated SQL queries.

---

## 8) Working with maps

Map properties are key/value structures exposed via array access.

### Read and write map data

    <?php

    $theme = $user->settings['theme'] ?? 'default';
    $user->settings['theme'] = 'dark';
    $user->commit();

### Remove a map entry

    <?php

    $user->settings['theme'] = null;
    $user->commit();

Important: assign map entries by key. Do not replace the map property itself with direct `=` assignment.

---

## 9) Soft-delete query behavior

For classes with `softdelete="true"`:

- normal finder queries exclude deleted rows
- include them explicitly with `includeDeletedRecords(true)`

    <?php

    $user = ObjectFinder::create(User::class)
        ->includeDeletedRecords(true)
        ->where('email', '=', 'joe@example.org')
        ->fetchOne();

---

## 10) Practical runtime guidance

- keep high-frequency fields in autoload datasets
- move heavy/rarely-used fields into separate lazy datasets
- use `fetchOne()` when one result is expected
- use `count()` for counts instead of fetching rows and counting in PHP
- keep finder chains explicit and readable

For identity caching and advanced runtime behavior, continue with [Advanced features](advanced-features.md).  
In particular, `ObjectCache` can reduce repeated SQL when the same objects are accessed successively, whether through `ObjectFinder` queries or through dereferencing relation-backed properties.