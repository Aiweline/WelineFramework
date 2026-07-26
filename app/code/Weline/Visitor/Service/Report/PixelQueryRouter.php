<?php

declare(strict_types=1);

namespace Weline\Visitor\Service\Report;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DomainException;
use InvalidArgumentException;
use Weline\Visitor\Model\Pixel;
use Weline\Visitor\Model\PixelStatsDaily;
use Weline\Visitor\Model\PixelStatsHourly;

/**
 * D03 + G06：报表查询路由（热明细 + 温聚合）。
 *
 * - 短窗（≤ hotWindowDays）且落在热保留内 → hot / w_pixel
 * - 长窗或早于热保留、但仍在温保留内 → warm_daily / pixel_stats_daily（默认）
 * - 超温保留或高基维（温表无列）→ DomainException（冷明细不走本聚合路由；见 G09
 *   `PixelColdArchiveQueryService` / `pixel-dashboard/archive-list`，须 website_id + ≤31 天 + 分页）
 */
class PixelQueryRouter
{
    public const SOURCE_HOT = 'hot';
    public const SOURCE_WARM_DAILY = 'warm_daily';
    public const SOURCE_WARM_HOURLY = 'warm_hourly';

    public const DEFAULT_HOT_WINDOW_DAYS = 7;
    public const DEFAULT_HOT_RETENTION_DAYS = 365;
    public const DEFAULT_WARM_RETENTION_DAYS = 1095;

    /** 落入温边界且跨度不超过该值时可用小时温表（少见；默认仍走日表）。 */
    public const DEFAULT_HOURLY_WARM_MAX_DAYS = 2;

    public function __construct(
        private ?PixelDimensionRegistry $dimensionRegistry = null,
        private int $hotWindowDays = self::DEFAULT_HOT_WINDOW_DAYS,
        private int $hotRetentionDays = self::DEFAULT_HOT_RETENTION_DAYS,
        private int $warmRetentionDays = self::DEFAULT_WARM_RETENTION_DAYS,
        private int $hourlyWarmMaxDays = self::DEFAULT_HOURLY_WARM_MAX_DAYS,
    ) {
        if ($this->hotWindowDays < 1) {
            throw new InvalidArgumentException('hot window days must be greater than zero');
        }
        if ($this->hotRetentionDays < $this->hotWindowDays) {
            throw new InvalidArgumentException('hot retention days must cover the hot query window');
        }
        if ($this->warmRetentionDays < $this->hotRetentionDays) {
            throw new InvalidArgumentException('warm retention days must cover hot retention');
        }
        if ($this->hourlyWarmMaxDays < 1) {
            throw new InvalidArgumentException('hourly warm max days must be greater than zero');
        }
    }

    /**
     * @param list<string> $dimensionIds
     * @return array{
     *   source: string,
     *   table: string,
     *   time_field: string,
     *   grain: string,
     *   from: string,
     *   to: string,
     *   dimensions: list<string>
     * }
     */
    public function route(
        array $dimensionIds,
        DateTimeInterface $from,
        DateTimeInterface $to,
        ?DateTimeInterface $now = null,
    ): array {
        $dimensions = $this->normalizeDimensions($dimensionIds);
        $fromAt = DateTimeImmutable::createFromInterface($from);
        $toAt = DateTimeImmutable::createFromInterface($to);
        $nowAt = $now === null
            ? new DateTimeImmutable('now', $fromAt->getTimezone())
            : DateTimeImmutable::createFromInterface($now);

        if ($toAt < $fromAt) {
            throw new InvalidArgumentException('query end must not be earlier than query start');
        }

        $spanSeconds = $toAt->getTimestamp() - $fromAt->getTimestamp();
        $spanDays = $spanSeconds / 86400;
        $hotBoundary = $nowAt->sub(new DateInterval('P' . $this->hotRetentionDays . 'D'));
        $warmBoundary = $nowAt->sub(new DateInterval('P' . $this->warmRetentionDays . 'D'));

        $withinHotWindow = $spanDays <= $this->hotWindowDays && $fromAt >= $hotBoundary;
        if ($withinHotWindow) {
            return $this->hotRoute($fromAt, $toAt, $dimensions);
        }

        if ($fromAt < $warmBoundary) {
            throw new DomainException('cold archive route is not available');
        }

        $this->assertWarmCompatibleDimensions($dimensions);

        // 长窗默认日表；仅极短温窗（例如刚超热保留的 1–2 天）可走小时温表
        if ($spanDays <= $this->hourlyWarmMaxDays && $fromAt < $hotBoundary) {
            return $this->warmHourlyRoute($fromAt, $toAt, $dimensions);
        }

        return $this->warmDailyRoute($fromAt, $toAt, $dimensions);
    }

