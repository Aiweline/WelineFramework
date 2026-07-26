<?php

declare(strict_types=1);

namespace Weline\Visitor\Service\Report;

/**
 * D01：报表维度注册表（纯内存，不查库）。
 *
 * §2.5 默认小时维（进 dim_hash）：
 * traffic_type, channel_code, utm_source, utm_medium, utm_campaign, event_name, device_category
 * 缺省维值用空串；page_path / landing_page 为高基维，注册但不进默认小时全维。
 */
class PixelDimensionRegistry
{
    public const CARDINALITY_LOW = 'low';
    public const CARDINALITY_HIGH = 'high';

    public const SOURCE_FLAT = 'flat';
    public const SOURCE_EVENT = 'event';
    public const SOURCE_DERIVED = 'derived';

    /**
     * §2.5 默认小时全维顺序（决定 dim_hash 序列化顺序）。
     *
     * @var list<string>
     */
    public const DEFAULT_HOURLY_DIMENSION_IDS = [
        'traffic_type',
        'channel_code',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'event_name',
        'device_category',
    ];

    /**
     * @var array<string, array{
     *   id: string,
     *   label: string,
     *   source: string,
     *   field: string,
     *   cardinality: string,
     *   in_default_hourly: bool
     * }>
     */
    private array $dimensions = [];

    public function __construct(bool $withDefaults = true)
    {
        if ($withDefaults) {
            $this->registerDefaults();
        }
    }

    /**
     * @param array{
     *   label?: string,
     *   source?: string,
     *   field?: string,
     *   cardinality?: string,
     *   in_default_hourly?: bool
     * } $meta
     */
    public function register(string $id, array $meta = []): void
    {
        $id = trim($id);
        if ($id === '') {
            throw new \InvalidArgumentException('dimension id must not be empty');
        }

        $cardinality = (string)($meta['cardinality'] ?? self::CARDINALITY_LOW);
        if (!\in_array($cardinality, [self::CARDINALITY_LOW, self::CARDINALITY_HIGH], true)) {
            throw new \InvalidArgumentException('invalid dimension cardinality: ' . $cardinality);
        }

        $source = (string)($meta['source'] ?? self::SOURCE_FLAT);
        if (!\in_array($source, [self::SOURCE_FLAT, self::SOURCE_EVENT, self::SOURCE_DERIVED], true)) {
            throw new \InvalidArgumentException('invalid dimension source: ' . $source);
        }

        $this->dimensions[$id] = [
            'id' => $id,
            'label' => trim((string)($meta['label'] ?? $id)) ?: $id,
            'source' => $source,
            'field' => trim((string)($meta['field'] ?? $id)) ?: $id,
            'cardinality' => $cardinality,
            'in_default_hourly' => (bool)($meta['in_default_hourly'] ?? false),
        ];
    }

    public function has(string $id): bool
    {
        return isset($this->dimensions[trim($id)]);
    }

    /**
     * @return array{
     *   id: string,
     *   label: string,
     *   source: string,
     *   field: string,
     *   cardinality: string,
     *   in_default_hourly: bool
     * }|null
     */
    public function get(string $id): ?array
    {
        $id = trim($id);

        return $this->dimensions[$id] ?? null;
    }

