<?php

declare(strict_types=1);

namespace Infrangible\ImportEntity\Model;

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
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\Store;
use Psr\Log\LoggerInterface;
use Zend_Db_Select_Exception;

/**
 * @author      Andreas Knollmann
 * @copyright   Copyright (c) 2014-2024 Softwareentwicklung Andreas Knollmann
 * @license     http://www.opensource.org/licenses/mit-license.php MIT
 */
abstract class Entity
    extends Import
{
    /** @var Stores */
    protected $storeHelper;

    /** @var Attribute */
    protected $attributeHelper;

    /** @var EntityType */
    protected $entityTypeHelper;

    /** @var Export */
    protected $exportHelper;

    /** @var array */
    private $storeIds = [];

    /** @var array */
    private $entityIds = [];

    /** @var array */
    private $prohibitAdminEavAttributeValues = [];

    /** @var array */
    private $defaultAdminEavAttributeValues = [];

    /** @var bool */
    private $ignoreUnknownAttributes = true;

    /** @var bool */
    private $unknownAttributesWarnOnly = false;

    /** @var bool */
    private $addUnknownAttributeOptionValues = false;

    /** @var bool */
    private $updateAdminStore = false;

    /** @var int */
    private $chunkSize = 1000;

    /** @var array */
    private $transformedCreateElementNumbers = [];

    /** @var array */
    private $createTableData = [];

    /** @var array */
    private $singleAttributeTableData = [];

    /** @var array */
    private $eavAttributeTableData = [];

    /** @var array */
    private $createDateAttributeCodes = [];

    /** @var array */
    private $updateDateAttributeCodes = [];

    /** @var array */
    private $replaceAttributeModels = [];

    /** @var array */
    private $specialAttributeModel = [];

    /** @var array */
    private $specialAttributes = [
        'entity_id' => 'int',
        'store_id'  => 'int'
    ];

    /** @var array */
    private $ignoreAttributes = ['entity_id', 'website_id'];

    /** @var array */
    private $forceAdminEavAttributeValues = [];

    /** @var array */
    private $importedEntities = [];

    /** @var array */
    private $createdEntityIds = [];

    /** @var bool */
    private $addElementKeyToCreateEntityData = true;

    /** @var array */
    private $associatedItemPrepareModels = [];

    /**
     * @param Arrays          $arrays
     * @param Variables       $variables
     * @param Database        $databaseHelper
     * @param Instances       $instanceHelper
     * @param Data            $importHelper
     * @param Stores          $storeHelper
     * @param Attribute       $attributeHelper
     * @param EntityType      $entityTypeHelper
     * @param Export          $exportHelper
     * @param LoggerInterface $logging
     */
    public function __construct(
        Arrays $arrays,
        Variables $variables,
        Database $databaseHelper,
        Instances $instanceHelper,
        Data $importHelper,
        Stores $storeHelper,
        Attribute $attributeHelper,
        EntityType $entityTypeHelper,
        Export $exportHelper,
        LoggerInterface $logging
    ) {
        parent::__construct($arrays, $variables, $databaseHelper, $instanceHelper, $importHelper, $logging);

        $this->storeHelper = $storeHelper;
        $this->attributeHelper = $attributeHelper;
        $this->entityTypeHelper = $entityTypeHelper;
        $this->exportHelper = $exportHelper;
    }

    /**
     * @return void
     * @throws Exception
     */
    protected function runImport()
    {
        parent::runImport();

        foreach ($this->getTransformedChangedElementNumbers() as $elementNumber) {
            $this->addImportedEntity($this->entityIds[$elementNumber], $this->storeIds[$elementNumber]);
        }
    }

    /**
     * @return string
     */
    abstract protected function getEntityTypeCode(): string;

    /**
     * @return int
     */
    public function getChunkSize(): int
    {
        return $this->chunkSize;
    }

    /**
     * @param int $chunkSize
     */
    public function setChunkSize(int $chunkSize)
    {
        $this->chunkSize = $chunkSize;
    }

    /**
     * @param bool $ignoreUnknownAttributes
     */
    public function setIgnoreUnknownAttributes(bool $ignoreUnknownAttributes = true)
    {
        $this->ignoreUnknownAttributes = $ignoreUnknownAttributes;
    }

    /**
     * @param bool $unknownAttributesWarnOnly
     */
    public function setUnknownAttributesWarnOnly(bool $unknownAttributesWarnOnly = true)
    {
        $this->unknownAttributesWarnOnly = $unknownAttributesWarnOnly;
    }

    /**
     * @return bool
     */
    public function isUnknownAttributesWarnOnly(): bool
    {
        return $this->unknownAttributesWarnOnly;
    }

    /**
     * @return array
     */
    public function getReplaceAttributeModels(): array
    {
        return $this->replaceAttributeModels;
    }

    /**
     * @param string $key
     * @param string $model
     *
     * @return void
     */
    public function addReplaceAttributeModel(string $key, string $model)
    {
        $this->replaceAttributeModels[$key] = $model;
    }

    /**
     * @param string $key
     * @param string $model
     *
     * @return void
     */
    public function addSpecialAttributeModel(string $key, string $model)
    {
        $this->specialAttributeModel[$key] = $model;
    }

    /**
     * @param string $key
     * @param string $type
     */
    public function addSpecialAttribute(string $key, string $type)
    {
        $this->specialAttributes[$key] = $type;
    }

    /**
     * @return bool
     */
    public function isAddUnknownAttributeOptionValues(): bool
    {
        return $this->addUnknownAttributeOptionValues === true;
    }

    /**
     * @param bool $addUnknownAttributeOptionValues
     *
     * @return  void
     */
    public function setAddUnknownAttributeOptionValues(bool $addUnknownAttributeOptionValues = true)
    {
        $this->addUnknownAttributeOptionValues = $addUnknownAttributeOptionValues;
    }

    /**
     * @param string $attributeCode
     *
     * @return void
     */
    public function addForceAdminEavAttributeValue(string $attributeCode)
    {
        $this->forceAdminEavAttributeValues[] = $attributeCode;
    }

    /**
     * @param string $attributeCode
     *
     * @return void
     */
    public function addProhibitAdminEavAttributeValue(string $attributeCode)
    {
        $this->prohibitAdminEavAttributeValues[] = $attributeCode;
    }

    /**
     * @param string $attributeCode
     * @param mixed  $value
     *
     * @return void
     */
    public function addDefaultAdminEavAttributeValue(string $attributeCode, $value)
    {
        $this->defaultAdminEavAttributeValues[$attributeCode] = $value;
    }

    /**
     * @param string $attributeCode
     *
     * @return void
     */
    public function addCreateDateAttributeCode(string $attributeCode)
    {
        $this->createDateAttributeCodes[] = $attributeCode;
    }

    /**
     * @param string $attributeCode
     *
     * @return void
     */
    public function addUpdateDateAttributeCode(string $attributeCode)
    {
        $this->updateDateAttributeCodes[] = $attributeCode;
    }

    /**
     * @param bool $updateAdminStore
     */
    public function setUpdateAdminStore(bool $updateAdminStore = true)
    {
        $this->updateAdminStore = $updateAdminStore;
    }

    /**
     * @return array
     */
    public function getSpecialAttributes(): array
    {
        return $this->specialAttributes;
    }

    /**
     * @return array
     */
    protected function getIgnoreAttributes(): array
    {
        return $this->ignoreAttributes;
    }

    /**
     * @return array
     */
    protected function getDefaultAdminEavAttributeValues(): array
    {
        return $this->defaultAdminEavAttributeValues;
    }

    /**
     * @param string $attributeCode
     */
    protected function addIgnoreAttribute(string $attributeCode)
    {
        $this->ignoreAttributes[] = $attributeCode;
    }

    /**
     * @param bool $addElementKeyToCreateEntityData
     */
    public function setAddElementKeyToCreateEntityData(bool $addElementKeyToCreateEntityData)
    {
        $this->addElementKeyToCreateEntityData = $addElementKeyToCreateEntityData;
    }

    /**
     * @return string
     */
    abstract protected function getEntityLogName(): string;

    /**
     * @param int    $elementNumber
     * @param array  $element
     * @param string $attributeCode
     *
     * @return array
     */
    protected function validateAttribute(int $elementNumber, array $element, string $attributeCode): array
    {
        $attributeValue = $element[$attributeCode];

        if (!empty($attributeValue) || is_numeric($attributeValue)) {
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

        if (is_null($attribute) && $this->ignoreUnknownAttributes) {
            unset($element[$attributeCode]);

            return $element;
        }

        if (is_null($attribute)) {
            $this->addTransformedInvalidElementReason($elementNumber, sprintf('Unknown attribute: %s', $attributeCode));
        } else {
            try {
                $attributeType = \Magento\ImportExport\Model\Import::getAttributeType($attribute);

                if ($attributeType !== 'varchar' && $attributeType !== 'text') {
                    $element[$attributeCode] = null;
                }
            } catch (Exception $exception) {
                $this->logging->error($exception);
            }
        }

        return $element;
    }

    /**
     * @param AdapterInterface $dbAdapter
     *
     * @return bool
     */
    protected function validateTransformedData(AdapterInterface $dbAdapter): bool
    {
        foreach ($this->getTransformedData() as $elementNumber => $element) {
            $element = $this->validateTransformedDataStore($elementNumber, $element);

            $attributeCodes = array_keys($element);

            foreach ($element as $attributeCode => $attributeValue) {
                if (in_array($attributeCode, $this->ignoreAttributes)) {
                    $attributeCodes = array_diff($attributeCodes, [$attributeCode]);
                }
            }

            foreach ($attributeCodes as $attributeCode) {
                if (array_key_exists($attributeCode, $this->replaceAttributeModels)) {
                    /** @var Replace $replaceItem */
                    $replaceItem = $this->instanceHelper->getInstance(
                        $this->replaceAttributeModels[$attributeCode], ['importer' => $this]
                    );

                    if ($replaceItem !== null) {
                        try {
                            $replaceItem->prepare($element[$attributeCode]);

                            $storeId = $element['store_id'];

                            $replaceItem->validate($dbAdapter, $storeId, $element);

                            $attributeValidationResult = $replaceItem->isValid();

                            if ($attributeValidationResult) {
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

                            unset($element[$attributeCode]);
                        } catch (Exception $exception) {
                            $this->addTransformedInvalidElementReason(
                                $elementNumber,
                                sprintf(
                                    'Invalid data in replace item: %s because: %s',
                                    $attributeCode,
                                    $exception->getMessage()
                                )
                            );

                            unset($element[$attributeCode]);
                        }
                    } else {
                        $this->addTransformedInvalidElementReason(
                            $elementNumber,
                            sprintf(
                                'Invalid attribute model: %s for attribute: %s',
                                $this->replaceAttributeModels[$attributeCode],
                                $attributeCode
                            )
                        );

                        unset($element[$attributeCode]);
                    }

                    $attributeCodes = array_diff($attributeCodes, [$attributeCode]);
                }
            }

            $this->prepareAssociatedModels($dbAdapter, $elementNumber, $element, $attributeCodes);

            foreach ($attributeCodes as $attributeCode) {
                if (array_key_exists($attributeCode, $this->specialAttributeModel)) {
                    /** @var SpecialAttribute $specialAttribute */
                    $specialAttribute = $this->instanceHelper->getInstance(
                        $this->specialAttributeModel[$attributeCode], ['importer' => $this]
                    );

                    if ($specialAttribute !== null) {
                        $specialAttribute->prepare($attributeCode, $element[$attributeCode], $element);

                        $element[$attributeCode] = $specialAttribute;
                    } else {
                        $this->addTransformedInvalidElementReason(
                            $elementNumber,
                            sprintf(
                                'Invalid attribute model: %s for attribute: %s',
                                $this->specialAttributeModel[$attributeCode],
                                $attributeCode
                            )
                        );

                        unset($element[$attributeCode]);
                    }

                    $attributeCodes = array_diff($attributeCodes, [$attributeCode]);
                }
            }

            foreach ($attributeCodes as $attributeCode) {
                $element = $this->validateAttribute($elementNumber, $element, $attributeCode);
            }

            $this->addTransformedData($element, $elementNumber);
        }

        $transformedValidationResult = true;

        foreach ($this->getTransformedData() as $elementNumber => $element) {
            $storeId = $element['store_id'];

            try {
                $this->storeHelper->getStore($storeId);

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
            } catch (NoSuchEntityException $exception) {
                $this->addTransformedInvalidElementReason($elementNumber, $exception->getMessage());

                $elementValidationResult = false;
            }

            $transformedValidationResult = $transformedValidationResult && $elementValidationResult;
        }

        return $transformedValidationResult;
    }

    /**
     * @param AdapterInterface $dbAdapter
     * @param int              $elementNumber
     * @param array            $element
     * @param array            $attributeCodes
     */
    protected function prepareAssociatedModels(
        AdapterInterface $dbAdapter,
        int $elementNumber,
        array &$element,
        array &$attributeCodes
    ) {
        $associatedItemModels = $this->getAssociatedItemModels();

        foreach ($attributeCodes as $attributeCode) {
            if (array_key_exists($attributeCode, $associatedItemModels)) {
                $associatedItem = $this->prepareAssociatedModel($attributeCode);

                if ($associatedItem !== null) {
                    $associatedItemData = $element[$attributeCode];

                    if (!is_array($associatedItemData)) {
                        /** @noinspection PhpToStringImplementationInspection */
                        $associatedItemData = explode('|', (string) $associatedItem);
                    }

                    $associatedItem->prepare($dbAdapter, $associatedItemData, $element);

                    $element[$attributeCode] = $associatedItem;
                } else {
                    $this->addTransformedInvalidElementReason(
                        $elementNumber,
                        sprintf(
                            'Invalid attribute model: %s for attribute: %s',
                            $associatedItemModels[$attributeCode],
                            $attributeCode
                        )
                    );

                    unset($element[$attributeCode]);
                }

                $attributeCodes = array_diff($attributeCodes, [$attributeCode]);
            }
        }
    }

    /**
     * @param string $attributeCode
     *
     * @return Associated|null
     */
    protected function prepareAssociatedModel(string $attributeCode): ?Associated
    {
        $associatedItemModels = $this->getAssociatedItemModels();

        /** @var Associated $associatedItem */
        $associatedItem =
            $this->instanceHelper->getInstance($associatedItemModels[$attributeCode], ['importer' => $this]);

        return $associatedItem;
    }

    /**
     * @param int   $elementNumber
     * @param array $element
     *
     * @return array
     */
    protected function validateTransformedDataStore(int $elementNumber, array $element): array
    {
        if (!array_key_exists('store_id', $element)) {
            if (array_key_exists('website', $element) && is_array($element['website'])) {
                if (array_key_exists('id', $element['website'])) {
                    $websiteId = $element['website']['id'];

                    try {
                        $defaultStore = $this->storeHelper->getDefaultStore($websiteId);

                        if (!$this->variableHelper->isEmpty($defaultStore->getId())) {
                            $element['store_id'] = $defaultStore->getId();
                        } else {
                            $this->addTransformedInvalidElementReason(
                                $elementNumber,
                                sprintf('Invalid website with id: %s', $websiteId)
                            );
                        }
                    } catch (Exception $exception) {
                        $this->addTransformedInvalidElementReason(
                            $elementNumber,
                            sprintf('Invalid website with id: %s', $websiteId)
                        );
                    }
                } elseif (array_key_exists('name', $element['website'])) {
                    $websiteName = $element['website']['name'];

                    try {
                        $defaultStore = $this->storeHelper->getDefaultStore($websiteName);

                        if (!$this->variableHelper->isEmpty($defaultStore->getId())) {
                            $element['store_id'] = $defaultStore->getId();
                        } else {
                            $this->addTransformedInvalidElementReason(
                                $elementNumber,
                                sprintf('Invalid website with code: %s', $websiteName)
                            );
                        }
                    } catch (Exception $exception) {
                        $this->addTransformedInvalidElementReason(
                            $elementNumber,
                            sprintf('Invalid website with code: %s', $websiteName)
                        );
                    }
                } else {
                    $this->addTransformedInvalidElementReason($elementNumber, 'Invalid website definition');
                }
            } elseif (array_key_exists('website_id', $element)) {
                $websiteId = $element['website_id'];

                try {
                    $defaultStore = $this->storeHelper->getDefaultStore($websiteId);

                    if (!$this->variableHelper->isEmpty($defaultStore->getId())) {
                        $element['store_id'] = $defaultStore->getId();
                    } else {
                        $this->addTransformedInvalidElementReason(
                            $elementNumber,
                            sprintf('Invalid website with id: %s', $websiteId)
                        );
                    }
                } catch (Exception $exception) {
                    $this->addTransformedInvalidElementReason(
                        $elementNumber,
                        sprintf('Invalid website with id: %s', $websiteId)
                    );
                }
            } else {
                $element['store_id'] = Store::DEFAULT_STORE_ID;
            }
        }

        return $element;
    }

    /**
     * @param AdapterInterface $dbAdapter
     * @param string           $entityTypeCode
     * @param int              $elementNumber
     * @param array            $element
     * @param int              $storeId
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
            if ($attributeValue instanceof Related) {
                try {
                    $attributeValue->validate($dbAdapter, $storeId, $element);

                    $attributeValidationResult = $attributeValue->isValid();

                    if (!$attributeValidationResult) {
                        if ($attributeValue instanceof Associated) {
                            $this->addTransformedInvalidElementReason(
                                $elementNumber,
                                sprintf(
                                    'Invalid data in associated item: %s because: %s',
                                    $attributeCode,
                                    $attributeValue->getInvalidReason()
                                )
                            );
                        } else {
                            $this->addTransformedInvalidElementReason(
                                $elementNumber,
                                sprintf(
                                    'Invalid attribute value for attribute: %s because: %s',
                                    $attributeCode,
                                    $attributeValue->getInvalidReason()
                                )
                            );
                        }
                    }
                } catch (Exception $exception) {
                    $attributeValidationResult = false;

                    if ($attributeValue instanceof Associated) {
                        $this->addTransformedInvalidElementReason(
                            $elementNumber,
                            sprintf(
                                'Invalid data in associated item: %s because: %s',
                                $attributeCode,
                                $exception->getMessage()
                            )
                        );
                    } else {
                        $this->addTransformedInvalidElementReason(
                            $elementNumber,
                            sprintf(
                                'Invalid attribute value for attribute: %s because: %s',
                                $attributeCode,
                                $exception->getMessage()
                            )
                        );
                    }

                    $this->logging->error($exception);
                }

                $validationResult = $validationResult && $attributeValidationResult;
            } else {
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
                    if ($exception->getCode() == Data::IGNORE_AND_REMOVE_ATTRIBUTE) {
                        unset($element[$attributeCode]);

                        $this->addTransformedData($element, $elementNumber);
                    } else {
                        $this->addTransformedInvalidElementReason($elementNumber, $exception->getMessage());

                        $validationResult = false;
                    }
                }
            }
        }

        $elementKey = $this->getElementKey($element);

        if (!array_key_exists('entity_id', $element) && !array_key_exists($elementKey, $element)) {
            $validationResult = false;

            $this->addTransformedInvalidElementReason($elementNumber, 'No entity id or element key');
        }

        return $validationResult;
    }

    /**
     * @param AdapterInterface $dbAdapter
     *
     * @return void
     * @throws Exception
     * @throws Zend_Db_Select_Exception
     */
    protected function importTransformedData(AdapterInterface $dbAdapter)
    {
        $this->determineEntityIds();

        foreach ($this->getTransformedData() as $elementNumber => $element) {
            if (in_array($elementNumber, $this->getTransformedInvalidElementNumbers())
                || in_array($elementNumber, $this->getTransformedCachedElementNumbers())
                || in_array($elementNumber, $this->getTransformedImportedElementNumbers())) {
                continue;
            }

            if (!isset($this->entityIds[$elementNumber])) {
                $this->transformedCreateElementNumbers[] = $elementNumber;
            } else {
                if ($this->updateAdminStore) {
                    $adminElement = $this->arrayHelper->arrayCopy($element);

                    $adminElement['store_id'] = 0;

                    $this->addTransformedData($adminElement);

                    $transformedData = $this->getTransformedData();

                    $this->entityIds[count($transformedData) - 1] = $this->entityIds[$elementNumber];
                }
            }
        }

        $this->determineStoreIds();

        $entityType = $this->entityTypeHelper->getEntityType($this->getEntityTypeCode());

        $createTableName = $this->entityTypeHelper->getEntityTypeTableByEntityType($entityType);

        $importData = [];

        foreach ($this->getTransformedData() as $elementNumber => $element) {
            if (in_array($elementNumber, $this->getTransformedInvalidElementNumbers())
                || in_array($elementNumber, $this->getTransformedCachedElementNumbers())
                || in_array($elementNumber, $this->getTransformedImportedElementNumbers())) {
                continue;
            }

            $storeId = $this->storeIds[$elementNumber];

            if (!array_key_exists($storeId, $importData)) {
                $importData[$storeId] = [];
            }

            $importData[$storeId][$elementNumber] = $element;
        }

        $this->logging->info(sprintf('Found %d store(s) in elements', count($importData)));

        foreach ($importData as $storeId => $storeImportData) {
            $importChunks = $this->getChunkSize() > 0 ? array_chunk($storeImportData, $this->getChunkSize(), true) :
                [$storeImportData];

            $this->logging->info(
                sprintf(
                    'Split data for store with id: %d in %d chunk(s)',
                    $storeId,
                    count($importChunks)
                )
            );

            foreach ($importChunks as $chunkCounter => $importChunk) {
                $dbAdapter->beginTransaction();

                $this->logging->info(
                    sprintf(
                        'Importing chunk: %d/%d containing %d element(s)',
                        $chunkCounter + 1,
                        count($importChunks),
                        count($importChunk)
                    )
                );

                $this->createTableData = [];
                $this->singleAttributeTableData = [];
                $this->eavAttributeTableData = [];

                $chunkAttributeCodes = [];
                $chunkEntityIds = [];

                $createdEntityIds = $this->getCreatedEntityIds();

                foreach ($importChunk as $elementNumber => $element) {
                    $key = array_search($elementNumber, $this->transformedCreateElementNumbers);

                    if ($key === false) {
                        continue;
                    }

                    $elementKey = $this->getElementKey($element);

                    if (array_key_exists($elementKey, $element)) {
                        $elementKeyValue = $element[$elementKey];

                        if (array_key_exists($elementKeyValue, $createdEntityIds)) {
                            $this->entityIds[$elementNumber] = $createdEntityIds[$elementKeyValue];

                            unset($this->transformedCreateElementNumbers[$key]);
                        }
                    }
                }

                foreach ($importChunk as $elementNumber => $element) {
                    if (in_array($elementNumber, $this->transformedCreateElementNumbers)) {
                        continue;
                    }

                    foreach ($element as $attributeCode => $attributeValue) {
                        if (in_array($attributeCode, $this->ignoreAttributes)
                            || ($attributeValue instanceof Associated)) {
                            continue;
                        }

                        $chunkAttributeCodes[] = $attributeCode;
                    }

                    $chunkEntityIds[] = $this->entityIds[$elementNumber];
                }

                $chunkAttributeCodes = array_unique($chunkAttributeCodes);

                $currentAttributeValues = $this->exportHelper->getCurrentAttributeValues(
                    $dbAdapter,
                    $this->getEntityTypeCode(),
                    $storeId,
                    $chunkAttributeCodes,
                    $chunkEntityIds,
                    $this->getSpecialAttributes()
                );

                $currentAdminAttributeValues = $this->exportHelper->getCurrentAttributeValues(
                    $dbAdapter,
                    $this->getEntityTypeCode(),
                    0,
                    $chunkAttributeCodes,
                    $chunkEntityIds,
                    $this->getSpecialAttributes()
                );

                foreach ($importChunk as $elementNumber => $element) {
                    if (!in_array($elementNumber, $this->transformedCreateElementNumbers)) {
                        continue;
                    }

                    $tableData = [];

                    foreach ($this->getCreateEntityData($element) as $createAttributeCode => $createAttributeValue) {
                        $tableData[$createAttributeCode] = $createAttributeValue;
                    }

                    $elementKey = $this->getElementKey($element);

                    if ($this->addElementKeyToCreateEntityData) {
                        if (array_key_exists($elementKey, $element)) {
                            $tableData[$elementKey] = $element[$elementKey];
                        }
                    }

                    $this->logging->info(
                        sprintf(
                            'Creating entity with key: %s',
                            array_key_exists($elementKey, $element) ? $element[$elementKey] : null
                        )
                    );

                    $this->addCreateTableData($elementNumber, $createTableName, $tableData);
                }

                $this->saveCreateTableData($dbAdapter, $createTableName, $importChunk);

                foreach ($importChunk as $elementNumber => $element) {
                    if (!array_key_exists($elementNumber, $this->entityIds)) {
                        $this->addTransformedInvalidElementReason(
                            $elementNumber,
                            sprintf('Could not identify %s to update', $this->getEntityLogName())
                        );
                    }
                }

                foreach ($importChunk as $elementNumber => $element) {
                    if (in_array($elementNumber, $this->getTransformedInvalidElementNumbers())
                        || in_array($elementNumber, $this->getTransformedCachedElementNumbers())) {
                        continue;
                    }

                    $entityId = $this->entityIds[$elementNumber];
                    $entityCurrentAttributeValues =
                        array_key_exists($entityId, $currentAttributeValues) ? $currentAttributeValues[$entityId] : [];
                    $entityCurrentAdminAttributeValues = array_key_exists($entityId, $currentAdminAttributeValues) ?
                        $currentAdminAttributeValues[$entityId] : [];

                    $this->logging->debug(
                        sprintf(
                            'Importing element with data: %s',
                            trim(print_r($this->importHelper->elementToArray($element), true))
                        )
                    );

                    $this->logging->debug(
                        sprintf(
                            'Current element values: %s',
                            trim(print_r($entityCurrentAttributeValues, true))
                        )
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
                            $entityCurrentAdminAttributeValues
                        );
                    } catch (Exception $exception) {
                        $this->addTransformedInvalidElementReason($elementNumber, $exception->getMessage());

                        $this->logging->error($exception);
                    }
                }

                if (!empty($this->singleAttributeTableData) || !empty($this->eavAttributeTableData)) {
                    $this->logging->debug('Saving collected attribute table data');

                    try {
                        $this->saveUpdateTableData($dbAdapter);
                    } catch (Exception $exception) {
                        if (!$this->isTest()) {
                            $dbAdapter->rollBack();
                        }

                        foreach (array_keys($importChunk) as $elementNumber) {
                            $this->logging->info(
                                sprintf(
                                    'Could not update %s with id: %d with values of store with id: %d because: %s',
                                    $this->getEntityLogName(),
                                    $this->entityIds[$elementNumber],
                                    $storeId,
                                    $exception->getMessage()
                                )
                            );

                            $this->addTransformedInvalidElementReason($elementNumber, $exception->getMessage());
                        }

                        $this->logging->error($exception);

                        return;
                    }
                } else {
                    $this->logging->debug('No collected attribute table data');
                }

                foreach (array_keys($importChunk) as $elementNumber) {
                    $this->addTransformedImportedElementNumbers($elementNumber);
                }

                $associatedItemEntityIds = [];

                foreach ($importChunk as $elementNumber => $element) {
                    if (in_array($elementNumber, $this->getTransformedInvalidElementNumbers())
                        || in_array($elementNumber, $this->getTransformedCachedElementNumbers())) {
                        continue;
                    }

                    $entityId = $this->entityIds[$elementNumber];

                    foreach ($element as $itemName => $attributeValue) {
                        if ($attributeValue instanceof Associated) {
                            if (!array_key_exists($itemName, $associatedItemEntityIds)) {
                                $associatedItemEntityIds[$itemName] = [];
                            }

                            $associatedItemEntityIds[$itemName][] = $entityId;
                        }
                    }
                }

                foreach ($associatedItemEntityIds as $associatedItemKey => $entityIds) {
                    if (array_key_exists($associatedItemKey, $this->associatedItemPrepareModels)) {
                        $associatedItemKeyPrepareModel =
                            $this->instanceHelper->getInstance($this->associatedItemPrepareModels[$associatedItemKey]);

                        if ($associatedItemKeyPrepareModel instanceof Prepare) {
                            $associatedItemKeyPrepareModel->prepare($entityIds, $storeId);
                        }
                    }
                }

                foreach ($importChunk as $elementNumber => $element) {
                    if (in_array($elementNumber, $this->getTransformedInvalidElementNumbers())
                        || in_array($elementNumber, $this->getTransformedCachedElementNumbers())) {
                        continue;
                    }

                    $entityId = $this->entityIds[$elementNumber];
                    $isCreatedEntity = in_array($elementNumber, $this->transformedCreateElementNumbers);
                    $hasChanges = false;

                    foreach ($element as $itemName => $attributeValue) {
                        if ($attributeValue instanceof Associated) {
                            try {
                                $entityCurrentAttributeValues = $currentAttributeValues[$entityId] ?? [];
                                $entityCurrentAdminAttributeValues = $currentAdminAttributeValues[$entityId] ?? [];

                                $attributeHasChanges = $attributeValue->update(
                                    $dbAdapter,
                                    $entityId,
                                    $storeId,
                                    $entityCurrentAttributeValues,
                                    $entityCurrentAdminAttributeValues,
                                    $isCreatedEntity
                                );

                                if ($attributeHasChanges) {
                                    $this->logging->info(
                                        sprintf(
                                            'Associated item of %s with id: %d with name: %s has changes in store with id: %d',
                                            $this->getEntityLogName(),
                                            $entityId,
                                            $itemName,
                                            $storeId
                                        )
                                    );

                                    $hasChanges = true;
                                } else {
                                    $this->logging->debug(
                                        sprintf(
                                            'Associated item of %s with id: %d with name: %s is unchanged in store with id: %d',
                                            $this->getEntityLogName(),
                                            $entityId,
                                            $itemName,
                                            $storeId
                                        )
                                    );
                                }

                                if (!in_array($elementNumber, $this->getTransformedImportedElementNumbers())) {
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
                    }

                    if ($hasChanges) {
                        $key = in_array($elementNumber, $this->getTransformedChangedElementNumbers());

                        if ($key === false) {
                            $this->addTransformedChangedElementNumbers($elementNumber);
                        }

                        $key = array_search($elementNumber, $this->getTransformedUnchangedElementNumbers());

                        if ($key !== false) {
                            $this->removeTransformedUnchangedElementNumbers($key);
                        }
                    } else {
                        $key = in_array($elementNumber, $this->getTransformedUnchangedElementNumbers());

                        if ($key === false) {
                            $key = in_array($elementNumber, $this->getTransformedChangedElementNumbers());

                            if ($key === false) {
                                $this->addTransformedUnchangedElementNumber($elementNumber);
                            }
                        }
                    }
                }

                $dbAdapter->commit();
            }
        }
    }

    /**
     * @param array $element
     *
     * @return array
     */
    abstract protected function getCreateEntityData(array $element): array;

    /**
     * @return  void
     */
    private function determineEntityIds()
    {
        $elementKeyValues = [];

        foreach ($this->getTransformedData() as $elementNumber => $element) {
            if (in_array($elementNumber, $this->getTransformedInvalidElementNumbers())
                || in_array($elementNumber, $this->getTransformedCachedElementNumbers())
                || in_array($elementNumber, $this->getTransformedImportedElementNumbers())) {
                continue;
            }

            if (array_key_exists('entity_id', $element)) {
                $this->entityIds[$elementNumber] = $element['entity_id'];
            } else {
                $elementKey = $this->getElementKey($element);

                if (array_key_exists($elementKey, $element)) {
                    $elementKeyValues[$elementNumber] = $element[$elementKey];
                }
            }
        }

        $entityIdInElementCount = count($this->entityIds);

        $this->logging->debug(sprintf('Found %d entity id(s) directly in elements', $entityIdInElementCount));

        $createdEntityIds = $this->getCreatedEntityIds();

        foreach ($this->getTransformedData() as $elementNumber => $element) {
            $elementKey = $this->getElementKey($element);

            if (array_key_exists($elementKey, $createdEntityIds)) {
                $this->entityIds[$elementNumber] = $createdEntityIds[$elementKey];
            }
        }

        $this->logging->debug(
            sprintf(
                'Found %d entity id(s) previously created',
                count($this->entityIds) - $entityIdInElementCount
            )
        );

        $this->logging->debug(
            sprintf(
                'Searching entity ids for %d unknown entries found in elements',
                count($elementKeyValues)
            )
        );

        foreach ($this->determineElementEntityIds($elementKeyValues) as $elementNumber => $entityId) {
            $this->entityIds[$elementNumber] = $entityId;
        }

        $this->logging->debug(
            sprintf(
                'Found %d entity id(s) in elements and searching with element key',
                count($this->entityIds)
            )
        );
    }

    /**
     * @param array $element
     *
     * @return string
     */
    abstract protected function getElementKey(array $element): string;

    /**
     * @param array $elementKeys
     *
     * @return array
     */
    abstract protected function determineElementEntityIds(array $elementKeys): array;

    /**
     * @return  void
     */
    protected function determineStoreIds()
    {
        foreach ($this->getTransformedData() as $elementNumber => $element) {
            if (in_array($elementNumber, $this->getTransformedInvalidElementNumbers())
                || in_array($elementNumber, $this->getTransformedImportedElementNumbers())) {
                continue;
            }

            $this->storeIds[$elementNumber] = $this->arrayHelper->getValue($element, 'store_id');
        }
    }

    /**
     * @param int    $elementNumber
     * @param string $table
     * @param array  $data
     *
     * @return void
     */
    protected function addCreateTableData(int $elementNumber, string $table, array $data)
    {
        if (!isset($this->createTableData[$table])) {
            $this->createTableData[$table] = [];
        }

        $this->createTableData[$table][$elementNumber] = $data;
    }

    /**
     * @param AdapterInterface $dbAdapter
     * @param string           $createTableName
     * @param array            $importChunk
     *
     * @return void
     * @throws Exception
     */
    protected function saveCreateTableData(AdapterInterface $dbAdapter, string $createTableName, array $importChunk)
    {
        $createdIds = $this->databaseHelper->saveCreateTableData($dbAdapter, $this->createTableData, $this->isTest());

        if (array_key_exists($createTableName, $createdIds)) {
            foreach ($createdIds[$createTableName] as $elementNumber => $entityId) {
                $element = $importChunk[$elementNumber];

                $elementKey = $this->getElementKey($element);

                $elementKeyValue = array_key_exists($elementKey, $element) ? $element[$elementKey] : null;

                $this->logging->info(sprintf('Created entity with key: %s has id: %d', $elementKeyValue, $entityId));

                $this->entityIds[$elementNumber] = $entityId;

                $this->addCreatedEntityId($elementKeyValue, $entityId);
            }
        }
    }

    /**
     * @param AdapterInterface $dbAdapter
     * @param string           $entityTypeCode
     * @param int              $entityId
     * @param int              $storeId
     * @param int              $elementNumber
     * @param array            $element
     * @param array            $currentAttributeValues
     * @param array            $currentAdminAttributeValues
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
    ) {
        $isCreatedEntity = in_array($elementNumber, $this->transformedCreateElementNumbers);

        $changeAttributeCodes = [];

        $hasChanges = false;

        foreach ($element as $attributeCode => $attributeValue) {
            if (in_array($attributeCode, $this->ignoreAttributes) || $attributeValue instanceof Associated) {
                continue;
            }

            if (in_array($attributeCode, $this->ignoreAttributes) || $attributeValue instanceof SpecialAttribute) {
                /** @var SpecialAttribute $attributeValue */
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

                $hasChanges = true;
            }
        }

        if (!$hasChanges) {
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

        $date = new \DateTime();

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
                !$isCreatedEntity,
                true,
                false
            );
        }
    }

    /**
     * @param string   $entityTypeCode
     * @param int      $entityId
     * @param int      $storeId
     * @param string   $attributeCode
     * @param mixed    $attributeValue
     * @param array    $currentAttributeValues
     * @param array    $currentAdminAttributeValues
     * @param bool     $isCreatedEntity
     * @param int|null $forceStoreId
     *
     * @return bool
     * @throws Exception
     */
    public function prepareAttribute(
        string $entityTypeCode,
        int $entityId,
        int $storeId,
        string $attributeCode,
        $attributeValue,
        array $currentAttributeValues,
        array $currentAdminAttributeValues,
        bool $isCreatedEntity,
        int $forceStoreId = null
    ): bool {
        if (array_key_exists($attributeCode, $this->specialAttributes)) {
            $preparedAttributeValue = $this->prepareSpecialAttributeValueForSave($attributeCode, $attributeValue);
        } else {
            $attribute = $this->attributeHelper->getAttribute($entityTypeCode, $attributeCode);

            if ($attribute->usesSource()) {
                $attributeType = \Magento\ImportExport\Model\Import::getAttributeType($attribute);

                if ($attributeType == 'multiselect') {
                    $attributeValues = explode(',', $attributeValue);

                    $attributeOptionIds = [];

                    foreach ($attributeValues as $attributeValue) {
                        $attributeValue = trim($attributeValue);

                        $optionId = $this->attributeHelper->getAttributeOptionId(
                            $entityTypeCode,
                            $attributeCode,
                            $this->variableHelper->isEmpty($forceStoreId) ? $storeId : $forceStoreId,
                            $attributeValue
                        );

                        if (empty($optionId)) {
                            if (!is_numeric($attributeValue)
                                && \Magento\ImportExport\Model\Import::getAttributeType($attribute) == 'int') {
                                throw new Exception(
                                    sprintf(
                                        'Invalid value "%s" in attribute with code: %s',
                                        $attributeValue,
                                        $attributeCode
                                    )
                                );
                            }

                            $attributeOptionIds[] = $attributeValue;
                        } else {
                            $attributeOptionIds[] = $optionId;
                        }
                    }

                    $attributeValue = implode(',', $attributeOptionIds);
                } else {
                    $optionId = $this->attributeHelper->getAttributeOptionId(
                        $entityTypeCode,
                        $attributeCode,
                        $this->variableHelper->isEmpty($forceStoreId) ? $storeId : $forceStoreId,
                        $attributeValue
                    );

                    if ($optionId === null) {
                        if (!is_numeric($attributeValue)
                            && \Magento\ImportExport\Model\Import::getAttributeType($attribute) == 'int') {
                            throw new Exception(
                                sprintf(
                                    'Invalid value "%s" in attribute with code: %s',
                                    $attributeValue,
                                    $attributeCode
                                )
                            );
                        }
                    } else {
                        $attributeValue = $optionId;
                    }
                }
            }

            $preparedAttributeValue = $this->importHelper->prepareValueForSave($attribute, $attributeValue);
        }

        if (array_key_exists($attributeCode, $currentAttributeValues)) {
            if (array_key_exists($attributeCode, $this->specialAttributes)) {
                $preparedCurrentAttributeValue = $this->prepareSpecialAttributeValueForSave(
                    $attributeCode,
                    $currentAttributeValues[$attributeCode]
                );

                $addAdminValue = !array_key_exists($attributeCode, $currentAdminAttributeValues)
                    || $currentAdminAttributeValues[$attributeCode] === null;

                $addEmptyAdminValue = ($this->variableHelper->isEmpty($forceStoreId) ? $storeId : $forceStoreId) !== 0;
            } else {
                $attribute = $this->attributeHelper->getAttribute($entityTypeCode, $attributeCode);

                $preparedCurrentAttributeValue =
                    $this->importHelper->prepareValueForSave($attribute, $currentAttributeValues[$attributeCode]);

                $isScopeGlobal = $this->isAttributeScopeGlobal($attribute);

                if ($isScopeGlobal) {
                    if (array_key_exists($attributeCode, $currentAdminAttributeValues)) {
                        $preparedCurrentAdminAttributeValue = $this->importHelper->prepareValueForSave(
                            $attribute,
                            $currentAdminAttributeValues[$attributeCode]
                        );

                        $addAdminValue = $preparedAttributeValue !== $preparedCurrentAdminAttributeValue;
                    } else {
                        $addAdminValue = true;
                    }
                } else {
                    $addAdminValue = !array_key_exists($attributeCode, $currentAdminAttributeValues)
                        || $currentAdminAttributeValues[$attributeCode] === null;
                }

                $addEmptyAdminValue = ($this->variableHelper->isEmpty($forceStoreId) ? $storeId : $forceStoreId) !== 0
                    && ((int) $attribute->getData('is_required')) !== 1
                    && !$isScopeGlobal;
            }

            if (($preparedAttributeValue === $preparedCurrentAttributeValue)
                || (is_object($preparedAttributeValue) && is_object($preparedCurrentAttributeValue)
                    && get_class($preparedAttributeValue) === get_class($preparedCurrentAttributeValue)
                    && method_exists($preparedAttributeValue, '__toString')
                    && method_exists($preparedCurrentAttributeValue, '__toString')
                    && $preparedAttributeValue->__toString() === $preparedCurrentAttributeValue->__toString())) {
                $this->logging->debug(
                    sprintf(
                        'Attribute with name: %s has unchanged value: %s',
                        $attributeCode,
                        $preparedAttributeValue
                    )
                );

                return false;
            }

            $this->logging->debug(
                sprintf(
                    'Attribute with name: %s has changed value to: %s from: %s',
                    $attributeCode,
                    $preparedAttributeValue,
                    $preparedCurrentAttributeValue
                )
            );
        } else {
            if (array_key_exists($attributeCode, $this->specialAttributes)) {
                $addAdminValue = !array_key_exists($attributeCode, $currentAdminAttributeValues)
                    || $currentAdminAttributeValues[$attributeCode] === null;

                $addEmptyAdminValue = ($this->variableHelper->isEmpty($forceStoreId) ? $storeId : $forceStoreId) !== 0;
            } else {
                $attribute = $this->attributeHelper->getAttribute($entityTypeCode, $attributeCode);

                $isScopeGlobal = $this->isAttributeScopeGlobal($attribute);

                $addAdminValue = !array_key_exists($attributeCode, $currentAdminAttributeValues)
                    || $currentAdminAttributeValues[$attributeCode] === null
                    || $isScopeGlobal;

                $addEmptyAdminValue = ($this->variableHelper->isEmpty($forceStoreId) ? $storeId : $forceStoreId) !== 0
                    && ((int) $attribute->getData('is_required')) !== 1
                    && !$isScopeGlobal;
            }

            $this->logging->debug(
                sprintf(
                    'Attribute with name: %s has new value: %s',
                    $attributeCode,
                    $preparedAttributeValue
                )
            );
        }

        if (in_array($attributeCode, $this->forceAdminEavAttributeValues)) {
            if (!array_key_exists($attributeCode, $currentAdminAttributeValues)
                || $currentAdminAttributeValues[$attributeCode] === null) {
                $addAdminValue = true;
                $addEmptyAdminValue = false;
            }
        }

        if (in_array($attributeCode, $this->prohibitAdminEavAttributeValues)) {
            $addAdminValue = false;
        }

        return $this->createAttributeUpdates(
            $entityTypeCode,
            $entityId,
            $this->variableHelper->isEmpty($forceStoreId) ? $storeId : $forceStoreId,
            $attributeCode,
            $attributeValue,
            !$isCreatedEntity,
            $addAdminValue || $isCreatedEntity,
            $addEmptyAdminValue
        );
    }

    /**
     * @param \Magento\Eav\Model\Entity\Attribute $attribute
     *
     * @return bool
     */
    abstract protected function isAttributeScopeGlobal(\Magento\Eav\Model\Entity\Attribute $attribute): bool;

    /**
     * @param string $entityTypeCode
     * @param int    $entityId
     * @param int    $storeId
     * @param string $attributeCode
     * @param mixed  $attributeValue
     * @param bool   $addStoreValue
     * @param bool   $addAdminValue
     * @param bool   $addEmptyAdminValue
     *
     * @return bool
     * @throws Exception
     */
    protected function createAttributeUpdates(
        string $entityTypeCode,
        int $entityId,
        int $storeId,
        string $attributeCode,
        $attributeValue,
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
            $type = $this->arrayHelper->getValue($update, 'type');
            $table = $this->arrayHelper->getValue($update, 'table');
            $tableData = $this->arrayHelper->getValue($update, 'data');

            if ($type === 'single') {
                $this->addSingleAttributeTableData($table, $attributeCode, $tableData);
            } else {
                $this->addEavAttributeTableData($table, $tableData);
            }
        }

        return true;
    }

    /**
     * @param string $attributeCode
     * @param mixed  $attributeValue
     *
     * @return mixed
     */
    private function prepareSpecialAttributeValueForSave(string $attributeCode, $attributeValue)
    {
        return $this->importHelper->prepareSpecialAttributeValueForSave(
            $this->specialAttributes,
            $attributeCode,
            $attributeValue
        );
    }

    /**
     * @param string $table
     * @param string $attributeCode
     * @param array  $data
     *
     * @return void
     */
    protected function addSingleAttributeTableData(string $table, string $attributeCode, array $data)
    {
        if (!isset($this->singleAttributeTableData[$table])) {
            $this->singleAttributeTableData[$table] = [];
        }

        if (!isset($this->singleAttributeTableData[$table][$attributeCode])) {
            $this->singleAttributeTableData[$table][$attributeCode] = [];
        }

        $this->singleAttributeTableData[$table][$attributeCode][] = $data;
    }

    /**
     * @param string $table
     * @param array  $data
     *
     * @return void
     */
    protected function addEavAttributeTableData(string $table, array $data)
    {
        if (!isset($this->eavAttributeTableData[$table])) {
            $this->eavAttributeTableData[$table] = [];
        }

        $this->eavAttributeTableData[$table][] = $data;
    }

    /**
     * @param AdapterInterface $dbAdapter
     *
     * @return void
     * @throws Exception
     */
    protected function saveUpdateTableData(AdapterInterface $dbAdapter)
    {
        $this->databaseHelper->saveUpdateTableData(
            $dbAdapter,
            $this->singleAttributeTableData,
            $this->eavAttributeTableData,
            $this->isTest()
        );
    }

    /**
     * @param int $entityId
     * @param int $storeId
     */
    protected function addImportedEntity(int $entityId, int $storeId)
    {
        $this->importedEntities[] = [
            'entity_id' => $entityId,
            'store_id'  => $storeId
        ];
    }

    /**
     * @return array
     */
    public function getImportedEntities(): array
    {
        return $this->importedEntities;
    }

    /**
     * @return array
     */
    public function getCreatedEntityIds(): array
    {
        return $this->createdEntityIds;
    }

    /**
     * @param string $elementKey
     * @param int    $entityId
     */
    protected function addCreatedEntityId(string $elementKey, int $entityId)
    {
        $this->createdEntityIds[$elementKey] = $entityId;
    }

    /**
     * @return array
     */
    public function getAssociatedItemPrepareModels(): array
    {
        return $this->associatedItemPrepareModels;
    }

    /**
     * @param string $key
     * @param string $model
     *
     * @return void
     */
    public function addAssociatedItemPrepareModel(string $key, string $model)
    {
        $this->associatedItemPrepareModels[$key] = $model;
    }
}
