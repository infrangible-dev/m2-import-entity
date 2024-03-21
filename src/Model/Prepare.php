<?php

declare(strict_types=1);

namespace Infrangible\ImportEntity\Model;

/**
 * @author      Andreas Knollmann
 * @copyright   Copyright (c) 2014-2024 Softwareentwicklung Andreas Knollmann
 * @license     http://www.opensource.org/licenses/mit-license.php MIT
 */
abstract class Prepare
{
    /**
     * @param int[] $entityIds
     * @param int   $storeId
     *
     * @return void
     */
    abstract public function prepare(array $entityIds, int $storeId);
}
