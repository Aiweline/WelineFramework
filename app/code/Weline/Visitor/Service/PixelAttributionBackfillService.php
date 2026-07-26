<?php

declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Model\Pixel;

/**
 * 历史像素归因扁平列回填（工程计划 A13a/A13b）。
 * A13a 干跑不写库；A13b apply 写扁平列并可标记 attribution_backfill_done。
 */
class PixelAttributionBackfillService
{
    public const ATTR_KEYS = [
        'session_id',
        'channel_code',
        'channel_name',
        'traffic_type',
        'utm_source',
        'utm_medium',
        'utm_campaign',
    ];

    public function __construct(
        private ?PixelEventService $eventService = null,
        private ?Pixel $pixelModel = null,
        private ?VisitorTrackingConfig $trackingConfig = null
    ) {
    }

    /**
     * A13a：仅干跑，强制不写库。
     *
     * @param array{website_id?: int, limit?: int, sample_limit?: int, offset?: int} $options
     * @return array{
     *   dry_run: true,
     *   apply: false,
     *   columns_ready: bool,
     *   scanned: int,
     *   would_update: int,
     *   skipped: int,
     *   updated: int,
     *   sample_has_values: bool,
     *   attribution_backfill_done: bool,
     *   marked_done: bool,
     *   samples: list<array<string, mixed>>,
     *   error?: string
     * }
     */
    public function dryRun(array $options = []): array
    {
        $report = $this->run(array_merge($options, ['apply' => false, 'mark_done' => false]));
        $report['dry_run'] = true;
        $report['apply'] = false;
        $report['updated'] = 0;
        $report['marked_done'] = false;

        return $report;
    }

    /**
     * A13b：正式回填；需显式 apply=true。
     *
     * @param array{website_id?: int, limit?: int, sample_limit?: int, offset?: int, mark_done?: bool} $options
     * @return array<string, mixed>
     */
    public function apply(array $options = []): array
    {
        return $this->run(array_merge($options, ['apply' => true]));
    }

    /**
     * 仅打标记（运维确认抽样有值后可单独调用）。
     *
     * @return array{ok: bool, attribution_backfill_done: bool, error?: string}
     */
    public function markDone(bool $done = true): array
    {
        $ok = $this->trackingConfig()->setAttributionBackfillDone($done);
        $report = [
            'ok' => $ok,
            'attribution_backfill_done' => $ok ? $done : $this->trackingConfig()->isAttributionBackfillDone(),
        ];
        if (!$ok) {
            $report['error'] = 'failed to persist attribution_backfill_done';
        }

        return $report;
    }

    /**
     * @param array{website_id?: int, limit?: int, sample_limit?: int, offset?: int, apply?: bool, mark_done?: bool} $options
     * @return array{
     *   dry_run: bool,
     *   apply: bool,
     *   columns_ready: bool,
     *   scanned: int,
     *   would_update: int,
     *   skipped: int,
     *   updated: int,
     *   sample_has_values: bool,
     *   attribution_backfill_done: bool,
     *   marked_done: bool,
     *   samples: list<array<string, mixed>>,
     *   error?: string
     * }
     */
    public function run(array $options = []): array
    {
        $apply = (bool)($options['apply'] ?? false);
        $markDone = (bool)($options['mark_done'] ?? false);
        $websiteId = (int)($options['website_id'] ?? 0);
        $limit = max(1, min(10000, (int)($options['limit'] ?? 500)));
        $offset = max(0, (int)($options['offset'] ?? 0));
        $sampleLimit = max(0, min(20, (int)($options['sample_limit'] ?? 5)));

        $report = [
            'dry_run' => !$apply,
            'apply' => $apply,
            'columns_ready' => false,
            'scanned' => 0,
            'would_update' => 0,
            'skipped' => 0,
            'updated' => 0,
            'sample_has_values' => false,
            'attribution_backfill_done' => $this->trackingConfig()->isAttributionBackfillDone(),
            'marked_done' => false,
            'samples' => [],
        ];

        if (!$this->hasAttributionColumns()) {
            $report['error'] = 'w_pixel attribution columns missing (session_id/channel_code/utm_*); run setup:upgrade first';

            return $report;
        }

        $report['columns_ready'] = true;

        try {
            $rows = $this->fetchRows($websiteId, $limit, $offset);
        } catch (\Throwable $e) {
            $report['error'] = 'fetch failed: ' . $e->getMessage();

            return $report;
        }

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $report['scanned']++;

            $projected = $this->projectRow($row);
            $changes = $this->diffAttributionFields($row, $projected);
            if ($changes === []) {
                $report['skipped']++;
                if ($this->rowHasAttributionValues($projected) || $this->rowHasAttributionValues($row)) {
                    $report['sample_has_values'] = true;
                }
                continue;
            }

            $report['would_update']++;
            if ($this->changesHaveValues($changes)) {
                $report['sample_has_values'] = true;
            }
            if (\count($report['samples']) < $sampleLimit) {
                $report['samples'][] = [
                    'pixel_id' => (int)($row['pixel_id'] ?? 0),
                    'website_id' => (int)($row['website_id'] ?? 0),
                    'changes' => $changes,
                ];
            }

            if (!$apply) {
                continue;
            }

            if ($this->persistProjectedRow((int)($row['pixel_id'] ?? 0), $projected, $changes)) {
                $report['updated']++;
            }
        }

