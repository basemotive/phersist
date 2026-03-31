# Sample model overview

This page is a quick guide to the included sample model for PHersist.
The sample model files are located in `examples/basic/`.

## Files

- **Model definition (XML):** [sample-model.xml](../examples/basic/sample-model.xml)  
  Defines classes, datasets, properties, relations, and maps.

- **Generated schema (SQL):** [sample-model.sql](../examples/basic/sample-model.sql)  
  Shows the corresponding SQL tables and fields generated from the XML model.

- **Sample trait extension:** [ForumMessageTrait.php](../examples/basic/ForumMessageTrait.php)  
  Demonstrates how generated PHersist classes can be extended with custom behavior through traits.

## What the sample demonstrates

The sample model includes practical patterns such as:

- multiple classes (`Forum`, `ForumMessage`, `Tag`, `User`)
- autoload and non-autoload datasets
- `Class` references between models
- one-to-many and many-to-many relations
- map properties for key/value-style data
- trait-based extension of generated objects (for example `ForumMessageTrait`)

PHersist can automatically include a trait during generation when a matching trait exists (for example `ForumMessageTrait` for `ForumMessage`) in the model namespace/autoload path. You can also explicitly set a trait on a class in the model XML using the `trait` attribute.

## Suggested reading order

1. Review the XML model in [sample-model.xml](../examples/basic/sample-model.xml)
2. Compare the generated SQL in [sample-model.sql](../examples/basic/sample-model.sql)
3. Inspect the trait example in [ForumMessageTrait.php](../examples/basic/ForumMessageTrait.php)
4. Read:
   - [Creating a model from an XML file](creating-model-from-xml.md)
   - [Using the model in PHP](using-model-in-php.md)
   - [Advanced features](advanced-features.md)

This gives you a complete path from model design to runtime usage.