# Advanced features

This guide covers PHersist features that become important as your project grows: object identity caching, fluent query construction, loading strategy tradeoffs, and model-generation options that affect runtime behavior.

For setup and first usage, see:

- [Getting started with PHersist](getting-started.md)
- [Creating a model from an XML file](creating-model-from-xml.md)
- [Using the model in PHP](using-model-in-php.md)

---

## 1) ObjectCache

`ObjectCache` is an optional in-memory identity cache for `ActiveRecord` instances.

### What it solves

Without an identity cache, the same database row can be represented by multiple PHP objects during one runtime.  
With `ObjectCache` enabled, PHersist can reuse an already-loaded instance for the same class + id, which improves identity consistency across your code and can reduce repeated SQL queries when the same objects are accessed successively.

### How it works

- Entries are keyed by `class:id`.
- PHersist stores **weak references** in the cache.
- Objects are cached only when they have a non-null id.
- Lookups happen during object restoration/fetching (including repeated `ObjectFinder` results and object restoration while dereferencing relation properties), so already-cached instances can be reused instead of issuing equivalent follow-up queries.
- A new object is added to the cache when its first `commit()` gives it an id.
- Existing objects can only be obtained through `MyClass::fetch($id)`, `ActiveRecord::fetchObject()`, `ObjectFinder` or a property/relation, which all consult the cache; `new MyClass($id)` throws an exception, so you can't create a second instance for the same row by accident.
- Objects are evicted on delete.
- Because references are weak, cache entries naturally disappear when no strong references remain.

### Enabling ObjectCache

Enable it early in bootstrap code:

```php
<?php

use PHersist\DB\DBConnectionManager;
use PHersist\ObjectCache;

DBConnectionManager::newMySQLConnection(
    'myapp',
    '127.0.0.1',
    'db_user',
    'db_password',
    'myapp'
);
ObjectCache::setEnabled(true);

// Optional later:
// ObjectCache::setEnabled(false);
```

### When to use it

Use `ObjectCache` when object identity consistency matters, especially with relation-heavy reads, repeated `ObjectFinder` usage, or successive relation dereferencing where the same objects are accessed multiple times.

### Boundaries

- It is runtime-local (not distributed/shared).
- It is not a persistence layer.
- It complements good query and dataset design; it does not replace it.
- Unserializing an object (for example from a session) puts it in the cache, replacing an instance with the same class and id that is already in use. From then on, `fetch()`, `ObjectFinder` and relations return the unserialized object, while code that holds the original keeps using that one. To avoid two instances for the same row, unserialize stored objects before fetching or querying the same objects.

---

## 2) Fluent query building with ObjectFinder

`ObjectFinder` query APIs are chainable and designed for readable query construction.

Typical flow:

1. `ObjectFinder::create(ClassName::class)`
2. Add filters with `where(...)`
3. For grouped logic, call `addAnd()` or `addOr()`, then add grouped `where(...)` clauses
4. If you need to continue at the parent expression level, call `end()`
5. Add ordering with `orderBy(...)`
6. Finish with `fetch()`, `fetchOne()`, or `count()`

Use `end()` when you need to close a group and keep building conditions at the level above it.  
If you do not need to add more parent-level expressions, `end()` is optional because expression objects pass through terminal and ordering methods (such as `orderBy(...)`, `fetch(...)`, `fetchOne()`, and `count()`) to the `ObjectFinder`.

---

## 3) Dataset loading strategy

Dataset design is one of the biggest performance levers in PHersist.

- `autoload="true"` datasets are loaded automatically in bulk retrieval (when full hydration is requested).
- Non-autoload datasets are loaded lazily.
- Accessing one property from a lazy dataset restores that dataset as a unit.

### Practical guideline

Keep frequently listed fields in autoload datasets.  
Place large or rarely-needed fields in separate non-autoload datasets.

---

## 4) Relation loading strategy

Relation settings directly impact query cost and object hydration behavior.

- `load_objects="true"`: related objects are restored with autoload data.
- `load_objects="false"`: related objects are created as id-only skeletons; later property access causes additional restores.

Choose `load_objects="true"` when related object data is usually needed immediately.  
Choose `false` when links are needed but related payload is usually not used.

---

## 5) Soft delete runtime behavior

Soft delete is opt-in and meant for the exceptional case where a record must stay available after it is deleted; by default, `delete()` removes the row. For classes with `softdelete="true"`:

