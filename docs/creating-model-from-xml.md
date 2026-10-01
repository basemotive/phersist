# Creating a model from an XML file

PHersist generates PHP model classes (and optionally MySQL schema) from a model XML file.  
This guide is the full reference for defining that XML.

If you want a quick onboarding flow first, read [Getting started with PHersist](getting-started.md).  
For runtime usage in PHP, see [Using the model in PHP](using-model-in-php.md).  
For caching and performance features, see [Advanced features](advanced-features.md).

---

## Quick start

Create `model/model.xml`:

```xml
<project database="myapp" tablestyle="SnakeCase" namespace="MyApp\Model" id_style="short">
    <class name="Product">
        <dataset autoload="true">
            <property name="name" required="true"/>
            <property name="price" type="Int"/>
        </dataset>
    </class>
</project>
```

Generate PHP classes:

```sh
vendor/bin/phersist --xml=model/model.xml
```

Generate classes + SQL schema:

```sh
vendor/bin/phersist --xml=model/model.xml --mysql=model/schema.sql
```

---

## Root element: `<project>`

Every model starts with a single `<project>` root:

```xml
<project database="myapp" tablestyle="SnakeCase" namespace="MyApp\Model" id_style="short">
    <!-- classes -->
</project>
```

### Attributes

| Attribute | Required | Default | Description |
|---|---|---|---|
| `database` | yes | — | Database connection identifier used at runtime with `DBConnectionManager::newMySQLConnection(...)`, `DBConnectionManager::newSQLiteConnection(...)`, or `DBConnectionManager::newSQLSrvLConnection(...)`. |
| `tablestyle` | yes | — | Name conversion strategy for table/column/id names. Built-in style: `SnakeCase`. |
| `namespace` | no | none | PHP namespace for generated classes. |
| `id_style` | no | `short` | Primary key naming mode for auto-generated class IDs. `short` => `id`, `long` => converted class name + `_id` (example: `forum_message_id`). |

### `id_style`: short vs long

`id_style` controls **auto-generated class primary key field names** when no explicit `id` is set on `<class>`.

- `id_style="short"` (default): class PK field name is `id`
- `id_style="long"`: class PK field name uses table style ID conversion, e.g. `ForumMessage` -> `forum_message_id`

You can always override per class with `<class id="...">`.

---

## Database-specific settings

Settings that only apply to one database type live in their own element directly under `<project>`, named after the database type. Settings that apply to all database types (such as `tablestyle`) stay on `<project>` itself.

### MySQL: `<mysql>`

```xml
<project database="myapp" tablestyle="SnakeCase" namespace="MyApp\Model">
    <mysql charset="utf8mb4" collate="utf8mb4_0900_ai_ci"/>
    <!-- classes -->
</project>
```

These settings only affect the schema generated with `--mysql`; the generated PHP classes are unaffected.

| Attribute | Required | Default | Description |
|---|---|---|---|
| `charset` | no | `utf8mb4` | Table character set (`DEFAULT CHARSET=...`). |
| `collate` | no | `utf8mb4_unicode_ci` if `charset` is not set, otherwise none | Table collation (`COLLATE=...`). When `charset` is set without `collate`, no `COLLATE` clause is emitted and MySQL uses the charset's default collation. |

The `<mysql>` element itself is optional; without it the defaults above are used. Values may only contain letters, digits and underscores.

---

## Defining classes: `<class>`

Each `<class>` generates one PHP class extending `\PHersist\ActiveRecord`.

```xml
<class name="ForumMessage" table="forum_messages" id="id">
    ...
</class>
```

### Attributes

| Attribute | Required | Default | Description |
|---|---|---|---|
| `name` | yes | — | Generated PHP class name (UpperCamelCase recommended). |
| `id` | no | auto | Primary key column name. Auto value depends on `id_style`. |
| `table` | no | auto | Base table name for this class. |
| `database` | no | project `database` | Optional per-class DB override. |
| `softdelete` | no | `false` | If `true`, `delete()` sets `deleted = 1` instead of removing row. |
| `trait` | no | auto-detected | Optional trait name to include in generated class. |

