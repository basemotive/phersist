<?php

/**
 * Invalid model XMLs must fail with a readable error, the same for every
 * generator (classes and every schema dialect).
 *
 * Each case is: the model XML, the exact expected error, and optionally other
 * XML files it <depends> on (relative path => XML). The files are written to a
 * fresh directory; '<dir>' in the expected error stands for that directory.
 */

use Tests\Support\Generate;

it('rejects the model with a readable error', function (string $xml, string $expected, array $files = []) {
	$dir = tempModelDir();
	file_put_contents("{$dir}/model.xml", $xml);
	foreach ($files as $path => $content) {
		if (!is_dir(dirname("{$dir}/{$path}")))
			mkdir(dirname("{$dir}/{$path}"), 0777, true);
		file_put_contents("{$dir}/{$path}", $content);
	}

	$error = null;
	try {
		Generate::all("{$dir}/model.xml");
	} catch (Exception $e) {
		$error = str_replace($dir, '<dir>', $e->getMessage());
	}
	expect($error)->toBe($expected);
})->with([
	'bad-converted-name' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<class name="Item">
				<dataset>
					<property name="ThisIsAVeryLongPropertyNameThatWillConvertToAColumnNameOfMoreThan64Chars"/>
				</dataset>
			</class>
		</project>
		XML,
		<<<'ERROR'
		'ThisIsAVeryLongPropertyNameThatWillConvertToAColumnNameOfMoreThan64Chars' converts to an invalid column name: 'this_is_a_very_long_property_name_that_will_convert_to_a_column_name_of_more_than64_chars' is longer than 64 characters, the maximum for MySQL names. Rename it, or set the column name explicitly
		ERROR,
	],
	'bad-dataset-name' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<class name="Item">
				<dataset name="1st">
					<property name="a"/>
				</dataset>
			</class>
		</project>
		XML,
		<<<'ERROR'
		Dataset name '1st' of class Item is invalid, it must start with a letter and contain only letters, digits and underscores
		ERROR,
	],
	'bad-decimal-default' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<class name="Item">
				<dataset>
					<property name="price" type="Decimal" precision="4" scale="2" default="123.45"/>
				</dataset>
			</class>
		</project>
		XML,
		<<<'ERROR'
		Invalid default for property 'price': '123.45' is out of range for DECIMAL(4,2)
		ERROR,
	],
	'bad-decimal' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<class name="Item">
				<dataset>
					<property name="price" type="Decimal" precision="3" scale="5"/>
				</dataset>
			</class>
		</project>
		XML,
		<<<'ERROR'
		Invalid Decimal property 'price': scale must be between 0 and 30 and not exceed the precision, got '5'
		ERROR,
	],
	'bad-fieldnames' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<class name="Item">
				<dataset>
					<property name="subject" type="DynamicClass" fieldnames="subject_type"/>
				</dataset>
			</class>
		</project>
		XML,
		<<<'ERROR'
		Property 'subject' of type DynamicClass needs 2 field names, got 1
		ERROR,
	],
	'bad-int-default' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<class name="Item">
				<dataset>
					<property name="count" type="Int" signed="false" default="-1"/>
				</dataset>
			</class>
		</project>
		XML,
		<<<'ERROR'
		Invalid default for property 'count': -1 is out of range for an unsigned INT column
		ERROR,
	],
	'bad-mysql-charset' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<schema dialect="mysql" charset="utf8-mb4"/>
			<class name="Item"/>
		</project>
		XML,
		<<<'ERROR'
		Invalid model XML:
		- line 2: <schema dialect="mysql"> has an invalid 'charset' attribute: 'utf8-mb4' may only contain letters, digits and underscores
		ERROR,
	],
	'bad-on-remote-delete' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<class name="Item">
				<dataset>
					<property name="owner" type="Class" class="Item" on_remote_delete="nullify"/>
				</dataset>
			</class>
		</project>
		XML,
		<<<'ERROR'
		Invalid on_remote_delete 'nullify' for property 'owner', use 'null', 'restrict' or 'cascade'
		ERROR,
	],
	'bad-update-on' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<class name="Item">
				<dataset>
					<property name="createdAt" type="DateTime" update_on="sometimes"/>
				</dataset>
			</class>
		</project>
		XML,
		<<<'ERROR'
		Invalid update_on 'sometimes' for property 'createdAt', use 'create' or 'modify'
		ERROR,
	],
	'depends-broken' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase" namespace="Blog">
			<depends xml="deps/broken.xml"/>
		</project>
		XML,
		<<<'ERROR'
		In model XML '<dir>/deps/broken.xml', from <depends> on line 2 of '<dir>/model.xml': Property 'a' of class Item has an unknown type 'Integer'
		ERROR,
		[
			'deps/broken.xml' => <<<'XML'
			<project database="main" tablestyle="SnakeCase" namespace="Broken">
				<class name="Item">
					<dataset>
						<property name="a" type="Integer"/>
					</dataset>
				</class>
			</project>
			XML,
		],
	],
	'depends-cycle' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase" namespace="Blog">
			<depends xml="deps/cycle.xml"/>
		</project>
		XML,
		<<<'ERROR'
		In model XML '<dir>/deps/cycle.xml', from <depends> on line 2 of '<dir>/model.xml': Model XMLs can't depend on themselves, directly or through others: <dir>/model.xml depends on <dir>/deps/cycle.xml depends on <dir>/model.xml
		ERROR,
		[
			'deps/cycle.xml' => <<<'XML'
			<project database="main" tablestyle="SnakeCase" namespace="Cycle">
				<depends xml="../model.xml"/>
			</project>
			XML,
		],
	],
	'depends-missing' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase" namespace="Blog">
			<depends xml="deps/nothing.xml"/>
		</project>
		XML,
		<<<'ERROR'
		Cannot find model XML '<dir>/deps/nothing.xml', from <depends> on line 2 of '<dir>/model.xml'
		ERROR,
	],
	'depends-other-database' => [
		<<<'XML'
		<project database="blog" tablestyle="SnakeCase" namespace="Blog">
			<depends xml="deps/core.xml"/>
			<class name="Post">
				<dataset>
					<property name="author" type="Class" class="Core\User"/>
				</dataset>
			</class>
		</project>
		XML,
		<<<'ERROR'
		Property 'author' of class 'Post' refers to class 'Core\User' of model XML '<dir>/deps/core.xml', which uses database 'main' instead of 'blog'. References between model XMLs need the same database (connection id), because a delete cleans them up in one transaction
		ERROR,
		[
			'deps/core.xml' => <<<'XML'
			<project database="main" tablestyle="SnakeCase" namespace="Core">
				<class name="User">
					<dataset>
						<property name="name"/>
					</dataset>
				</class>
			</project>
			XML,
		],
	],
	'depends-same-namespace' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase" namespace="Core">
			<depends xml="deps/core.xml"/>
			<class name="Post"/>
		</project>
		XML,
		<<<'ERROR'
		Model XMLs '<dir>/deps/core.xml' and '<dir>/model.xml' have the same namespace 'Core'. Give each model XML its own namespace: the references from its classes are generated into a class in that namespace
		ERROR,
		[
			'deps/core.xml' => <<<'XML'
			<project database="main" tablestyle="SnakeCase" namespace="Core">
				<class name="User">
					<dataset>
						<property name="name"/>
					</dataset>
				</class>
			</project>
			XML,
		],
	],
	'depends-table-clash' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase" namespace="Blog">
			<depends xml="deps/core.xml"/>
			<class name="User"/>
		</project>
		XML,
		<<<'ERROR'
		Invalid model XML:
		- line 3: <class> 'User' uses table 'users', which is already a table of <class> 'User' on line 2 of '<dir>/deps/core.xml': give each class its own tables
		ERROR,
		[
			'deps/core.xml' => <<<'XML'
			<project database="main" tablestyle="SnakeCase" namespace="Core">
				<class name="User">
					<dataset>
						<property name="name"/>
					</dataset>
				</class>
			</project>
			XML,
		],
	],
	'derived-dynamic-namespace' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<class name="Item">
				<relation name="comments" type="NN" class="Comment" table="comments" local_id="subject_id" local_type="subject_type" use_namespace="true" remote_id="id"/>
			</class>
			<class name="Comment">
				<dataset>
					<property name="subject" type="DynamicClass"/>
				</dataset>
			</class>
		</project>
		XML,
		<<<'ERROR'
		Relation 'comments' of class 'Item' must have the same use_namespace value as property 'subject' of class 'Comment', so its local_type matches the stored class names
		ERROR,
	],
	'derived-dynamic-no-local-type' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<class name="Item">
				<relation name="comments" type="NN" class="Comment" table="comments" local_id="subject_id" remote_id="id"/>
			</class>
			<class name="Comment">
				<dataset>
					<property name="subject" type="DynamicClass"/>
				</dataset>
			</class>
		</project>
		XML,
		<<<'ERROR'
		Relation 'comments' of class 'Item' uses column 'subject_id' of table 'comments' as local_id, which belongs to property 'subject' of class 'Comment', a DynamicClass that can refer to any class. Set local_type="subject_type", its class-name column, so the relation only lists the objects that refer to a Item
		ERROR,
	],
	'derived-dynamic-remote' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<class name="Owner">
				<relation name="subjects" type="NN" class="Item" table="comments" local_id="owner_id" remote_id="subject_id"/>
			</class>
			<class name="Item"/>
			<class name="Comment">
				<dataset>
					<property name="owner" type="Class" class="Owner"/>
					<property name="subject" type="DynamicClass"/>
				</dataset>
			</class>
		</project>
		XML,
		<<<'ERROR'
		Relation 'subjects' of class 'Owner' uses column 'subject_id' of table 'comments' as remote_id, which belongs to property 'subject' of class 'Comment', a DynamicClass that can refer to any class. A relation can't select its related objects by class name: use a column that refers to class 'Item'
		ERROR,
	],
	'derived-dynamic-type-column' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<class name="Item">
				<relation name="comments" type="NN" class="Comment" table="comments" local_id="subject_type" remote_id="id"/>
			</class>
			<class name="Comment">
				<dataset>
					<property name="subject" type="DynamicClass"/>
				</dataset>
			</class>
		</project>
		XML,
		<<<'ERROR'
		Relation 'comments' of class 'Item' uses column 'subject_type' of table 'comments' as local_id, which belongs to property 'subject' of class 'Comment', as its class-name column. Use its id column 'subject_id' as local_id, and its class-name column 'subject_type' as local_type
		ERROR,
	],
	'derived-local-id-is-id' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<class name="Item">
				<dataset>
					<property name="parent" type="Class" class="Item"/>
				</dataset>
				<relation name="children" type="NN" class="Item" table="items" local_id="id" remote_id="parent_id"/>
			</class>
		</project>
		XML,
		<<<'ERROR'
		Relation 'children' of class 'Item' uses column 'id' of table 'items' as local_id, which is the id column of class 'Item'. Its rows would be the object itself, so it holds at most one related object: use a Class property instead
		ERROR,
	],
	'derived-missing-column' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<class name="Owner">
				<relation name="items" type="NN" class="Item" table="items" local_id="owner_ref" remote_id="id"/>
			</class>
			<class name="Item"/>
		</project>
		XML,
		<<<'ERROR'
		Relation 'items' of class 'Owner' uses column 'owner_ref' as local_id, but table 'items' of class 'Item' doesn't have it: use its id column 'id' or the column of a property in that table
		ERROR,
	],
	'derived-not-a-reference' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<class name="Owner">
				<relation name="items" type="NN" class="Item" table="items" local_id="title" remote_id="id"/>
			</class>
			<class name="Item">
				<dataset>
					<property name="title"/>
				</dataset>
			</class>
		</project>
		XML,
		<<<'ERROR'
		Relation 'items' of class 'Owner' uses column 'title' of table 'items' as local_id, which belongs to property 'title' of class 'Item', of type Text, which doesn't refer to an object: use a column that refers to class 'Owner'
		ERROR,
	],
	'derived-remote-id-is-other-id' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<class name="Owner">
				<relation name="tags" type="NN" class="Tag" table="items" local_id="owner_id" remote_id="id"/>
			</class>
			<class name="Item">
				<dataset>
					<property name="owner" type="Class" class="Owner"/>
				</dataset>
			</class>
			<class name="Tag"/>
		</project>
		XML,
		<<<'ERROR'
		Relation 'tags' of class 'Owner' uses column 'id' of table 'items' as remote_id, which is the id column of class 'Item': use a column that refers to class 'Tag'
		ERROR,
	],
	'derived-softdelete-column' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<class name="Owner">
				<relation name="items" type="NN" class="Item" table="items" local_id="deleted" remote_id="id"/>
			</class>
			<class name="Item" softdelete="true"/>
		</project>
		XML,
		<<<'ERROR'
		Relation 'items' of class 'Owner' uses column 'deleted' of table 'items' as local_id, which is the softdelete column of class 'Item': use a column that refers to class 'Owner'
		ERROR,
	],
	'derived-wrong-class' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<class name="Owner">
				<relation name="items" type="NN" class="Item" table="items" local_id="tag_id" remote_id="id"/>
			</class>
			<class name="Item">
				<dataset>
					<property name="tag" type="Class" class="Tag"/>
				</dataset>
			</class>
			<class name="Tag"/>
		</project>
		XML,
		<<<'ERROR'
		Relation 'items' of class 'Owner' uses column 'tag_id' of table 'items' as local_id, which belongs to property 'tag' of class 'Item', a reference to class 'Tag'. The relation would use the Tag with the same ID: use a column that refers to class 'Owner'
		ERROR,
	],
	'duplicate-dataset-name' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<class name="Item">
				<dataset name="main">
					<property name="a"/>
				</dataset>
				<dataset name="main" table="item_extras">
					<property name="b"/>
				</dataset>
			</class>
		</project>
		XML,
		<<<'ERROR'
		Class Item has more than one dataset named 'main'
		ERROR,
	],
	'index-columns' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<class name="Item">
				<dataset>
					<property name="title"/>
					<property name="count" type="Int"/>
					<property name="owner" type="Class" class="Item"/>
					<property name="stamp" type="TimestampText"/>
				</dataset>
				<index name="a">
					<field property="titel" length="10"/>
					<field property="count" length="10"/>
					<field property="owner" length="10"/>
					<field property="stamp" length="10"/>
					<field fieldname="id" length="10"/>
				</index>
				<index name="b">
					<field property="title"/>
					<field property="count"/>
					<field/>
					<field property="count" fieldname="count"/>
					<field fieldname="count"/>
					<field fieldname="deleted"/>
					<field property="title" length="0"/>
				</index>
				<index name="c"/>
			</class>
		</project>
		XML,
		<<<'ERROR'
		Invalid model XML:
		- line 10: <field property="titel"> of <index> 'a' of class 'Item' refers to an unknown property, did you mean 'title'?
		- line 11: <field property="count"> of <index> 'a' of class 'Item' can't have a length: only text columns can have a prefix length, and property 'count' has type Int
		- line 12: <field property="owner"> of <index> 'a' of class 'Item' can't have a length: only text columns can have a prefix length, and property 'owner' has type Class
		- line 13: <field property="stamp"> of <index> 'a' of class 'Item' can't have a length: only text columns can have a prefix length, and property 'stamp' has type TimestampText with the default date format, stored as a date and time
		- line 14: <field fieldname="id"> of <index> 'a' of class 'Item' can't have a length: only text columns can have a prefix length, and 'id' is an integer column
		- line 17: <field property="title"> of <index> 'b' of class 'Item' needs a length attribute: property 'title' is stored as text, which MySQL only indexes with a prefix length, the number of characters to index
		- line 19: <field> of <index> 'b' of class 'Item' needs either a property or a fieldname attribute
		- line 20: <field> of <index> 'b' of class 'Item' needs either a property or a fieldname attribute
		- line 21: <field fieldname="count"> of <index> 'b' of class 'Item' is a column of property 'count': use property="count" instead
		- line 22: <field fieldname="deleted"> of <index> 'b' of class 'Item' isn't a column of the class: fieldname is for the id column 'id', use property for the columns of properties
		- line 23: <field property="title"> of <index> 'b' of class 'Item' has an invalid length '0': expected a positive integer, the number of characters to index
		- line 23: <field property="title"> of <index> 'b' of class 'Item' uses column 'title', which is already in the index
		- line 25: <index> 'c' of class 'Item' has no <field>, it needs at least one
		ERROR,
	],
	'index-duplicate-column' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<class name="Item">
				<dataset>
					<property name="target" type="DynamicClass" fieldnames="target_type,target_id"/>
				</dataset>
				<index name="target">
					<field property="target"/>
					<field fieldname="ID"/>
					<field fieldname="id"/>
				</index>
			</class>
		</project>
		XML,
		<<<'ERROR'
		Invalid model XML:
		- line 9: <field fieldname="id"> of <index> 'target' of class 'Item' uses column 'id', which is already in the index
		ERROR,
	],
	'index-names' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<class name="Item">
				<dataset>
					<property name="owner" type="Class" class="Item"/>
					<property name="count" type="Int"/>
				</dataset>
				<dataset table="item_extras">
					<property name="size" type="Int"/>
				</dataset>
				<index name="Primary"><field property="count"/></index>
				<index name="count"><field property="count"/></index>
				<index name="COUNT"><field property="owner"/></index>
				<index name="IDX_OWNER"><field property="count"/></index>
				<!-- Another table: fine -->
				<index name="count"><field property="size"/></index>
			</class>
		</project>
		XML,
		<<<'ERROR'
		Invalid model XML:
		- line 10: <index> 'Primary' of class 'Item' can't be named 'Primary', which MySQL reserves for the primary key
		- line 12: <index> 'COUNT' of class 'Item' has the same name as the <index> on line 11, in table 'items'
		- line 13: <index> 'IDX_OWNER' of class 'Item' has the name of the automatic index on property 'owner', in table 'items': choose another name
		ERROR,
	],
	'index-tables' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<class name="Item" softdelete="true">
				<dataset>
					<property name="count" type="Int"/>
				</dataset>
				<dataset table="item_extras">
					<property name="size" type="Int"/>
				</dataset>
				<index name="a"><field property="count"/><field property="size"/></index>
				<index name="b"><field property="size"/><field fieldname="id"/><field fieldname="deleted"/></index>
			</class>
		</project>
		XML,
		<<<'ERROR'
		Invalid model XML:
		- line 9: <index> 'a' of class 'Item' has columns in different tables ('items', 'item_extras'): an index is on one table, so use the properties of datasets in the same table
		- line 10: <index> 'b' of class 'Item' has columns in different tables ('item_extras', 'items'): an index is on one table, so use the properties of datasets in the same table
		ERROR,
	],
	'index-unknown-attribute' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<class name="Item">
				<dataset>
					<property name="count" type="Int"/>
				</dataset>
				<index name="count" unique="yes" order="desc">
					<field property="count" lenght="10"/>
					<field property=""/>
				</index>
				<index><field property="count"/></index>
				<dataset/>
			</class>
		</project>
		XML,
		<<<'ERROR'
		Invalid model XML:
		- line 6: <index> 'count' of class 'Item' has unknown attribute 'order'
		- line 6: <index> 'count' of class 'Item' has an invalid 'unique' attribute: expected 'true' or 'false', got 'yes'
		- line 7: <field> of class 'Item' has unknown attribute 'lenght', did you mean 'length'?
		- line 8: <field> of class 'Item' has an empty 'property' attribute, leave it out instead
		- line 10: <index> of class 'Item' is missing the required attribute 'name'
		ERROR,
	],
	'join-missing-column' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<class name="Item">
				<relation name="tags" type="NN" class="Tag" table="item_tags" local_id="item_id" remote_id="tag_id" table_owner="true"/>
			</class>
			<class name="Tag">
				<relation name="items" type="NN" class="Item" table="item_tags" local_id="tag_id" remote_id="item_id" order_field="position"/>
			</class>
		</project>
		XML,
		<<<'ERROR'
		Relation 'items' of class 'Tag' uses column 'position' as order_field, which table 'item_tags' doesn't have: relation 'tags' of class 'Item' owns the table with columns local_id 'item_id', remote_id 'tag_id'
		ERROR,
	],
	'join-no-local-type' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<class name="Item">
				<relation name="tags" type="NN" class="Tag" table="taggings" local_id="object_id" remote_id="tag_id" local_type="object_type" table_owner="true"/>
			</class>
			<class name="Photo">
				<relation name="tags" type="NN" class="Tag" table="taggings" local_id="object_id" remote_id="tag_id" table_owner="true"/>
			</class>
			<class name="Tag"/>
		</project>
		XML,
		<<<'ERROR'
		Relation 'tags' of class 'Photo' uses table 'taggings' with local_id 'object_id', like relation 'tags' of class 'Item', so both need a local_type attribute: the local_type column stores the class name that tells their rows apart
		ERROR,
	],
	'join-other-local-type' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<class name="Item">
				<relation name="tags" type="NN" class="Tag" table="taggings" local_id="object_id" remote_id="tag_id" local_type="object_type" table_owner="true"/>
			</class>
			<class name="Photo">
				<relation name="tags" type="NN" class="Tag" table="taggings" local_id="object_id" remote_id="tag_id" local_type="kind" table_owner="true"/>
			</class>
			<class name="Tag"/>
		</project>
		XML,
		<<<'ERROR'
		Relation 'tags' of class 'Photo' uses table 'taggings' with local_id 'object_id', like relation 'tags' of class 'Item', so it must have the same local_type column
		ERROR,
	],
	'join-owner-columns' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<class name="Item">
				<relation name="tags" type="NN" class="Tag" table="item_tags" local_id="item_id" remote_id="tag_id" table_owner="true" order_field="position"/>
			</class>
			<class name="Tag">
				<relation name="items" type="NN" class="Item" table="item_tags" local_id="tag_id" remote_id="item_id" table_owner="true"/>
			</class>
		</project>
		XML,
		<<<'ERROR'
		Relation 'items' of class 'Tag' owns table 'item_tags' with columns local_id 'tag_id', remote_id 'item_id', but relation 'tags' of class 'Item' owns it with columns local_id 'item_id', remote_id 'tag_id', order_field 'position'. All columns of a join table are required, and each owner inserts rows with only its own columns: give them the same local_type and order_field, and the same local_id and remote_id (swapped is fine)
		ERROR,
	],
	'join-owner-order-swapped' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<class name="Item">
				<relation name="tags" type="NN" class="Tag" table="item_tags" local_id="item_id" remote_id="tag_id" table_owner="true" order_field="position"/>
			</class>
			<class name="Tag">
				<relation name="items" type="NN" class="Item" table="item_tags" local_id="tag_id" remote_id="item_id" table_owner="true" order_field="position"/>
			</class>
		</project>
		XML,
		<<<'ERROR'
		Relation 'items' of class 'Tag' owns table 'item_tags' in the other direction than relation 'tags' of class 'Item', but the table has order_field 'position'. Its positions are per local_id, so each owner would overwrite the other's positions on commit: let one of them own the table, and give the other table_owner="false"
		ERROR,
	],
	'join-same-relation' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<class name="Item">
				<relation name="tags" type="NN" class="Tag" table="item_tags" local_id="item_id" remote_id="tag_id" table_owner="true"/>
				<relation name="labels" type="NN" class="Tag" table="item_tags" local_id="item_id" remote_id="tag_id"/>
			</class>
			<class name="Tag"/>
		</project>
		XML,
		<<<'ERROR'
		Relation 'labels' of class 'Item' is the same as relation 'tags' of class 'Item': both use table 'item_tags' with local_id 'item_id' and remote_id 'tag_id'. Nothing in the table tells their rows apart, so they would hold the same objects: give each relation its own table
		ERROR,
	],
	'join-two-owners' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<class name="Item">
				<relation name="tags" type="NN" class="Tag" table="item_links" local_id="item_id" remote_id="tag_id" table_owner="true"/>
				<relation name="labels" type="NN" class="Tag" table="item_links" local_id="item_id" remote_id="label_id" table_owner="true"/>
			</class>
			<class name="Tag"/>
		</project>
		XML,
		<<<'ERROR'
		Relation 'labels' of class 'Item' cannot own table 'item_links' with local_id 'item_id', because relation 'tags' of class 'Item' already does. Each would delete the other's rows on commit: give each relation its own table
		ERROR,
	],
	'map-class-table' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<class name="Item">
				<map name="settings" table="items">
					<key name="k"/>
					<value name="v"/>
				</map>
			</class>
		</project>
		XML,
		<<<'ERROR'
		Map 'settings' of class 'Item' cannot use table 'items', because it is a table of class 'Item'. A map replaces all rows of its owner on commit, so it needs a table of its own
		ERROR,
	],
	'map-no-key' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<class name="Item">
				<map name="settings" table="item_settings">
					<value name="v"/>
				</map>
			</class>
		</project>
		XML,
		<<<'ERROR'
		Map settings of class Item must have at least one <key>
		ERROR,
	],
	'map-relation-table' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<class name="Item">
				<relation name="tags" type="NN" class="Item" table="item_tags" local_id="item_id" remote_id="tag_id" table_owner="true"/>
				<map name="settings" table="item_tags">
					<key name="k"/>
					<value name="v"/>
				</map>
			</class>
		</project>
		XML,
		<<<'ERROR'
		Map 'settings' of class 'Item' cannot use table 'item_tags', because it is the table of relation 'tags' of class 'Item'. A map replaces all rows of its owner on commit, so it needs a table of its own
		ERROR,
	],
	'map-same-class' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<class name="Item">
				<map name="settings" table="item_settings">
					<key name="k"/>
					<value name="v"/>
				</map>
				<map name="options" table="item_settings">
					<key name="k"/>
					<value name="v"/>
				</map>
			</class>
		</project>
		XML,
		<<<'ERROR'
		Map 'options' of class 'Item' cannot use table 'item_settings', because map 'settings' of class 'Item' already does. Maps of the same class would load and delete each other's rows, so each needs a table of its own
		ERROR,
	],
	'map-shared-columns' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<class name="Item">
				<map name="settings" table="settings" type="owner_type">
					<key name="k"/>
					<value name="v"/>
				</map>
			</class>
			<class name="Photo">
				<map name="settings" table="settings" type="owner_type">
					<key name="k"/>
					<value name="v"/>
				</map>
			</class>
		</project>
		XML,
		<<<'ERROR'
		Map 'settings' of class 'Photo' shares table 'settings' with map 'settings' of class 'Item', so it must have the same id, type, <key> and <value> column names
		ERROR,
	],
	'map-shared-no-type' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<class name="Item">
				<map name="settings" table="settings" id="owner_id" type="owner_type">
					<key name="k"/>
					<value name="v"/>
				</map>
			</class>
			<class name="Photo">
				<map name="settings" table="settings" id="owner_id">
					<key name="k"/>
					<value name="v"/>
				</map>
			</class>
		</project>
		XML,
		<<<'ERROR'
		Map 'settings' of class 'Photo' shares table 'settings' with map 'settings' of class 'Item', so both need a type attribute: the type column stores the class name that tells their rows apart
		ERROR,
	],
	'map-two-values' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<class name="Item">
				<map name="settings" table="item_settings">
					<key name="k"/>
					<value name="v"/>
					<value name="w"/>
				</map>
			</class>
		</project>
		XML,
		<<<'ERROR'
		Map settings of class Item must have exactly one <value>, found 2
		ERROR,
	],
	'misspelled-attribute' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<class name="Item">
				<dataset>
					<property name="a" requried="true"/>
				</dataset>
			</class>
		</project>
		XML,
		<<<'ERROR'
		Invalid model XML:
		- line 4: <property> 'a' of class 'Item' has unknown attribute 'requried', did you mean 'required'?
		ERROR,
	],
	'owned-class-table' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<class name="Item">
				<dataset>
					<property name="parent" type="Class" class="Item"/>
				</dataset>
				<relation name="children" type="NN" class="Item" table="items" local_id="parent_id" remote_id="id" table_owner="true"/>
			</class>
		</project>
		XML,
		<<<'ERROR'
		Relation 'children' of class 'Item' cannot have table_owner="true", because its table 'items' is a table of class 'Item'. A relation on a class's table is derived and must be read-only: use table_owner="false", and change the property of the related objects instead
		ERROR,
	],
	'relation-own-table' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<class name="Item">
				<dataset>
					<property name="tag" type="Class" class="Tag"/>
				</dataset>
				<relation name="tags" type="NN" class="Tag" table="items" local_id="id" remote_id="tag_id"/>
			</class>
			<class name="Tag"/>
		</project>
		XML,
		<<<'ERROR'
		Relation 'tags' of class 'Item' cannot use its own class's table 'items'. Its rows would be the object itself, so it holds at most one related object: use a Class property instead
		ERROR,
	],
	'relation-same-column' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<class name="Item">
				<relation name="links" type="NN" class="Item" table="links" local_id="item_id" remote_id="ITEM_ID" table_owner="true"/>
			</class>
		</project>
		XML,
		<<<'ERROR'
		Relation 'links' of class 'Item' uses column 'ITEM_ID' as both local_id and remote_id: each needs a column of its own
		ERROR,
	],
	'required-null-on-remote-delete' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<class name="Item">
				<dataset>
					<property name="owner" type="Class" class="Item" required="true" on_remote_delete="null"/>
				</dataset>
			</class>
		</project>
		XML,
		<<<'ERROR'
		Property 'owner' can't have on_remote_delete="null", because it is required
		ERROR,
	],
	'reserved-class-name' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase" namespace="Blog">
			<class name="PHersistReferences"/>
		</project>
		XML,
		<<<'ERROR'
		Invalid model XML:
		- line 2: <class> 'PHersistReferences' has an invalid 'name' attribute: 'PHersistReferences' clashes with the class that the generator writes next to the classes, with the references between them
		ERROR,
	],
	'schema-twice' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<schema dialect="mysql" charset="utf8mb4"/>
			<schema dialect="mysql" collate="utf8mb4_bin" engine="MyISAM"/>
			<class name="Item"/>
		</project>
		XML,
		<<<'ERROR'
		Invalid model XML:
		- line 3: there's already a <schema dialect="mysql"> on line 2, use one per dialect
		- line 3: <schema dialect="mysql"> has unknown attribute 'engine'
		ERROR,
	],
	'schema-unknown-dialect' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<schema dialect="mysq" charset="utf8mb4"/>
			<schema dialect="oracle"/>
			<schema charset="utf8mb4"/>
			<class name="Item"/>
		</project>
		XML,
		<<<'ERROR'
		Invalid model XML:
		- line 2: <schema dialect="mysq"> has an unknown dialect 'mysq', did you mean 'mysql'?
		- line 3: <schema dialect="oracle"> has an unknown dialect 'oracle', use 'mysql' or 'mariadb' or 'sqlite'
		- line 4: <schema> is missing the required attribute 'dialect'
		ERROR,
	],
	'unique-names' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase" id_style="long">
			<class name="Item" softdelete="true">
				<dataset>
					<property name="id"/>
					<property name="item_id"/>
					<property name="title"/>
					<property name="label" fieldname="Title"/>
					<property name="gone" fieldname="deleted"/>
				</dataset>
				<map name="title" table="item_titles">
					<key name="k"/>
					<value name="v"/>
				</map>
			</class>
			<class name="ITEM"/>
			<class name="Other" table="items"/>
			<class name="Third">
				<dataset table="items">
					<property name="x"/>
				</dataset>
			</class>
			<class name="Clash">
				<dataset>
					<property name="clashId" fieldname="clash_id"/>
				</dataset>
			</class>
		</project>
		XML,
		<<<'ERROR'
		Invalid model XML:
		- line 4: <property> 'id' of class 'Item' can't be named 'id', it is reserved for the object's id
		- line 5: <property> 'item_id' of class 'Item' can't have the name of the class's id column 'item_id', the object stores its id under that name
		- line 10: <map> 'title' of class 'Item' has the same name as <property> 'title' of class 'Item' on line 6
		- line 5: <property> 'item_id' of class 'Item' uses column 'item_id' in table 'items', which is already the id column of <class> 'Item': set another column name with fieldname or fieldnames
		- line 7: <property> 'label' of class 'Item' uses column 'Title' in table 'items', which is already used by <property> 'title' of class 'Item' on line 6: set another column name with fieldname or fieldnames
		- line 8: <property> 'gone' of class 'Item' uses column 'deleted' in table 'items', which is already the softdelete column of <class> 'Item': set another column name with fieldname or fieldnames
		- line 15: <class> 'ITEM' has the same name as <class> 'Item' on line 2
		- line 15: <class> 'ITEM' uses table 'items', which is already a table of <class> 'Item' on line 2: give each class its own tables
		- line 16: <class> 'Other' uses table 'items', which is already a table of <class> 'Item' on line 2: give each class its own tables
		- line 17: <class> 'Third' uses table 'items', which is already a table of <class> 'Item' on line 2: give each class its own tables
		- line 24: <property> 'clashId' of class 'Clash' uses column 'clash_id' in table 'clashes', which is already the id column of <class> 'Clash': set another column name with fieldname or fieldnames
		ERROR,
	],
	'unknown-table-style' => [
		<<<'XML'
		<project database="main" tablestyle="KebabCase">
			<class name="Item"/>
		</project>
		XML,
		<<<'ERROR'
		Cannot find table style converter class PHersist\Generator\TSKebabCase
		ERROR,
	],
	'unknown-type' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<class name="Item">
				<dataset>
					<property name="count" type="Integer"/>
				</dataset>
			</class>
		</project>
		XML,
		<<<'ERROR'
		Property 'count' of class Item has an unknown type 'Integer'
		ERROR,
	],
	'use-namespace-on-class' => [
		<<<'XML'
		<project database="main" tablestyle="SnakeCase">
			<class name="Item">
				<dataset>
					<property name="owner" type="Class" class="Item" use_namespace="true"/>
				</dataset>
			</class>
		</project>
		XML,
		<<<'ERROR'
		Invalid model XML:
		- line 4: <property> 'owner' of class 'Item' has attribute 'use_namespace', which a property of type Class can't have
		ERROR,
	],
]);