    /**
     * @return array<string, array{
     *   id: string,
     *   label: string,
     *   source: string,
     *   field: string,
     *   cardinality: string,
     *   in_default_hourly: bool
     * }>
     */
    public function all(): array
    {
        return $this->dimensions;
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->dimensions);
    }

    /**
     * 默认小时聚合维 ID（有序）。
     *
     * @return list<string>
     */
    public function defaultHourlyIds(): array
    {
        $ids = [];
        foreach (self::DEFAULT_HOURLY_DIMENSION_IDS as $id) {
            if ($this->has($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    public function highCardinalityIds(): array
    {
        $ids = [];
        foreach ($this->dimensions as $id => $meta) {
            if ($meta['cardinality'] === self::CARDINALITY_HIGH) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param list<string> $ids
     * @throws \InvalidArgumentException
     */
    public function assertKnown(array $ids): void
    {
        foreach ($ids as $id) {
            if (!$this->has((string)$id)) {
                throw new \InvalidArgumentException('unknown dimension: ' . $id);
            }
        }
    }

    /**
     * 从事件行抽取维值（缺省为空串）。
     *
     * @param array<string, mixed> $row
     * @param list<string>|null $dimensionIds null=默认小时维
     * @return array<string, string> 有序
     */
    public function extractValues(array $row, ?array $dimensionIds = null): array
    {
        $ids = $dimensionIds ?? $this->defaultHourlyIds();
        $this->assertKnown($ids);

        $out = [];
        foreach ($ids as $id) {
            $meta = $this->dimensions[$id];
            $field = $meta['field'];
            $value = '';
            if ($meta['source'] === self::SOURCE_EVENT && $field === 'event') {
                $value = (string)($row['event'] ?? $row['event_name'] ?? '');
            } elseif (\array_key_exists($field, $row)) {
                $value = (string)$row[$field];
            } elseif ($id === 'event_name') {
                $value = (string)($row['event'] ?? $row['event_name'] ?? '');
            } elseif ($id === 'device_category') {
                $value = (string)($row['device_category'] ?? '');
            } elseif ($id === 'landing_page') {
                $value = (string)($row['landing_page'] ?? $row['page_path'] ?? '');
            } elseif ($id === 'page_path') {
                $value = (string)($row['page_path'] ?? $row['path'] ?? '');
            }
            $out[$id] = trim($value);
        }

        return $out;
    }

    /**
     * §2.5：有序字典 JSON 序列化后 sha1；缺省维用空串。
     *
     * @param array<string, mixed> $row 事件行，或已含维字段的字典
     * @param list<string>|null $dimensionIds
     */
    public function computeDimHash(array $row, ?array $dimensionIds = null): string
    {
        $values = $this->extractValues($row, $dimensionIds);

        return sha1($this->serializeDimValues($values));
    }

    /**
     * @param array<string, string> $values 有序维值（键序即序列化序）
     */
    public function serializeDimValues(array $values): string
    {
        $ordered = [];
        foreach ($values as $key => $value) {
            $ordered[(string)$key] = trim((string)$value);
        }

        $json = json_encode($ordered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('failed to encode dim values');
        }

        return $json;
    }

    private function registerDefaults(): void
    {
        $hourly = array_fill_keys(self::DEFAULT_HOURLY_DIMENSION_IDS, true);

        $this->register('traffic_type', [
            'label' => 'Traffic type',
            'source' => self::SOURCE_FLAT,
            'field' => 'traffic_type',
            'cardinality' => self::CARDINALITY_LOW,
            'in_default_hourly' => isset($hourly['traffic_type']),
        ]);
        $this->register('channel_code', [
            'label' => 'Channel code',
            'source' => self::SOURCE_FLAT,
            'field' => 'channel_code',
            'cardinality' => self::CARDINALITY_LOW,
            'in_default_hourly' => isset($hourly['channel_code']),
        ]);
        $this->register('utm_source', [
            'label' => 'UTM source',
            'source' => self::SOURCE_FLAT,
            'field' => 'utm_source',
            'cardinality' => self::CARDINALITY_LOW,
            'in_default_hourly' => isset($hourly['utm_source']),
        ]);
        $this->register('utm_medium', [
            'label' => 'UTM medium',
            'source' => self::SOURCE_FLAT,
            'field' => 'utm_medium',
            'cardinality' => self::CARDINALITY_LOW,
            'in_default_hourly' => isset($hourly['utm_medium']),
        ]);
        $this->register('utm_campaign', [
            'label' => 'UTM campaign',
            'source' => self::SOURCE_FLAT,
            'field' => 'utm_campaign',
            'cardinality' => self::CARDINALITY_LOW,
            'in_default_hourly' => isset($hourly['utm_campaign']),
        ]);
        $this->register('event_name', [
            'label' => 'Event name',
            'source' => self::SOURCE_EVENT,
            'field' => 'event',
            'cardinality' => self::CARDINALITY_LOW,
            'in_default_hourly' => isset($hourly['event_name']),
        ]);
        $this->register('device_category', [
            'label' => 'Device category',
            'source' => self::SOURCE_DERIVED,
            'field' => 'device_category',
            'cardinality' => self::CARDINALITY_LOW,
            'in_default_hourly' => isset($hourly['device_category']),
        ]);

        // 高基维：可单维报表，不进默认小时全维
        $this->register('page_path', [
            'label' => 'Page path',
            'source' => self::SOURCE_DERIVED,
            'field' => 'page_path',
            'cardinality' => self::CARDINALITY_HIGH,
            'in_default_hourly' => false,
        ]);
        $this->register('landing_page', [
            'label' => 'Landing page',
            'source' => self::SOURCE_DERIVED,
            'field' => 'landing_page',
            'cardinality' => self::CARDINALITY_HIGH,
            'in_default_hourly' => false,
        ]);
        $this->register('channel_name', [
            'label' => 'Channel name',
            'source' => self::SOURCE_FLAT,
            'field' => 'channel_name',
            'cardinality' => self::CARDINALITY_LOW,
            'in_default_hourly' => false,
        ]);
    }
}
