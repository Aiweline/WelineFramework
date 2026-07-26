<?php
declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Model\Pixel;
use Weline\Visitor\Model\PixelSource;

/**
 * B13：旧 Cron 写 source 的兼容层。
 *
 * 新归因（S0~S3）在入库阶段已写 channel_code/channel_name/traffic_type，
 * 旧 Cron 依据 PixelSource.referer_domain_contains 反写 source 会覆盖新结果。
 * 默认只做 source ← channel_code 的兼容同步，并标记 cron_deal=1；
 * 仅当 legacy 开关显式开启时，才对无 channel_code 的历史行走旧 referer 映射。
 */
class PixelCronSourceCompatService
{
    public const REASON_CHANNEL_CODE = 'channel_code';
    public const REASON_ALREADY_SYNCED = 'already_synced';
    public const REASON_LEGACY_REFERER = 'legacy_referer';
    public const REASON_SKIPPED = 'skipped';

    public function __construct(
        private ?VisitorTrackingConfig $trackingConfig = null,
    ) {
    }

    /**
     * 判定单行应如何处理 source。
     *
     * @param array<string, mixed>       $row
     * @param array<int, array<string, mixed>> $legacyMaps PixelSource 行集合
     *
     * @return array{pixel_id: int, source: ?string, reason: string}
     */
    public function decide(array $row, bool $legacyEnabled = false, array $legacyMaps = []): array
    {
        $pixelId = (int)($row[Pixel::schema_fields_ID] ?? $row['pixel_id'] ?? 0);
        $channelCode = trim((string)($row[Pixel::schema_fields_CHANNEL_CODE] ?? ''));
        $source = trim((string)($row[Pixel::schema_fields_SOURCE] ?? ''));

        if ($channelCode !== '') {
            if ($source === $channelCode) {
                return $this->result($pixelId, null, self::REASON_ALREADY_SYNCED);
            }

            return $this->result($pixelId, $channelCode, self::REASON_CHANNEL_CODE);
        }

        if (!$legacyEnabled) {
            return $this->result($pixelId, null, self::REASON_SKIPPED);
        }

        $legacyCode = $this->matchLegacySource((string)($row['referer'] ?? ''), $legacyMaps);
        if ($legacyCode === '' || $legacyCode === $source) {
            return $this->result($pixelId, null, self::REASON_SKIPPED);
        }

        return $this->result($pixelId, $legacyCode, self::REASON_LEGACY_REFERER);
    }

    /**
     * 旧 referer 域名包含匹配。
     *
     * @param array<int, array<string, mixed>> $legacyMaps
     */
    public function matchLegacySource(string $referer, array $legacyMaps): string
    {
        $referer = trim($referer);
        if ($referer === '' || !$legacyMaps) {
            return '';
        }
        $host = (string)(parse_url($referer, PHP_URL_HOST) ?: '');
        if ($host === '') {
            return '';
        }
        $host = strtolower($host);

        foreach ($legacyMaps as $item) {
            $code = trim((string)($item['code'] ?? ''));
            $contains = trim((string)($item['referer_domain_contains'] ?? ''));
            if ($code === '' || $contains === '') {
                continue;
            }
            foreach (explode(',', $contains) as $needle) {
                $needle = strtolower(trim($needle));
                if ($needle !== '' && str_contains($host, $needle)) {
                    return $code;
                }
            }
        }

        return '';
    }

    public function isLegacyEnabled(): bool
    {
        try {
            return $this->getTrackingConfig()->isLegacyCronSourceEnabled();
        } catch (\Throwable $throwable) {
            return false;
        }
    }

    /**
     * 处理一批未处理行；返回统计。
     *
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array{total: int, updated: int, synced: int, legacy: int, skipped: int, failed: int, legacy_enabled: bool}
     */
    public function process(array $rows, ?bool $legacyEnabled = null): array
    {
        $legacyEnabled = $legacyEnabled ?? $this->isLegacyEnabled();
        $legacyMaps = $legacyEnabled ? $this->loadLegacyMaps() : [];
        $stat = [
            'total' => \count($rows),
            'updated' => 0,
            'synced' => 0,
            'legacy' => 0,
            'skipped' => 0,
            'failed' => 0,
            'legacy_enabled' => $legacyEnabled,
        ];

        foreach ($rows as $row) {
            $decision = $this->decide($row, $legacyEnabled, $legacyMaps);
            if ($decision['pixel_id'] <= 0) {
                $stat['failed']++;
                continue;
            }
            if ($decision['reason'] === self::REASON_CHANNEL_CODE) {
                $stat['synced']++;
            } elseif ($decision['reason'] === self::REASON_LEGACY_REFERER) {
                $stat['legacy']++;
            } else {
                $stat['skipped']++;
            }

            if ($this->persist($decision)) {
                $stat['updated']++;
            } else {
                $stat['failed']++;
            }
        }

        return $stat;
    }

    /**
     * @param array{pixel_id: int, source: ?string, reason: string} $decision
     */
    protected function persist(array $decision): bool
    {
        try {
            /** @var Pixel $pixel */
            $pixel = ObjectManager::getInstance(Pixel::class)->load($decision['pixel_id']);
            if (!$pixel->getId()) {
                return false;
            }
            if ($decision['source'] !== null) {
                $pixel->setData(Pixel::schema_fields_SOURCE, $decision['source']);
            }
            $pixel->setData(Pixel::schema_fields_CRON_DEAL, 1);
            $pixel->save();

            return true;
        } catch (\Throwable $throwable) {
            if (defined('DEV') && DEV) {
                w_log_error('PixelCronSourceCompat 保存失败: ' . $throwable->getMessage());
            }

            return false;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function loadLegacyMaps(): array
    {
        try {
            /** @var PixelSource $model */
            $model = ObjectManager::getInstance(PixelSource::class);

            return $model::all() ?: [];
        } catch (\Throwable $throwable) {
            return [];
        }
    }

    /**
     * @return array{pixel_id: int, source: ?string, reason: string}
     */
    private function result(int $pixelId, ?string $source, string $reason): array
    {
        return ['pixel_id' => $pixelId, 'source' => $source, 'reason' => $reason];
    }

    private function getTrackingConfig(): VisitorTrackingConfig
    {
        if (!$this->trackingConfig) {
            $this->trackingConfig = ObjectManager::getInstance(VisitorTrackingConfig::class);
        }

        return $this->trackingConfig;
    }
}
