<?php

declare(strict_types=1);

/**
 * @author      Andreas Knollmann
 * @copyright   Copyright (c) 2014-2026 Softwareentwicklung Andreas Knollmann
 * @license     http://www.opensource.org/licenses/mit-license.php MIT
 */

namespace Infrangible\ImportEntity\Test\Unit\Model;

use Infrangible\ImportEntity\Model\Entity;
use Magento\Eav\Model\Entity\Attribute as EavAttribute;
use Magento\Eav\Model\Entity\Collection\AbstractCollection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use RuntimeException;

/**
 * An entity import of products by SKU that exposes its protected steps to the tests.
 */
class TestEntity extends Entity
{
    /**
     * @inheritDoc
     */
    protected function prepare(): void
    {
    }

    /**
     * @inheritDoc
     */
    protected function cleanup(): void
    {
    }

    /**
     * @inheritDoc
     */
    protected function getEntitiesLogName(): string
    {
        return 'products';
    }

    /**
     * @inheritDoc
     */
    protected function getEntityLogName(): string
    {
        return 'product';
    }

    /**
     * @inheritDoc
     */
    protected function getEntityTypeCode(): string
    {
        return 'catalog_product';
    }

    /**
     * @inheritDoc
     */
    protected function readSourceData(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    protected function validateSourceData(array $sourceData): void
    {
    }

    /**
     * @inheritDoc
     */
    protected function getSourceElementKey(array $sourceElement): string
    {
        return 'sku';
    }

    /**
     * @inheritDoc
     */
    protected function findSourceDataEntityIds(array $sourceElementKeys): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    protected function transformElement(int $sourceElementNumber, array $sourceElement): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    protected function getCreateEntityData(array $element): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    protected function getTransformedElementKey(array $transformedElement): string
    {
        return 'sku';
    }

    /**
     * @inheritDoc
     */
    protected function findTransformedDataEntityIds(array $transformedElementKeys): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    protected function isAttributeScopeGlobal(EavAttribute $attribute): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    protected function getEntityCollection(): AbstractCollection
    {
        throw new RuntimeException('Not used in the tests');
    }

    /**
     * @inheritDoc
     */
    protected function getSourceElementHashKey(array $sourceElement): string
    {
        return '';
    }

    /**
     * @inheritDoc
     */
    protected function getTransformedElementHashKey(array $element): string
    {
        return '';
    }

    /**
     * @inheritDoc
     */
    protected function displaySourceInvalidElement(
        int $sourceElementNumber,
        mixed $sourceElement,
        string $reason,
        string $severity = 'error'
    ): void {
    }

    /**
     * @inheritDoc
     */
    protected function displayTransformedInvalidElement(
        int $transformedElementNumber,
        mixed $transformedElement,
        string $reason,
        string $severity = 'error'
    ): void {
    }

    /**
     * Returns the element with its store.
     *
     * @param int $elementNumber
     * @param array<string, mixed> $element
     *
     * @return array<string, mixed>
     */
    public function addStore(int $elementNumber, array $element): array
    {
        return $this->validateTransformedDataStore($elementNumber, $element);
    }

    /**
     * Returns the element with the prepared Associated models.
     *
     * @param AdapterInterface $dbAdapter
     * @param array<string, mixed> $element
     *
     * @return array<string, mixed>
     */
    public function addAssociatedModels(AdapterInterface $dbAdapter, array $element): array
    {
        $attributeCodes = array_keys($element);

        $this->prepareAssociatedModels($dbAdapter, 1, $element, $attributeCodes);

        return $element;
    }
}
