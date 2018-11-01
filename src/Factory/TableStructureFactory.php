<?php

namespace Laminaria\Conv\Factory;

use Laminaria\Conv\Structure\Attribute;
use Laminaria\Conv\Structure\ColumnStructure;
use Laminaria\Conv\Structure\IndexStructure;
use Laminaria\Conv\Structure\PartitionLongStructure;
use Laminaria\Conv\Structure\PartitionPartStructure;
use Laminaria\Conv\Structure\PartitionShortStructure;
use Laminaria\Conv\Structure\TableStructure;
use Laminaria\Conv\Util\Config;
use Laminaria\Conv\Util\Evaluator;
use Laminaria\Conv\Util\PartitionType;
use Laminaria\Conv\Util\SchemaKey;
use Laminaria\Conv\Util\SchemaValidator;
use Symfony\Component\Yaml\Yaml;

class TableStructureFactory
{
    /**
     * @param \PDO   $pdo
     * @param string $dbName
     * @param string $tableName
     * @return TableStructure
     */
    public static function fromTable(\PDO $pdo, string $dbName, string $tableName): TableStructure
    {
        $pdo->exec('USE ' . $dbName);
        $rawStatus = $pdo->query("SHOW TABLE STATUS LIKE '$tableName'")->fetch();

        $rawColumnList = $pdo->query(
            sprintf(
                "SELECT * FROM information_schema.COLUMNS WHERE table_schema = '%s' AND  table_name = '%s' ORDER BY ORDINAL_POSITION ASC",
                $dbName,
                $tableName
            )
        )->fetchAll();

        $rawPartitionList = $pdo->query(
            sprintf(
                "SELECT * FROM information_schema.PARTITIONS WHERE table_schema = '%s' AND  table_name = '%s' ORDER BY PARTITION_ORDINAL_POSITION ASC",
                $dbName,
                $tableName
            )
        )->fetchAll();

        $partitionGroups = [];
        if (count($rawPartitionList) !== 1 and !is_null(reset($rawPartitionList)['PARTITION_METHOD'])) {
            $methods = array_fill_keys(array_column($rawPartitionList, 'PARTITION_METHOD'), []);
            foreach ($rawPartitionList as $item) {
                $methods[$item['PARTITION_METHOD']][] = $item;
            }
            foreach ($methods as $method => $methodValue) {
                $expressions = array_fill_keys(array_column($methodValue, 'PARTITION_EXPRESSION'), []);
                foreach ($methodValue as $value) {
                    $expressions[$value['PARTITION_EXPRESSION']][$value['PARTITION_ORDINAL_POSITION']] = [
                        'PARTITION_NAME' => $value['PARTITION_NAME'],
                        'PARTITION_DESCRIPTION' => $value['PARTITION_DESCRIPTION'],
                        'PARTITION_COMMENT' => $value['PARTITION_COMMENT'],
                    ];
                }
                $partitionGroups[$method] = $expressions;
            }
        }

        $partition = null;
        foreach ($partitionGroups as $method => $group) {
            $type = PartitionType::METHOD_TYPE[$method];
            switch ($type) {
                case PartitionType::SHORT:
                    foreach ($group as $value => $raw) {
                        $partition = new PartitionShortStructure(
                            $method,
                            $value,
                            count($raw)
                        );
                    }
                    break;
                case PartitionType::LONG:
                    foreach ($group as $value => $raw) {
                        $parts = [];
                        foreach ($raw as $order => $array) {
                            $parts[$order] = new PartitionPartStructure(
                                $array['PARTITION_NAME'],
                                PartitionType::METHOD_OPERATOR[$method],
                                $array['PARTITION_DESCRIPTION'],
                                $array['PARTITION_COMMENT']
                            );
                        }
                        $partition = new PartitionLongStructure(
                            $method,
                            $value,
                            $parts
                        );
                    }
                    break;
            }
        }

        $columnStructureList = [];

        foreach ($rawColumnList as $column) {
            $attribute = [];
            if ((bool) preg_match('/auto_increment/', $column['EXTRA'])) {
                $attribute[] = Attribute::AUTO_INCREMENT;
            }
            if ('YES' === $column['IS_NULLABLE']) {
                $attribute[] = Attribute::NULLABLE;
            }
            if ((bool) preg_match('/unsigned/', $column['COLUMN_TYPE'])) {
                $attribute[] = Attribute::UNSIGNED;
            }
            if ((bool) preg_match('/STORED/', $column['EXTRA'])) {
                $attribute[] = Attribute::STORED;
            }

            $collationName = $column['COLLATION_NAME'];
            $generationExpression = empty($column['GENERATION_EXPRESSION']) ? null : $column['GENERATION_EXPRESSION'];

            $columnStructureList[] = new ColumnStructure(
                $column['COLUMN_NAME'],
                str_replace(' unsigned', '', $column['COLUMN_TYPE']),
                $column['COLUMN_DEFAULT'],
                $column['COLUMN_COMMENT'],
                $attribute,
                $collationName,
                $generationExpression,
                []
            );
        }

        $rawIndexList = $pdo->query("SHOW INDEX FROM $tableName")->fetchAll();
        $keyList = [];
        foreach ($rawIndexList as $index) {
            $columnName = $index['Column_name'];
            if (!is_null($index['Sub_part'])) {
                $subPart = $index['Sub_part'];
                $columnName = "$columnName($subPart)";
            }
            $keyList[$index['Key_name']][] = [
                'Non_unique' => $index['Non_unique'],
                'Column_name' => $columnName,
                'Index_type' => $index['Index_type']
            ];
        }
        $indexStructureList = [];
        foreach ($keyList as $keyName => $indexList) {
            $indexStructureList[] = new IndexStructure(
                $keyName,
                '0' == $indexList[0]['Non_unique'],
                $indexList[0]['Index_type'],
                array_column($indexList, 'Column_name')
            );
        }

        $createQuery = $pdo->query("SHOW CREATE TABLE $tableName")->fetch()[1];
        $defaultCharsetSearch = mb_strstr($createQuery, 'DEFAULT CHARSET=');
        if (false !== $defaultCharsetSearch) {
            $defaultCharsetSearch = str_replace('DEFAULT CHARSET=', '', $defaultCharsetSearch);
            $defaultCharset = explode(' ', $defaultCharsetSearch)[0];
        } else {
            $defaultCharset = null;
        }

        $tableStructure = new TableStructure(
            $tableName,
            $rawStatus['Comment'],
            $rawStatus['Engine'],
            $defaultCharset,
            $rawStatus['Collation'],
            $columnStructureList,
            $indexStructureList,
            $partition,
            []
        );

        return $tableStructure;
    }
}