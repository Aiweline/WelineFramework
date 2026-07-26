<?php

declare(strict_types=1);

namespace Weline\Visitor\Service;

use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use PDO;
use Throwable;
use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Model\Pixel;
use Weline\Visitor\Model\PixelArchive;

/**
 * G09：冷归档明细查询（显式入口；强约束：必选站点、窗口 ≤31 天、强制分页）。
 *
 * 不接入报表引擎聚合路由；超温长周期仍由 PixelQueryRouter 拒绝静默扫热。
 */
class PixelColdArchiveQueryService
{
    public const MAX_WINDOW_DAYS = 31;
    public const DEFAULT_PAGE_SIZE = 50;
    public const MAX_PAGE_SIZE = 200;
    public const MIN_PAGE_SIZE = 1;

    /** @var list<string> 冷查明细允许的预设窗（不含 90d）。 */
    public const ALLOWED_RANGES = ['today', 'yesterday', '7d', '30d', 'custom'];

    /**
     * @param array<string, mixed> $filters
     * @return array{
     *   website_id: int,
     *   website_id_raw: string,
     *   event: string|null,
     *   range: string,
     *   start_date: string,
     *   end_date: string,
     *   start_day: string,
     *   end_day: string,
     *   day_count: int,
     *   channel_code: string|null,
     *   traffic_type: string|null,
     *   utm_source: string|null,
     *   utm_medium: string|null,
     *   utm_campaign: string|null,
     *   page: int,
     *   page_size: int
     * }
     */
    public function normalizeQuery(array $filters = [], int $page = 1, int $pageSize = self::DEFAULT_PAGE_SIZE): array
    {
        $websiteId = $this->requireWebsiteId($filters);
        [$page, $pageSize] = $this->normalizePagination($page, $pageSize);

        $event = trim((string)($filters['event'] ?? ''));
        $event = $event === '' ? null : $event;
        if ($event !== null && strlen($event) > 255) {
            throw new InvalidArgumentException('event filter is too long');
        }

        $range = trim((string)($filters['range'] ?? '30d'));
        if ($range === '') {
            $range = '30d';
        }
        if ($range === '90d') {
            throw new DomainException('cold archive query window exceeds ' . self::MAX_WINDOW_DAYS . ' days');
        }
        if (!\in_array($range, self::ALLOWED_RANGES, true)) {
            throw new InvalidArgumentException('invalid cold archive range');
        }

        $startRaw = trim((string)($filters['startDate'] ?? $filters['start_date'] ?? ''));
        $endRaw = trim((string)($filters['endDate'] ?? $filters['end_date'] ?? ''));

        if ($range === 'custom' || $startRaw !== '' || $endRaw !== '') {
            if ($startRaw === '' || $endRaw === '') {
                throw new InvalidArgumentException('custom range requires start and end dates');
            }
            $startDate = $this->normalizeDate($startRaw, false);
            $endDate = $this->normalizeDate($endRaw, true);
            $range = 'custom';
        } else {
            [$startDate, $endDate] = $this->resolvePresetRange($range);
        }

        if (strtotime($startDate) > strtotime($endDate)) {
            throw new InvalidArgumentException('start date must not be later than end date');
        }

        $startDay = substr($startDate, 0, 10);
        $endDay = substr($endDate, 0, 10);
        $dayCount = $this->countDays($startDay, $endDay);
        if ($dayCount > self::MAX_WINDOW_DAYS) {
            throw new DomainException('cold archive query window exceeds ' . self::MAX_WINDOW_DAYS . ' days');
        }

        $attribution = $this->normalizeAttributionFilters($filters);

        return [
            'website_id' => $websiteId,
            'website_id_raw' => (string)$websiteId,
            'event' => $event,
            'range' => $range,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'start_day' => $startDay,
            'end_day' => $endDay,
            'day_count' => $dayCount,
            'channel_code' => $attribution['channel_code'],
            'traffic_type' => $attribution['traffic_type'],
            'utm_source' => $attribution['utm_source'],
            'utm_medium' => $attribution['utm_medium'],
            'utm_campaign' => $attribution['utm_campaign'],
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    /**
     * 归因筛选归一化（与热 list C03a 同口径；本服务自包含，避免单测依赖 Phrase `__()`）。
     *
     * @param array<string, mixed> $filters
     * @return array{
     *   channel_code: string|null,
     *   traffic_type: string|null,
     *   utm_source: string|null,
     *   utm_medium: string|null,
     *   utm_campaign: string|null
     * }
     */
    private function normalizeAttributionFilters(array $filters): array
    {
        $channelCode = $this->optionalString($filters['channel_code'] ?? $filters['channelCode'] ?? null, 64);
        $trafficType = $this->optionalString($filters['traffic_type'] ?? $filters['trafficType'] ?? null, 32);
        if ($trafficType !== null && !\in_array($trafficType, \Weline\Visitor\Model\PixelChannel::TRAFFIC_TYPES, true)) {
            throw new InvalidArgumentException('invalid traffic_type filter');
        }

        return [
            'channel_code' => $channelCode,
            'traffic_type' => $trafficType,
            'utm_source' => $this->optionalString($filters['utm_source'] ?? $filters['utmSource'] ?? null, 255),
            'utm_medium' => $this->optionalString($filters['utm_medium'] ?? $filters['utmMedium'] ?? null, 255),
            'utm_campaign' => $this->optionalString($filters['utm_campaign'] ?? $filters['utmCampaign'] ?? null, 255),
        ];
    }

    private function optionalString(mixed $raw, int $maxLen): ?string
    {
        if ($raw === null) {
            return null;
        }
        $value = trim((string)$raw);
        if ($value === '') {
            return null;
        }
        if (strlen($value) > $maxLen) {
            throw new InvalidArgumentException('filter value too long');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{
     *   rows: list<array<string, mixed>>,
     *   total: int,
     *   page: int,
     *   page_size: int,
     *   page_count: int,
     *   filters: array<string, mixed>,
     *   error: string,
     *   source: string
     * }
     */
    public function queryPage(
        array $filters = [],
        int $page = 1,
        int $pageSize = self::DEFAULT_PAGE_SIZE,
        ?callable $pageLoader = null,
    ): array {
        try {
            $normalized = $this->normalizeQuery($filters, $page, $pageSize);
        } catch (Throwable $e) {
            [$page, $pageSize] = $this->normalizePagination($page, $pageSize);

            return [
                'rows' => [],
                'total' => 0,
                'page' => $page,
                'page_size' => $pageSize,
                'page_count' => 0,
                'filters' => $this->displayFiltersOnError($filters, $page, $pageSize),
                'error' => $e->getMessage(),
                'source' => 'cold',
            ];
        }

        try {
            if ($pageLoader !== null) {
                $loaded = $pageLoader($normalized);
                $total = (int)($loaded['total'] ?? 0);
                $rows = \is_array($loaded['rows'] ?? null) ? $loaded['rows'] : [];
            } else {
                [$total, $rows] = $this->fetchPage($normalized);
            }

            $pageCount = $total > 0 ? (int)ceil($total / $normalized['page_size']) : 0;
            $page = (int)$normalized['page'];
            if ($pageCount > 0 && $page > $pageCount) {
                $page = $pageCount;
            }

            return [
                'rows' => $rows,
                'total' => $total,
                'page' => $page,
                'page_size' => (int)$normalized['page_size'],
                'page_count' => $pageCount,
                'filters' => $normalized,
                'error' => '',
                'source' => 'cold',
            ];
        } catch (Throwable $e) {
            return [
                'rows' => [],
                'total' => 0,
                'page' => (int)$normalized['page'],
                'page_size' => (int)$normalized['page_size'],
                'page_count' => 0,
                'filters' => $normalized,
                'error' => $e->getMessage(),
                'source' => 'cold',
            ];
        }
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function requireWebsiteId(array $filters): int
    {
        $raw = $filters['website_id'] ?? $filters['websiteId'] ?? null;
        if ($raw === null || $raw === '' || $raw === 'all') {
            throw new DomainException('cold archive query requires website_id');
        }
        if (!is_numeric($raw)) {
            throw new InvalidArgumentException('invalid website_id');
        }
        $websiteId = (int)$raw;
        if ($websiteId < 0) {
            throw new InvalidArgumentException('invalid website_id');
        }

        return $websiteId;
    }

    /**
     * @return array{0: int, 1: int}
     */
    public function normalizePagination(int $page, int $pageSize): array
    {
        $page = max(1, $page);
        if ($pageSize < self::MIN_PAGE_SIZE) {
            $pageSize = self::DEFAULT_PAGE_SIZE;
        }
        $pageSize = min(self::MAX_PAGE_SIZE, max(self::MIN_PAGE_SIZE, $pageSize));

        return [$page, $pageSize];
    }

    /**
     * @param array{
     *   website_id: int,
     *   start_date: string,
     *   end_date: string,
     *   event: string|null,
     *   channel_code: string|null,
     *   traffic_type: string|null,
     *   utm_source: string|null,
     *   utm_medium: string|null,
     *   utm_campaign: string|null,
     *   page: int,
     *   page_size: int
     * } $normalized
     * @return array{0: int, 1: list<array<string, mixed>>}
     */
    private function fetchPage(array $normalized): array
    {
        [$whereSql, $params] = $this->buildWhere($normalized);
        $table = $this->quoteTable(PixelArchive::schema_table);
        $countRow = $this->fetchOne("SELECT COUNT(*) AS cnt FROM {$table} a WHERE {$whereSql}", $params);
        $total = (int)($countRow['cnt'] ?? 0);

        $page = (int)$normalized['page'];
        $pageSize = (int)$normalized['page_size'];
        $pageCount = $total > 0 ? (int)ceil($total / $pageSize) : 0;
        if ($pageCount > 0 && $page > $pageCount) {
            $page = $pageCount;
        }
        $offset = ($page - 1) * $pageSize;

        $created = $this->qi(PixelArchive::schema_fields_CREATED_AT);
        $pixelId = $this->qi(PixelArchive::schema_fields_PIXEL_ID);
        $limitSql = $this->pdoDriver() === 'mysql'
            ? "LIMIT {$offset}, {$pageSize}"
            : "LIMIT {$pageSize} OFFSET {$offset}";

        $sql = 'SELECT a.' . $this->qi(PixelArchive::schema_fields_ID) . ' AS pixel_archive_id,'
            . ' a.' . $pixelId . ' AS pixel_id,'
            . ' a.' . $this->qi(PixelArchive::schema_fields_WEBSITE_ID) . ' AS website_id,'
            . ' a.' . $this->qi(PixelArchive::schema_fields_EVENT) . ' AS event,'
            . ' a.' . $this->qi(PixelArchive::schema_fields_URL) . ' AS url,'
            . ' a.' . $this->qi(PixelArchive::schema_fields_IP) . ' AS ip,'
            . ' a.' . $this->qi(PixelArchive::schema_fields_SOURCE) . ' AS source,'
            . ' a.' . $this->qi(PixelArchive::schema_fields_SESSION_ID) . ' AS session_id,'
            . ' a.' . $this->qi(PixelArchive::schema_fields_CHANNEL_CODE) . ' AS channel_code,'
            . ' a.' . $this->qi(PixelArchive::schema_fields_TRAFFIC_TYPE) . ' AS traffic_type,'
            . ' a.' . $this->qi(PixelArchive::schema_fields_UTM_SOURCE) . ' AS utm_source,'
            . ' a.' . $this->qi(PixelArchive::schema_fields_UTM_MEDIUM) . ' AS utm_medium,'
            . ' a.' . $this->qi(PixelArchive::schema_fields_UTM_CAMPAIGN) . ' AS utm_campaign,'
            . ' a.' . $this->qi(PixelArchive::schema_fields_VALUE) . ' AS value,'
            . ' a.' . $created . ' AS created_at,'
            . ' a.' . $this->qi(PixelArchive::schema_fields_ARCHIVED_AT) . ' AS archived_at'
            . " FROM {$table} a"
            . " WHERE {$whereSql}"
            . " ORDER BY a.{$created} DESC, a.{$pixelId} DESC"
            . " {$limitSql}";

        $rawRows = $this->fetchAll($sql, $params);
        $rows = [];
        foreach ($rawRows as $row) {
            $rows[] = [
                'pixel_archive_id' => (int)($row['pixel_archive_id'] ?? 0),
                'pixel_id' => (int)($row['pixel_id'] ?? 0),
                'website_id' => (int)($row['website_id'] ?? 0),
                'event' => (string)($row['event'] ?? ''),
                'url' => (string)($row['url'] ?? ''),
                'ip' => (string)($row['ip'] ?? ''),
                'source' => (string)($row['source'] ?? ''),
                'session_id' => (string)($row['session_id'] ?? ''),
                'channel_code' => (string)($row['channel_code'] ?? ''),
                'traffic_type' => (string)($row['traffic_type'] ?? ''),
                'utm_source' => (string)($row['utm_source'] ?? ''),
                'utm_medium' => (string)($row['utm_medium'] ?? ''),
                'utm_campaign' => (string)($row['utm_campaign'] ?? ''),
                'value' => (float)($row['value'] ?? 0),
                'created_at' => $row['created_at'] ?? null,
                'archived_at' => $row['archived_at'] ?? null,
            ];
        }

        return [$total, $rows];
    }

    /**
     * @param array<string, mixed> $normalized
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildWhere(array $normalized): array
    {
        $clauses = [
            'a.' . $this->qi(PixelArchive::schema_fields_WEBSITE_ID) . ' = :website_id',
            'a.' . $this->qi(PixelArchive::schema_fields_CREATED_AT) . ' >= :start_date',
            'a.' . $this->qi(PixelArchive::schema_fields_CREATED_AT) . ' <= :end_date',
        ];
        $params = [
            'website_id' => (int)$normalized['website_id'],
            'start_date' => (string)$normalized['start_date'],
            'end_date' => (string)$normalized['end_date'],
        ];

        if (!empty($normalized['event'])) {
            $clauses[] = 'a.' . $this->qi(PixelArchive::schema_fields_EVENT) . ' = :event';
            $params['event'] = (string)$normalized['event'];
        }
        foreach (
            [
                'channel_code' => PixelArchive::schema_fields_CHANNEL_CODE,
                'traffic_type' => PixelArchive::schema_fields_TRAFFIC_TYPE,
                'utm_source' => PixelArchive::schema_fields_UTM_SOURCE,
                'utm_medium' => PixelArchive::schema_fields_UTM_MEDIUM,
                'utm_campaign' => PixelArchive::schema_fields_UTM_CAMPAIGN,
            ] as $key => $col
        ) {
            if (!empty($normalized[$key])) {
                $clauses[] = 'a.' . $this->qi($col) . ' = :' . $key;
                $params[$key] = (string)$normalized[$key];
            }
        }

        return [implode(' AND ', $clauses), $params];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function displayFiltersOnError(array $filters, int $page, int $pageSize): array
    {
        [$page, $pageSize] = $this->normalizePagination($page, $pageSize);
        $rawWebsite = trim((string)($filters['websiteId'] ?? $filters['website_id'] ?? ''));

        return [
            'website_id' => null,
            'website_id_raw' => $rawWebsite,
            'event' => trim((string)($filters['event'] ?? '')) ?: null,
            'range' => trim((string)($filters['range'] ?? '30d')) ?: '30d',
            'start_day' => trim((string)($filters['startDate'] ?? $filters['start_date'] ?? '')),
            'end_day' => trim((string)($filters['endDate'] ?? $filters['end_date'] ?? '')),
            'channel_code' => trim((string)($filters['channel_code'] ?? '')) ?: null,
            'traffic_type' => trim((string)($filters['traffic_type'] ?? '')) ?: null,
            'utm_source' => trim((string)($filters['utm_source'] ?? '')) ?: null,
            'utm_medium' => trim((string)($filters['utm_medium'] ?? '')) ?: null,
            'utm_campaign' => trim((string)($filters['utm_campaign'] ?? '')) ?: null,
            'page' => $page,
            'page_size' => $pageSize,
            'day_count' => 0,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolvePresetRange(string $range): array
    {
        $today = new DateTimeImmutable('today');

        return match ($range) {
            'today' => [
                $today->format('Y-m-d 00:00:00'),
                $today->format('Y-m-d 23:59:59'),
            ],
            'yesterday' => [
                $today->modify('-1 day')->format('Y-m-d 00:00:00'),
                $today->modify('-1 day')->format('Y-m-d 23:59:59'),
            ],
            '7d' => [
                $today->modify('-6 days')->format('Y-m-d 00:00:00'),
                $today->format('Y-m-d 23:59:59'),
            ],
            default => [
                $today->modify('-29 days')->format('Y-m-d 00:00:00'),
                $today->format('Y-m-d 23:59:59'),
            ],
        };
    }

    private function normalizeDate(string $value, bool $endOfDay): string
    {
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value) {
            return $endOfDay ? $date->format('Y-m-d 23:59:59') : $date->format('Y-m-d 00:00:00');
        }

        $dateTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        if ($dateTime instanceof DateTimeImmutable && $dateTime->format('Y-m-d H:i:s') === $value) {
            return $dateTime->format('Y-m-d H:i:s');
        }

        throw new InvalidArgumentException('invalid date format, use YYYY-MM-DD');
    }

    private function countDays(string $startDay, string $endDay): int
    {
        $start = new DateTimeImmutable($startDay);
        $end = new DateTimeImmutable($endDay);

        return ((int)$start->diff($end)->days) + 1;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function fetchOne(string $sql, array $params): array
    {
        $rows = $this->fetchAll($sql, $params);

        return $rows[0] ?? [];
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function fetchAll(string $sql, array $params): array
    {
        $pdo = ObjectManager::getInstance(Pixel::class)->getConnection()->getConnector()->getLink();
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . ltrim((string)$key, ':'), $value);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return \is_array($rows) ? $rows : [];
    }

    private function pdoDriver(): string
    {
        try {
            $pdo = ObjectManager::getInstance(Pixel::class)->getConnection()->getConnector()->getLink();

            return (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (Throwable) {
            return 'sqlite';
        }
    }

    private function quoteTable(string $name): string
    {
        $prefix = '';
        try {
            $prefix = (string)ObjectManager::getInstance(Pixel::class)
                ->getConnection()
                ->getConfigProvider()
                ->getPrefix();
        } catch (Throwable) {
            $prefix = '';
        }

        return $this->qi($prefix . $name);
    }

    private function qi(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }
}
