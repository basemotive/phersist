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

```php
<?php

use PHersist\DB\DBConnectionManager;

DBConnectionManager::newMySQLConnection(
    'myapp',
    '127.0.0.1',
    'db_user',
    'db_password',
    'myapp'
);
```

For other backends, use `DBConnectionManager::newSQLiteConnection(...)` or `DBConnectionManager::newSQLSrvLConnection(...)`.

---

## 2) Working with generated objects

Every generated model class extends `\PHersist\ActiveRecord`.

### Create and insert

```php
<?php

use MyApp\Model\User;

$user = new User();
$user->email = 'joe@example.org';
$user->name = 'Joe Example';
$user->commit();
```

After `commit()`, the object receives its primary key (`$user->id`).

A new object starts out with the [default values](creating-model-from-xml.md#default-values) from the model (for example `0` for an `Int` with `default="0"`), and those are stored on the first `commit()`. Properties without a default are `null` until you assign them. If a `required` property still has no value, `commit()` throws an exception and nothing is stored.

`commit()` only stores the object it is called on. Objects you refer to, through a `Class` or `DynamicClass` property or an owned relation, are not committed along with it, so commit those first. If a changed property or relation refers to an object that has no id yet, `commit()` throws an exception and nothing is stored.

Some property types convert assigned values, so a property always has the same kind of value, whether it was assigned or loaded from the database. For example, `$user->age = '42'` stores the int `42` for an `Int` property, `$event->startsAt = '2026-01-01 12:30'` stores a `DateTimeImmutable` for a `DateTime` property, and `$product->price = 12.5` stores `'12.50'` for a `Decimal`. Invalid values, like `'2026-02-30'` for a date, throw an exception on assignment. See [Property types](creating-model-from-xml.md#property-types).

`isset()` and `empty()` work on properties as they do on regular PHP properties: `isset($user->email)` is `true` when the property has a non-null value, and `false` for `null` or for a property that doesn't exist. The same goes for array syntax (`isset($user['email'])`). Like reading the property, this loads its dataset if it isn't loaded yet.

### Load and update

```php
<?php

$user = User::fetch(123);   // id-based object
$user->name = 'Joseph Example';
$user->commit();
```

`User::fetch($id)` is a shortcut for `ActiveRecord::fetchObject(User::class, $id)`. It returns an object without querying the database, so it doesn't tell you whether the row exists (see [Check existence](#check-existence)); the data is loaded when you first read a property. Reading a property of an object without a row throws an exception, and so does committing changes to it, before anything is stored (including relations and maps). With `ObjectCache` enabled, it returns the instance that is already in use for that id.

The constructor is for new objects only: `new User(123)` throws an exception.

`commit()` only writes the properties that changed since the object was loaded or last committed. If nothing changed, it does nothing at all, so it's safe to call unconditionally. (A new object is always inserted on its first `commit()`, even if nothing was set.)

Assigning a property the value it already has (compared with `===` after conversion, and by moment in time for dates) doesn't count as a change. The exception is a property whose dataset hasn't been loaded yet: its current value isn't known, so the assignment is always treated as a change.

### Reload

```php
<?php

$user->reload();
```

An object keeps the values it loaded, even when the database changes. This happens when another process updates the row, or when you delete an object that this one refers to (see [Deleting objects with relations](creating-model-from-xml.md#deleting-objects-with-relations)). `reload()` makes the object forget its loaded properties, relations and maps, so they are loaded from the database again when you next read them. A `Map` or relation array that you got from the object before reloading keeps the old values, so read the property again. Such a `Map` can still be read, but changing it throws an exception, because the object no longer commits it. The same goes for a `Map` that you read during a transaction that is rolled back afterwards (see [Transactions](#transactions)), unless it was already loaded before the object was first committed or deleted in that transaction.

If the object has uncommitted changes, `reload()` throws an exception, so you don't lose them by accident. Use `$user->reload(true)` to discard them. Reloading a new object that hasn't been committed yet, or a deleted object, also throws an exception; a soft-deleted object can be reloaded, though.

### Delete

```php
<?php

$user->delete();
```

This removes the object's row. References to it are cleaned up as well: rows in join tables are removed, and objects that refer to it through a `Class` or `DynamicClass` property get `NULL` in that property, are deleted along, or make `delete()` throw an exception, depending on the property's `on_remote_delete` (and the relation's `cascade_delete`). By default, a required property makes `delete()` throw, and other properties are set to `NULL`. See [Deleting objects with relations](creating-model-from-xml.md#deleting-objects-with-relations).

After `delete()`, the object's lifecycle has ended: its `id` becomes `null`, and setting a property (including map entries) or calling `commit()` on it throws an exception, instead of inserting it again as a new row. Properties that were already loaded can still be read; properties that weren't read as `null`. To store the same data again, create a new object. Use `$user->isDeleted()` to check whether an object has been deleted.

If the class uses `softdelete="true"`, `delete()` only sets `deleted = 1` instead of removing the row. The object becomes inactive (finder queries skip it), but its relations are left alone and objects that refer to it keep working: their `Class` properties and relations still return it. The object itself keeps its `id` and can still be read, including properties, relations and maps that weren't loaded yet, and `reload()` works on it; only changing and committing it throws an exception, like after a normal delete. This also holds for an instance that is fetched again later, for example in another request: as soon as its base table data is loaded (by reading a property in that table, or through `ObjectFinder` or a relation), `isDeleted()` returns `true`, and committing changes that were set before that throws as well. `exists()` returns `false` for it, unless you pass `true` (see [Check existence](#check-existence)).

### Transactions

A single `commit()` or `delete()` can run many statements: inserts or updates for every dataset table, and rewriting relations and maps. These run in a database transaction, so if one of them fails, none of them are stored and `commit()` or `delete()` throws the database's `PDOException`. This happens even if you switched the connection to `PDO::ERRMODE_SILENT` or `PDO::ERRMODE_WARNING`: PHersist uses `PDO::ERRMODE_EXCEPTION` for the duration of the call and then restores your setting, because otherwise a failed statement would go unnoticed and the others would still be stored. (The connections that `DBConnectionManager` creates use `PDO::ERRMODE_EXCEPTION` anyway.) The object stays as it was: its changes are still pending, so you can fix the cause and call `commit()` again, and a new object whose commit failed has no `id`. A failed `delete()` doesn't mark the object as deleted, and neither are the objects its cascade (`cascade_delete` or `on_remote_delete="cascade"`) had already deleted.

To store several objects together, run them in a `PHersist\Transaction`. It commits the transaction when your function returns, and rolls it back when it throws, after which the exception is thrown again. `run()` returns what your function returns.

```php
<?php

use PHersist\Transaction;

Transaction::run('myapp', function () use ($user, $message) {
    $user->commit();
    $message->commit();
});
```

Rolling back also restores the objects in memory: every object that was committed or deleted in the transaction gets back the state it had just before its first `commit()` or `delete()` in it. Changes that were pending at that point are pending again, a new object has no `id` again, a deleted object is no longer deleted, and the `ObjectCache` is restored to match. Changes you made to such an object after that first call are undone, and so is data that was loaded into it in the meantime; that data is loaded from the database again when you use it.

If you can't wrap the work in a function, start the transaction yourself and end it with `commit()` or `rollBack()`:

```php
<?php

$transaction = Transaction::begin('myapp');
try {
    $user->commit();
    $message->commit();
    $transaction->commit();
} catch (\Throwable $e) {
    $transaction->rollBack();
    throw $e;
}
```

Transactions can be nested, on the same connection. A nested transaction uses a savepoint: rolling it back only undoes what happened inside it, and the outer transaction can still be committed. Committing it makes its changes part of the outer transaction, which can still roll them back. Nested transactions must be ended before the transaction they are in, or `commit()` and `rollBack()` throw an exception. A single `commit()` or `delete()` inside a transaction is a nested transaction too, so when it fails, only its own statements are rolled back; your transaction stays active, and you decide whether to continue or roll back. Each of them costs two extra statements (setting and releasing the savepoint), which you may notice when you commit many small objects in one transaction.

Until the transaction ends, it keeps every object that was committed or deleted in it in memory, so it can restore them. For a very large batch, consider several smaller transactions.

Some things are not rolled back in memory:

- Objects that weren't committed or deleted in the transaction themselves. For example, when a `delete()` sets references to `NULL` in the database, objects that are already loaded don't see that, whether the transaction is rolled back or not; see [Deleting objects with relations](creating-model-from-xml.md#deleting-objects-with-relations). The same goes for data that such an object loaded during the transaction, which may contain changes that were rolled back afterwards; call `reload()` on it.
- Work on another connection. A transaction belongs to one connection, and an object in a different database is committed or deleted in a transaction on its own connection, which a failure on the first connection doesn't roll back.
- A transaction you start directly on the PDO object with `beginTransaction()`. PHersist can't see when that one ends, so `commit()` and `delete()` run in a savepoint inside it, but rolling it back with `$pdo->rollBack()` only rolls back the database: objects that were committed in it no longer have pending changes, new ones keep the `id` they received, and deleted ones stay marked as deleted. Use `Transaction` instead. Likewise, don't end a `Transaction` by calling `commit()` or `rollBack()` on the PDO object.

### Check existence

```php
<?php

$user = User::fetch((int)$_GET['user']);
if ($user->exists()) {
    // row is present
}
```

`exists()` checks the database, so it's useful for an object you got by id, for example an id from an HTTP request. It returns `false` for an object that has no id yet or has been deleted.

For a class with `softdelete="true"`, a soft-deleted object doesn't count as existing, so `exists()` also tells you whether an object is still active. Pass `true` to count soft-deleted objects as well:

```php
<?php

if ($tag->exists(true)) {
    // row is present, whether the tag is soft-deleted or not
}
```

### Extending generated objects with Traits

PHersist can automatically extend generated model classes with trait methods.

Automatic behavior:
- if a trait named `ClassNameTrait` exists in the same namespace as the generated class, it is automatically added during generation
- for example, `ForumMessage` automatically picks up `ForumMessageTrait` when that trait exists

This keeps custom behavior out of generated files while still making methods available directly on objects.

Example using `ForumMessageTrait::createdAtRelative()`:

```php
<?php

use Babble\Model\ForumMessage;

$message = ForumMessage::fetch(123);
echo $message->createdAtRelative() . PHP_EOL;
```

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
- `fetch(?int $limit = null, int $offset = 0)`
- `fetchOne()`
- `count()`
- `includeDeletedRecords(true|false)`, only relevant for classes with `softdelete="true"`

Besides the properties from your XML, `id` can be used in `where(...)` (also at the end of a path, such as `forum->id`) and in `orderBy(...)`. A property or path that doesn't exist makes `where(...)` throw right away. `orderBy(...)` only takes properties of the class itself, not paths.

Supported operators include:
`=`, `>`, `<`, `>=`, `<=`, `!=`, `LIKE`, `NOT LIKE`.

To find `NULL` values, use `null` with `=`, like `where('deletedAt', '=', null)`. It is translated to `IS NULL`, and `!=` with `null` to `NOT ... IS NULL`, for properties of any type.

Unlike plain SQL, `!=` and `NOT LIKE` with a non-null value also match objects whose property is `NULL`, as they would in PHP: `where('color', '!=', 'green')` returns objects without a color too. The same holds for a path through a reference that is `NULL`, such as `where('owner->name', '!=', 'Bob')` for objects without an owner. To leave those out, add a condition: `where('color', '!=', 'green')->where('color', '!=', null)`.

The value must suit the property's type, just like a value you assign to it: `where('name', '=', ['ann', 'bob'])` or `where('viewCount', '=', 'five')` throws an `\InvalidArgumentException` right away. Values are converted as on assignment too, so `where('viewCount', '=', '5')` works. There are a few exceptions:

- `LIKE` and `NOT LIKE` only work on `Text` and `TimestampText` properties (and throw for other types, including `id`). The value is a pattern and must be a string, like `where('createdAt', 'LIKE', '2026-%')`. For dates and numbers, use a range instead, like `where('date', '>=', '2026-10-01')->where('date', '<', '2026-11-01')`.
- With `<`, `>`, `<=` and `>=`, values that could never be stored still make sense, so they're accepted: an `Int` may be out of the column's range, a `Decimal` may be any number (like `'1.005'` for a `scale` of 2), and a `TimestampText` may be any string (like `'2026-10'`).
- `null` can only be used with `=` and `!=`.
- `id` takes an int or an integer string.

The `IS` operator is deprecated: it is treated as `=` and triggers an `E_USER_DEPRECATED` notice.

A property that refers to another object (type `Class`) can be compared with an object of that class or with its id, like `where('forum', '=', $forum)` or `where('forum', '=', 5)`. Other values, and new objects that have no id yet, throw an `\InvalidArgumentException`.

A `DynamicClass` property can only be compared with an object (or `null`), because an id alone doesn't say which class is meant. Only `=` and `!=` are meaningful for it.

### Simple lookup

```php
<?php

use PHersist\ObjectFinder;
use MyApp\Model\User;

$user = ObjectFinder::create(User::class)
    ->where('email', '=', 'joe@example.org')
    ->fetchOne();
```

### Filter + ordering + limit

```php
<?php

use PHersist\ObjectFinder;
use MyApp\Model\ForumMessage;

$messages = ObjectFinder::create(ForumMessage::class)
    ->where('title', 'LIKE', '%release%')
    ->orderBy('createdAt', ObjectFinder::DIRECTION_DESC)
    ->fetch(20);
```

`fetch()` takes the maximum number of objects and, optionally, the number of matching objects to skip: `fetch(20, 40)` returns the third page of 20. Both must be integers (an offset requires a limit), and the direction for `orderBy(...)` must be one of the two `ObjectFinder::DIRECTION_*` constants; anything else throws.

### Count rows

```php
<?php

$count = ObjectFinder::create(User::class)
    ->where('name', 'LIKE', 'A%')
    ->count();
```

### Query through references

You can dereference object properties in conditions:

```php
<?php

$messages = ObjectFinder::create(ForumMessage::class)
    ->where('user->email', '=', 'joe@example.org')
    ->fetch();
```

### Result types and static analysis

`ObjectFinder` is annotated with generics for static analysers such as PHPStan and Psalm. The class you pass to `create()` determines the result types, so `ObjectFinder::create(User::class)->fetch()` is known to return a `list<User>` and `fetchOne()` a `?User`, including the `@property` declarations of the generated class. The same applies to `ActiveRecord::fetchObject($class, $id)` and `User::fetch($id)`.

For this to work, the class name must be a `class-string`. `User::class` always is, so prefer it over a string literal like `'MyApp\Model\User'`.

If you must pass a plain string, for example because the class name is constructed dynamically, the analyser cannot tell which class it names and reports an error such as *Unable to resolve the template type*. This has no effect at runtime, but you can resolve it in one of two ways. The recommended way is to check the class at runtime, which the analyser understands as well:

```php
<?php

use PHersist\ActiveRecord;
use PHersist\ObjectFinder;

$class = 'MyApp\\Model\\' . ucfirst($type);
if (!is_subclass_of($class, ActiveRecord::class))
    throw new \InvalidArgumentException("Unknown model type: $type");

$objects = ObjectFinder::create($class)->fetch(); // list<ActiveRecord>
```

If you already know the string is valid, you can instead declare its type with an inline `@var` annotation:

```php
<?php

/** @var class-string<ActiveRecord> $class */
$class = 'MyApp\\Model\\' . ucfirst($type);

$objects = ObjectFinder::create($class)->fetch(); // list<ActiveRecord>
```

In both cases the results are typed as `ActiveRecord`, because the analyser does not know the specific class. If you do know it, use `class-string<User>` in the annotation to get `User` results.

### Soft-deleted records

For the rare class with `softdelete="true"`, finder queries exclude soft-deleted rows. Include them explicitly with `includeDeletedRecords(true)`:

```php
<?php

$user = ObjectFinder::create(User::class)
    ->includeDeletedRecords(true)
    ->where('email', '=', 'joe@example.org')
    ->fetchOne();
```

### Loading objects from your own queries

For queries that `ObjectFinder` can't express, you can write the SQL yourself and pass each row to `ActiveRecord::fetchObject()`. It fills the object's datasets with the values from the row, so reading those properties doesn't cost another query.

Select each column under the alias `ds_<dataset>#<column>`. `<dataset>` is the dataset's `name`, or its position in the class if it has no name (the first `<dataset>` element is `1`). Name the datasets you load this way, so your queries keep working when datasets are added or reordered in the XML:

```xml
<class name="User">
    <dataset name="main" autoload="true">
        <property name="email"/>
        <property name="password"/>
        <property name="name"/>
    </dataset>
</class>
```

```php
<?php

use PHersist\ActiveRecord;
use PHersist\DB\DBConnectionManager;

// Users who posted more than 10 messages in the last week
$stmt = DBConnectionManager::getPDO('default')->prepare("
    select `users`.`id`,
        `users`.`email` as `ds_main#email`,
        `users`.`password` as `ds_main#password`,
        `users`.`name` as `ds_main#name`
    from `users`
    inner join `forum_messages` on `forum_messages`.`user_id` = `users`.`id`
    where `forum_messages`.`created_at` > now() - interval 7 day
    group by `users`.`id`, `users`.`email`, `users`.`password`, `users`.`name`
    having count(*) > 10
");
$stmt->execute();

$users = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
    $users[] = ActiveRecord::fetchObject(User::class, (int) $row['id'], $row);
```

Without the `name` attribute, the aliases would be `ds_1#email` and so on.

- Use the database column names, not the property names. A property with several columns, like a `DynamicClass`, needs all of them.
- A dataset is loaded as a whole: the row must hold all of its columns or none of them, otherwise `fetchObject()` throws an exception. This applies to every dataset, not just the `autoload` ones. Datasets that aren't in the row are loaded when you first read one of their properties, as usual. Columns whose name doesn't start with `ds_`, like `id` above, are ignored, but a `ds_` column that doesn't belong to any dataset of the class (usually a typo) throws an exception, so don't use that prefix for other columns. When an exception is thrown, none of the row is assigned.
- Select the columns as they are stored. The values go through the property types like any other loaded data, so don't format them in SQL.
- The query is entirely yours: `fetchObject()` doesn't check that the rows exist, and it doesn't leave out soft-deleted objects; add `` `deleted` = 0 `` to the query for that.
- With `ObjectCache` enabled, an object that is already in use gets the values from the row. Properties you changed but haven't committed keep their new values.

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

```php
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
```

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

```php
<?php

foreach ($forum->messages as $message) {
    echo $message->title . PHP_EOL;
}
```

A relation also lists related objects that are soft-deleted (see [Soft-deleted records](#soft-deleted-records)), like a `Class` property still returns them; use `exists()` to check whether one is still active.

### Write owned N-N relations

```php
<?php

$message->tags = [$tag1, $tag2];
$message->commit();
```

For `table_owner="true"` relations, commit replaces relation rows for that owning object. The related objects must have been committed already; otherwise `commit()` throws an exception.  
A relation can only be set to an array of objects of its `class`; anything else, like a single object or `null`, throws an exception (use `[]` to clear it). An object can only occur once (by id, or by instance for objects that have no id yet), unless the relation has an `order_field`: then its position is stored with each row, so a list like `[$a, $b, $a]` is kept as it is.  
Relations with `table_owner="false"` (including derived relations) are read-only: assigning to them throws an exception. Change the property or relation on the owning side instead. See [Read-only and derived relations](creating-model-from-xml.md#read-only-and-derived-relations).

With `ObjectCache` enabled, dereferencing relations/properties that point to objects already loaded earlier in the same runtime can also reduce repeated SQL queries.

---

## 8) Working with maps

Map properties are key/value structures exposed via array access.

### Read and write map data

```php
<?php

$theme = $user->settings['theme'] ?? 'default';
$user->settings['theme'] = 'dark';
$user->commit();
```

A key that doesn't exist reads as `null`, and `isset()` / `??` work as they do on arrays. For a map with more than one key, `isset($page->texts['nl'])` tells whether there are any entries under `nl`.

`empty($user->settings)` is always `false`, even for a map without entries: the map property is an object, and PHP considers every object non-empty. Use `count($user->settings) === 0` to check whether a map has entries.

### Remove a map entry

```php
<?php

$user->settings['theme'] = null;
$user->commit();
```

`unset($user->settings['theme'])` does the same.

### Replace a whole map or submap

Assigning an array replaces the contents; entries that aren't in the array are removed on commit. The array must be nested exactly as deep as the map has keys. For a map with two keys (`lang`, `field`):

```php
<?php

// Replace the whole map
$page->texts = [
    'nl' => ['title' => 'Titel', 'content' => 'De inhoud hier'],
    'en' => ['title' => 'Title', 'content' => 'The content here'],
];

// Replace only the 'nl' submap
$page->texts['nl'] = ['title' => 'Titel', 'content' => 'De inhoud hier'];

// Remove the 'en' submap, or empty the whole map
unset($page->texts['en']);
$page->texts = [];

$page->commit();
```

An array that doesn't fit the key structure (too deep, too shallow, or a non-string-like value) throws an `\InvalidArgumentException` and leaves the map unchanged.

Map keys and values are strings. Like for `Text` properties, ints, floats and `Stringable` objects are converted to strings; other keys and values, like bools, arrays and `null` keys, throw an `\InvalidArgumentException`. Keys and values must also be valid UTF-8, so they are stored as text that you can query in the database directly; other strings, like raw binary data, throw an `\InvalidArgumentException` too. Encode binary data first, for example with `base64_encode()`. So `$user->settings['flag'] = false` and `$user->settings[] = 'x'` (an append, which has no key) both throw. Store a flag as `'0'`/`'1'` instead. Note that PHP turns numeric string keys into ints in the arrays `toArray()` returns, so `'5'` comes back as `5`.

To add entries without removing the others, assign them by key, or merge first: `$page->texts = array_replace_recursive($page->texts->toArray(), $new);`

#### `set()` for static analysis

The generated classes declare a map property as `PHersist\Maps\Map`, because that is what reading it returns. Static analyzers such as PHPStan therefore report an error when you assign an array to it directly, even though it works at runtime. Use `set()` instead if you want your code to pass analysis; it does exactly the same, and also works on a submap:

```php
<?php

// Same as $page->texts = [...]
$page->texts->set([
    'nl' => ['title' => 'Titel', 'content' => 'De inhoud hier'],
]);

// Same as $page->texts['nl'] = [...]
$page->texts['nl']->set(['title' => 'Titel', 'content' => 'De inhoud hier']);

// Same as $page->texts = []
$page->texts->set([]);
```

### Get a map as an array

A map property is an object, so a PHP `(array)` cast doesn't give its contents. Use `toArray()`, which also works on a submap, or loop over it directly:

```php
<?php

$all = $page->texts->toArray();        // ['nl' => ['title' => 'Titel', ...], ...]
$nl = $page->texts['nl']->toArray();   // ['title' => 'Titel', ...]

foreach ($page->texts['nl'] as $field => $text)
    echo "$field: $text\n";
```

The result is a copy; changing it doesn't change the map.

`count()` and `json_encode()` also work on a map or submap:

```php
<?php

$languages = count($page->texts);         // number of entries directly under the map: 2
$fields = count($page->texts['nl']);      // 2
$json = json_encode($page->texts);        // {"nl":{"title":"Titel",...},"en":{...}}
```

The JSON output is always an object, also for an empty map (`{}`) or one with numeric keys. `getJSONData()` is deprecated; use `toArray()` instead.

---

## 9) Practical runtime guidance

- keep high-frequency fields in autoload datasets
- move heavy/rarely-used fields into separate lazy datasets
- use `fetchOne()` when one result is expected
- use `count()` for counts instead of fetching rows and counting in PHP
- keep finder chains explicit and readable

For identity caching and advanced runtime behavior, continue with [Advanced features](advanced-features.md).  
In particular, `ObjectCache` can reduce repeated SQL when the same objects are accessed successively, whether through `ObjectFinder` queries or through dereferencing relation-backed properties.