# Infrangible Import Entity

The base of imports of Magento entities, for example products, categories or customers. It extends
[Infrangible Import](https://www.infrangible.dev/modules/import) with the steps that write the elements into the
entity and attribute tables. The module imports nothing by itself; import routines extend its class `Model\Entity`.

## Steps of an import

On top of the steps of `Infrangible\Import\Model\Import`, `Model\Entity`:

1. finds the entities of the source and transformed elements by their `entity_id` or their key
   (`getSourceElementKey()`, `getTransformedElementKey()`, `findSourceDataEntityIds()`,
   `findTransformedDataEntityIds()`),
2. determines the store of every transformed element: `store_id`, else the default store of the website given as
   `website_id` or `website` (`['id' => …]` or `['name' => <code>]`), else the admin store,
3. replaces, prepares and validates the values of the elements (see below),
4. creates the missing entities (`getCreateEntityData()`; an entity created for a key is reused for later elements
   with the same key) and saves the changed attribute values, per store, in chunks of `setChunkSize()` elements, each
   in a transaction,
5. records the changed entities (`getImportedEntities()`).

Unchanged values are not written; an element without changed values is marked as unchanged. `setUpdateAdminStore()`
also saves the values of existing entities in the admin store; `addCreateDateAttributeCode()` and
`addUpdateDateAttributeCode()` set date attributes of created or changed entities.

## Values

- Attributes: values of attributes with options are replaced by the IDs of the options (separated by commas for
  multiselect attributes); unknown options can be added (`setAddUnknownAttributeOptionValues()`). Unknown attributes
  are removed (`setIgnoreUnknownAttributes()`) and reported as debug messages or errors
  (`setUnknownAttributesWarnOnly()`). Admin store values can be forced, prohibited or given as default
  (`addForceAdminEavAttributeValue()`, `addProhibitAdminEavAttributeValue()`, `addDefaultAdminEavAttributeValue()`).
- Columns of the entity table: `addSpecialAttribute()` with the type of the column. `store_id` is one by default (the
  customer table has it); routines of entities without this column, like products, call
  `addIgnoreAttribute('store_id')`.
- `Model\Replace` (`addReplaceAttributeModel()`): replaces a value with another attribute, for example a name with an
  ID.
- `Model\SpecialAttribute` (`addSpecialAttributeModel()`): saves the value of an attribute by itself.
- `Model\Associated` (`addAssociatedItemModel()`): data that is saved in its own tables, for example images, given
  as array or as text separated by `|`. `Model\Prepare` (`addAssociatedItemPrepareModel()`) prepares the associated
  data of all entities of a chunk.

## Requirements

- Magento Open Source or Adobe Commerce 2.4.6 or newer
- PHP 8.2 or newer

## Installation

```bash
composer require infrangible/m2-import-entity
bin/magento module:enable Infrangible_ImportEntity
bin/magento setup:upgrade
```

In production mode, also run `bin/magento setup:di:compile`.

## License

Infrangible Import Entity is licensed under the MIT License - see the LICENSE file for details.
