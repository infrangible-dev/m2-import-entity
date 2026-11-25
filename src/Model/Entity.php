<?php

declare(strict_types=1);

/**
 * @author      Andreas Knollmann
 * @copyright   Copyright (c) 2014-2026 Softwareentwicklung Andreas Knollmann
 * @license     http://www.opensource.org/licenses/mit-license.php MIT
 */

namespace Infrangible\ImportEntity\Model;

use DateTime;
use Exception;
use FeWeDev\Base\Arrays;
use FeWeDev\Base\Variables;
use Infrangible\Core\Helper\Attribute;
use Infrangible\Core\Helper\Database;
use Infrangible\Core\Helper\EntityType;
use Infrangible\Core\Helper\Export;
use Infrangible\Core\Helper\Instances;
use Infrangible\Core\Helper\Stores;
use Infrangible\Import\Helper\Data;
use Infrangible\Import\Model\Import;
use Infrangible\Import\Model\Related;
use Magento\Eav\Model\Entity\Attribute as EavAttribute;
use Magento\Eav\Model\Entity\Collection\AbstractCollection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\ImportExport\Model\Import as ImportExport;
use Magento\Store\Model\Store;
use Psr\Log\LoggerInterface;

/**
 * The import of the elements of an EAV entity type, for example products or categories: finds the entities of the
 * elements by their ID or key, creates the missing entities and saves the changed attribute values per store, in
 * chunks. Attributes can be replaced (Replace), saved by their own model (SpecialAttribute) or carry associated data
 * (Associated).
 */
abstract class Entity extends Import
{
    /** @var array<int, int> store IDs by transformed element number */
    private array $storeIds = [];

    /** @var array<int|string, mixed> entity IDs by source element number */
    private array $sourceDataEntityIds = [];

    /** @var array<int, int> entity IDs by transformed element number */
    private array $transformedDataEntityIds = [];

    /** @var list<string> */
    private array $prohibitAdminEavAttributeValues = [];

    /** @var array<string, mixed> */
    private array $defaultAdminEavAttributeValues = [];

    /** @var bool */
    private bool $ignoreUnknownAttributes = true;

    /** @var bool */
    private bool $unknownAttributesWarnOnly = false;

    /** @var bool */
    private bool $addUnknownAttributeOptionValues = false;

    /** @var bool */
    private bool $updateAdminStore = false;

    /** @var int */
    private int $chunkSize = 1000;

    /** @var array<int, int> numbers of the transformed elements whose entity is created */
    private array $transformedCreateElementNumbers = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $createTableData = [];

    /** @var array<string, array<string, list<array<string, mixed>>>> */
    private array $singleAttributeTableData = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $eavAttributeTableData = [];

    /** @var list<string> */
    private array $createDateAttributeCodes = [];

    /** @var list<string> */
    private array $updateDateAttributeCodes = [];

    /** @var array<string, string> */
    private array $replaceAttributeModels = [];

    /** @var array<string, string> */
    private array $specialAttributeModel = [];

    /** @var array<string, string> */
    private array $specialAttributes = [
        'entity_id' => 'int',
        'store_id'  => 'int'
    ];

    /** @var list<string> */
    private array $ignoreAttributes = ['entity_id', 'website_id'];

    /** @var list<string> */
    private array $forceAdminEavAttributeValues = [];

    /** @var list<array{entity_id: int, store_id: int}> */
    private array $importedEntities = [];

    /** @var array<string, int> */
    private array $createdEntityIds = [];

    /** @var bool */
    private bool $addElementKeyToCreateEntityData = true;

    /** @var array<string, string> */
    private array $associatedItemPrepareModels = [];

    /**
     * @param Arrays $arrays
     * @param Variables $variables
     * @param Database $databaseHelper
     * @param Instances $instanceHelper
     * @param Data $importHelper
     * @param Stores $storeHelper
     * @param Attribute $attributeHelper
     * @param EntityType $entityTypeHelper
     * @param Export $exportHelper
     * @param LoggerInterface $logging
     */
    public function __construct(
        Arrays $arrays,
        Variables $variables,
        Database $databaseHelper,
        Instances $instanceHelper,
        Data $importHelper,
        protected Stores $storeHelper,
        protected Attribute $attributeHelper,
        protected EntityType $entityTypeHelper,
        protected Export $exportHelper,
        LoggerInterface $logging
    ) {
        parent::__construct($arrays, $variables, $databaseHelper, $instanceHelper, $importHelper, $logging);
    }

    /**
     * Runs the import and records the changed entities.
     *
     * @return void
     * @throws Exception
     */
    protected function runImport(): void
    {
        parent::runImport();

        foreach ($this->getTransformedChangedElementNumbers() as $elementNumber) {
            $this->addImportedEntity($this->transformedDataEntityIds[$elementNumber], $this->storeIds[$elementNumber]);
        }
    }

    /**
     * Returns the code of the imported entity type, for example "catalog_product".
     *
     * @return string
     */
    abstract protected function getEntityTypeCode(): string;

    /**
     * Returns the number of elements that are saved in one transaction, 0 for all.
     *
     * @return int
     */
    public function getChunkSize(): int
    {
        return $this->chunkSize;
    }

    /**
     * Sets the number of elements that are saved in one transaction, 0 for all.
     *
     * @param int $chunkSize
     *
     * @return void
     */
    public function setChunkSize(int $chunkSize): void
    {
        $this->chunkSize = $chunkSize;
    }

    /**
     * Sets whether values of unknown attributes are removed from the elements instead of making them invalid.
     *
     * @param bool $ignoreUnknownAttributes
     *
     * @return void
     */
    public function setIgnoreUnknownAttributes(bool $ignoreUnknownAttributes = true): void
    {
        $this->ignoreUnknownAttributes = $ignoreUnknownAttributes;
    }

    /**
     * Sets whether unknown attributes are logged as debug messages instead of errors.
     *
     * @param bool $unknownAttributesWarnOnly
     *
     * @return void
     */
    public function setUnknownAttributesWarnOnly(bool $unknownAttributesWarnOnly = true): void
    {
        $this->unknownAttributesWarnOnly = $unknownAttributesWarnOnly;
    }

    /**
     * Returns whether unknown attributes are logged as debug messages instead of errors.
     *
     * @return bool
     */
    public function isUnknownAttributesWarnOnly(): bool
    {
        return $this->unknownAttributesWarnOnly;
    }

    /**
     * Returns the classes of the Replace models by attribute code.
     *
     * @return array
     * @phpstan-return array<string, string>
     */
    public function getReplaceAttributeModels(): array
    {
        return $this->replaceAttributeModels;
    }

    /**
     * Replaces the values of the attribute with the Replace model of the class.
     *
     * @param string $key
     * @param string $model
     *
     * @return void
     */
    public function addReplaceAttributeModel(string $key, string $model): void
    {
        $this->replaceAttributeModels[$key] = $model;
    }

    /**
     * Saves the values of the attribute with the SpecialAttribute model of the class.
     *
     * @param string $key
     * @param string $model
     *
     * @return void
     */
    public function addSpecialAttributeModel(string $key, string $model): void
    {
        $this->specialAttributeModel[$key] = $model;
    }

    /**
     * Saves the attribute in the entity table, with the value type (int, decimal, datetime, varchar or text).
     *
     * @param string $key
     * @param string $type
     *
     * @return void
     */
    public function addSpecialAttribute(string $key, string $type): void
    {
        $this->specialAttributes[$key] = $type;
    }

    /**
     * Returns whether unknown values of attributes with options are added as options.
     *
     * @return bool
     */
    public function isAddUnknownAttributeOptionValues(): bool
    {
        return $this->addUnknownAttributeOptionValues;
    }

    /**
     * Sets whether unknown values of attributes with options are added as options.
     *
     * @param bool $addUnknownAttributeOptionValues
     *
     * @return void
     */
    public function setAddUnknownAttributeOptionValues(bool $addUnknownAttributeOptionValues = true): void
    {
        $this->addUnknownAttributeOptionValues = $addUnknownAttributeOptionValues;
    }

    /**
     * Saves the value of the attribute also in the admin store, if it has no value there.
     *
     * @param string $attributeCode
     *
     * @return void
     */
    public function addForceAdminEavAttributeValue(string $attributeCode): void
    {
        $this->forceAdminEavAttributeValues[] = $attributeCode;
    }

    /**
     * Never saves a value of the attribute in the admin store.
     *
     * @param string $attributeCode
     *
     * @return void
     */
    public function addProhibitAdminEavAttributeValue(string $attributeCode): void
    {
        $this->prohibitAdminEavAttributeValues[] = $attributeCode;
    }