### Extending generated classes with Traits

You can extend PHersist-generated classes by writing a trait.  
When generating a class, PHersist checks whether a trait named `ClassNameTrait` exists in the same namespace and includes it automatically.

Example for class `ForumMessage`:
- class: `ForumMessage`
- auto-detected trait: `ForumMessageTrait`

A sample implementation is available in `examples/basic/ForumMessageTrait.php`, where `ForumMessageTrait::createdAtRelative()` adds a custom convenience method to the generated object.

If the trait cannot be auto-detected, or if you want a different trait name, set the `trait` attribute explicitly on the class:

```xml
<class name="ForumMessage" trait="ForumMessageTrait">
    ...
</class>
```

If the trait is in another namespace, provide the fully qualified name:

```xml
<class name="ForumMessage" trait="Babble\Model\ForumMessageTrait">
    ...
</class>
```

This lets you keep custom behavior outside generated files while still having it available directly on generated PHersist objects.

---

## Datasets: `<dataset>`

Properties are grouped into datasets. A dataset is loaded in one query.

```xml
<dataset autoload="true">
    <property name="title" required="true"/>
    <property name="summary"/>
</dataset>

<dataset>
    <property name="messageContent" required="true"/>
</dataset>
```

### Attributes

| Attribute | Required | Default | Description |
|---|---|---|---|
| `autoload` | no | `false` | Load this dataset automatically in bulk fetches. |
| `table` | no | class base table | Optional table override for dataset-backed properties. The table shares the id of the base table: every object has exactly one row in it, created on the first commit and removed on a hard delete. |

### Why split datasets?

Put high-frequency fields in `autoload="true"` datasets, and large or rarely-used fields in separate datasets.  
When one property in a non-autoload dataset is accessed, the full dataset is restored together.

---

## Properties: `<property>`

Properties define class fields and column mapping.

```xml
<property name="email" required="true"/>
<property name="viewCount" type="Int"/>
<property name="author" type="Class" class="User" fieldname="author_id"/>
```

### Attributes

