<?php

declare(strict_types=1);

namespace Infrangible\ImportEntity\Model;

use Infrangible\Core\Helper\Database;
use Infrangible\Import\Model\Import;
use Psr\Log\LoggerInterface;

/**
 * @author      Andreas Knollmann
 * @copyright   Copyright (c) 2014-2024 Softwareentwicklung Andreas Knollmann
 * @license     http://www.opensource.org/licenses/mit-license.php MIT
 */
abstract class SpecialAttribute
    extends Extended
{
    /** @var Database */
    protected $databaseHelper;

    /**
     * @param Database        $databaseHelper
     * @param LoggerInterface $logging
     * @param Import          $importer
     */
    public function __construct(
        Database $databaseHelper,
        LoggerInterface $logging,
        Import $importer
    ) {
        parent::__construct($logging, $importer);

        $this->databaseHelper = $databaseHelper;
    }

    /**
     * @param string $attributeCode
     * @param mixed  $value
     * @param array  $element
     *
     * @return void
     */
    abstract public function prepare(string $attributeCode, $value, array $element);
}
