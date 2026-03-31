# Getting started with PHersist

This guide takes you from installation to your first working model and query in a few clear steps.

If you want the full XML reference, continue with [Creating a model from an XML file](creating-model-from-xml.md).  
For runtime patterns and advanced behavior, see [Using the model in PHP](using-model-in-php.md) and [Advanced features](advanced-features.md).

---

## 1) Install PHersist

Install PHersist with Composer:

```sh
composer require basemotive/phersist
```

---

## 2) Configure PSR-4 autoloading

PHersist generates classes into your own namespace.  
Make sure your `composer.json` contains a PSR-4 mapping, for example:

```json
{
  "autoload": {
    "psr-4": {
      "MyApp\\Model\\": "src/Model/"
    }
  }
}
```

Then refresh autoload metadata:

```sh
composer dump-autoload
```

---

## 3) Create your model XML

Create `model/model.xml`:

```xml
<project database="myapp" tablestyle="SnakeCase" namespace="MyApp\Model" id_style="short">
    <class name="User">
        <dataset autoload="true">
            <property name="email" required="true"/>
            <property name="name" required="true"/>
        </dataset>
    </class>
</project>
```

What these project attributes mean:

- `database`: connection identifier used at runtime (`myapp` in this example)
- `tablestyle`: naming conversion strategy (`SnakeCase`)
- `namespace`: namespace for generated PHP classes
- `id_style`: primary key naming mode (`short` => `id`, `long` => class-based id such as `user_id`)

---

## 4) Generate classes and optional schema

Generate model classes:

```sh
vendor/bin/phersist --xml=model/model.xml
```

Generate classes plus MySQL schema:

```sh
vendor/bin/phersist --xml=model/model.xml --mysql=model/schema.sql
```

Generate schema only:

```sh
vendor/bin/phersist --xml=model/model.xml --mysql=model/schema.sql --skip-classes
```

---

## 5) Apply the database schema

If you generated `model/schema.sql`, apply it to your target database (`myapp`) using your usual migration or SQL execution workflow.

---

## 6) Register your database connection

Before using generated objects, create and register a connection using one of the available `DBConnectionManager` factory methods.

MySQL example:

```php
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
```

For other backends, use:

- `DBConnectionManager::newSQLiteConnection(...)`
- `DBConnectionManager::newSQLSrvLConnection(...)`

---

## 7) Create and store your first object

```php
<?php

use MyApp\Model\User;

$user = new User();
$user->email = 'joe@example.org';
$user->name = 'Joe Example';
$user->commit();
```

After `commit()`, the object has a primary key in `$user->id`.

---

## 8) Query with ObjectFinder

```php
<?php

use PHersist\ObjectFinder;
use MyApp\Model\User;

$user = ObjectFinder::create(User::class)
    ->where('email', '=', 'joe@example.org')
    ->fetchOne();

if ($user !== null) {
    echo $user->name . PHP_EOL;
}
```

`ObjectFinder` supports fluent query chains (`where`, `orderBy`, `fetch`, `fetchOne`, `count`).

---

## 9) Continue with the full docs

- Full XML model reference: [Creating a model from an XML file](creating-model-from-xml.md)
- Runtime usage details: [Using the model in PHP](using-model-in-php.md)
- Advanced behavior (including `ObjectCache`): [Advanced features](advanced-features.md)
- Example model walkthrough: [Sample model overview](sample-model.md)

You have a complete baseline setup and can start expanding your model safely.