<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Service\Bridge;

use Weline\AiAcquisition\Model\BridgeEvent;
use Weline\AiAcquisition\Model\LeadRun;
use Weline\AiAcquisition\Service\Run\RunService;
use Weline\Framework\Manager\ObjectManager;

use function __;

/**
 * 浏览器桥事件服务。
 *
 * 只负责数据库读写：事件入队、拉取、确认、在线心跳与票据校验；
 * 不做任何输出（echo / print / header），SSE 输出由控制器负责。
 */
class BridgeService
{
    /** 单次拉取上限 */
    public const PULL_LIMIT_MAX = 50;
    /** 事件保留时长（秒） */
    public const EVENT_TTL = 1800;
    /** 票据格式：32 字节 hex（64 位） */
    public const TICKET_PATTERN = '/^[a-f0-9]{16,128}$/i';
    /** 每票据每分钟请求上限 */
    public const RATE_LIMIT_PER_MINUTE = 60;
    /** 心跳元数据最大字节数 */
    private const META_MAX_BYTES = 1800;

    public function __construct(
        private ?RunService $runService = null,
        private ?BridgeEvent $eventModel = null,
        private ?LeadRun $runModel = null,
    ) {
    }

    /**
     * 事件入队，返回事件 ID。
     *
     * @param array<string, mixed> $payload
     */
    public function enqueue(
        int $runId,
        string $type,
        array $payload,
        string $direction = BridgeEvent::DIRECTION_SERVER_TO_BROWSER
    ): int {
        if ($runId <= 0) {
            throw new \InvalidArgumentException(__('运行 ID 无效'));
        }

        $type = self::normalizeType($type);
        $direction = self::normalizeDirection($direction);
        $now = \time();
        $model = $this->eventModel();
        $model->clearData();
        $model->setData([
            BridgeEvent::schema_fields_RUN_ID => $runId,
            BridgeEvent::schema_fields_DIRECTION => $direction,
            BridgeEvent::schema_fields_TYPE => $type,
            BridgeEvent::schema_fields_PAYLOAD_JSON => self::encodePayload($payload),
            BridgeEvent::schema_fields_STATUS => BridgeEvent::STATUS_PENDING,
            BridgeEvent::schema_fields_CREATED_AT => $now,
            BridgeEvent::schema_fields_DELIVERED_AT => 0,
            BridgeEvent::schema_fields_CONSUMED_AT => 0,
            BridgeEvent::schema_fields_EXPIRES_AT => $now + self::EVENT_TTL,
        ]);
        $model->save();

        return (int)$model->getId();
    }

    /**
     * 拉取运行的事件（只返回未过期事件，拉到即标记 delivered）。
     *
     * @return list<array{id:int,run_id:int,type:string,payload:array<string,mixed>,created_at:int}>
     */
    public function pull(int $runId, int $afterEventId, int $limit = 20): array
    {
        if ($runId <= 0) {
            throw new \InvalidArgumentException(__('运行 ID 无效'));
        }

        $limit = self::normalizeLimit($limit);
        $afterEventId = \max(0, $afterEventId);
        $now = \time();

        $rows = $this->eventModel()
            ->clearData()
            ->clearQuery()
            ->where(BridgeEvent::schema_fields_RUN_ID, $runId)
            ->where(BridgeEvent::schema_fields_DIRECTION, BridgeEvent::DIRECTION_SERVER_TO_BROWSER)
            ->where(BridgeEvent::schema_fields_STATUS, BridgeEvent::STATUS_CONSUMED, '!=')
            ->where(BridgeEvent::schema_fields_ID, $afterEventId, '>')
            ->where(BridgeEvent::schema_fields_EXPIRES_AT, $now, '>')
            ->order(BridgeEvent::schema_fields_ID, 'ASC')
            ->limit($limit)
            ->select()
            ->fetchArray();

        if (!\is_array($rows) || $rows === []) {
            return [];
        }

        $events = [];
        $ids = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $id = (int)($row[BridgeEvent::schema_fields_ID] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $ids[] = $id;
            $events[] = [
                'id' => $id,
                'run_id' => (int)($row[BridgeEvent::schema_fields_RUN_ID] ?? $runId),
                'type' => (string)($row[BridgeEvent::schema_fields_TYPE] ?? ''),
                'payload' => self::decodePayload($row[BridgeEvent::schema_fields_PAYLOAD_JSON] ?? null),
                'created_at' => (int)($row[BridgeEvent::schema_fields_CREATED_AT] ?? 0),
            ];
        }

        if ($ids !== []) {
            $this->eventModel()
                ->clearData()
                ->clearQuery()
                ->where(BridgeEvent::schema_fields_RUN_ID, $runId)
                ->where(BridgeEvent::schema_fields_ID, $ids, 'IN')
                ->where(BridgeEvent::schema_fields_STATUS, BridgeEvent::STATUS_PENDING)
                ->update([
                    BridgeEvent::schema_fields_STATUS => BridgeEvent::STATUS_DELIVERED,
                    BridgeEvent::schema_fields_DELIVERED_AT => $now,
                ])
                ->fetch();
        }

        return $events;
    }

