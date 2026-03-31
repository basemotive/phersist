# Creating a model from an XML file

PHersist generates PHP model classes (and optionally MySQL schema) from a model XML file.  
This guide is the full reference for defining that XML.

If you want a quick onboarding flow first, read [Getting started with PHersist](getting-started.md).  
For runtime usage in PHP, see [Using the model in PHP](using-model-in-php.md).  
For caching and performance features, see [Advanced features](advanced-features.md).

---

## Quick start

Create `model/model.xml`:

    <project database="myapp" tablestyle="SnakeCase" namespace="MyApp\Model" id_style="short">
        <class name="Product">
            <dataset autoload="true">
                <property name="name" required="true"/>
                <property name="price" type="Int"/>
            </dataset>
        </class>
    </project>

Generate PHP classes:

    vendor/bin/phersist --xml=model/model.xml

Generate classes + SQL schema:

    vendor/bin/phersist --xml=model/model.xml --mysql=model/schema.sql

---

## Root element: `<project>`

Every model starts with a single `<project>` root:

    <project database="myapp" tablestyle="SnakeCase" namespace="MyApp\Model" id_style="short">
        <!-- classes -->
    </project>

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

## Defining classes: `<class>`

Each `<class>` generates one PHP class extending `\PHersist\ActiveRecord`.

    <class name="ForumMessage" table="forum_messages" id="id">
        ...
    </class>

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

    <class name="ForumMessage" trait="ForumMessageTrait">
        ...
    </class>

If the trait is in another namespace, provide the fully qualified name:

    <class name="ForumMessage" trait="Babble\Model\ForumMessageTrait">
        ...
    </class>

This lets you keep custom behavior outside generated files while still having it available directly on generated PHersist objects.

---

## Datasets: `<dataset>`

Properties are grouped into datasets. A dataset is loaded in one query.

    <dataset autoload="true">
        <property name="title" required="true"/>
        <property name="summary"/>
    </dataset>

    <dataset>
        <property name="messageContent" required="true"/>
    </dataset>

### Attributes

| Attribute | Required | Default | Description |
|---|---|---|---|
| `autoload` | no | `false` | Load this dataset automatically in bulk fetches. |
| `table` | no | class base table | Optional table override for dataset-backed properties. |

### Why split datasets?

Put high-frequency fields in `autoload="true"` datasets, and large or rarely-used fields in separate datasets.  
When one property in a non-autoload dataset is accessed, the full dataset is restored together.

---

## Properties: `<property>`

Properties define class fields and column mapping.

    <property name="email" required="true"/>
    <property name="viewCount" type="Int"/>
    <property name="author" type="Class" class="User" fieldname="author_id"/>

### Attributes

| Attribute | Required | Default | Description |
|---|---|---|---|
| `name` | yes | — | Property name used in PHP (`$object->name`). |
| `type` | no | `Text` | Property type (`Text`, `Int`, `Class`, `DynamicClass`, `TimestampText`). |
| `required` | no | `false` | If `true`, must not be null. |
| `fieldname` | no | auto | Custom single-column field name. |
| `fieldnames` | no | auto | Custom comma-separated multi-column names (used by multi-field types). |

> `fieldnames` is optional for `DynamicClass`.  
> If omitted, PHersist generates two field names automatically in the form `propname_class,propname_id` (translated with the configured table style).

---

## Property types

### `Text`
Default string-like field.

    <property name="description" type="Text"/>

### `Int`
Integer field (schema can be signed/unsigned).

    <property name="price" type="Int"/>
    <property name="score" type="Int" signed="false"/>

### `Class`
Reference to another class in the model.

    <property name="forum" type="Class" class="Forum" required="true"/>

Extra attribute:

| Attribute | Required | Description |
|---|---|---|
| `class` | yes | Name of target class in this model XML. |

### `DynamicClass`
Polymorphic reference: class + id pair.

    <property name="target" type="DynamicClass"/>

Optional override:

    <property name="target" type="DynamicClass" fieldnames="target_class,target_id"/>

Extra attribute:

| Attribute | Required | Description |
|---|---|---|
| `fieldnames` | no | Optional two-field override (class-name column, id column). If omitted, PHersist auto-generates `propname_class,propname_id` using the configured table style. |

### `TimestampText`
Datetime field with optional auto-updating.

    <property name="createdAt" type="TimestampText" update_on="create"/>
    <property name="modifiedAt" type="TimestampText" update_on="modify"/>

Extra attributes:

| Attribute | Required | Description |
|---|---|---|
| `update_on` | no | `create` or `modify`. |
| `date_format` | no | PHP `date()` format string. |

---

## Relations: `<relation>`

PHersist currently uses `type="NN"` for both one-to-many and many-to-many patterns.

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
| `cascade_delete` | no | Delete related objects when owner is deleted. |
| `local_type` | no | Type column for polymorphic filtering with dynamic local references. |

### Common patterns

- **1-N derived relation**: `table_owner="false"` against related class table.
- **N-N relation with join table**: `table_owner="true"` and dedicated join table.
- **Polymorphic reverse relation**: add `local_type` when class discriminator is needed.

---

## Maps: `<map>`

Maps provide key/value data attached to an object through a table.

    <map name="settings" table="user_settings" id="user_id" type="object_type">
        <key name="key_name"/>
        <value name="value_text"/>
    </map>

### `<map>` attributes

| Attribute | Required | Default | Description |
|---|---|---|---|
| `name` | yes | — | Map property name on object. |
| `table` | yes | — | Backing table. |
| `id` | no | auto | Owner ID column name. |
| `type` | no | none | Optional class discriminator column for shared map tables. |

### `<key>` and `<value>`

- One or more `<key>` elements define key hierarchy.
- One or more `<value>` elements define stored payload columns.

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

    # Generate PHP classes
    vendor/bin/phersist --xml=model/model.xml

    # Generate classes + MySQL schema
    vendor/bin/phersist --xml=model/model.xml --mysql=model/schema.sql

    # Generate only MySQL schema
    vendor/bin/phersist --xml=model/model.xml --mysql=model/schema.sql --skip-classes

    # Include custom class snippets
    vendor/bin/phersist --xml=model/model.xml --includesdir=model/includes

---

## Related guides

- [Getting started with PHersist](getting-started.md)
- [Using the model in PHP](using-model-in-php.md)
- [Advanced features](advanced-features.md)
- [sample-model.xml](../examples/basic/sample-model.xml)