    public function isWarmSource(string $source): bool
    {
        return \in_array($source, [self::SOURCE_WARM_DAILY, self::SOURCE_WARM_HOURLY], true);
    }

    public function getHotWindowDays(): int
    {
        return $this->hotWindowDays;
    }

    public function getHotRetentionDays(): int
    {
        return $this->hotRetentionDays;
    }

    public function getWarmRetentionDays(): int
    {
        return $this->warmRetentionDays;
    }

    /**
     * @param list<string> $dimensionIds
     * @return list<string>
     */
    private function normalizeDimensions(array $dimensionIds): array
    {
        $dimensions = [];
        foreach ($dimensionIds as $dimensionId) {
            $dimensionId = trim((string)$dimensionId);
            if ($dimensionId === '' || isset($dimensions[$dimensionId])) {
                continue;
            }
            $dimensions[$dimensionId] = $dimensionId;
        }

        $ids = array_values($dimensions);
        $this->dimensions()->assertKnown($ids);

        return $ids;
    }

    /**
     * 温表仅含默认小时全维；高基维 / channel_name 等不得走温。
     *
     * @param list<string> $dimensionIds
     */
    private function assertWarmCompatibleDimensions(array $dimensionIds): void
    {
        $allowed = array_fill_keys($this->dimensions()->defaultHourlyIds(), true);
        foreach ($dimensionIds as $id) {
            if (!isset($allowed[$id])) {
                throw new DomainException('warm aggregate does not support dimension: ' . $id);
            }
        }
    }

    /**
     * @param list<string> $dimensions
     * @return array{
     *   source: string,
     *   table: string,
     *   time_field: string,
     *   grain: string,
     *   from: string,
     *   to: string,
     *   dimensions: list<string>
     * }
     */
    private function hotRoute(DateTimeImmutable $fromAt, DateTimeImmutable $toAt, array $dimensions): array
    {
        return [
            'source' => self::SOURCE_HOT,
            'table' => Pixel::schema_table,
            'time_field' => Pixel::schema_fields_CREATED_AT,
            'grain' => 'event',
            'from' => $fromAt->format('Y-m-d H:i:s'),
            'to' => $toAt->format('Y-m-d H:i:s'),
            'dimensions' => $dimensions,
        ];
    }

    /**
     * @param list<string> $dimensions
     * @return array{
     *   source: string,
     *   table: string,
     *   time_field: string,
     *   grain: string,
     *   from: string,
     *   to: string,
     *   dimensions: list<string>
     * }
     */
    private function warmDailyRoute(DateTimeImmutable $fromAt, DateTimeImmutable $toAt, array $dimensions): array
    {
        return [
            'source' => self::SOURCE_WARM_DAILY,
            'table' => PixelStatsDaily::schema_table,
            'time_field' => PixelStatsDaily::schema_fields_DAY_BUCKET,
            'grain' => 'day',
            'from' => $fromAt->format('Y-m-d'),
            'to' => $toAt->format('Y-m-d'),
            'dimensions' => $dimensions,
        ];
    }

    /**
     * @param list<string> $dimensions
     * @return array{
     *   source: string,
     *   table: string,
     *   time_field: string,
     *   grain: string,
     *   from: string,
     *   to: string,
     *   dimensions: list<string>
     * }
     */
    private function warmHourlyRoute(DateTimeImmutable $fromAt, DateTimeImmutable $toAt, array $dimensions): array
    {
        return [
            'source' => self::SOURCE_WARM_HOURLY,
            'table' => PixelStatsHourly::schema_table,
            'time_field' => PixelStatsHourly::schema_fields_HOUR_BUCKET,
            'grain' => 'hour',
            'from' => $fromAt->format('Y-m-d H:i:s'),
            'to' => $toAt->format('Y-m-d H:i:s'),
            'dimensions' => $dimensions,
        ];
    }

    private function dimensions(): PixelDimensionRegistry
    {
        return $this->dimensionRegistry ??= new PixelDimensionRegistry();
    }
}