    /**
     * 确认事件已消费；只更新本次运行的事件。
     *
     * @param list<int|string> $eventIds
     */
    public function ack(int $runId, array $eventIds): int
    {
        if ($runId <= 0) {
            throw new \InvalidArgumentException(__('运行 ID 无效'));
        }

        $ids = [];
        foreach ($eventIds as $eventId) {
            $eventId = (int)$eventId;
            if ($eventId > 0) {
                $ids[] = $eventId;
            }
        }
        $ids = \array_values(\array_unique($ids));
        if ($ids === []) {
            return 0;
        }

        $rows = $this->eventModel()
            ->clearData()
            ->clearQuery()
            ->where(BridgeEvent::schema_fields_RUN_ID, $runId)
            ->where(BridgeEvent::schema_fields_ID, $ids, 'IN')
            ->where(BridgeEvent::schema_fields_STATUS, BridgeEvent::STATUS_CONSUMED, '!=')
            ->select()
            ->fetchArray();

        $consumedIds = [];
        foreach (\is_array($rows) ? $rows : [] as $row) {
            $id = (int)(\is_array($row) ? ($row[BridgeEvent::schema_fields_ID] ?? 0) : 0);
            if ($id > 0) {
                $consumedIds[] = $id;
            }
        }
        if ($consumedIds === []) {
            return 0;
        }

        $this->eventModel()
            ->clearData()
            ->clearQuery()
            ->where(BridgeEvent::schema_fields_RUN_ID, $runId)
            ->where(BridgeEvent::schema_fields_ID, $consumedIds, 'IN')
            ->update([
                BridgeEvent::schema_fields_STATUS => BridgeEvent::STATUS_CONSUMED,
                BridgeEvent::schema_fields_CONSUMED_AT => \time(),
            ])
            ->fetch();

        return \count($consumedIds);
    }

    /**
     * 浏览器在线心跳：更新运行时间与元数据（敏感键直接丢弃）。
     *
     * @param array<string, mixed> $meta
     */
    public function heartbeat(int $runId, array $meta): void
    {
        if ($runId <= 0) {
            return;
        }

        $run = $this->loadRun($runId);
        if ($run === null) {
            return;
        }

        $existing = self::decodePayload($run[LeadRun::schema_fields_META_JSON] ?? null);
        $merged = \array_merge($existing, $this->sanitizeMeta($meta));
        $encoded = self::encodePayload($merged);
        if (\strlen($encoded) > self::META_MAX_BYTES) {
            $trimmed = [];
            $size = 2;
            foreach ($merged as $key => $value) {
                $piece = self::encodePayload([$key => $value]);
                if ($size + \strlen($piece) > self::META_MAX_BYTES) {
                    break;
                }
                $trimmed[$key] = $value;
                $size += \strlen($piece);
            }
            $encoded = self::encodePayload($trimmed);
        }

        $this->runModel()
            ->clearData()
            ->clearQuery()
            ->where(LeadRun::schema_fields_ID, $runId)
            ->update([
                LeadRun::schema_fields_META_JSON => $encoded,
                LeadRun::schema_fields_UPDATED_AT => \time(),
            ])
            ->fetch();
    }

