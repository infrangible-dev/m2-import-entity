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
abstract class Replace
    extends Related
{
    /**
     * @return string
     */
    abstract public function getReplaceResultAttributeId(): string;

    /**
     * @param mixed $value
     *
     * @return void
     */
    abstract public function prepare($value);

    /**
     * @param AdapterInterface $dbAdapter
     * @param int              $storeId
     *
     * @return mixed
     * @throws Exception
     */
    abstract public function replace(AdapterInterface $dbAdapter, int $storeId);
}