    /**
     * Saves the value in the admin store, when the attribute gets a value in a store but has none in the admin store.
     *
     * @param string $attributeCode
     * @param mixed $value
     *
     * @return void
     */
    public function addDefaultAdminEavAttributeValue(string $attributeCode, mixed $value): void
    {
        $this->defaultAdminEavAttributeValues[$attributeCode] = $value;
    }

    /**
     * Sets the attribute to the current date when an entity is created.
     *
     * @param string $attributeCode
     *
     * @return void
     */
    public function addCreateDateAttributeCode(string $attributeCode): void
    {
        $this->createDateAttributeCodes[] = $attributeCode;
    }

    /**
     * Sets the attribute to the current date when an entity is changed.
     *
     * @param string $attributeCode
     *
     * @return void
     */
    public function addUpdateDateAttributeCode(string $attributeCode): void
    {
        $this->updateDateAttributeCodes[] = $attributeCode;
    }

    /**
     * Sets whether the values of existing entities are also saved in the admin store.
     *
     * @param bool $updateAdminStore
     *
     * @return void
     */
    public function setUpdateAdminStore(bool $updateAdminStore = true): void
    {
        $this->updateAdminStore = $updateAdminStore;
    }

    /**
     * Returns the value types of the attributes that are saved in the entity table, by attribute code.
     *
     * @return array
     * @phpstan-return array<string, string>
     */
    public function getSpecialAttributes(): array
    {
        return $this->specialAttributes;
    }

    /**
     * Returns the codes of the attributes that are not saved.
     *
     * @return array
     * @phpstan-return list<string>
     */
    protected function getIgnoreAttributes(): array
    {
        return $this->ignoreAttributes;
    }

    /**
     * Returns the values that are saved in the admin store, by attribute code.
     *
     * @return array
     * @phpstan-return array<string, mixed>
     */
    protected function getDefaultAdminEavAttributeValues(): array
    {
        return $this->defaultAdminEavAttributeValues;
    }

    /**
     * Does not save the attribute.
     *
     * @param string $attributeCode
     *
     * @return void
     */
    protected function addIgnoreAttribute(string $attributeCode): void
    {
        $this->ignoreAttributes[] = $attributeCode;
    }

    /**
     * Sets whether the element key is saved in the entity table of created entities.
     *
     * @param bool $addElementKeyToCreateEntityData
     *
     * @return void
     */
    public function setAddElementKeyToCreateEntityData(bool $addElementKeyToCreateEntityData): void
    {
        $this->addElementKeyToCreateEntityData = $addElementKeyToCreateEntityData;
    }

    /**
     * Returns the name of one entity for the log, for example "product".
     *
     * @return string
     */
    abstract protected function getEntityLogName(): string;

    /**
     * Adds the IDs of the existing entities to the source elements.
     *
     * @param array $sourceData
     * @phpstan-param array<int|string, mixed> $sourceData
     *
     * @return array
     * @phpstan-return array<int|string, mixed>
     */
    protected function prepareSourceData(array $sourceData): array
    {
        $this->determineSourceDataEntityIds($sourceData);

        foreach ($sourceData as $sourceElementNumber => $sourceElement) {
            if (is_array($sourceElement) && array_key_exists($sourceElementNumber, $this->sourceDataEntityIds)) {
                $sourceElement['entity_id'] = $this->sourceDataEntityIds[$sourceElementNumber];

                $sourceData[$sourceElementNumber] = $sourceElement;
            }
        }

        return $sourceData;
    }

    /**
     * Collects the entity IDs of the valid source elements: given in the element, or found by its key.
     *
     * @param array $sourceData
     * @phpstan-param array<int|string, mixed> $sourceData
     *
     * @return void
     */
    private function determineSourceDataEntityIds(array $sourceData): void
    {
        $sourceElementKeyValues = [];

        foreach ($sourceData as $sourceElementNumber => $sourceElement) {
            if (! is_array($sourceElement)
                || in_array($sourceElementNumber, $this->getSourceInvalidElementNumbers())) {
                continue;
            }

            if (array_key_exists('entity_id', $sourceElement)) {
                $this->sourceDataEntityIds[$sourceElementNumber] = $sourceElement['entity_id'];
            } else {
                $elementKey = $this->getSourceElementKey($sourceElement);

                if (array_key_exists($elementKey, $sourceElement)) {
                    $sourceElementKeyValues[$sourceElementNumber] = $sourceElement[$elementKey];
                }
            }
        }

        $this->logging->debug(
            sprintf('Found %d entity id(s) directly in source elements', count($this->sourceDataEntityIds))
        );

        $this->logging->debug(
            sprintf('Searching entity ids for %d unknown entries found in elements', count($sourceElementKeyValues))
        );

        foreach ($this->findSourceDataEntityIds($sourceElementKeyValues) as $elementNumber => $entityId) {
            $this->sourceDataEntityIds[$elementNumber] = $entityId;
        }

        $this->logging->debug(
            sprintf(
                'Found %d entity id(s) in source elements and searching with element key',
                count($this->sourceDataEntityIds)
            )
        );
    }

    /**
     * Returns the key of the source element that identifies its entity, for example "sku".
     *
     * @param array $sourceElement
     * @phpstan-param array<int|string, mixed> $sourceElement
     *
     * @return string
     */
    abstract protected function getSourceElementKey(array $sourceElement): string;

    /**
     * Returns the IDs of the existing entities with the key values, by element number.
     *
     * @param array $sourceElementKeys
     * @phpstan-param array<int|string, mixed> $sourceElementKeys
     *
     * @return array
     * @phpstan-return array<int|string, mixed>
     */
    abstract protected function findSourceDataEntityIds(array $sourceElementKeys): array;

    /**
     * Transforms every source element into a transformed element.
     *
     * A source element that cannot be transformed becomes invalid with the message of the exception.
     *
     * @param array $sourceData
     * @phpstan-param array<int|string, mixed> $sourceData
     *
     * @return array
     * @phpstan-return array<int, array<string, mixed>>
     */
    protected function transformData(array $sourceData): array
    {
        $transformedData = [];

        foreach ($sourceData as $sourceElementNumber => $sourceElement) {
            $sourceElementNumber = $this->variables->intValue($sourceElementNumber);
            $transformedElementNumber = count($transformedData);

            try {
                $transformedData[$transformedElementNumber] = $this->transformElement(
                    $sourceElementNumber,
                    is_array($sourceElement) ? $sourceElement : []
                );

                $this->addSourceTransformedRelation($sourceElementNumber, $transformedElementNumber);
            } catch (Exception $exception) {
                $this->addSourceInvalidElementReason($sourceElementNumber, $exception->getMessage());
            }
        }

        return $transformedData;
    }

    /**
     * Returns the transformed element of the source element, with attribute codes as keys.
     *
     * @param int $sourceElementNumber
     * @param array $sourceElement
     * @phpstan-param array<int|string, mixed> $sourceElement
     *
     * @return array
     * @phpstan-return array<string, mixed>
     * @throws Exception
     */
    abstract protected function transformElement(int $sourceElementNumber, array $sourceElement): array;

    /**
     * Checks the empty value of the attribute in the element.
     *
     * Removes unknown attributes (or makes the element invalid) and empties the value of attributes that are not of
     * type varchar or text.
     *
     * @param int $elementNumber
     * @param array $element
     * @phpstan-param array<string, mixed> $element
     * @param string $attributeCode
     *
     * @return array
     * @phpstan-return array<string, mixed>
     */
    protected function validateAttribute(int $elementNumber, array $element, string $attributeCode): array
    {
        $attributeValue = $element[$attributeCode];

        if (! empty($attributeValue) || is_numeric($attributeValue)) {
            return $element;
        }

        try {
            $attribute = $this->attributeHelper->getAttribute($this->getEntityTypeCode(), $attributeCode);
        } catch (Exception $exception) {
            if ($this->isUnknownAttributesWarnOnly()) {
                $this->logging->debug($exception->getMessage());
            } else {
                $this->logging->error($exception);
            }

            $attribute = null;
        }

        if ($attribute === null) {
            if ($this->ignoreUnknownAttributes) {
                unset($element[$attributeCode]);
            } else {
                $this->addTransformedInvalidElementReason(
                    $elementNumber,
                    sprintf('Unknown attribute: %s', $attributeCode)
                );
            }

            return $element;
        }

        try {
            $attributeType = ImportExport::getAttributeType($attribute);

            if ($attributeType !== 'varchar' && $attributeType !== 'text') {
                $element[$attributeCode] = null;
            }
        } catch (Exception $exception) {
            $this->logging->error($exception);
        }

        return $element;
    }