    /**
     * 票据校验：空值、格式错误、过期、已结束都抛 RuntimeException。
     *
     * @return array<string, mixed> 运行记录
     */
    public function validateTicket(string $ticket): array
    {
        $ticket = \trim($ticket);
        if ($ticket === '' || \strlen($ticket) > 128 || \preg_match(self::TICKET_PATTERN, $ticket) !== 1) {
            throw new \RuntimeException(__('票据为空或格式不正确'), 401);
        }

        $run = $this->runService()->findByTicket($ticket);
        if (!\is_array($run) || $run === []) {
            throw new \RuntimeException(__('票据无效或已过期'), 401);
        }

        return $run;
    }

    /**
     * 运行详情用：最近事件（双方向、倒序、只读不改投递状态）。
     *
     * @return list<array{id:int,type:string,direction:string,payload:array<string,mixed>,created_at:int}>
     */
    public function recent(int $runId, int $limit = 50): array
    {
        if ($runId <= 0) {
            return [];
        }
        $limit = \max(1, \min(100, $limit));

        $rows = $this->eventModel()
            ->clearData()
            ->clearQuery()
            ->where(BridgeEvent::schema_fields_RUN_ID, $runId)
            ->order(BridgeEvent::schema_fields_ID, 'DESC')
            ->limit($limit)
            ->select()
            ->fetchArray();
        if (!\is_array($rows)) {
            return [];
        }

        $events = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $events[] = [
                'id' => (int)($row[BridgeEvent::schema_fields_ID] ?? 0),
                'type' => (string)($row[BridgeEvent::schema_fields_TYPE] ?? ''),
                'direction' => (string)($row[BridgeEvent::schema_fields_DIRECTION] ?? ''),
                'payload' => self::decodePayload($row[BridgeEvent::schema_fields_PAYLOAD_JSON] ?? null),
                'created_at' => (int)($row[BridgeEvent::schema_fields_CREATED_AT] ?? 0),
            ];
        }