        if ($apply && $markDone && empty($report['error'])) {
            $marked = $this->trackingConfig()->setAttributionBackfillDone(true);
            $report['marked_done'] = $marked;
            $report['attribution_backfill_done'] = $marked
                ? true
                : $this->trackingConfig()->isAttributionBackfillDone();
            if (!$marked) {
                $report['error'] = 'apply succeeded but failed to mark attribution_backfill_done';
            }
        } else {
            $report['attribution_backfill_done'] = $this->trackingConfig()->isAttributionBackfillDone();
        }

        return $report;
    }

    /**
     * 从历史行重建 hydrate 输入，再投影归因扁平列（与 prepare 共用 hydratePreparedAttribution）。
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function projectRow(array $row): array
    {
        $input = $this->buildHydrationInput($row);

        return $this->eventService()->hydratePreparedAttribution($input['post'], $input['data']);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{post: array<string, mixed>, data: array<string, mixed>}
     */
    public function buildHydrationInput(array $row): array
    {
        $browser = $this->decodeBrowserInfo($row['browser_info'] ?? null);
        $additional = \is_array($browser['additionalInfo'] ?? null) ? $browser['additionalInfo'] : [];
        $url = (string)($row['url'] ?? '');
        $referer = (string)($row['referer'] ?? '');
        $sessionId = trim((string)($row['session_id'] ?? ''));
        if ($sessionId === '') {
            $sessionId = trim((string)($browser['session_id'] ?? ''));
        }
        if ($sessionId === '') {
            $sessionId = trim((string)($additional['environment']['session_id'] ?? ''));
        }
        if ($sessionId === '') {
            $sessionId = trim((string)($additional['funnel']['session_id'] ?? ''));
        }

        $sticky = $this->extractStickyFromBrowser($browser, $additional);

        $post = [
            'url' => $url,
            'referer' => $referer,
            'referrer' => $referer,
            'session_id' => $sessionId,
            'additionalInfo' => $additional,
        ];
        if ($sticky !== null) {
            $post['sticky'] = $sticky;
        }

        $data = [
            'url' => $url,
            'referer' => $referer,
            'website_id' => (int)($row['website_id'] ?? 0),
            'session_id' => trim((string)($row['session_id'] ?? '')),
            'channel_code' => trim((string)($row['channel_code'] ?? '')),
            'channel_name' => trim((string)($row['channel_name'] ?? '')),
            'traffic_type' => trim((string)($row['traffic_type'] ?? '')),
            'utm_source' => trim((string)($row['utm_source'] ?? '')),
            'utm_medium' => trim((string)($row['utm_medium'] ?? '')),
            'utm_campaign' => trim((string)($row['utm_campaign'] ?? '')),
        ];

        return ['post' => $post, 'data' => $data];
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array<string, array{from: string, to: string}>
     */
    public function diffAttributionFields(array $before, array $after): array
    {
        $changes = [];
        foreach (self::ATTR_KEYS as $key) {
            $from = trim((string)($before[$key] ?? ''));
            $to = trim((string)($after[$key] ?? ''));
            if ($to === $from) {
                continue;
            }
            if ($to === '' && $from === '') {
                continue;
            }
            $changes[$key] = ['from' => $from, 'to' => $to];
        }

        return $changes;
    }

    public function wouldUpdateRow(array $before, array $after): bool
    {
        return $this->diffAttributionFields($before, $after) !== [];
    }

    /**
     * @param array<string, mixed> $row
     */
    public function rowHasAttributionValues(array $row): bool
    {
        foreach (['session_id', 'channel_code', 'utm_source', 'utm_medium', 'utm_campaign', 'traffic_type'] as $key) {
            if (trim((string)($row[$key] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, array{from: string, to: string}> $changes
     */
    public function changesHaveValues(array $changes): bool
    {
        foreach ($changes as $change) {
            if (trim((string)($change['to'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    public function hasAttributionColumns(): bool
    {
        try {
            $pixel = $this->pixel();
            $connector = $pixel->getConnection()->getConnector();
            $table = (string)$pixel->getTable();
            foreach (['session_id', 'channel_code', 'traffic_type', 'utm_source', 'utm_medium', 'utm_campaign'] as $field) {
                if (!$connector->hasField($table, $field)) {
                    return false;
                }
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchRows(int $websiteId, int $limit, int $offset): array
    {
        $model = $this->pixel()->reset();
        $fields = implode(',', array_merge(
            ['pixel_id', 'website_id', 'url', 'referer', 'browser_info', 'created_at'],
            self::ATTR_KEYS
        ));
        $model->fields($fields);
        if ($websiteId > 0) {
            $model->where(Pixel::schema_fields_WEBSITE_ID, $websiteId);
        }
        $rows = $model
            ->order('pixel_id', 'ASC')
            ->limit($limit, $offset)
            ->select()
            ->fetchArray();

        return \is_array($rows) ? $rows : [];
    }

    /**
     * @param array<string, mixed> $projected
     * @param array<string, array{from: string, to: string}> $changes
     */
    private function persistProjectedRow(int $pixelId, array $projected, array $changes): bool
    {
        if ($pixelId <= 0 || $changes === []) {
            return false;
        }

        try {
            $model = $this->pixel()->reset()->load($pixelId);
            if (!(int)$model->getId()) {
                return false;
            }
            foreach (array_keys($changes) as $key) {
                $model->setData($key, (string)($projected[$key] ?? ''));
            }
            $model->save();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param mixed $raw
     * @return array<string, mixed>
     */
    private function decodeBrowserInfo(mixed $raw): array
    {
        if (\is_array($raw)) {
            return $raw;
        }
        if (!\is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $browser
     * @param array<string, mixed> $additional
     * @return array<string, mixed>|null
     */
    private function extractStickyFromBrowser(array $browser, array $additional): ?array
    {
        $attribution = \is_array($additional['attribution'] ?? null) ? $additional['attribution'] : [];
        foreach ([
            $browser['sticky'] ?? null,
            $additional['sticky'] ?? null,
            $additional['sticky_utm'] ?? null,
            $attribution['sticky'] ?? null,
        ] as $candidate) {
            if (\is_array($candidate) && $candidate !== []) {
                return $candidate;
            }
        }

        return null;
    }

    private function eventService(): PixelEventService
    {
        if (!$this->eventService) {
            /** @var PixelEventService $service */
            $service = ObjectManager::getInstance(PixelEventService::class);
            $this->eventService = $service;
        }

        return $this->eventService;
    }

    private function pixel(): Pixel
    {
        if (!$this->pixelModel) {
            /** @var Pixel $pixel */
            $pixel = ObjectManager::getInstance(Pixel::class);
            $this->pixelModel = $pixel;
        }

        return $this->pixelModel;
    }

    private function trackingConfig(): VisitorTrackingConfig
    {
        if (!$this->trackingConfig) {
            /** @var VisitorTrackingConfig $config */
            $config = ObjectManager::getInstance(VisitorTrackingConfig::class);
            $this->trackingConfig = $config;
        }

        return $this->trackingConfig;
    }
}
