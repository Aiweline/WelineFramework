<?php

declare(strict_types=1);

namespace Weline\Visitor\Service;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;
use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Model\Pixel;
use Weline\Visitor\Model\PixelArchive;

/**
 * G07：热表 → pixel_archive 手动迁移（只复制，永不删热；删热属 G08）。
 */
class PixelArchiveMigrateService
{
    public const DEFAULT_LIMIT = 500;
    public const MAX_LIMIT = 5000;
    public const DEFAULT_HOT_DAYS = 365;

    /**
     * @param array{
     *   website_id?: int|null,
     *   before?: string|null,
     *   after?: string|null,
     *   limit?: int,
     *   offset?: int
     * } $options
     * @return array{
     *   website_id: int|null,
     *   before: string,
     *   after: string|null,
     *   limit: int,
     *   offset: int,
     *   dry_run: bool,
     *   deletes_hot: false,
     *   candidates: int,
     *   already_archived: int,
     *   would_insert: int,
     *   inserted: int,
     *   skipped: int,
     *   sample_pixel_ids: list<int>,
     *   message: string
     * }
     */
    public function dryRun(array $options = [], ?callable $candidateLoader = null, ?callable $existingIdsLoader = null): array
    {
        return $this->run(false, $options, $candidateLoader, $existingIdsLoader, null);
    }

    /**
     * @param array{
     *   website_id?: int|null,
     *   before?: string|null,
     *   after?: string|null,
     *   limit?: int,
     *   offset?: int
     * } $options
     * @return array{
     *   website_id: int|null,
     *   before: string,
     *   after: string|null,
     *   limit: int,
     *   offset: int,
     *   dry_run: bool,
     *   deletes_hot: false,
     *   candidates: int,
     *   already_archived: int,
     *   would_insert: int,
     *   inserted: int,
     *   skipped: int,
     *   sample_pixel_ids: list<int>,
     *   message: string
     * }
     */
    public function migrate(array $options = [], ?callable $candidateLoader = null, ?callable $existingIdsLoader = null, ?callable $inserter = null): array
    {
        return $this->run(true, $options, $candidateLoader, $existingIdsLoader, $inserter);
    }

