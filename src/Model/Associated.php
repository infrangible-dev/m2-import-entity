<?php

declare(strict_types=1);

namespace Infrangible\ImportEntity\Model;

use Exception;
use Infrangible\Core\Helper\Database;
use Infrangible\Import\Model\Import;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Psr\Log\LoggerInterface;

/**
 * @author      Andreas Knollmann
 * @copyright   Copyright (c) 2014-2024 Softwareentwicklung Andreas Knollmann
 * @license     http://www.opensource.org/licenses/mit-license.php MIT
 */
abstract class Associated
    extends Extended
{
    /** @var Database */
    protected $databaseHelper;

    /**
     * @param Database        $databaseHelper
     * @param LoggerInterface $logging
     * @param Import          $importer
     */
    public function __construct(Database $databaseHelper, LoggerInterface $logging, Import $importer)
    {
        parent::__construct($logging, $importer);

        $this->databaseHelper = $databaseHelper;
    }

    /**
     * @param AdapterInterface $dbAdapter
     * @param array            $data
     * @param array            $element
     *
     * @return void
     */
    abstract public function prepare(AdapterInterface $dbAdapter, array $data, array $element);

    /**
     * @param AdapterInterface $dbAdapter
     * @param string           $tableName
     * @param array            $tableData
     * @param bool             $checkDuplicate
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
     * @param AdapterInterface $dbAdapter
     * @param string           $tableName
     * @param array            $tableData
     * @param mixed            $where
     *
     * @return void
     * @throws Exception
     */
    protected function updateTableData(
        AdapterInterface $dbAdapter,
        string $tableName,
        array $tableData,
        $where = null
    ) {
        $this->databaseHelper->updateTableData(
            $dbAdapter,
            $tableName,
            $tableData,
            $where,
            $this->getImporter()->isTest()
        );
    }

    /**
     * @param AdapterInterface $dbAdapter
     * @param string           $tableName
     * @param mixed            $where
     *
     * @return void
     * @throws Exception
     */
    protected function deleteTableData(AdapterInterface $dbAdapter, string $tableName, $where = null)
    {
        $this->databaseHelper->deleteTableData($dbAdapter, $tableName, $where, $this->getImporter()->isTest());
    }

}
