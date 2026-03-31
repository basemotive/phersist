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
- Objects are evicted on delete.
- Because references are weak, cache entries naturally disappear when no strong references remain.

### Enabling ObjectCache

Enable it early in bootstrap code:

    <?php

    use PHersist\DB\DBConnectionManager;
    use PHersist\ObjectCache;

    DBConnectionManager::newMySQLConnection(
        'myapp',
        '127.0.0.1',
        'db_user',
        'db_password',
        'myapp',
        'UTF8'
    );
    ObjectCache::setEnabled(true);

    // Optional later:
    // ObjectCache::setEnabled(false);

### When to use it

Use `ObjectCache` when object identity consistency matters, especially with relation-heavy reads, repeated `ObjectFinder` usage, or successive relation dereferencing where the same objects are accessed multiple times.

### Boundaries

- It is runtime-local (not distributed/shared).
- It is not a persistence layer.
- It complements good query and dataset design; it does not replace it.

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

### Soft-delete awareness in finder chains

For classes configured with `softdelete="true"`:

- deleted rows are excluded by default
- call `includeDeletedRecords(true)` to include them explicitly

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

For classes with `softdelete="true"`:

- `delete()` marks the row as deleted (`deleted = 1`) rather than physically removing it
- default finder queries hide deleted rows
- relation rows are not automatically removed for soft-deleted objects, which supports restore/undelete flows

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

## 7) XMLAutoloader in development workflows

`XMLAutoloader` can generate/evaluate classes from XML at runtime, which is convenient during rapid model iteration.

Recommended use:

- development: useful for fast iteration
- production: prefer generated class files for predictable performance

---

## 8) Operational checklist

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