# PHersist

PHersist is a lightweight ORM (Object-Relational Mapper) for PHP.

This allows you to generate PHP model classes that are backed by database tables. You can create objects and work with them like normal, and them commit the changes to the database when you're done modifying them. PHersist handles the SQL for you.

## Small but powerful

PHersist is designed to be minimalistic in the sense that its codebase is kept small, and it doesn't use any external libraries. It manages that while still offering robust mapping for objects, relations and maps.

## Use an existing database schema, or create one

You can make it work with most existing database schemas, as long as your tables are normalized. If you don't have an existing schema yet, you can generate one alongside your model classes.

## Working with your model is easy

Creating new objects goes like this:
```php
$user = new User();
$user->email = 'joe@example.org';
$user->password = password_hash('secret', PASSWORD_DEFAULT);
$user->name = 'Joe Example';
$user->commit();

echo "User created: {$user->name}\n";
```
And your new `User` is added to the database. Now if you need find that user later, it goes like this:
```php
use PHersist\ObjectFinder;

$user = ObjectFinder::create(User::class)
	->where('email', '=', 'joe@example.org')
	->fetchOne();

echo "User retrieved: {$user->name}\n";
```
And you've retrieved the `User` you had previously created.

## Getting started

Read [the documentation](docs/index.md).