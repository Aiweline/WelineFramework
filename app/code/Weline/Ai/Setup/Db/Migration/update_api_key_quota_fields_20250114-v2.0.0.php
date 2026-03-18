<?php

declare(strict_types=1);

namespace Weline\Ai\Setup\Db\Migration;

use Weline\Ai\Model\AiApiKey;
use Weline\Database\AbstractMigration;
use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;

/**
 * ai_api_key：补充 last_used_time、call_count；配额字段语义以业务/文档为准（与文件名推导类名一致）
 */
class UpdateApiKeyQuotaFields20250114V200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'AI API Key 表配额相关字段补充（last_used_time、call_count）';
    }

    public function getVersion(): string
    {
        return '2.0.0';
    }

    public function install(): bool
    {
        $connection = ObjectManager::getInstance(ConnectionFactory::class)->getConnection();
        $table = ObjectManager::getInstance(AiApiKey::class)->getTable();
        $alter = $connection->alterTable()->forTable($table, AiApiKey::schema_primary_key, '');
        $hasField = $this->columnExistsFn($connection);

        if (!$hasField($table, 'last_used_time')) {
            $alter->addColumn(
                'last_used_time',
                '',
                TableInterface::column_type_INTEGER,
                '',
                'NULL DEFAULT NULL',
                '最后使用时间戳'
            );
        }
        if (!$hasField($table, 'call_count')) {
            $alter->addColumn(
                'call_count',
                '',
                TableInterface::column_type_INTEGER,
                '',
                'NOT NULL DEFAULT 0',
                '累计调用次数'
            );
        }
        $alter->alter();

        return true;
    }

    public function uninstall(): bool
    {
        $connection = ObjectManager::getInstance(ConnectionFactory::class)->getConnection();
        $table = ObjectManager::getInstance(AiApiKey::class)->getTable();
        $hasField = $this->columnExistsFn($connection);
        $alter = $connection->alterTable()->forTable($table, AiApiKey::schema_primary_key, '');
        if ($hasField($table, 'call_count')) {
            $alter->deleteColumn('call_count');
        }
        if ($hasField($table, 'last_used_time')) {
            $alter->deleteColumn('last_used_time');
        }
        $alter->alter();

        return true;
    }

    /** @return callable(string,string):bool */
    private function columnExistsFn(object $connection): callable
    {
        return function (string $t, string $f) use ($connection): bool {
            if (method_exists($connection, 'hasField')) {
                return $connection->hasField($t, $f);
            }
            foreach ($connection->getTableColumns($t) as $col) {
                $name = $col['Field'] ?? $col['field'] ?? $col['column_name'] ?? '';
                if (strcasecmp((string) $name, $f) === 0) {
                    return true;
                }
            }

            return false;
        };
    }
}
