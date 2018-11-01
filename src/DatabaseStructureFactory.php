<?php

namespace Laminaria\Conv;

use Laminaria\Conv\Factory\TableStructureFactory;
use Laminaria\Conv\Factory\ViewStructureFactory;
use Laminaria\Conv\Operator\Operator;
use Laminaria\Conv\Operator\OperatorInterface;
use Laminaria\Conv\Structure\ColumnStructure;
use Laminaria\Conv\Structure\DatabaseStructure;
use Laminaria\Conv\Structure\IndexStructure;
use Laminaria\Conv\Structure\TableStructureType;
use Laminaria\Conv\Util\Config;
use Laminaria\Conv\Util\Evaluator;
use Laminaria\Conv\Util\SchemaKey;
use Laminaria\Conv\Util\SchemaValidator;
use Howyi\Evi;
use Symfony\Component\Console\Helper\ProgressBar;

class DatabaseStructureFactory
{
    private const TMP_DBNAME = 'conv_tmp';

    /**
     * @param string $path
     */
    public static function fromDir(
        string $path
    ): DatabaseStructure {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path)
        );
        $tableList = [];
        $specList = [];
        foreach ($iterator as $fileinfo) {
            if ($fileinfo->isFile()) {
                $name = pathinfo($fileinfo->getPathName(), PATHINFO_FILENAME);
                $specList[$name] = Evi::parse($fileinfo->getPathName(), Config::option('eval'));
            }
        }

        foreach ($specList as $name => $spec) {
            if (!isset($spec[SchemaKey::TABLE_TYPE])) {
                $spec[SchemaKey::TABLE_TYPE] = TableStructureType::TABLE;
            }
            SchemaValidator::validate($name, $spec);
            if ($spec[SchemaKey::TABLE_TYPE] === TableStructureType::TABLE) {
                $table = TableStructureFactory::fromSpec($name, $spec);
            } else {
                $table = ViewStructureFactory::fromSpec($name, $spec);
            }
            $tableList[$table->getName()] = $table;
        }
        return new DatabaseStructure($tableList);
    }

    /**
     * @param \PDO          $pdo
     * @param string        $dbName
     * @param callable|null $filter
     */
    public static function fromPDO(
        \PDO $pdo,
        string $dbName,
        callable $filter = null
    ): DatabaseStructure {
        $rawTableList = $pdo->query(
            "SELECT * FROM INFORMATION_SCHEMA.TABLES WHERE table_schema = '$dbName'"
        )->fetchAll();
        $tableList = [];
        foreach ($rawTableList as $value) {
            $tableName = $value['TABLE_NAME'];
            switch ($value['TABLE_TYPE']) {
                case 'BASE TABLE':
                    $table = TableStructureFactory::fromTable($pdo, $dbName, $tableName);
                    break;
                case 'VIEW':
                    $table = ViewStructureFactory::fromView($pdo, $dbName, $tableName);
                    break;
                default:
                    continue 2;
            }
            if (!is_null($filter) and !$filter($table)) {
                continue;
            }
            $tableList[$tableName] = $table;
        }
        return new DatabaseStructure($tableList);
    }

    /**
     * @param \PDO              $pdo      Creatable DB
     * @param string            $path
     * @param OperatorInterface $operator
     * @param callable|null $filter
     * @return DatabaseStructure
     */
    public static function fromSqlDir(
        \PDO $pdo,
        string $path,
        OperatorInterface $operator,
        callable $filter = null
    ): DatabaseStructure {
        $operator->output('<comment>Genarate temporary database</>');
        $pdo->exec('DROP DATABASE IF EXISTS ' . self::TMP_DBNAME);
        $pdo->exec('CREATE DATABASE ' . self::TMP_DBNAME);
        $pdo->exec('USE ' . self::TMP_DBNAME);
        $pdo->exec('SET sql_mode = \'\'');
        $views = [];

        $operator->startProgress(count(glob("$path/*.sql")));
        $ddls = [];
        foreach (new \DirectoryIterator($path) as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }
            if ('sql' !== strtolower($fileInfo->getExtension())) {
                continue;
            }
            $query = file_get_contents($fileInfo->getRealPath());
            $ddl = new class($query, $pdo, $operator) {
                private $query;
                private $isView;
                private $hasCreated = false;
                private $references = [];
                private $pdo;
                private $operator;

                /**
                 * @param string            $query
                 * @param \PDO              $pdo
                 * @param OperatorInterface $operator
                 */
                public function __construct(string $query, \PDO $pdo, OperatorInterface $operator)
                {
                    $this->query = $query;
                    $this->isView =  false !== strpos($query, 'CREATE ALGORITHM');
                    preg_match_all('/REFERENCES `?([a-zA-Z][a-zA-Z0-9_\ ]*?)`? /s', $query, $matches);
                    if (0 < count($matches[1])) {
                        $this->references = $matches[1];
                    }
                    $this->pdo = $pdo;
                    $this->operator = $operator;
                }

                /**
                 * @return bool
                 */
                public function isView(): bool
                {
                    return $this->isView;
                }

                /**
                 * @return string[]
                 */
                public function getReferences(): array
                {
                    return $this->references;
                }

                /**
                 * @return bool
                 */
                public function hasCreated(): bool
                {
                    return $this->hasCreated;
                }

                public function create(): void
                {
                    $this->pdo->exec($this->query);
                    $this->operator->advanceProgress();
                    $this->hasCreated = true;
                }
            };
            $ddls[$fileInfo->getBaseName('.sql')] = $ddl;
        }

        foreach ($ddls as $name => $ddl) {
            if ($ddl->isView()) {
                $views[] = $ddl;
                continue;
            }
            self::createTableRecursive($ddls, $name);
        }

        foreach ($views as $view) {
            $view->create();
        }
        $operator->finishProgress('');
        $databaseStructure = self::fromPDO($pdo, self::TMP_DBNAME, $filter);
        $pdo->exec('DROP DATABASE IF EXISTS ' . self::TMP_DBNAME);

        return $databaseStructure;
    }

    /**
     * @param array  $ddls
     * @param string $current
     */
    private static function createTableRecursive(array $ddls, string $current): void
    {
        if (!$ddls[$current]->hasCreated()) {
            foreach ($ddls[$current]->getReferences() as $reference) {
                self::createTableRecursive($ddls, $reference);
            }
            $ddls[$current]->create();
        }
    }
}
