<?php
declare(strict_types=1);
namespace Weline\AiKnowledge\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/**
 * Call History Model
 *
 * Stores AI call history for analytics and debugging.
 * Tracks MCP tool calls, search queries, and usage patterns.
 */
#[Table(comment: 'AI call history')]
#[Index(name: 'idx_method', columns: ['method'], comment: 'MCP method name')]
#[Index(name: 'idx_created_at', columns: ['created_at'], comment: 'Created timestamp')]
class CallHistory extends Model
{
    public const schema_table = 'ai_knowledge_call_history';
    public const schema_primary_key = 'id';
    #[Col(type: 'integer', length: 11, nullable: false, primaryKey: true, autoIncrement: true, comment: 'ID')]
    public const schema_fields_ID = 'id';
    #[Col(type: 'varchar', length: 100, nullable: false, comment: 'MCP method name')]
    public const schema_fields_METHOD = 'method';
    #[Col(type: 'text', nullable: true, comment: 'Request parameters (JSON)')]
    public const schema_fields_PARAMS = 'params';
    #[Col(type: 'varchar', length: 50, nullable: true, default: 'success', comment: 'Result type (success/error)')]
    public const schema_fields_RESULT_TYPE = 'result_type';
    #[Col(type: 'integer', length: 11, nullable: true, default: 0, comment: 'Number of results returned')]
    public const schema_fields_RESULT_COUNT = 'result_count';
    #[Col(type: 'integer', length: 11, nullable: true, default: 0, comment: 'Request duration in milliseconds')]
    public const schema_fields_DURATION_MS = 'duration_ms';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: 'Client information')]
    public const schema_fields_CLIENT_INFO = 'client_info';
    #[Col(type: 'timestamp', nullable: true, default: 'CURRENT_TIMESTAMP', comment: 'Created timestamp')]
    public const schema_fields_CREATED_AT = 'created_at';
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
            self::schema_fields_METHOD => $method,
            self::schema_fields_PARAMS => json_encode($params),
            self::schema_fields_RESULT_TYPE => $resultType,
            self::schema_fields_RESULT_COUNT => $resultCount,
            self::schema_fields_DURATION_MS => $durationMs,
            self::schema_fields_CLIENT_INFO => $clientInfo,
        ]);
        $this->save();
        return $this;
    }
    /**
     * Get call statistics
     */
    public function getStatistics(int $days = 30): array
    {
        $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        // Total calls
        $totalCalls = $this->clear()
            ->where(self::schema_fields_CREATED_AT, $since, '>=')
            ->count();
        // Calls by method
        $byMethod = $this->clear()
            ->fields([self::schema_fields_METHOD, 'COUNT(*) as count'])
            ->where(self::schema_fields_CREATED_AT, $since, '>=')
            ->group(self::schema_fields_METHOD)
            ->order('count', 'DESC')
            ->select()
            ->fetchArray();
        // Average duration
        $avgDuration = $this->clear()
            ->fields(['AVG(' . self::schema_fields_DURATION_MS . ') as avg_ms'])
            ->where(self::schema_fields_CREATED_AT, $since, '>=')
            ->find()
            ->fetch();
        // Error rate
        $errorCount = $this->clear()
            ->where(self::schema_fields_CREATED_AT, $since, '>=')
            ->where(self::schema_fields_RESULT_TYPE, 'error')
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
            ->order(self::schema_fields_CREATED_AT, 'DESC')
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
            ->where(self::schema_fields_CREATED_AT, $cutoff, '<')
            ->delete()
            ->fetch();
    }
}