    /**
     * Prepares and validates the transformed elements.
     *
     * Determines the store of every element, replaces values with the Replace models, prepares the Associated and
     * SpecialAttribute models, checks the attributes and validates the elements.
     *
     * @param AdapterInterface $dbAdapter
     *
     * @return bool
     */
    protected function validateTransformedData(AdapterInterface $dbAdapter): bool
    {
        foreach ($this->getTransformedData() as $elementNumber => $element) {
            $elementNumber = $this->variables->intValue($elementNumber);

            $element = $this->validateTransformedDataStore($elementNumber, $this->toElement($element));

            $attributeCodes = array_values(array_diff(array_keys($element), $this->ignoreAttributes));

            $this->prepareReplaceModels($dbAdapter, $elementNumber, $element, $attributeCodes);
            $this->prepareAssociatedModels($dbAdapter, $elementNumber, $element, $attributeCodes);
            $this->prepareSpecialAttributeModels($elementNumber, $element, $attributeCodes);

            foreach ($attributeCodes as $attributeCode) {
                $element = $this->validateAttribute($elementNumber, $element, $attributeCode);
            }

            $this->addTransformedData($element, $elementNumber);
        }

        $transformedValidationResult = true;

        foreach ($this->getTransformedData() as $elementNumber => $element) {
            $elementNumber = $this->variables->intValue($elementNumber);
            $element = $this->toElement($element);

            // elements without a store were made invalid when their store was determined
            if (! array_key_exists('store_id', $element)) {
                $transformedValidationResult = false;

                continue;
            }

            $storeId = $this->variables->intValue($element['store_id']);

            try {
                $this->storeHelper->getStore($storeId);
            } catch (Exception $exception) {
                $this->addTransformedInvalidElementReason($elementNumber, $exception->getMessage());

                $transformedValidationResult = false;

                continue;
            }

            try {
                $elementValidationResult = $this->validateTransformedElement(
                    $dbAdapter,
                    $this->getEntityTypeCode(),
                    $elementNumber,
                    $element,
                    $storeId
                );
            } catch (Exception $exception) {
                $this->logging->error($exception);

                $elementValidationResult = false;
            }

            $transformedValidationResult = $transformedValidationResult && $elementValidationResult;
        }

        return $transformedValidationResult;
    }

    /**
     * Replaces the values of the attributes with Replace models by the result of the model.
     *
     * @param AdapterInterface $dbAdapter
     * @param int $elementNumber
     * @param array $element
     * @phpstan-param array<string, mixed> $element
     * @param array $attributeCodes
     * @phpstan-param list<string> $attributeCodes
     *
     * @return void
     */
    private function prepareReplaceModels(
        AdapterInterface $dbAdapter,
        int $elementNumber,
        array &$element,
        array &$attributeCodes
    ): void {
        foreach ($attributeCodes as $attributeCode) {
            if (! array_key_exists($attributeCode, $this->replaceAttributeModels)) {
                continue;
            }

            $modelClass = $this->replaceAttributeModels[$attributeCode];

            $replaceItem = $this->instanceHelper->getInstance($modelClass, ['importer' => $this]);

            if ($replaceItem instanceof Replace) {
                try {
                    $replaceItem->prepare($element[$attributeCode]);

                    $storeId = $this->variables->intValue($element['store_id'] ?? Store::DEFAULT_STORE_ID);

                    $replaceItem->validate($dbAdapter, $storeId, $element);

                    if ($replaceItem->isValid()) {
                        $element[$replaceItem->getReplaceResultAttributeId()] =
                            $replaceItem->replace($dbAdapter, $storeId);
                    } else {
                        $this->addTransformedInvalidElementReason(
                            $elementNumber,
                            sprintf(
                                'Invalid data in replace item: %s because: %s',
                                $attributeCode,
                                $replaceItem->getInvalidReason()
                            )
                        );
                    }
                } catch (Exception $exception) {
                    $this->addTransformedInvalidElementReason(
                        $elementNumber,
                        sprintf(
                            'Invalid data in replace item: %s because: %s',
                            $attributeCode,
                            $exception->getMessage()
                        )
                    );
                }
            } else {
                $this->addInvalidModelReason($elementNumber, $modelClass, $attributeCode);
            }

            unset($element[$attributeCode]);

            $attributeCodes = array_values(array_diff($attributeCodes, [$attributeCode]));
        }
    }

    /**
     * Replaces the values of the attributes with Associated models by the prepared model.
     *
     * @param AdapterInterface $dbAdapter
     * @param int $elementNumber
     * @param array $element
     * @phpstan-param array<string, mixed> $element
     * @param array $attributeCodes
     * @phpstan-param list<string> $attributeCodes
     *
     * @return void
     */
    protected function prepareAssociatedModels(
        AdapterInterface $dbAdapter,
        int $elementNumber,
        array &$element,
        array &$attributeCodes
    ): void {
        $associatedItemModels = $this->getAssociatedItemModels();

        foreach ($attributeCodes as $attributeCode) {
            if (! array_key_exists($attributeCode, $associatedItemModels)) {
                continue;
            }

            $associatedItem = $this->prepareAssociatedModel($attributeCode);

            if ($associatedItem !== null) {
                $associatedItemData = $element[$attributeCode];

                if (! is_array($associatedItemData)) {
                    $associatedItemData = explode('|', $this->variables->stringValue($associatedItemData));
                }

                $associatedItem->prepare($dbAdapter, $associatedItemData, $element);

                $element[$attributeCode] = $associatedItem;
            } else {
                $this->addInvalidModelReason($elementNumber, $associatedItemModels[$attributeCode], $attributeCode);

                unset($element[$attributeCode]);
            }

            $attributeCodes = array_values(array_diff($attributeCodes, [$attributeCode]));
        }
    }

    /**
     * Returns a new Associated model of the attribute, or null if its class is no Associated model.
     *
     * @param string $attributeCode
     *
     * @return Associated|null
     */
    protected function prepareAssociatedModel(string $attributeCode): ?Associated
    {
        $associatedItem = $this->instanceHelper->getInstance(
            $this->getAssociatedItemModels()[$attributeCode],
            ['importer' => $this]
        );

        return $associatedItem instanceof Associated ? $associatedItem : null;
    }

    /**
     * Replaces the values of the attributes with SpecialAttribute models by the prepared model.
     *
     * @param int $elementNumber
     * @param array $element
     * @phpstan-param array<string, mixed> $element
     * @param array $attributeCodes
     * @phpstan-param list<string> $attributeCodes
     *
     * @return void
     */
    private function prepareSpecialAttributeModels(int $elementNumber, array &$element, array &$attributeCodes): void
    {
        foreach ($attributeCodes as $attributeCode) {
            if (! array_key_exists($attributeCode, $this->specialAttributeModel)) {
                continue;
            }

            $modelClass = $this->specialAttributeModel[$attributeCode];

            $specialAttribute = $this->instanceHelper->getInstance($modelClass, ['importer' => $this]);

            if ($specialAttribute instanceof SpecialAttribute) {
                $specialAttribute->prepare($attributeCode, $element[$attributeCode], $element);

                $element[$attributeCode] = $specialAttribute;
            } else {
                $this->addInvalidModelReason($elementNumber, $modelClass, $attributeCode);

                unset($element[$attributeCode]);
            }

            $attributeCodes = array_values(array_diff($attributeCodes, [$attributeCode]));
        }
    }

    /**
     * Makes the element invalid because the model class of the attribute is not usable.
     *
     * @param int $elementNumber
     * @param string $modelClass
     * @param string $attributeCode
     *
     * @return void
     */
    private function addInvalidModelReason(int $elementNumber, string $modelClass, string $attributeCode): void
    {
        $this->addTransformedInvalidElementReason(
            $elementNumber,
            sprintf('Invalid attribute model: %s for attribute: %s', $modelClass, $attributeCode)
        );
    }

    /**
     * Adds the store to an element without one.
     *
     * That is the default store of its website (website with id or name, or website_id), else the admin store.
     *
     * @param int $elementNumber
     * @param array $element
     * @phpstan-param array<string, mixed> $element
     *
     * @return array
     * @phpstan-return array<string, mixed>
     */
    protected function validateTransformedDataStore(int $elementNumber, array $element): array
    {
        if (array_key_exists('store_id', $element)) {
            return $element;
        }

        $website = $element['website'] ?? null;

        if (is_array($website)) {
            if (array_key_exists('id', $website)) {
                $storeId = $this->getWebsiteDefaultStoreId($website['id']);
                $error = 'Invalid website with id: %s';
            } elseif (array_key_exists('name', $website)) {
                $storeId = $this->getWebsiteDefaultStoreId($website['name']);
                $error = 'Invalid website with code: %s';
            } else {
                $this->addTransformedInvalidElementReason($elementNumber, 'Invalid website definition');

                return $element;
            }

            $websiteValue = $website['id'] ?? $website['name'];
        } elseif (array_key_exists('website_id', $element)) {
            $websiteValue = $element['website_id'];
            $storeId = $this->getWebsiteDefaultStoreId($websiteValue);
            $error = 'Invalid website with id: %s';
        } else {
            $element['store_id'] = Store::DEFAULT_STORE_ID;

            return $element;
        }

        if ($storeId === null) {
            $this->addTransformedInvalidElementReason(
                $elementNumber,
                sprintf($error, $this->variables->stringValue($websiteValue))
            );
        } else {
            $element['store_id'] = $storeId;

            // the website only defines the store, it is no attribute
            unset($element['website']);
        }

        return $element;
    }

