# PHersist documentation

Welcome to the PHersist docs.

## Documentation

- [Getting started with PHersist](getting-started.md)  
  Install PHersist, configure autoloading, connect your database, and generate your first model classes.

- [Creating a model from an XML file](creating-model-from-xml.md)  
  Full reference for the model XML format (`<project>`, `<class>`, `<dataset>`, `<property>`, `<relation>`, and `<map>`), including table style and id field configuration.

- [Using the model in PHP](using-model-in-php.md)  
  Work with generated classes, create/update/delete records, use relations and maps, and query with `ObjectFinder`.

- [Advanced features](advanced-features.md)  
  Runtime and performance features such as `ObjectCache`, soft delete behavior, and trait-based class extension.

## Example model

Start with:

- [Sample model overview](sample-model.md)

Then inspect:

- [sample-model.xml](../examples/basic/sample-model.xml)
- [sample-model.sql](../examples/basic/sample-model.sql)