        return $events;
    }

    /**
     * 已投递给浏览器的最大事件 ID；没有事件时返回 null。
     */
    public function lastSeen(int $runId): ?int
    {
        if ($runId <= 0) {
            return null;
        }

        $row = $this->eventModel()
            ->clearData()
            ->clearQuery()
            ->where(BridgeEvent::schema_fields_RUN_ID, $runId)
            ->where(BridgeEvent::schema_fields_DIRECTION, BridgeEvent::DIRECTION_SERVER_TO_BROWSER)
            ->where(BridgeEvent::schema_fields_STATUS, BridgeEvent::STATUS_PENDING, '!=')
            ->order(BridgeEvent::schema_fields_ID, 'DESC')
            ->limit(1)
            ->find()
            ->fetchArray();

        $id = \is_array($row) ? (int)($row[BridgeEvent::schema_fields_ID] ?? 0) : 0;

        return $id > 0 ? $id : null;
    }

    /**
     * 每票据每分钟请求数限制（按 browser_to_server 事件计数）。
     */
    public function assertRateLimit(
        int $runId,
        int $max = self::RATE_LIMIT_PER_MINUTE,
        int $windowSeconds = 60
    ): void {
        if ($runId <= 0) {
            throw new \InvalidArgumentException(__('运行 ID 无效'));
        }

        $count = $this->eventModel()
            ->clearData()
            ->clearQuery()
            ->where(BridgeEvent::schema_fields_RUN_ID, $runId)
            ->where(BridgeEvent::schema_fields_DIRECTION, BridgeEvent::DIRECTION_BROWSER_TO_SERVER)
            ->where(BridgeEvent::schema_fields_CREATED_AT, \time() - \max(1, $windowSeconds), '>=')
            ->total();

        if ((int)$count >= \max(1, $max)) {
            throw new \RuntimeException(__('请求过于频繁，请稍后再试'), 429);
        }
    }

    /**
     * 运行已入库线索数 +1（供 /lead 回传使用）。
     */
    public function markLeadSaved(int $runId): void
    {
        $run = $this->loadRun($runId);
        if ($run === null) {
            return;
        }

        $saved = (int)($run[LeadRun::schema_fields_LEADS_SAVED] ?? 0);
        $this->runModel()
            ->clearData()
            ->clearQuery()
            ->where(LeadRun::schema_fields_ID, $runId)
            ->update([
                LeadRun::schema_fields_LEADS_SAVED => $saved + 1,
                LeadRun::schema_fields_UPDATED_AT => \time(),
            ])
            ->fetch();
    }

    /**
     * 单次拉取数量归一化：1–50。
     */
    public static function normalizeLimit(int $limit): int
    {
        return \max(1, \min(self::PULL_LIMIT_MAX, $limit));
    }

    /**
     * 事件类型归一化：1–64 位字母、数字、下划线、斜杠、连字符。
     */
    public static function normalizeType(string $type): string
    {
        $type = \trim($type);
        if ($type === '' || \strlen($type) > 64 || \preg_match('/^[A-Za-z0-9_\-\/]+$/', $type) !== 1) {
            throw new \InvalidArgumentException(__('事件类型不合法'));
        }

        return $type;
    }

    /**
     * 事件方向归一化，只允许两个方向。
     */
    public static function normalizeDirection(string $direction): string
    {
        $direction = \strtolower(\trim($direction));
        if (!\in_array($direction, [
            BridgeEvent::DIRECTION_SERVER_TO_BROWSER,
            BridgeEvent::DIRECTION_BROWSER_TO_SERVER,
        ], true)) {
            throw new \InvalidArgumentException(__('事件方向不合法'));
        }

        return $direction;
    }

    /**
     * 负载编码：必须是合法 UTF-8 JSON。
     *
     * @param array<string, mixed> $payload
     */
    public static function encodePayload(array $payload): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if (\defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= (int)\constant('JSON_INVALID_UTF8_SUBSTITUTE');
        }
        $encoded = \json_encode($payload, $flags);
        if ($encoded === false) {
            throw new \InvalidArgumentException(__('事件负载无法序列化'));
        }

        return $encoded;
    }

    /**
     * 负载解码：非法 JSON 一律当作空数组。
     *
     * @return array<string, mixed>
     */
    public static function decodePayload(mixed $json): array
    {
        if (\is_array($json)) {
            return $json;
        }
        if (!\is_string($json) || \trim($json) === '') {
            return [];
        }
        $decoded = \json_decode($json, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadRun(int $runId): ?array
    {
        if ($runId <= 0) {
            return null;
        }

        $row = $this->runModel()
            ->clearData()
            ->clearQuery()
            ->where(LeadRun::schema_fields_ID, $runId)
            ->find()
            ->fetchArray();

        return \is_array($row) && $row !== [] ? $row : null;
    }

    /**
     * 心跳元数据过滤：票据、密钥类键不入库；只保留标量与小数组。
     *
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function sanitizeMeta(array $meta): array
    {
        $blocked = ['ticket', 'token', 'authorization', 'cookie', 'password', 'secret', 'api_key', 'apikey'];
        $safe = [];
        foreach ($meta as $key => $value) {
            $key = \trim((string)$key);
            if ($key === '' || \strlen($key) > 64) {
                continue;
            }
            $lower = \strtolower($key);
            foreach ($blocked as $word) {
                if (\str_contains($lower, $word)) {
                    continue 2;
                }
            }
            if (\is_scalar($value) || $value === null) {
                $safe[$key] = \is_string($value)
                    ? (\function_exists('mb_substr') ? \mb_substr($value, 0, 200) : \substr($value, 0, 200))
                    : $value;
                continue;
            }
            if (\is_array($value) && \count($value) <= 20) {
                $safe[$key] = \array_slice($value, 0, 20, true);
            }
        }

        return $safe;
    }

    private function runService(): RunService
    {
        if ($this->runService === null) {
            $this->runService = ObjectManager::getInstance(RunService::class);
        }

        return $this->runService;
    }

    private function eventModel(): BridgeEvent
    {
        if ($this->eventModel === null) {
            $this->eventModel = ObjectManager::getInstance(BridgeEvent::class);
        }

        return $this->eventModel;
    }

    private function runModel(): LeadRun
    {
        if ($this->runModel === null) {
            $this->runModel = ObjectManager::getInstance(LeadRun::class);
        }

        return $this->runModel;
    }
}