    /**
     * Returns the ID of the default store of the website with the ID or code, or null if there is none.
     *
     * @param mixed $website
     *
     * @return int|null
     */
    private function getWebsiteDefaultStoreId(mixed $website): ?int
    {
        if (! is_int($website) && ! is_string($website)) {
            return null;
        }

        try {
            $websiteModel = $this->storeHelper->getWebsite(
                is_string($website) && preg_match('/^\d+$/', $website) === 1 ? (int)$website : $website
            );

            $defaultStore = $this->storeHelper->getDefaultStore($this->variables->intValue($websiteModel->getId()));

            $storeId = $defaultStore->getId();

            return $this->variables->isEmpty($storeId) ? null : $this->variables->intValue($storeId);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Validates the values of the element in the store.
     *
     * Related models validate themselves, the other values are validated for their attribute. Ignored attributes are
     * not validated; values of attributes that the validation marks as to ignore are removed.
     *
     * @param AdapterInterface $dbAdapter
     * @param string $entityTypeCode
     * @param int $elementNumber
     * @param array $element
     * @phpstan-param array<string, mixed> $element
     * @param int $storeId
     *
     * @return bool
     */
    protected function validateTransformedElement(
        AdapterInterface $dbAdapter,
        string $entityTypeCode,
        int $elementNumber,
        array $element,
        int $storeId
    ): bool {
        $validationResult = true;

        foreach ($element as $attributeCode => $attributeValue) {
            if (in_array($attributeCode, $this->ignoreAttributes)) {
                continue;
            }

            $isAssociated = $attributeValue instanceof Associated;

            if ($attributeValue instanceof Related) {
                $attributeValidationResult = $this->validateRelated(
                    $dbAdapter,
                    $elementNumber,
                    $element,
                    $storeId,
                    $attributeCode,
                    $attributeValue,
                    $isAssociated ? 'Invalid data in associated item: %s because: %s' :
                        'Invalid attribute value for attribute: %s because: %s'
                );

                $validationResult = $validationResult && $attributeValidationResult;

                continue;
            }

            try {
                $this->importHelper->validateAttribute(
                    $dbAdapter,
                    $entityTypeCode,
                    $attributeCode,
                    $attributeValue,
                    $storeId,
                    $this->isAddUnknownAttributeOptionValues(),
                    $this->isUnknownAttributesWarnOnly(),
                    $this->specialAttributes,
                    $this->isTest()
                );
            } catch (Exception $exception) {
                if ($exception->getCode() === Data::IGNORE_AND_REMOVE_ATTRIBUTE) {
                    unset($element[$attributeCode]);

                    $this->addTransformedData($element, $elementNumber);
                } else {
                    $this->addTransformedInvalidElementReason($elementNumber, $exception->getMessage());

                    $validationResult = false;
                }
            }
        }

        $elementKey = $this->getTransformedElementKey($element);

        if (! array_key_exists('entity_id', $element) && ! array_key_exists($elementKey, $element)) {
            $validationResult = false;

            $this->addTransformedInvalidElementReason($elementNumber, 'No entity id or element key');
        }

        return $validationResult;
    }

    /**
     * Validates the Related model of the attribute and returns whether it is valid.
     *
     * @param AdapterInterface $dbAdapter
     * @param int $elementNumber
     * @param array $element
     * @phpstan-param array<string, mixed> $element
     * @param int $storeId
     * @param string $attributeCode
     * @param Related $related
     * @param string $message the reason of an invalid value, with placeholders for attribute code and cause
     *
     * @return bool
     */
    private function validateRelated(
        AdapterInterface $dbAdapter,
        int $elementNumber,
        array $element,
        int $storeId,
        string $attributeCode,
        Related $related,
        string $message
    ): bool {
        try {
            $related->validate($dbAdapter, $storeId, $element);
        } catch (Exception $exception) {
            $this->addTransformedInvalidElementReason(
                $elementNumber,
                sprintf($message, $attributeCode, $exception->getMessage())
            );

            $this->logging->error($exception);

            return false;
        }

        if ($related->isValid()) {
            return true;
        }

        $this->addTransformedInvalidElementReason(
            $elementNumber,
            sprintf($message, $attributeCode, $related->getInvalidReason())
        );

        return false;
    }

    /**
     * Creates the missing entities and saves the changed values of the elements, per store and in chunks.
     *
     * @param AdapterInterface $dbAdapter
     *
     * @return void
     * @throws Exception
     */
    protected function importTransformedData(AdapterInterface $dbAdapter): void
    {
        $this->determineTransformedDataEntityIds();

        foreach (array_keys($this->getTransformedData()) as $elementNumber) {
            $elementNumber = $this->variables->intValue($elementNumber);

            if ($this->isSkippedElement($elementNumber)) {
                continue;
            }

            if (! array_key_exists($elementNumber, $this->transformedDataEntityIds)) {
                $this->transformedCreateElementNumbers[] = $elementNumber;
            } elseif ($this->updateAdminStore) {
                $adminElement = $this->toElement($this->getTransformedData()[$elementNumber]);

                $adminElement['store_id'] = Store::DEFAULT_STORE_ID;

                $this->addTransformedData($adminElement);

                $adminElementNumber = $this->variables->intValue(array_key_last($this->getTransformedData()));

                $this->transformedDataEntityIds[$adminElementNumber] = $this->transformedDataEntityIds[$elementNumber];
            }
        }

        $this->determineStoreIds();

        $entityType = $this->entityTypeHelper->getEntityType($this->getEntityTypeCode());

        if ($entityType === null) {
            throw new Exception(sprintf('Unknown entity type: %s', $this->getEntityTypeCode()));
        }

        $createTableName = $this->entityTypeHelper->getEntityTypeTableByEntityType($entityType);

        $importData = [];

        foreach ($this->getTransformedData() as $elementNumber => $element) {
            $elementNumber = $this->variables->intValue($elementNumber);

            if (! $this->isSkippedElement($elementNumber)) {
                $importData[$this->storeIds[$elementNumber]][$elementNumber] = $this->toElement($element);
            }
        }

        $this->logging->info(sprintf('Found %d store(s) in elements', count($importData)));

        foreach ($importData as $storeId => $storeImportData) {
            $importChunks = $this->getChunkSize() > 0 ?
                array_chunk($storeImportData, $this->getChunkSize(), true) : [$storeImportData];

            $this->logging->info(
                sprintf('Split data for store with id: %d in %d chunk(s)', $storeId, count($importChunks))
            );

            foreach ($importChunks as $chunkCounter => $importChunk) {
                $this->logging->info(
                    sprintf(
                        'Importing chunk: %d/%d containing %d element(s)',
                        $chunkCounter + 1,
                        count($importChunks),
                        count($importChunk)
                    )
                );

                if (! $this->importChunk($dbAdapter, $createTableName, $storeId, $importChunk)) {
                    return;
                }
            }
        }
    }

    /**
     * Returns whether the transformed element is invalid, cached or already imported.
     *
     * @param int $elementNumber
     *
     * @return bool
     */
    private function isSkippedElement(int $elementNumber): bool
    {
        return in_array($elementNumber, $this->getTransformedInvalidElementNumbers())
            || in_array($elementNumber, $this->getTransformedCachedElementNumbers())
            || in_array($elementNumber, $this->getTransformedImportedElementNumbers());
    }

    /**
     * Returns whether the transformed element is invalid or cached.
     *
     * @param int $elementNumber
     *
     * @return bool
     */
    private function isInvalidOrCachedElement(int $elementNumber): bool
    {
        return in_array($elementNumber, $this->getTransformedInvalidElementNumbers())
            || in_array($elementNumber, $this->getTransformedCachedElementNumbers());
    }

    /**
     * Imports the elements of the chunk in the store in one transaction.
     *
     * Returns false if the attribute values could not be saved, which stops the import.
     *
     * @param AdapterInterface $dbAdapter
     * @param string $createTableName
     * @param int $storeId
     * @param array $importChunk
     * @phpstan-param array<int, array<string, mixed>> $importChunk
     *
     * @return bool
     * @throws Exception
     */
    private function importChunk(
        AdapterInterface $dbAdapter,
        string $createTableName,
        int $storeId,
        array $importChunk
    ): bool {
        $dbAdapter->beginTransaction();

        $this->createTableData = [];
        $this->singleAttributeTableData = [];
        $this->eavAttributeTableData = [];

        $this->assignCreatedEntityIds($importChunk);

        $chunkAttributeCodes = [];
        $chunkEntityIds = [];

        foreach ($importChunk as $elementNumber => $element) {
            if (in_array($elementNumber, $this->transformedCreateElementNumbers)) {
                continue;
            }

            foreach ($element as $attributeCode => $attributeValue) {
                if (! in_array($attributeCode, $this->ignoreAttributes) && ! ($attributeValue instanceof Associated)) {
                    $chunkAttributeCodes[] = $attributeCode;
                }
            }

            $chunkEntityIds[] = $this->transformedDataEntityIds[$elementNumber];
        }

        $chunkAttributeCodes = array_values(array_unique($chunkAttributeCodes));
        $staticAttributes = array_fill_keys(array_keys($this->getSpecialAttributes()), true);

        $currentAttributeValues = $this->exportHelper->getCurrentAttributeValues(
            $dbAdapter,
            $this->getEntityTypeCode(),
            $storeId,
            $chunkAttributeCodes,
            $chunkEntityIds,
            $staticAttributes,
            $this->getEntityCollection()
        );

        $currentAdminAttributeValues = $this->exportHelper->getCurrentAttributeValues(
            $dbAdapter,
            $this->getEntityTypeCode(),
            Store::DEFAULT_STORE_ID,
            $chunkAttributeCodes,
            $chunkEntityIds,
            $staticAttributes,
            $this->getEntityCollection()
        );

        $this->createEntities($dbAdapter, $createTableName, $importChunk);

        foreach (array_keys($importChunk) as $elementNumber) {
            if (! array_key_exists($elementNumber, $this->transformedDataEntityIds)) {
                $this->addTransformedInvalidElementReason(
                    $elementNumber,
                    sprintf('Could not identify %s to update', $this->getEntityLogName())
                );
            }
        }

        foreach ($importChunk as $elementNumber => $element) {
            if ($this->isInvalidOrCachedElement($elementNumber)) {
                continue;
            }

            $entityId = $this->transformedDataEntityIds[$elementNumber];
            $entityCurrentAttributeValues = $this->getEntityValues($currentAttributeValues, $entityId);

            $this->logging->debug(
                sprintf(
                    'Importing element with data: %s',
                    $this->formatLogValue($this->importHelper->elementToArray($element))
                )
            );

            $this->logging->debug(
                sprintf('Current element values: %s', $this->formatLogValue($entityCurrentAttributeValues))
            );

            try {
                $this->prepareElement(
                    $dbAdapter,
                    $this->getEntityTypeCode(),
                    $entityId,
                    $storeId,
                    $elementNumber,
                    $element,
                    $entityCurrentAttributeValues,
                    $this->getEntityValues($currentAdminAttributeValues, $entityId)
                );
            } catch (Exception $exception) {
                $this->addTransformedInvalidElementReason($elementNumber, $exception->getMessage());

                $this->logging->error($exception);
            }
        }

        if (! $this->saveChunkAttributeValues($dbAdapter, $storeId, $importChunk)) {
            return false;
        }

        foreach (array_keys($importChunk) as $elementNumber) {
            $this->addTransformedImportedElementNumbers($elementNumber);
        }

        $this->prepareAssociatedItems($storeId, $importChunk);

        foreach ($importChunk as $elementNumber => $element) {
            if (! $this->isInvalidOrCachedElement($elementNumber)) {
                $this->updateAssociatedItems(
                    $dbAdapter,
                    $storeId,
                    $elementNumber,
                    $element,
                    $currentAttributeValues,
                    $currentAdminAttributeValues
                );
            }
        }

        $dbAdapter->commit();

        return true;
    }

    /**
     * Uses the entities created for an earlier element with the same key instead of creating them again.
     *
     * @param array $importChunk
     * @phpstan-param array<int, array<string, mixed>> $importChunk
     *
     * @return void
     */
    private function assignCreatedEntityIds(array $importChunk): void
    {
        $createdEntityIds = $this->getCreatedEntityIds();

        foreach ($importChunk as $elementNumber => $element) {
            $key = array_search($elementNumber, $this->transformedCreateElementNumbers);

            if ($key === false) {
                continue;
            }

            $elementKeyValue = $this->getElementKeyValue($element);

            if ($elementKeyValue !== null && array_key_exists($elementKeyValue, $createdEntityIds)) {
                $this->transformedDataEntityIds[$elementNumber] = $createdEntityIds[$elementKeyValue];

                unset($this->transformedCreateElementNumbers[$key]);
            }
        }
    }

    /**
     * Returns the value of the element key in the element, or null if it has none.
     *
     * @param array $element
     * @phpstan-param array<string, mixed> $element
     *
     * @return string|null
     */
    private function getElementKeyValue(array $element): ?string
    {
        $elementKeyValue = $element[$this->getTransformedElementKey($element)] ?? null;

        return is_scalar($elementKeyValue) ? (string)$elementKeyValue : null;
    }

    /**
     * Creates the entities of the elements in the chunk that have none.
     *
     * @param AdapterInterface $dbAdapter
     * @param string $createTableName
     * @param array $importChunk
     * @phpstan-param array<int, array<string, mixed>> $importChunk
     *
     * @return void
     * @throws Exception
     */
    private function createEntities(AdapterInterface $dbAdapter, string $createTableName, array $importChunk): void
    {
        foreach ($importChunk as $elementNumber => $element) {
            if (! in_array($elementNumber, $this->transformedCreateElementNumbers)) {
                continue;
            }

            $tableData = $this->getCreateEntityData($element);

            $elementKey = $this->getTransformedElementKey($element);

            if ($this->addElementKeyToCreateEntityData && array_key_exists($elementKey, $element)) {
                $tableData[$elementKey] = $element[$elementKey];
            }

            $this->logging->info(sprintf('Creating entity with key: %s', $this->getElementKeyValue($element)));

            $this->addCreateTableData($elementNumber, $createTableName, $tableData);
        }

        $this->saveCreateTableData($dbAdapter, $createTableName, $importChunk);
    }

    /**
     * Returns the values of the entity in the values of the chunk.
     *
     * @param array $attributeValues
     * @phpstan-param array<int|string, mixed> $attributeValues
     * @param int $entityId
     *
     * @return array
     * @phpstan-return array<string, mixed>
     */
    private function getEntityValues(array $attributeValues, int $entityId): array
    {
        $entityValues = $attributeValues[$entityId] ?? [];

        return is_array($entityValues) ? $this->toElement($entityValues) : [];
    }

    /**
     * Saves the collected attribute values of the chunk.
     *
     * If that fails, rolls back the transaction, makes all elements of the chunk invalid and returns false.
     *
     * @param AdapterInterface $dbAdapter
     * @param int $storeId
     * @param array $importChunk
     * @phpstan-param array<int, array<string, mixed>> $importChunk
     *
     * @return bool
     */
    private function saveChunkAttributeValues(AdapterInterface $dbAdapter, int $storeId, array $importChunk): bool
    {
        if (count($this->singleAttributeTableData) === 0 && count($this->eavAttributeTableData) === 0) {
            $this->logging->debug('No collected attribute table data');

            return true;
        }

        $this->logging->debug('Saving collected attribute table data');

        try {
            $this->saveUpdateTableData($dbAdapter);

            return true;
        } catch (Exception $exception) {
            $dbAdapter->rollBack();

            foreach (array_keys($importChunk) as $elementNumber) {
                $this->logging->info(
                    sprintf(
                        'Could not update %s with id: %d with values of store with id: %d because: %s',
                        $this->getEntityLogName(),
                        $this->transformedDataEntityIds[$elementNumber] ?? 0,
                        $storeId,
                        $exception->getMessage()
                    )
                );

                $this->addTransformedInvalidElementReason($elementNumber, $exception->getMessage());
            }

            $this->logging->error($exception);

            return false;
        }
    }

    /**
     * Runs the Prepare models of the associated items with the entities of the chunk that have these items.
     *
     * @param int $storeId
     * @param array $importChunk
     * @phpstan-param array<int, array<string, mixed>> $importChunk
     *
     * @return void
     */
    private function prepareAssociatedItems(int $storeId, array $importChunk): void
    {
        $associatedItemEntityIds = [];

        foreach ($importChunk as $elementNumber => $element) {
            if ($this->isInvalidOrCachedElement($elementNumber)) {
                continue;
            }

            foreach ($element as $itemName => $attributeValue) {
                if ($attributeValue instanceof Associated) {
                    $associatedItemEntityIds[$itemName][] = $this->transformedDataEntityIds[$elementNumber];
                }
            }
        }

        foreach ($associatedItemEntityIds as $associatedItemKey => $entityIds) {
            if (array_key_exists($associatedItemKey, $this->associatedItemPrepareModels)) {
                $prepareModel =
                    $this->instanceHelper->getInstance($this->associatedItemPrepareModels[$associatedItemKey]);

                if ($prepareModel instanceof Prepare) {
                    $prepareModel->prepare($entityIds, $storeId);
                }
            }
        }
    }

    /**
     * Saves the associated items of the element and marks the element as changed if one of them changed.
     *
     * @param AdapterInterface $dbAdapter
     * @param int $storeId
     * @param int $elementNumber
     * @param array $element
     * @phpstan-param array<string, mixed> $element
     * @param array $currentAttributeValues
     * @phpstan-param array<int|string, mixed> $currentAttributeValues
     * @param array $currentAdminAttributeValues
     * @phpstan-param array<int|string, mixed> $currentAdminAttributeValues
     *
     * @return void
     */
    private function updateAssociatedItems(
        AdapterInterface $dbAdapter,
        int $storeId,
        int $elementNumber,
        array $element,
        array $currentAttributeValues,
        array $currentAdminAttributeValues
    ): void {
        $entityId = $this->transformedDataEntityIds[$elementNumber];
        $isCreatedEntity = in_array($elementNumber, $this->transformedCreateElementNumbers);
        $hasChanges = false;

        foreach ($element as $itemName => $attributeValue) {
            if (! ($attributeValue instanceof Associated)) {
                continue;
            }

            try {
                $attributeHasChanges = $attributeValue->update(
                    $dbAdapter,
                    $entityId,
                    $storeId,
                    $this->getEntityValues($currentAttributeValues, $entityId),
                    $this->getEntityValues($currentAdminAttributeValues, $entityId),
                    $isCreatedEntity
                );

                $this->logging->log(
                    $attributeHasChanges ? 'info' : 'debug',
                    sprintf(
                        'Associated item of %s with id: %d with name: %s %s in store with id: %d',
                        $this->getEntityLogName(),
                        $entityId,
                        $itemName,
                        $attributeHasChanges ? 'has changes' : 'is unchanged',
                        $storeId
                    )
                );

                $hasChanges = $hasChanges || $attributeHasChanges;

                if (! in_array($elementNumber, $this->getTransformedImportedElementNumbers())) {
                    $this->addTransformedImportedElementNumbers($elementNumber);
                }
            } catch (Exception $exception) {
                $this->addTransformedInvalidElementReason(
                    $elementNumber,
                    sprintf(
                        'Could not process associated item of %s with id: %d with name: %s because: %s',
                        $this->getEntityLogName(),
                        $entityId,
                        $itemName,
                        $exception->getMessage()
                    )
                );

                $this->logging->error($exception);
            }
        }

        $isChanged = in_array($elementNumber, $this->getTransformedChangedElementNumbers());

        if ($hasChanges) {
            if (! $isChanged) {
                $this->addTransformedChangedElementNumbers($elementNumber);
            }

            $this->removeTransformedUnchangedElementNumbers($elementNumber);
        } elseif (! $isChanged && ! in_array($elementNumber, $this->getTransformedUnchangedElementNumbers())) {
            $this->addTransformedUnchangedElementNumber($elementNumber);
        }
    }

    /**
     * Returns the values of the entity table of a new entity of the element, without the element key.
     *
     * @param array $element
     * @phpstan-param array<string, mixed> $element
     *
     * @return array
     * @phpstan-return array<string, mixed>
     */
    abstract protected function getCreateEntityData(array $element): array;

    /**
     * Collects the entity IDs of the transformed elements.
     *
     * An ID is given in the element, created for an earlier element with the same key, or found by its key.
     *
     * @return void
     */
    private function determineTransformedDataEntityIds(): void
    {
        $transformedData = $this->getTransformedData();

        $transformedElementKeyValues = [];

        foreach ($transformedData as $transformedElementNumber => $transformedElement) {
            $transformedElementNumber = $this->variables->intValue($transformedElementNumber);
            $transformedElement = $this->toElement($transformedElement);

            if ($this->isSkippedElement($transformedElementNumber)) {
                continue;
            }

            if (array_key_exists('entity_id', $transformedElement)) {
                $this->transformedDataEntityIds[$transformedElementNumber] =
                    $this->variables->intValue($transformedElement['entity_id']);
            } else {
                $elementKey = $this->getTransformedElementKey($transformedElement);

                if (array_key_exists($elementKey, $transformedElement)) {
                    $transformedElementKeyValues[$transformedElementNumber] = $transformedElement[$elementKey];
                }
            }
        }

        $entityIdsInElementsCount = count($this->transformedDataEntityIds);

        $this->logging->debug(
            sprintf('Found %d entity id(s) directly in transformed elements', $entityIdsInElementsCount)
        );

        $createdEntityIds = $this->getCreatedEntityIds();

        foreach ($transformedData as $transformedElementNumber => $transformedElement) {
            $elementKeyValue = $this->getElementKeyValue($this->toElement($transformedElement));

            if ($elementKeyValue !== null && array_key_exists($elementKeyValue, $createdEntityIds)) {
                $this->transformedDataEntityIds[$this->variables->intValue($transformedElementNumber)] =
                    $createdEntityIds[$elementKeyValue];
            }
        }

        $this->logging->debug(
            sprintf(
                'Found %d entity id(s) previously created',
                count($this->transformedDataEntityIds) - $entityIdsInElementsCount
            )
        );

        $this->logging->debug(
            sprintf(
                'Searching entity ids for %d unknown entries found in transformed elements',
                count($transformedElementKeyValues)
            )
        );

        $foundEntityIds = $this->findTransformedDataEntityIds($transformedElementKeyValues);

        foreach ($foundEntityIds as $elementNumber => $entityId) {
            $this->transformedDataEntityIds[$this->variables->intValue($elementNumber)] =
                $this->variables->intValue($entityId);
        }

        $this->logging->debug(
            sprintf(
                'Found %d entity id(s) in transformed elements and searching with element key',
                count($this->transformedDataEntityIds)
            )
        );
    }

    /**
     * Returns the key of the transformed element that identifies its entity, for example "sku".
     *
     * @param array $transformedElement
     * @phpstan-param array<string, mixed> $transformedElement
     *
     * @return string
     */
    abstract protected function getTransformedElementKey(array $transformedElement): string;

    /**
     * Returns the IDs of the existing entities with the key values, by element number.
     *
     * @param array $transformedElementKeys
     * @phpstan-param array<int, mixed> $transformedElementKeys
     *
     * @return array
     * @phpstan-return array<int|string, int|string>
     */
    abstract protected function findTransformedDataEntityIds(array $transformedElementKeys): array;

    /**
     * Collects the store IDs of the transformed elements that are not invalid or imported.
     *
     * @return void
     */
    protected function determineStoreIds(): void
    {
        foreach ($this->getTransformedData() as $elementNumber => $element) {
            $elementNumber = $this->variables->intValue($elementNumber);

            if (in_array($elementNumber, $this->getTransformedInvalidElementNumbers())
                || in_array($elementNumber, $this->getTransformedImportedElementNumbers())) {
                continue;
            }

            $this->storeIds[$elementNumber] =
                $this->variables->intValue($this->toElement($element)['store_id'] ?? Store::DEFAULT_STORE_ID);
        }
    }

    /**
     * Collects a row of the table for the element.
     *
     * @param int $elementNumber
     * @param string $table
     * @param array $data
     * @phpstan-param array<string, mixed> $data
     *
     * @return void
     */
    protected function addCreateTableData(int $elementNumber, string $table, array $data): void
    {
        $this->createTableData[$table][$elementNumber] = $data;
    }

    /**
     * Inserts the collected rows and records the IDs of the created entities.
     *
     * @param AdapterInterface $dbAdapter
     * @param string $createTableName
     * @param array $importChunk
     * @phpstan-param array<int, array<string, mixed>> $importChunk
     *
     * @return void
     * @throws Exception
     */
    protected function saveCreateTableData(
        AdapterInterface $dbAdapter,
        string $createTableName,
        array $importChunk
    ): void {
        $createdIds = $this->databaseHelper->saveCreateTableData($dbAdapter, $this->createTableData, $this->isTest());

        $createdTableIds = $createdIds[$createTableName] ?? [];

        if (! is_array($createdTableIds)) {
            return;
        }

        foreach ($createdTableIds as $elementNumber => $entityId) {
            $elementNumber = $this->variables->intValue($elementNumber);
            $elementKeyValue = $this->getElementKeyValue($importChunk[$elementNumber] ?? []);
            $entityId = $this->variables->intValue($entityId);

            $this->logging->info(sprintf('Created entity with key: %s has id: %d', $elementKeyValue, $entityId));

            $this->transformedDataEntityIds[$elementNumber] = $entityId;

            if ($elementKeyValue !== null) {
                $this->addCreatedEntityId($elementKeyValue, $entityId);
            }
        }
    }

    /**
     * Collects the changed values of the element and marks the element as changed or unchanged.
     *
     * Created and changed entities get the current date in the create and update date attributes.
     *
     * @param AdapterInterface $dbAdapter
     * @param string $entityTypeCode
     * @param int $entityId
     * @param int $storeId
     * @param int $elementNumber
     * @param array $element
     * @phpstan-param array<string, mixed> $element
     * @param array $currentAttributeValues
     * @phpstan-param array<string, mixed> $currentAttributeValues
     * @param array $currentAdminAttributeValues
     * @phpstan-param array<string, mixed> $currentAdminAttributeValues
     *
     * @return void
     * @throws Exception
     */
    protected function prepareElement(
        AdapterInterface $dbAdapter,
        string $entityTypeCode,
        int $entityId,
        int $storeId,
        int $elementNumber,
        array $element,
        array $currentAttributeValues,
        array $currentAdminAttributeValues
    ): void {
        $isCreatedEntity = in_array($elementNumber, $this->transformedCreateElementNumbers);

        $changeAttributeCodes = [];

        foreach ($element as $attributeCode => $attributeValue) {
            if (in_array($attributeCode, $this->ignoreAttributes) || $attributeValue instanceof Associated) {
                continue;
            }

            if ($attributeValue instanceof SpecialAttribute) {
                $attributeHasChanges = $attributeValue->update(
                    $dbAdapter,
                    $entityId,
                    $storeId,
                    $currentAttributeValues,
                    $currentAdminAttributeValues,
                    $isCreatedEntity
                );
            } else {
                $attributeHasChanges = $this->prepareAttribute(
                    $entityTypeCode,
                    $entityId,
                    $storeId,
                    $attributeCode,
                    $attributeValue,
                    $currentAttributeValues,
                    $currentAdminAttributeValues,
                    $isCreatedEntity
                );
            }

            if ($attributeHasChanges) {
                $changeAttributeCodes[] = $attributeCode;
            }
        }

        if (count($changeAttributeCodes) === 0) {
            $this->logging->debug(
                sprintf(
                    '%s with id: %d has unchanged attribute values in store with id: %d',
                    $this->getEntityLogName(),
                    $entityId,
                    $storeId
                )
            );

            $this->addTransformedUnchangedElementNumber($elementNumber);

            return;
        }

        $this->addTransformedChangedElementNumbers($elementNumber);

        $this->logging->info(
            sprintf(
                '%s with id: %d has changed values in attributes: %s in store with id: %d',
                ucfirst($this->getEntityLogName()),
                $entityId,
                implode(', ', $changeAttributeCodes),
                $storeId
            )
        );

        $date = new DateTime();

        if ($isCreatedEntity) {
            foreach ($this->createDateAttributeCodes as $createDateAttributeCode) {
                $this->createAttributeUpdates(
                    $entityTypeCode,
                    $entityId,
                    $storeId,
                    $createDateAttributeCode,
                    $date,
                    false,
                    true,
                    false
                );
            }
        }

        foreach ($this->updateDateAttributeCodes as $updateDateAttributeCode) {
            $this->createAttributeUpdates(
                $entityTypeCode,
                $entityId,
                $storeId,
                $updateDateAttributeCode,
                $date,
                ! $isCreatedEntity,
                true,
                false
            );
        }
    }

    /**
     * Collects the updates of the value of the attribute if it differs from the current value.
     *
     * Returns whether it does. Values of attributes with options are replaced by the IDs of the options.
     *
     * @param string $entityTypeCode
     * @param int $entityId
     * @param int $storeId
     * @param string $attributeCode
     * @param mixed $attributeValue
     * @param array $currentAttributeValues
     * @phpstan-param array<string, mixed> $currentAttributeValues
     * @param array $currentAdminAttributeValues
     * @phpstan-param array<string, mixed> $currentAdminAttributeValues
     * @param bool $isCreatedEntity
     * @param int|null $forceStoreId the store to save the value in instead of the store of the element
     *
     * @return bool
     * @throws Exception
     */
    public function prepareAttribute(
        string $entityTypeCode,
        int $entityId,
        int $storeId,
        string $attributeCode,
        mixed $attributeValue,
        array $currentAttributeValues,
        array $currentAdminAttributeValues,
        bool $isCreatedEntity,
        ?int $forceStoreId = null
    ): bool {
        $targetStoreId = $forceStoreId ?? $storeId;
        $isSpecialAttribute = array_key_exists($attributeCode, $this->specialAttributes);
        $attribute = $isSpecialAttribute ? null : $this->attributeHelper->getAttribute($entityTypeCode, $attributeCode);

        if ($attribute === null) {
            $preparedAttributeValue = $this->prepareSpecialAttributeValueForSave($attributeCode, $attributeValue);
        } else {
            if ($attribute->usesSource() && ! $this->variables->isEmpty($attributeValue)) {
                $attributeValue = $this->getOptionValue($entityTypeCode, $attribute, $targetStoreId, $attributeValue);
            }

            $preparedAttributeValue = $this->importHelper->prepareValueForSave($attribute, $attributeValue);
        }

        $isScopeGlobal = $attribute !== null && $this->isAttributeScopeGlobal($attribute);
        $hasCurrentAdminValue = ($currentAdminAttributeValues[$attributeCode] ?? null) !== null;
        $addEmptyAdminValue = $targetStoreId !== Store::DEFAULT_STORE_ID
            && ($attribute === null || (! $this->isRequired($attribute) && ! $isScopeGlobal));

        if (array_key_exists($attributeCode, $currentAttributeValues)) {
            if ($attribute === null) {
                $preparedCurrentAttributeValue = $this->prepareSpecialAttributeValueForSave(
                    $attributeCode,
                    $currentAttributeValues[$attributeCode]
                );

                $addAdminValue = ! $hasCurrentAdminValue;
            } else {
                $preparedCurrentAttributeValue =
                    $this->importHelper->prepareValueForSave($attribute, $currentAttributeValues[$attributeCode]);

                if ($isScopeGlobal) {
                    $addAdminValue = ! array_key_exists($attributeCode, $currentAdminAttributeValues)
                        || $preparedAttributeValue !== $this->importHelper->prepareValueForSave(
                            $attribute,
                            $currentAdminAttributeValues[$attributeCode]
                        );
                } else {
                    $addAdminValue = ! $hasCurrentAdminValue;
                }
            }

            if ($this->isSameValue($preparedAttributeValue, $preparedCurrentAttributeValue)) {
                $this->logging->debug(
                    sprintf(
                        'Attribute with name: %s has unchanged value: %s',
                        $attributeCode,
                        $this->formatLogValue($preparedAttributeValue)
                    )
                );

                return false;
            }

            $this->logging->debug(
                sprintf(
                    'Attribute with name: %s has changed value to: %s from: %s',
                    $attributeCode,
                    $this->formatLogValue($preparedAttributeValue),
                    $this->formatLogValue($preparedCurrentAttributeValue)
                )
            );
        } else {
            $addAdminValue = ! $hasCurrentAdminValue || $isScopeGlobal;

            $this->logging->debug(
                sprintf(
                    'Attribute with name: %s has new value: %s',
                    $attributeCode,
                    $this->formatLogValue($preparedAttributeValue)
                )
            );
        }

        if (in_array($attributeCode, $this->forceAdminEavAttributeValues) && ! $hasCurrentAdminValue) {
            $addAdminValue = true;
            $addEmptyAdminValue = false;
        }

        if (in_array($attributeCode, $this->prohibitAdminEavAttributeValues)) {
            $addAdminValue = false;
        }

        return $this->createAttributeUpdates(
            $entityTypeCode,
            $entityId,
            $targetStoreId,
            $attributeCode,
            $attributeValue,
            ! $isCreatedEntity,
            $addAdminValue || $isCreatedEntity,
            $addEmptyAdminValue
        );
    }

    /**
     * Returns the IDs of the options with the values (separated by commas for multiselect attributes).
     *
     * Values without an option are kept, unless the attribute saves integers and the value is not numeric.
     *
     * @param string $entityTypeCode
     * @param EavAttribute $attribute
     * @param int $storeId
     * @param mixed $attributeValue
     *
     * @return mixed
     * @throws Exception
     */
    private function getOptionValue(
        string $entityTypeCode,
        EavAttribute $attribute,
        int $storeId,
        mixed $attributeValue
    ): mixed {
        $attributeCode = $this->variables->stringValue($attribute->getAttributeCode());
        $attributeType = ImportExport::getAttributeType($attribute);
        $isMultiSelect = $attributeType === 'multiselect';
        $values = $isMultiSelect ?
            array_map('trim', explode(',', $this->variables->stringValue($attributeValue))) :
            [$this->variables->stringValue($attributeValue)];

        $optionIds = [];

        foreach ($values as $value) {
            $optionId = $this->attributeHelper->getAttributeOptionId($entityTypeCode, $attributeCode, $storeId, $value);

            if ($optionId === null || ($isMultiSelect && $optionId === 0)) {
                if (! is_numeric($value) && $this->getBackendType($attribute) === 'int') {
                    throw new Exception(
                        sprintf('Invalid value "%s" in attribute with code: %s', $value, $attributeCode)
                    );
                }

                $optionIds[] = $isMultiSelect ? $value : $attributeValue;
            } else {
                $optionIds[] = $optionId;
            }
        }

        return $isMultiSelect ? implode(',', $optionIds) : $optionIds[0];
    }

    /**
     * Returns the type of the table the values of the attribute are saved in.
     *
     * @param EavAttribute $attribute
     *
     * @return string
     */
    private function getBackendType(EavAttribute $attribute): string
    {
        return ImportExport::getAttributeType($attribute) === 'multiselect' ? 'varchar' :
            $this->variables->stringValue($attribute->getBackendType());
    }

    /**
     * Returns whether the attribute is required.
     *
     * @param EavAttribute $attribute
     *
     * @return bool
     */
    private function isRequired(EavAttribute $attribute): bool
    {
        $isRequired = $attribute->getData('is_required');

        return is_scalar($isRequired) && $this->variables->intValue($isRequired) === 1;
    }

    /**
     * Returns whether the prepared values are the same: identical, or objects of the same class with the same text.
     *
     * @param mixed $value
     * @param mixed $currentValue
     *
     * @return bool
     */
    private function isSameValue(mixed $value, mixed $currentValue): bool
    {
        if ($value === $currentValue) {
            return true;
        }

        return is_object($value) && is_object($currentValue) && $value::class === $currentValue::class
            && method_exists($value, '__toString') && method_exists($currentValue, '__toString')
            && $value->__toString() === $currentValue->__toString();
    }

    /**
     * Returns the value as text for the log.
     *
     * @param mixed $value
     *
     * @return string
     */
    private function formatLogValue(mixed $value): string
    {
        if ($value === null || is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
            return $this->variables->stringValue($value);
        }

        return (string)json_encode($value);
    }

    /**
     * Returns whether the values of the attribute are global.
     *
     * @param EavAttribute $attribute
     *
     * @return bool
     */
    abstract protected function isAttributeScopeGlobal(EavAttribute $attribute): bool;

    /**
     * Collects the updates of the attribute value in the store, and in the admin store if requested. Returns true.
     *
     * @param string $entityTypeCode
     * @param int $entityId
     * @param int $storeId
     * @param string $attributeCode
     * @param mixed $attributeValue
     * @param bool $addStoreValue
     * @param bool $addAdminValue
     * @param bool $addEmptyAdminValue
     *
     * @return bool
     * @throws Exception
     */
    protected function createAttributeUpdates(
        string $entityTypeCode,
        int $entityId,
        int $storeId,
        string $attributeCode,
        mixed $attributeValue,
        bool $addStoreValue,
        bool $addAdminValue,
        bool $addEmptyAdminValue
    ): bool {
        $updates = $this->importHelper->createAttributeUpdates(
            $entityTypeCode,
            $this->getIgnoreAttributes(),
            $this->getSpecialAttributes(),
            $this->getDefaultAdminEavAttributeValues(),
            $entityId,
            $storeId,
            $attributeCode,
            $attributeValue,
            $addStoreValue,
            $addAdminValue,
            $addEmptyAdminValue
        );

        foreach ($updates as $update) {
            $table = $update['table'];
            $tableData = $update['data'];

            if ($table === null) {
                throw new Exception(sprintf('Found no table for the values of attribute: %s', $attributeCode));
            }

            if ($update['type'] === 'single') {
                $this->addSingleAttributeTableData($table, $attributeCode, $tableData);
            } else {
                $this->addEavAttributeTableData($table, $tableData);
            }
        }

        return true;
    }

    /**
     * Returns the value of the special attribute as it is saved.
     *
     * @param string $attributeCode
     * @param mixed $attributeValue
     *
     * @return mixed
     */
    private function prepareSpecialAttributeValueForSave(string $attributeCode, mixed $attributeValue): mixed
    {
        return $this->importHelper->prepareSpecialAttributeValueForSave(
            $this->specialAttributes,
            $attributeCode,
            $attributeValue
        );
    }

    /**
     * Collects a row of the entity table for the attribute.
     *
     * @param string $table
     * @param string $attributeCode
     * @param array $data
     * @phpstan-param array<string, mixed> $data
     *
     * @return void
     */
    protected function addSingleAttributeTableData(string $table, string $attributeCode, array $data): void
    {
        $this->singleAttributeTableData[$table][$attributeCode][] = $data;
    }

    /**
     * Collects a row of an attribute value table.
     *
     * @param string $table
     * @param array $data
     * @phpstan-param array<string, mixed> $data
     *
     * @return void
     */
    protected function addEavAttributeTableData(string $table, array $data): void
    {
        $this->eavAttributeTableData[$table][] = $data;
    }

    /**
     * Saves the collected attribute values, unless in test mode.
     *
     * @param AdapterInterface $dbAdapter
     *
     * @return void
     * @throws Exception
     */
    protected function saveUpdateTableData(AdapterInterface $dbAdapter): void
    {
        $this->databaseHelper->saveUpdateTableData(
            $dbAdapter,
            $this->singleAttributeTableData,
            $this->eavAttributeTableData,
            $this->isTest()
        );
    }

    /**
     * Records the changed entity in the store.
     *
     * @param int $entityId
     * @param int $storeId
     *
     * @return void
     */
    protected function addImportedEntity(int $entityId, int $storeId): void
    {
        $this->importedEntities[] = [
            'entity_id' => $entityId,
            'store_id'  => $storeId
        ];
    }

    /**
     * Returns the changed entities with their store.
     *
     * @return array
     * @phpstan-return list<array{entity_id: int, store_id: int}>
     */
    public function getImportedEntities(): array
    {
        return $this->importedEntities;
    }

    /**
     * Returns the IDs of the created entities by element key value.
     *
     * @return array
     * @phpstan-return array<string, int>
     */
    public function getCreatedEntityIds(): array
    {
        return $this->createdEntityIds;
    }

    /**
     * Records the ID of the entity created for the element key value.
     *
     * @param string $elementKey
     * @param int $entityId
     *
     * @return void
     */
    protected function addCreatedEntityId(string $elementKey, int $entityId): void
    {
        $this->createdEntityIds[$elementKey] = $entityId;
    }

    /**
     * Returns the classes of the Prepare models by associated item.
     *
     * @return array
     * @phpstan-return array<string, string>
     */
    public function getAssociatedItemPrepareModels(): array
    {
        return $this->associatedItemPrepareModels;
    }

    /**
     * Prepares the associated item with the Prepare model of the class.
     *
     * @param string $key
     * @param string $model
     *
     * @return void
     */
    public function addAssociatedItemPrepareModel(string $key, string $model): void
    {
        $this->associatedItemPrepareModels[$key] = $model;
    }

    /**
     * Returns the entity IDs of the source elements, by element number.
     *
     * @return array
     * @phpstan-return array<int|string, mixed>
     */
    public function getSourceDataEntityIds(): array
    {
        return $this->sourceDataEntityIds;
    }

    /**
     * Returns the entity IDs of the transformed elements, by element number.
     *
     * @return array
     * @phpstan-return array<int, int>
     */
    public function getTransformedDataEntityIds(): array
    {
        return $this->transformedDataEntityIds;
    }

    /**
     * Returns the collection of the entities, to read their current values.
     *
     * @return AbstractCollection
     */
    abstract protected function getEntityCollection(): AbstractCollection;

    /**
     * Returns the transformed element as array with attribute codes as keys.
     *
     * @param mixed $element
     *
     * @return array
     * @phpstan-return array<string, mixed>
     */
    private function toElement(mixed $element): array
    {
        $result = [];

        if (is_array($element)) {
            foreach ($element as $key => $value) {
                $result[(string)$key] = $value;
            }
        }

        return $result;
    }
}
