<?php

declare(strict_types=1);

/**
 * @author      Andreas Knollmann
 * @copyright   Copyright (c) 2014-2026 Softwareentwicklung Andreas Knollmann
 * @license     http://www.opensource.org/licenses/mit-license.php MIT
 */

namespace Infrangible\ImportEntity\Model;

/**
 * Prepares the import of associated data for all imported entities of a chunk, before the associated data is saved.
 */
abstract class Prepare
{
    /**
     * Prepares the entities in the store.
     *
     * @param int[] $entityIds
     * @param int $storeId
     *
     * @return void
     */
    abstract public function prepare(array $entityIds, int $storeId): void;
}
