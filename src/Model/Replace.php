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
 * Replaces a value of an imported element with another attribute, for example a name with the ID of the entity.
 */
abstract class Replace extends Related
{
    /**
     * Returns the code of the attribute that takes the result of the replacement.
     *
     * @return string
     */
    abstract public function getReplaceResultAttributeId(): string;

    /**
     * Takes the value to replace.
     *
     * @param mixed $value
     *
     * @return void
     */
    abstract public function prepare(mixed $value): void;

    /**
     * Returns the replacement of the value in the store.
     *
     * @param AdapterInterface $dbAdapter
     * @param int $storeId
     *
     * @return mixed
     * @throws Exception
     */
    abstract public function replace(AdapterInterface $dbAdapter, int $storeId): mixed;
}