    /**
     * @param array<string, mixed> $options
     * @return array{
     *   website_id: int|null,
     *   before: string,
     *   after: string|null,
     *   limit: int,
     *   offset: int
     * }
     */
    public function normalizeOptions(array $options): array
    {
        $websiteId = array_key_exists('website_id', $options) ? $options['website_id'] : null;
        if ($websiteId !== null) {
            $websiteId = (int)$websiteId;
            if ($websiteId < 0) {
                $websiteId = null;
            }
        }

        $hotDays = (int)($options['hot_days'] ?? self::DEFAULT_HOT_DAYS);
        $hotDays = max(1, $hotDays);

        $before = trim((string)($options['before'] ?? ''));
        if ($before === '') {
            $before = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->modify('-' . $hotDays . ' days')
                ->format('Y-m-d H:i:s');
        } else {
            $before = $this->normalizeDateTime($before, true);
        }

        $after = trim((string)($options['after'] ?? ''));
        $after = $after === '' ? null : $this->normalizeDateTime($after, false);

        $limit = (int)($options['limit'] ?? self::DEFAULT_LIMIT);
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $offset = max(0, (int)($options['offset'] ?? 0));

        return [
            'website_id' => $websiteId,
            'before' => $before,
            'after' => $after,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * @param array<string, mixed> $hotRow
     * @return array<string, mixed>
     */
    public function mapHotRowToArchive(array $hotRow, ?string $archivedAt = null): array
    {
        $archivedAt ??= date('Y-m-d H:i:s');
        $out = [
            PixelArchive::schema_fields_ARCHIVED_AT => $archivedAt,
        ];
        foreach (PixelArchive::HOT_MIRROR_FIELDS as $field) {
            if ($field === 'pixel_id') {
                $out[$field] = (int)($hotRow[Pixel::schema_fields_ID] ?? $hotRow['pixel_id'] ?? 0);
                continue;
            }
            if ($field === 'value') {
                $out[$field] = (float)($hotRow[Pixel::schema_fields_VALUE] ?? $hotRow['value'] ?? 0);
                continue;
            }
            if ($field === 'website_id' || $field === 'user_id' || $field === 'cron_deal') {
                $out[$field] = (int)($hotRow[$field] ?? 0);
                continue;
            }
            $out[$field] = $hotRow[$field] ?? null;
        }

        return $out;
    }

    /**
     * 从候选行与已归档 ID 集合计算插入计划（纯逻辑）。
     *
     * @param list<array<string, mixed>> $candidates
     * @param list<int>|array<int, true> $existingPixelIds
     * @return array{
     *   to_insert: list<array<string, mixed>>,
     *   already_archived: int,
     *   skipped: int,
     *   sample_pixel_ids: list<int>
     * }
     */
    public function planInserts(array $candidates, array $existingPixelIds, ?string $archivedAt = null): array
    {
        $existing = [];
        foreach ($existingPixelIds as $key => $value) {
            if (\is_int($key) && $value === true) {
                $existing[$key] = true;
                continue;
            }
            $existing[(int)$value] = true;
        }

        $toInsert = [];
        $already = 0;
        $skipped = 0;
        $sample = [];
        $seenInBatch = [];
        foreach ($candidates as $row) {
            if (!\is_array($row)) {
                $skipped++;
                continue;
            }
            $pixelId = (int)($row[Pixel::schema_fields_ID] ?? $row['pixel_id'] ?? 0);
            if ($pixelId <= 0) {
                $skipped++;
                continue;
            }
            if (isset($existing[$pixelId])) {
                $already++;
                continue;
            }
            if (isset($seenInBatch[$pixelId])) {
                $skipped++;
                continue;
            }
            $seenInBatch[$pixelId] = true;
            $mapped = $this->mapHotRowToArchive($row, $archivedAt);
            $toInsert[] = $mapped;
            if (\count($sample) < 10) {
                $sample[] = $pixelId;
            }
        }

        return [
            'to_insert' => $toInsert,
            'already_archived' => $already,
            'skipped' => $skipped,
            'sample_pixel_ids' => $sample,
        ];
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function buildCandidateSql(?int $websiteId, string $before, ?string $after, int $limit, int $offset): array
    {
        $table = $this->quoteTable(Pixel::schema_table);
        $created = 'COALESCE(p.' . $this->qi(Pixel::schema_fields_CREATED_AT) . ', p.' . $this->qi('create_time') . ')';
        $sql = "SELECT p.* FROM {$table} p WHERE {$created} < :before";
        $params = ['before' => $before];
        if ($after !== null) {
            $sql .= " AND {$created} >= :after";
            $params['after'] = $after;
        }
        if ($websiteId !== null) {
            $sql .= ' AND p.' . $this->qi(Pixel::schema_fields_WEBSITE_ID) . ' = :website_id';
            $params['website_id'] = $websiteId;
        }
        $sql .= ' ORDER BY ' . $created . ' ASC, p.' . $this->qi(Pixel::schema_fields_ID) . ' ASC'
            . ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;

        return [$sql, $params];
    }

    /**
     * @param list<int> $pixelIds
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function buildExistingIdsSql(array $pixelIds): array
    {
        $ids = [];
        foreach ($pixelIds as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        $ids = array_values($ids);
        if ($ids === []) {
            return ['SELECT 0 AS pixel_id WHERE 0', []];
        }

        $table = $this->quoteTable(PixelArchive::schema_table);
        $placeholders = [];
        $params = [];
        foreach ($ids as $i => $id) {
            $key = 'id' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }
        $sql = 'SELECT ' . $this->qi(PixelArchive::schema_fields_PIXEL_ID)
            . ' FROM ' . $table
            . ' WHERE ' . $this->qi(PixelArchive::schema_fields_PIXEL_ID)
            . ' IN (' . implode(', ', $placeholders) . ')';

        return [$sql, $params];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function run(
        bool $write,
        array $options,
        ?callable $candidateLoader,
        ?callable $existingIdsLoader,
        ?callable $inserter
    ): array {
        $normalized = $this->normalizeOptions($options);
        $candidates = $candidateLoader !== null
            ? $candidateLoader($normalized)
            : $this->loadCandidates($normalized);
        if (!\is_array($candidates)) {
            $candidates = [];
        }

        $pixelIds = [];
        foreach ($candidates as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $id = (int)($row[Pixel::schema_fields_ID] ?? $row['pixel_id'] ?? 0);
            if ($id > 0) {
                $pixelIds[] = $id;
            }
        }

        $existing = $existingIdsLoader !== null
            ? $existingIdsLoader($pixelIds)
            : $this->loadExistingPixelIds($pixelIds);
        if (!\is_array($existing)) {
            $existing = [];
        }

        $plan = $this->planInserts($candidates, $existing);
        $inserted = 0;
        if ($write && $plan['to_insert'] !== []) {
            if ($inserter !== null) {
                $inserted = (int)$inserter($plan['to_insert']);
            } else {
                $inserted = $this->insertArchiveRows($plan['to_insert']);
            }
        }

        return [
            'website_id' => $normalized['website_id'],
            'before' => $normalized['before'],
            'after' => $normalized['after'],
            'limit' => $normalized['limit'],
            'offset' => $normalized['offset'],
            'dry_run' => !$write,
            'deletes_hot' => false,
            'candidates' => \count($candidates),
            'already_archived' => $plan['already_archived'],
            'would_insert' => \count($plan['to_insert']),
            'inserted' => $inserted,
            'skipped' => $plan['skipped'],
            'sample_pixel_ids' => $plan['sample_pixel_ids'],
            'message' => $write
                ? 'copied to pixel_archive; hot rows NOT deleted (G08 retention owns deletes)'
                : 'dry-run only; no writes; hot rows NOT deleted',
        ];
    }

    /**
     * @param array{website_id: int|null, before: string, after: string|null, limit: int, offset: int} $normalized
     * @return list<array<string, mixed>>
     */
    private function loadCandidates(array $normalized): array
    {
        [$sql, $params] = $this->buildCandidateSql(
            $normalized['website_id'],
            $normalized['before'],
            $normalized['after'],
            $normalized['limit'],
            $normalized['offset']
        );

        return $this->fetchAll($sql, $params);
    }

    /**
     * @param list<int> $pixelIds
     * @return list<int>
     */
    private function loadExistingPixelIds(array $pixelIds): array
    {
        if ($pixelIds === []) {
            return [];
        }
        [$sql, $params] = $this->buildExistingIdsSql($pixelIds);
        $rows = $this->fetchAll($sql, $params);
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int)($row['pixel_id'] ?? 0);
        }

        return $ids;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function insertArchiveRows(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }
        /** @var PixelArchive $model */
        $model = ObjectManager::getInstance(PixelArchive::class);
        $model->reset()->insert($rows, [], '', true)->fetch();

        return \count($rows);
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

    private function normalizeDateTime(string $raw, bool $endOfDayIfDateOnly): string
    {
        $raw = trim($raw);
        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
                $dt = new DateTimeImmutable($raw . ($endOfDayIfDateOnly ? ' 23:59:59' : ' 00:00:00'), new DateTimeZone('UTC'));
            } else {
                $dt = new DateTimeImmutable($raw, new DateTimeZone('UTC'));
            }

            return $dt->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            throw new \InvalidArgumentException('invalid datetime: ' . $raw, 0, $e);
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