| Attribute | Required | Default | Description |
|---|---|---|---|
| `name` | yes | — | Property name used in PHP (`$object->name`). The name `id` is reserved for the object's id; the generator refuses it. |
| `type` | no | `Text` | Property type (`Text`, `Int`, `Float`, `Decimal`, `Bool`, `Date`, `DateTime`, `Class`, `DynamicClass`, `TimestampText`). |
| `required` | no | `false` | If `true`, must not be null. A new object must have a value for every required property (assigned or from `default`), or `commit()` throws an exception. |
| `fieldname` | no | auto | Custom single-column field name. |
| `fieldnames` | no | auto | Custom comma-separated multi-column names (used by multi-field types). |
| `default` | no | — | Default value. Applies to `Text`, `Int`, `Float`, `Decimal`, and `Bool` properties. There are no implicit defaults, also not for `required` properties. See [Default values](#default-values). |

> `fieldnames` is optional for `DynamicClass`.  
> If omitted, PHersist generates two field names automatically in the form `propname_type,propname_id` (translated with the configured table style).

### Default values

```xml
<property name="title" required="true" default=""/>
<property name="isPublished" type="Bool" required="true" default="true"/>
<property name="views" type="Int" required="true" default="0"/>
```

A default value is used in two places:

- **Database schema:** it becomes the column default (`DEFAULT ...`) in the generated MySQL schema.
- **New objects:** it is set on every newly instantiated object (`new Article()`), so `$article->views` is `0` right away instead of being unset. These values are written on the first `commit()` along with any other changes, so the stored row always matches what the application saw, even for existing tables without column defaults.

Objects loaded from the database are unaffected; they always get the stored values.

On a new object, a property without a default reads as `null` until it is assigned. PHersist never assumes a default that isn't in the model: if a `required` property has no `default` and isn't assigned, `commit()` throws an exception listing the missing properties instead of storing an empty value. The exception is a `Date`, `DateTime` or `TimestampText` property with `update_on`, which gets its value on commit. In the generated schema, such a column is `NOT NULL` without a `DEFAULT`.

> Default values are stored in the generated classes' metadata, so regenerate your classes after adding or changing a `default`.

---

## Property types

### `Text`
Default string-like field. Maps to a `TEXT` column.

```xml
<property name="description" type="Text"/>
<property name="title" type="Text" required="true" default="Untitled"/>
```

Default value behaviour:
- If `default` is set, that string value is used as the default.
- There is no implicit default, so a `required` field without `default` must be assigned before the first `commit()`.

### `Int`
Integer field. Maps to an `INT` column (signed by default, `INT UNSIGNED` when `signed="false"`). Values read from the database are returned as `int`. Assigned integer strings (like `'42'`) and floats without a fractional part (like `42.0`) are converted to `int`; other values, like `3.5`, `'3.0'` or `'1e3'`, throw an exception.

```xml
<property name="price" type="Int"/>
<property name="score" type="Int" signed="false"/>
<property name="viewCount" type="Int" required="true" default="0"/>
```

Extra attribute:

| Attribute | Required | Default | Description |
|---|---|---|---|
| `signed` | no | `true` | Set to `false` to emit `INT UNSIGNED`. |

Default value behaviour:
- If `default` is set, the integer equivalent of that value is used as the default.
- There is no implicit default, so a `required` field without `default` must be assigned before the first `commit()`.

### `Float`
Floating point field. Maps to a `DOUBLE` column, which has the same (double) precision as a PHP `float`. Values read from the database are returned as `float`. Assigned ints and numeric strings are converted to `float`; other values throw an exception.

```xml
<property name="weight" type="Float"/>
<property name="rating" type="Float" required="true" default="2.5"/>
```

Default value behaviour:
- If `default` is set, the float equivalent of that value is used as the default.
- There is no implicit default, so a `required` field without `default` must be assigned before the first `commit()`.

> Floating point values are inexact. Don't use `Float` for money or other values that need exact decimal arithmetic; use [`Decimal`](#decimal) instead.

### `Decimal`
Exact decimal number, for money and other values that must not be rounded. Maps to a `DECIMAL(precision,scale)` column.

```xml
<property name="price" type="Decimal" required="true"/>
<property name="exchangeRate" type="Decimal" precision="12" scale="6" default="1"/>
```

Extra attributes:

| Attribute | Required | Default | Description |
|---|---|---|---|
| `precision` | no | `10` | Total number of digits (1–65). |
| `scale` | no | `2` | Number of digits after the decimal point (0–30, at most `precision`). |

PHP has no exact decimal type, so values are **strings** with exactly `scale` decimals, like `'12.50'`. You can assign:
- **numeric strings** and **ints**, like `'12.5'` or `12` (both become `'12.50'`). These must fit exactly: a value with more decimals than `scale` (`'12.345'`), or too many digits before the decimal point, throws an exception instead of being rounded silently. Exponent notation (`'1e3'`) isn't accepted.
- **floats**, which are rounded to `scale` decimals (`0.1 + 0.2` becomes `'0.30'`), since floats are inexact anyway.

To calculate with these values exactly, use an extension like [bcmath](https://www.php.net/manual/en/book.bc.php), or ints in the smallest unit (e.g. cents).

Default value behaviour:
- If `default` is set, it must be a valid decimal that fits the column; the generator reports an error otherwise.
- There is no implicit default, so a `required` field without `default` must be assigned before the first `commit()`.

### `Bool`
Boolean field. Maps to an `TINYINT UNSIGNED` column, storing `1` for true and `0` for false. Values read from the database are returned as `bool`. Assigned ints `0` and `1` and strings `'0'` and `'1'` are converted to `bool`; other values, like `2` or `'false'`, throw an exception. The same applies to values used when searching with `ObjectFinder::where()`.

```xml
<property name="active" type="Bool"/>
<property name="visible" type="Bool" required="true" default="true"/>
```

Default value behaviour:
- If `default` is set, use `"true"` to default to `true` (`1`) or any other value to default to `false` (`0`).
- There is no implicit default, so a `required` field without `default` must be assigned before the first `commit()`.

### `Date`
Date without a time. Maps to a `DATE` column. Values are `DateTimeImmutable` objects at midnight in PHP's default timezone.

```xml
<property name="birthday" type="Date"/>
<property name="lastChangedOn" type="Date" update_on="modify"/>
```

Accepts the same values as [`DateTime`](#datetime), but only uses the date part. That date is taken as written, in the value's own timezone: `'2026-01-01T23:30:00-05:00'` becomes 2026-01-01, even though that moment is already January 2 in Europe.

`default` is not supported for `Date` properties. `update_on` works like it does for [`DateTime`](#datetime), storing the current date.

### `DateTime`
Date and time. Maps to a `DATETIME` column. Values are `DateTimeImmutable` objects in PHP's default timezone.

```xml
<property name="startsAt" type="DateTime" required="true"/>
<property name="createdAt" type="DateTime" update_on="create"/>
<property name="modifiedAt" type="DateTime" update_on="modify"/>
```

Extra attribute:

| Attribute | Required | Default | Description |
|---|---|---|---|
| `update_on` | no | — | `create`: set to the current time when the object is first stored. `modify`: set to the current time every time the object is stored, including the first time. |

With `update_on`, the automatic value always replaces a value you assigned yourself. `modify` only applies when `commit()` actually stores something: committing an object without changes doesn't update it. After `commit()`, the property holds the stored value, so you don't need to reload the object to read it.

You can assign any `DateTimeInterface` object or a string in one of these formats:

| Format | Example |
|---|---|
| Date | `'2026-01-01'` (midnight) |
| Date and time | `'2026-01-01 12:30'`, `'2026-01-01 12:30:00'` |
| ISO 8601 | `'2026-01-01T12:30:00'` |
| ISO 8601 with offset | `'2026-01-01T12:30:00+02:00'`, `'2026-01-01T10:30:00.000Z'` |

Strings are converted to `DateTimeImmutable` right away, so `$event->startsAt` is always an object. Other strings, like `'tomorrow'` or `'01-01-2026'`, and impossible dates like `'2026-02-30'` throw an exception, instead of being guessed at or rolled over to another date.

Timezones:
- A `DATETIME` column doesn't store a timezone. Values are converted to PHP's default timezone (`date_default_timezone_get()`) before they are stored, and read back in that timezone. The moment in time is kept, but the original offset is not: `'2026-01-01T12:30:00+02:00'` is stored as `2026-01-01 10:30:00` when the default timezone is UTC.
- Strings without an offset are interpreted in the default timezone.
- Because stored values depend on the default timezone, it must be the same everywhere your application runs, and must not change once there is data.

Choosing the default timezone:

- **UTC** (`date_default_timezone_set('UTC')`) is the safest choice. UTC has no daylight saving time, so every stored value means exactly one moment in time. It also doesn't depend on local server settings. The downside is that you convert values for display, for example `$event->startsAt->setTimezone(new DateTimeZone('Europe/Amsterdam'))->format('H:i')`.
- **A local timezone**, like `Europe/Amsterdam`, stores values as local time, so they display as-is and are easy to read in the database. This works if your application only deals with that one timezone, but daylight saving time makes some values ambiguous or impossible. When the clocks go back, an hour happens twice (in Amsterdam, `02:30` on the last Sunday of October happens twice), and the stored value can't tell which one it was. When the clocks go forward, an hour is skipped, and times in that hour are moved to a different time.

PHersist doesn't enforce either choice; it uses whatever `date_default_timezone_get()` returns.

Values are stored with a precision of seconds; fractions of seconds are dropped.

`default` is not supported for `DateTime` properties. For automatic creation/modification times, use `update_on`.

### `Class`
Reference to another class in the model. Assigned values must be instances of that class (or a subclass); anything else, like an object of another class or an ID, throws an exception.

```xml
<property name="forum" type="Class" class="Forum" required="true"/>
```

Extra attribute:

| Attribute | Required | Description |
|---|---|---|
| `class` | yes | Name of target class in this model XML. |

### `DynamicClass`
Polymorphic reference: class + id pair. Assigned values must be `ActiveRecord` objects; anything else throws an exception.

```xml
<property name="target" type="DynamicClass"/>
```

Optional override:

```xml
<property name="target" type="DynamicClass" fieldnames="target_class,target_id"/>
```

Extra attribute:

| Attribute | Required | Description |
|---|---|---|
| `fieldnames` | no | Optional two-field override (class-name column, id column). If omitted, PHersist auto-generates `propname_type,propname_id` using the configured table style. |

In the generated MySQL schema, the class-name column is `VARCHAR(191)` and the id column is `INT UNSIGNED`, indexed together.

### `TimestampText`
Datetime field with optional auto-updating.

```xml
<property name="createdAt" type="TimestampText" update_on="create"/>
<property name="modifiedAt" type="TimestampText" update_on="modify"/>
```

Extra attributes:

| Attribute | Required | Description |
|---|---|---|
| `update_on` | no | `create` or `modify`. |
| `date_format` | no | PHP `date()` format string. |

Values are plain strings. For new models, a [`DateTime`](#datetime) or [`Date`](#date) property with `update_on` is usually a better fit, since it gives you `DateTimeImmutable` objects.

---

## Relations: `<relation>`

PHersist currently uses `type="NN"` for both one-to-many and many-to-many patterns.

```xml
<relation
    name="tags"
    type="NN"
    class="Tag"
    table="forum_message_tags"
    local_id="forum_message_id"
    remote_id="tag_id"
    table_owner="true"
    load_objects="true"
/>
```

### Attributes

| Attribute | Required | Description |
|---|---|---|
| `name` | yes | Relation property name on object. |
| `type` | yes | Currently `NN`. |
| `class` | yes | Target class name. |
| `table` | yes | Relation table (join table or target/base table for derived reads). |
| `local_id` | yes | Column storing local object ID. |
| `remote_id` | yes | Column storing related object ID. |
| `table_owner` | yes | `true` if this side owns/writes relation rows. |
| `load_objects` | yes | `true` to load autoload datasets for related objects; `false` for ID-only skeletons. |
| `order_field` | no | SQL order column when restoring relation. |
| `cascade_delete` | no | `true` to delete the related objects when this object is deleted (default `false`). See [Deleting objects with relations](#deleting-objects-with-relations). |
| `local_type` | no | Column holding the class name of the local object, for tables that hold rows of several classes. When omitted, every row matching `local_id` is taken to belong to this class. |
| `use_namespace` | no | Controls what value is stored in `local_type`. `false` (default): store the short class name (e.g. `ForumMessage`). `true`: store the fully-qualified class name (e.g. `MyApp\Model\ForumMessage`). Only used when `local_type` is set. |

### Common patterns

- **1-N derived relation**: `table_owner="false"` against related class table.
- **N-N relation with join table**: `table_owner="true"` and dedicated join table.
- **Polymorphic reverse relation**: add `local_type` when class discriminator is needed.

### Deleting objects with relations

When an object is deleted (without `softdelete`), each of its relations is cleaned up so no rows keep referring to it. What happens depends on the relation's `table`:

| `table` is… | Without `cascade_delete` | With `cascade_delete="true"` |
|---|---|---|
| A join table (owned or not) | The object's rows in the join table are deleted; the related objects stay. | The related objects are deleted, then the object's rows in the join table. |
| One of the related class's own tables (derived relation) | The references are set to `NULL` (both columns for a `local_type` relation). | The related objects are deleted. |

For a derived relation, the reference usually belongs to a property of the related class, like `Page.picture` for a `Picture.pages` relation. If that property is `required`, the reference can't be set to `NULL`, so `delete()` throws an exception when any object still refers to the deleted one. Delete those objects first, or set `cascade_delete="true"` to delete them along.

References from softdeleted objects are set to `NULL` too, so they don't refer to a missing object when they are undeleted, and they also count for the required check above. With `cascade_delete`, softdeleted objects aren't deleted again; related objects of a class with `softdelete="true"` are softdeleted and keep their reference.

The whole delete runs in one transaction, so if one step fails, nothing is deleted. Objects that are already in memory are not updated: a `Page` you loaded before deleting its picture still returns the deleted `Picture` object. Fetch it again to see the change.

Only relations defined on the deleted object's class are cleaned up. If `Page` refers to `Picture` but `Picture` has no `pages` relation, deleting a picture leaves the `picture_id` values in place.

When the deleted object's class uses `softdelete="true"`, relations and references are left alone: the object is only made inactive, and objects that refer to it keep working.

### Polymorphic relations and `use_namespace`

When `local_type` is set, PHersist writes the owner class name into that column so a shared relation table can store rows from different classes.  
By default (`use_namespace="false"`), the **short class name** is stored — just the final segment without namespace:

```xml
<relation
    name="comments"
    type="NN"
    class="Comment"
    table="comments"
    local_id="owner_id"
    remote_id="comment_id"
    local_type="owner_type"
    table_owner="true"
    load_objects="true"
/>
```

Here the `owner_type` column will contain `ForumMessage`, not `MyApp\Model\ForumMessage`.  
This keeps the stored values short and human-readable, and avoids tying your database data to a specific PHP namespace.

Without `local_type`, PHersist assumes no discriminator is needed: any row with the object's ID in `local_id` belongs to the current class.

In the generated MySQL schema, a join table (`table_owner="true"`) gets the `local_type` column as `VARCHAR(191) NOT NULL`, indexed together with `local_id`. A join table shared by several classes gets the combined columns of all relations that use it. When `table` is a class's own base or dataset table, the relation adds nothing to the schema: the columns are expected to be defined by that class (for example by a `DynamicClass` property).

Set `use_namespace="true"` only when you need the fully-qualified name — for example to match values an external system has already stored:

```xml
<relation
    name="comments"
    ...
    local_type="owner_type"
    use_namespace="true"
/>
```

---

## Maps: `<map>`

Maps provide key/value data attached to an object through a table.

```xml
<map name="settings" table="user_settings" id="user_id" type="object_type">
    <key name="key_name"/>
    <value name="value_text"/>
</map>
```

### `<map>` attributes

| Attribute | Required | Default | Description |
|---|---|---|---|
| `name` | yes | — | Map property name on object. |
| `table` | yes | — | Backing table. |
| `id` | no | auto | Owner ID column name. |
| `type` | no | none | Optional class discriminator column for shared map tables. |
| `use_namespace` | no | `false` | Controls what value is stored in the `type` column. `false` (default): store the short class name (e.g. `User`). `true`: store the fully-qualified class name (e.g. `MyApp\Model\User`). Only used when `type` is set. |

### `<key>` and `<value>`

- One or more `<key>` elements define key hierarchy.
- Exactly one `<value>` element defines the column that stores the value. The generator reports an error for a map with no or multiple `<value>` elements; use an extra `<key>` instead to store several values per key.

### Generated table

The `type` and key columns are `VARCHAR(191)`, the value column is `TEXT`. Key columns use the binary collation of the charset (e.g. `utf8mb4_bin`), so keys are case-sensitive, just like PHP array keys.

The table gets exactly one index:

- **Up to 4 text columns** (the key columns, plus the `type` column if set; so 3 keys with `type`, 4 without): a unique index on the owner (`type` if set, then `id`) plus all key columns. Each key path can hold only one value, and the database rejects duplicates.
- **More than that:** a plain index on the owner only, because a unique index over all columns would exceed InnoDB's index size limit. Such maps work the same, but the database doesn't prevent duplicate rows for a key path. PHersist never writes duplicates itself; if something else does, the map keeps the last row it reads.

PHersist always loads a map as a whole by its owner, so the key columns have no indexes of their own. If you query a map table by key in your own SQL, add the index you need yourself.

Tables generated by older versions (`TEXT` columns, an index per key, no unique index) keep working. To bring one up to date, provided it has no duplicate keys and no keys longer than 191 characters:

```sql
ALTER TABLE `user_settings`
    MODIFY `object_type` VARCHAR(191) NOT NULL,
    MODIFY `key_name` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    DROP INDEX `idx_user_id`,
    DROP INDEX `idx_key_name`,
    ADD UNIQUE INDEX `uniq_user_id` (`object_type`, `user_id`, `key_name`);
```

### Shared map tables and `use_namespace`

When multiple classes share the same map table, the `type` column acts as a class discriminator to filter rows belonging to each class.  
By default (`use_namespace="false"`), the **short class name** is stored — just the final segment without namespace:

```xml
<map name="settings" table="object_settings" id="owner_id" type="owner_type">
    <key name="setting_key"/>
    <value name="setting_value"/>
</map>
```

Here the `owner_type` column will contain `User`, not `MyApp\Model\User`.  
This keeps stored values short and decoupled from your PHP namespace.

Set `use_namespace="true"` only when the fully-qualified name is required — for example to be compatible with values an external system has already stored:

```xml
<map name="settings" table="object_settings" id="owner_id" type="owner_type" use_namespace="true">
    ...
</map>
```

---

## Name conversion (`tablestyle="SnakeCase"`)

PHersist’s `TSSnakeCase` converter maps camel/Pascal case names to snake_case and applies pluralization rules for table names.

### Conversion behavior

- `table`: snake_case singular + pluralization rules
- `id` / `relation_id`: snake_case + `_id`
- `relation_combo`: `name_type,name_id`
- `fieldname`: snake_case

### Table pluralization rules

For generated table names (from singular class name), `TSSnakeCase` applies English plural handling:

- endings `s`, `sh`, `ch`, `x`, `z` -> add `es`
- consonant + `y` -> replace `y` with `ies`
- ending `f` -> `ves`
- ending `fe` -> `ves`
- ending `o` -> add `es` (except patterns like `oo`, `eo`, `io`, `uo`)
- otherwise -> add `s`

Examples:

| Class | Table |
|---|---|
| `ForumMessage` | `forum_messages` |
| `Category` | `categories` |
| `Box` | `boxes` |
| `Knife` | `knives` |
| `Hero` | `heroes` |
| `Zoo` | `zoos` |

### Case transition handling

Acronyms are handled during snake conversion:

- `XMLDocument` -> `xml_document`
- `isXML` -> `is_xml`

### Overriding generated names

You can override any generated name directly:

- class table: `<class table="...">`
- class id field: `<class id="...">`
- property field(s): `<property fieldname="...">` / `<property fieldnames="...">`
- dataset table: `<dataset table="...">`

---

## Full example

A full, realistic model is provided in [sample-model.xml](../examples/basic/sample-model.xml).

---

## Generation commands

```sh
# Generate PHP classes
vendor/bin/phersist --xml=model/model.xml

# Generate classes + MySQL schema
vendor/bin/phersist --xml=model/model.xml --mysql=model/schema.sql

# Generate only MySQL schema
vendor/bin/phersist --xml=model/model.xml --mysql=model/schema.sql --skip-classes

# Include custom class snippets
vendor/bin/phersist --xml=model/model.xml --includesdir=model/includes
```

---

## Related guides

- [Getting started with PHersist](getting-started.md)
- [Using the model in PHP](using-model-in-php.md)
- [Advanced features](advanced-features.md)
- [sample-model.xml](../examples/basic/sample-model.xml)