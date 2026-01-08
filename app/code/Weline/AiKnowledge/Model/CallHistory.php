<?php

declare(strict_types=1);

namespace Weline\AiKnowledge\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\DataInterface;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * Call History Model
 * 
 * Stores AI call history for analytics and debugging.
 * Tracks MCP tool calls, search queries, and usage patterns.
 */
class CallHistory extends Model implements DataInterface
{
    public string $table = 'ai_knowledge_call_history';
    
    public const fields_ID = 'id';
    public const fields_METHOD = 'method';
    public const fields_PARAMS = 'params';
    public const fields_RESULT_TYPE = 'result_type';
    public const fields_RESULT_COUNT = 'result_count';
    public const fields_DURATION_MS = 'duration_ms';
    public const fields_CLIENT_INFO = 'client_info';
    public const fields_CREATED_AT = 'created_at';
    
    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }
    
    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // No upgrade needed for now
        // Future upgrades can be added here
    }
    
    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable()
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    11,
                    'primary key auto_increment',
                    'ID'
                )
                ->addColumn(
                    self::fields_METHOD,
                    TableInterface::column_type_VARCHAR,
                    100,
                    'not null',
                    'MCP method name'
                )
                ->addColumn(
                    self::fields_PARAMS,
                    TableInterface::column_type_TEXT,
                    null,
                    '',
                    'Request parameters (JSON)'
                )
                ->addColumn(
                    self::fields_RESULT_TYPE,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'default "success"',
                    'Result type (success/error)'
                )
                ->addColumn(
                    self::fields_RESULT_COUNT,
                    TableInterface::column_type_INTEGER,
                    11,
                    'default 0',
                    'Number of results returned'
                )
                ->addColumn(
                    self::fields_DURATION_MS,
                    TableInterface::column_type_INTEGER,
                    11,
                    'default 0',
                    'Request duration in milliseconds'
                )
                ->addColumn(
                    self::fields_CLIENT_INFO,
                    TableInterface::column_type_VARCHAR,
                    255,
                    '',
                    'Client information'
                )
                ->addColumn(
                    self::fields_CREATED_AT,
                    TableInterface::column_type_TIMESTAMP,
                    null,
                    'default CURRENT_TIMESTAMP',
                    'Created timestamp'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_method',
                    self::fields_METHOD
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_created_at',
                    self::fields_CREATED_AT
                )
                ->create();
        }
    }
    
    /**
     * Record a call
     */
    public function recordCall(
        string $method,
        array $params,
        string $resultType = 'success',
        int $resultCount = 0,
        int $durationMs = 0,
        string $clientInfo = ''
    ): self {
        $this->setData([
            self::fields_METHOD => $method,
            self::fields_PARAMS => json_encode($params),
            self::fields_RESULT_TYPE => $resultType,
            self::fields_RESULT_COUNT => $resultCount,
            self::fields_DURATION_MS => $durationMs,
            self::fields_CLIENT_INFO => $clientInfo,
        ]);
        
        return $this->save();
    }
    
    /**
     * Get call statistics
     */
    public function getStatistics(int $days = 30): array
    {
        $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        // Total calls
        $totalCalls = $this->clear()
            ->where(self::fields_CREATED_AT, $since, '>=')
            ->count();
        
        // Calls by method
        $byMethod = $this->clear()
            ->fields([self::fields_METHOD, 'COUNT(*) as count'])
            ->where(self::fields_CREATED_AT, $since, '>=')
            ->group(self::fields_METHOD)
            ->order('count', 'DESC')
            ->select()
            ->fetchArray();
        
        // Average duration
        $avgDuration = $this->clear()
            ->fields(['AVG(' . self::fields_DURATION_MS . ') as avg_ms'])
            ->where(self::fields_CREATED_AT, $since, '>=')
            ->find()
            ->fetch();
        
        // Error rate
        $errorCount = $this->clear()
            ->where(self::fields_CREATED_AT, $since, '>=')
            ->where(self::fields_RESULT_TYPE, 'error')
            ->count();
        
        return [
            'total_calls' => $totalCalls,
            'by_method' => $byMethod,
            'avg_duration_ms' => round((float)($avgDuration['avg_ms'] ?? 0), 2),
            'error_count' => $errorCount,
            'error_rate' => $totalCalls > 0 ? round($errorCount / $totalCalls * 100, 2) : 0,
            'period_days' => $days,
        ];
    }
    
    /**
     * Get recent calls
     */
    public function getRecentCalls(int $limit = 100): array
    {
        return $this->clear()
            ->order(self::fields_CREATED_AT, 'DESC')
            ->limit($limit)
            ->select()
            ->fetchArray();
    }
    
    /**
     * Clean up old records
     */
    public function cleanup(int $keepDays = 90): int
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$keepDays} days"));
        
        return $this->clear()
            ->where(self::fields_CREATED_AT, $cutoff, '<')
            ->delete()
            ->fetch();
    }
}
