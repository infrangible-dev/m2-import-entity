<?php

declare(strict_types=1);

/**
 * @author      Andreas Knollmann
 * @copyright   Copyright (c) 2014-2026 Softwareentwicklung Andreas Knollmann
 * @license     http://www.opensource.org/licenses/mit-license.php MIT
 */

namespace Infrangible\ImportEntity\Model;

use Infrangible\Core\Helper\Database;
use Infrangible\Import\Model\Import;
use Psr\Log\LoggerInterface;

/**
 * An attribute of an imported entity whose value is saved by its own model instead of the attribute tables.
 */
abstract class SpecialAttribute extends Extended
{
    /**
     * @param Database $databaseHelper
     * @param LoggerInterface $logging
     * @param Import $importer
     */
    public function __construct(
        protected Database $databaseHelper,
        LoggerInterface $logging,
        Import $importer
    ) {
        parent::__construct($logging, $importer);
    }

    /**
     * Takes the value of the attribute in the element.
     *
     * @param string $attributeCode
     * @param mixed $value
     * @param array $element
     * @phpstan-param array<string, mixed> $element
     *
     * @return void
     */
    abstract public function prepare(string $attributeCode, mixed $value, array $element): void;
}
