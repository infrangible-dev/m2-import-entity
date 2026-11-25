<?php

declare(strict_types=1);

/**
 * @author      Andreas Knollmann
 * @copyright   Copyright (c) 2014-2026 Softwareentwicklung Andreas Knollmann
 * @license     http://www.opensource.org/licenses/mit-license.php MIT
 */

namespace Infrangible\ImportEntity\Model;

use Exception;
use Infrangible\Import\Model\Related;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * A value of an imported entity that saves itself, after the attribute values of the entity were saved.
 */
abstract class Extended extends Related
{
    /**
     * Saves the value for the entity in the store and returns whether it changed.
     *
     * @param AdapterInterface $dbAdapter
     * @param int $entityId
     * @param int $storeId
     * @param array $currentAttributeValues
     * @phpstan-param array<string, mixed> $currentAttributeValues
     * @param array $currentAdminAttributeValues
     * @phpstan-param array<string, mixed> $currentAdminAttributeValues
     * @param bool $isCreatedEntity
     *
     * @return bool
     * @throws Exception
     */
    abstract public function update(
        AdapterInterface $dbAdapter,
        int $entityId,
        int $storeId,
        array $currentAttributeValues,
        array $currentAdminAttributeValues,
        bool $isCreatedEntity
    ): bool;
}