- `delete()` marks the row as deleted (`deleted = 1`) rather than physically removing it
- default finder queries hide deleted rows; call `includeDeletedRecords(true)` to include them
- relation rows and references from other objects are left alone, so the record stays usable wherever it is referenced: a `Class` property pointing at a soft-deleted object still returns it, with all its data, and relations still list it. So when you rewrite a relation from its loaded value (`$msg->tags = [...$msg->tags, $tag]`), soft-deleted objects keep their place in it. The deleted object itself keeps its `id` and can still be read (but not changed or committed), so this also holds for objects that were already loaded and share the same instance, for example through the `ObjectCache`. New references to a soft-deleted object can be committed as well; only the object itself can't be changed anymore. Use `exists()` or `isDeleted()` if you need to know whether an object is still active. `isDeleted()` doesn't query the database itself: an object that is fetched again in a later request returns `true` once its base table data has been loaded (by reading a property in that table, or through `ObjectFinder` or a relation). Either way, `commit()` throws for a soft-deleted object, because it only updates rows with `deleted = 0`.

Use soft delete as a deliberate lifecycle choice; it adds operational complexity and should be applied intentionally.

---

## 6) Advanced naming behavior in model generation

Two generation settings are useful for a wide range of schema styles.

### `id_style` (`short` / `long`)

Set at `<project>` level:

- `short` (default): generated class primary key field is `id`
- `long`: generated class primary key field is class-derived, e.g. `forum_message_id`

Per-class `<class id="...">` still overrides generated behavior.

### `SnakeCase` table naming

`TSSnakeCase` applies plural handling for generated table names and robust acronym splitting in snake_case conversion.  
This keeps generated names predictable and reduces manual naming overrides.

---

## 7) Polymorphic discriminator columns and `use_namespace`

Both polymorphic relations (`local_type`) and shared map tables (`type`) write a class name into a database column to distinguish rows from different object types.  
The `use_namespace` attribute on `<relation>` and `<map>` controls which form of the class name is stored. A `DynamicClass` property has the same attribute for the class name it stores; see below for how it differs.

### Default: short class name (`use_namespace="false"`)

When `use_namespace` is absent or `false`, PHersist stores only the **short class name** — the final segment of the PHP class, without any namespace prefix:

| Class | Stored value |
|---|---|
| `MyApp\Model\ForumMessage` | `ForumMessage` |
| `MyApp\Model\User` | `User` |

This is the recommended default because:

- values stay short and readable in the database
- data is not tied to a specific PHP namespace, making refactoring or moving classes painless
- discriminator columns remain easy to inspect and filter in raw SQL

### Optional: fully-qualified class name (`use_namespace="true"`)

Set `use_namespace="true"` when you need the full namespace in the column, for example when integrating with an external system that already stores fully-qualified names, or when two classes in different namespaces share the same short name:

| Class | Stored value |
|---|---|
| `MyApp\Model\ForumMessage` | `MyApp\Model\ForumMessage` |
| `MyApp\Model\User` | `MyApp\Model\User` |

### Where it applies

- **`<relation local_type="...">` + `use_namespace`**: affects the discriminator column written during store/delete/restore of a polymorphic NN relation.
- **`<map type="...">` + `use_namespace`**: affects the discriminator column written during commit/delete/restore of a shared map table.
- **`<property type="DynamicClass">` + `use_namespace`**: affects the class-name column of the property. Because this name is used to load the referred object, the namespace is only left out for classes in the same namespace as the class with the property; others are stored fully qualified. When reading, a name without namespace is taken to be in that namespace.

See the [XML reference](creating-model-from-xml.md) for the exact attribute syntax.

---

## 8) XMLAutoloader in development workflows

`XMLAutoloader` can generate/evaluate classes from XML at runtime, which is convenient during rapid model iteration.

Recommended use:

- development: useful for fast iteration
- production: prefer generated class files for predictable performance

---

## 9) Operational checklist

- Keep finder chains explicit and readable.
- Use `count()` for counts instead of fetching rows just to count in PHP.
- Keep relation ownership (`table_owner`) correct to avoid write-path surprises.
- Revisit dataset boundaries as read patterns evolve.
- Enable `ObjectCache` where identity consistency provides clear value.

---

## Related documentation

- [Getting started with PHersist](getting-started.md)
- [Creating a model from an XML file](creating-model-from-xml.md)
- [Using the model in PHP](using-model-in-php.md)
- [Sample model overview](sample-model.md)
- [sample-model.xml](../examples/basic/sample-model.xml)
- [sample-model.sql](../examples/basic/sample-model.sql)