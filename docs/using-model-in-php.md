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

### Load and update

```php
<?php

$user = new User(123);   // id-based object
$user->name = 'Joseph Example';
$user->commit();
```

`commit()` only writes the properties that changed since the object was loaded or last committed. If nothing changed, it does nothing at all, so it's safe to call unconditionally. (A new object is always inserted on its first `commit()`, even if nothing was set.)

Assigning a property the value it already has (compared with `===` after conversion, and by moment in time for dates) doesn't count as a change. The exception is a property whose dataset hasn't been loaded yet: its current value isn't known, so the assignment is always treated as a change.

### Delete

```php
<?php

$user->delete();
```

If the class uses `softdelete="true"`, this sets `deleted = 1` instead of removing the row.

After `delete()`, the object's lifecycle has ended: its `id` becomes `null`, and setting a property (including map entries) or calling `commit()` on it throws an exception, instead of inserting it again as a new row. Properties that were already loaded can still be read; properties that weren't read as `null`. To store the same data again, create a new object. Use `$user->isDeleted()` to check whether an object has been deleted.

### Check existence

```php
<?php

if ($user->exists()) {
    // row is present
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

$message = new ForumMessage(123);
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
- `includeDeletedRecords(true|false)` for soft-delete classes

Besides the properties from your XML, `id` can be used in `where(...)` (also at the end of a path, such as `forum->id`) and in `orderBy(...)`.

Supported operators include:
`=`, `IS`, `>`, `<`, `>=`, `<=`, `!=`, `LIKE`, `NOT LIKE`.

To find `NULL` values, use `null` with `=` or `IS`, like `where('deletedAt', '=', null)`. It is translated to `IS NULL`, and `!=` with `null` to `NOT ... IS NULL`, for properties of any type.

A property that refers to another object (type `Class`) can be compared with an object of that class or with its id, like `where('forum', '=', $forum)` or `where('forum', '=', 5)`. Other values, and new objects that have no id yet, throw an `\InvalidArgumentException`.

A `DynamicClass` property can only be compared with an object (or `null`), because an id alone doesn't say which class is meant. Only `=`, `IS` and `!=` are meaningful for it.

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

`ObjectFinder` is annotated with generics for static analysers such as PHPStan and Psalm. The class you pass to `create()` determines the result types, so `ObjectFinder::create(User::class)->fetch()` is known to return a `list<User>` and `fetchOne()` a `?User`, including the `@property` declarations of the generated class. The same applies to `ActiveRecord::fetchObject($class, $id)`.

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

### Write owned N-N relations

```php
<?php

$message->tags = [$tag1, $tag2];
$message->commit();
```

For `table_owner="true"` relations, commit replaces relation rows for that owning object. The related objects must have been committed already; otherwise `commit()` throws an exception.  
For derived relations (`table_owner="false"`), treat them as read-only views.

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

An array that doesn't fit the key structure (too deep, too shallow, or a non-string-like value) throws an `\InvalidArgumentException` and leaves the map unchanged. Values are stored as strings.

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

## 9) Soft-delete query behavior

For classes with `softdelete="true"`:

- normal finder queries exclude deleted rows
- include them explicitly with `includeDeletedRecords(true)`

```php
<?php

$user = ObjectFinder::create(User::class)
    ->includeDeletedRecords(true)
    ->where('email', '=', 'joe@example.org')
    ->fetchOne();
```

---

## 10) Practical runtime guidance

- keep high-frequency fields in autoload datasets
- move heavy/rarely-used fields into separate lazy datasets
- use `fetchOne()` when one result is expected
- use `count()` for counts instead of fetching rows and counting in PHP
- keep finder chains explicit and readable

For identity caching and advanced runtime behavior, continue with [Advanced features](advanced-features.md).  
In particular, `ObjectCache` can reduce repeated SQL when the same objects are accessed successively, whether through `ObjectFinder` queries or through dereferencing relation-backed properties.