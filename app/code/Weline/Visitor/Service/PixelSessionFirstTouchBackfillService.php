<?php

declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Model\Pixel;

/**
 * 同会话服务端首触回填（工程计划 A04b / §2.1）。
 * 不查 pixel_channel；仅读 w_pixel 历史行。
 */
class PixelSessionFirstTouchBackfillService
{
    private const ATTR_KEYS = [
        'channel_code',
        'channel_name',
        'traffic_type',
        'utm_source',
        'utm_medium',
        'utm_campaign',
    ];

    public function __construct(
        private ?Pixel $pixelModel = null
    ) {
    }

    /**
     * @param array<string, mixed> $data prepare 产出的扁平行
     * @return array<string, mixed>
     */
    public function backfill(array $data): array
    {
        if (!$this->lacksMarketingSignals($data)) {
            return $data;
        }

        $websiteId = (int)($data['website_id'] ?? 0);
        $sessionId = trim((string)($data['session_id'] ?? ''));
        if ($websiteId <= 0 || $sessionId === '') {
            return $data;
        }

        return $this->applyFirstTouch($data, $this->findFirstMarketingTouch($websiteId, $sessionId));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function lacksMarketingSignals(array $data): bool
    {
        return trim((string)($data['channel_code'] ?? '')) === ''
            && trim((string)($data['utm_source'] ?? '')) === ''
            && trim((string)($data['utm_medium'] ?? '')) === ''
            && trim((string)($data['utm_campaign'] ?? '')) === '';
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $firstTouch
     * @return array<string, mixed>
     */
    public function applyFirstTouch(array $data, ?array $firstTouch): array
    {
        if ($firstTouch === null || !$this->lacksMarketingSignals($data)) {
            return $data;
        }
        if ($this->lacksMarketingSignals($firstTouch)) {
            return $data;
        }

        foreach (self::ATTR_KEYS as $key) {
            $value = trim((string)($firstTouch[$key] ?? ''));
            if ($value !== '') {
                $data[$key] = $value;
            }
        }

        return $data;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findFirstMarketingTouch(int $websiteId, string $sessionId): ?array
    {
        if ($websiteId <= 0 || $sessionId === '') {
            return null;
        }

        try {
            $model = $this->pixel();
            $rows = $model->reset()
                ->fields(implode(',', array_merge(['pixel_id', 'created_at'], self::ATTR_KEYS)))
                ->where(Pixel::schema_fields_WEBSITE_ID, $websiteId)
                ->where(Pixel::schema_fields_SESSION_ID, $sessionId)
                ->order('created_at', 'ASC')
                ->order('pixel_id', 'ASC')
                ->limit(50)
                ->select()
                ->fetchArray();
        } catch (\Throwable) {
            return null;
        }

        if (!\is_array($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            if (!$this->lacksMarketingSignals($row)) {
                return [
                    'channel_code' => trim((string)($row['channel_code'] ?? '')),
                    'channel_name' => trim((string)($row['channel_name'] ?? '')),
                    'traffic_type' => trim((string)($row['traffic_type'] ?? '')),
                    'utm_source' => trim((string)($row['utm_source'] ?? '')),
                    'utm_medium' => trim((string)($row['utm_medium'] ?? '')),
                    'utm_campaign' => trim((string)($row['utm_campaign'] ?? '')),
                ];
            }
        }

        return null;
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
}
