<?php

declare(strict_types=1);

/**
 * @author      Andreas Knollmann
 * @copyright   Copyright (c) 2014-2026 Softwareentwicklung Andreas Knollmann
 * @license     http://www.opensource.org/licenses/mit-license.php MIT
 */

namespace Infrangible\ImportEntity\Model;

use Exception;
use Infrangible\Core\Helper\Database;
use Infrangible\Import\Model\Import;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Psr\Log\LoggerInterface;

/**
 * Associated data of an imported entity, for example the images or links of a product, saved in their own tables.
 */
abstract class Associated extends Extended
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
     * Takes the associated data of the element.
     *
     * @param AdapterInterface $dbAdapter
     * @param array $data
     * @phpstan-param array<int|string, mixed> $data
     * @param array $element
     * @phpstan-param array<string, mixed> $element
     *
     * @return void
     */
    abstract public function prepare(AdapterInterface $dbAdapter, array $data, array $element): void;

    /**
     * Inserts a row into the table, unless in test mode, and returns its ID.
     *
     * @param AdapterInterface $dbAdapter
     * @param string $tableName
     * @param array $tableData
     * @phpstan-param array<string, mixed> $tableData
     * @param bool $checkDuplicate
     *
     * @return int
     * @throws Exception
     */
    protected function createTableData(
        AdapterInterface $dbAdapter,
        string $tableName,
        array $tableData,
        bool $checkDuplicate = false
    ): int {
        return $this->databaseHelper->createTableData(
            $dbAdapter,
            $tableName,
            $tableData,
            $checkDuplicate,
            $this->getImporter()->isTest()
        );
    }

    /**
     * Updates the rows of the table, unless in test mode.
     *
     * @param AdapterInterface $dbAdapter
     * @param string $tableName
     * @param array $tableData
     * @phpstan-param array<string, mixed> $tableData
     * @param mixed $where
     *
     * @return void
     * @throws Exception
     */
    protected function updateTableData(
        AdapterInterface $dbAdapter,
        string $tableName,
        array $tableData,
        mixed $where = null
    ): void {
        $this->databaseHelper->updateTableData(
            $dbAdapter,
            $tableName,
            $tableData,
            $where,
            $this->getImporter()->isTest()
        );
    }

    /**
     * Deletes the rows of the table, unless in test mode.
     *
     * @param AdapterInterface $dbAdapter
     * @param string $tableName
     * @param mixed $where
     *
     * @return void
     * @throws Exception
     */
    protected function deleteTableData(AdapterInterface $dbAdapter, string $tableName, mixed $where = null): void
    {
        $this->databaseHelper->deleteTableData($dbAdapter, $tableName, $where, $this->getImporter()->isTest());
    }
}
