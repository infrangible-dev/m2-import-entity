<?php

declare(strict_types=1);

namespace Infrangible\ImportEntity\Model;

use Exception;
use Infrangible\Import\Model\Related;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * @author      Andreas Knollmann
 * @copyright   Copyright (c) 2014-2024 Softwareentwicklung Andreas Knollmann
 * @license     http://www.opensource.org/licenses/mit-license.php MIT
 */
abstract class Extended
    extends Related
{
    /**
     * @param AdapterInterface $dbAdapter
     * @param int              $entityId
     * @param int              $storeId
     * @param array            $currentAttributeValues
     * @param array            $currentAdminAttributeValues
     * @param bool             $isCreatedEntity
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
        bool $isCreatedEntity): bool;
}
