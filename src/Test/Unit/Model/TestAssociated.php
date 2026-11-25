<?php

declare(strict_types=1);

/**
 * @author      Andreas Knollmann
 * @copyright   Copyright (c) 2014-2026 Softwareentwicklung Andreas Knollmann
 * @license     http://www.opensource.org/licenses/mit-license.php MIT
 */

namespace Infrangible\ImportEntity\Test\Unit\Model;

use Infrangible\ImportEntity\Model\Associated;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Associated data that keeps the data it was prepared with.
 */
class TestAssociated extends Associated
{
    /** @var array<int|string, mixed> */
    public array $data = [];

    /**
     * @inheritDoc
     */
    public function prepare(AdapterInterface $dbAdapter, array $data, array $element): void
    {
        $this->data = $data;
    }

    /**
     * @inheritDoc
     */
    public function validate(AdapterInterface $dbAdapter, int $storeId, array $element): void
    {
    }

    /**
     * @inheritDoc
     */
    public function update(
        AdapterInterface $dbAdapter,
        int $entityId,
        int $storeId,
        array $currentAttributeValues,
        array $currentAdminAttributeValues,
        bool $isCreatedEntity
    ): bool {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
