<?php

declare(strict_types=1);

namespace WeShop\Social\Service;

use WeShop\Social\Model\SocialShare;
use Weline\Framework\App\Env;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

class SocialService
{
    /**
     * @var array<string, array{label: string, icon: string, config: string}>
     */
    private const FOOTER_LINK_DEFINITIONS = [
        'facebook' => ['label' => 'Facebook', 'icon' => 'facebook', 'config' => 'social.links.facebook'],
        'instagram' => ['label' => 'Instagram', 'icon' => 'instagram', 'config' => 'social.links.instagram'],
        'x' => ['label' => 'X', 'icon' => 'x', 'config' => 'social.links.x'],
        'youtube' => ['label' => 'YouTube', 'icon' => 'youtube', 'config' => 'social.links.youtube'],
        'tiktok' => ['label' => 'TikTok', 'icon' => 'tiktok', 'config' => 'social.links.tiktok'],
        'linkedin' => ['label' => 'LinkedIn', 'icon' => 'linkedin', 'config' => 'social.links.linkedin'],
    ];

    /**
     * @param SocialShare|null $shareModel
     * @param EventsManager|null $eventsManager
     */
    public function __construct(
        private readonly ?SocialShare $shareModel = null,
        private readonly ?EventsManager $eventsManager = null
    ) {
    }

    /**
     * @param array<string, mixed> $shareData
     */
    public function recordShare(array $shareData): SocialShare
    {
        $platform = $this->normalizePlatform((string) ($shareData['platform'] ?? ''));
        if ($platform === '') {
            throw new \InvalidArgumentException((string) __('A social platform is required.'));
        }

        $shareData['platform'] = $platform;
        $eventData = ['share_data' => &$shareData];
        $this->getEventsManager()->dispatch('WeShop_Social::share_before', $eventData);

        $share = $this->createShareModel();
        $share->clearData();
        $share->setData(SocialShare::schema_fields_CUSTOMER_ID, max(0, (int) ($shareData['customer_id'] ?? 0)));
        $share->setData(SocialShare::schema_fields_PRODUCT_ID, max(0, (int) ($shareData['product_id'] ?? 0)));
        $share->setData(SocialShare::schema_fields_PLATFORM, $platform);
        $share->save();

        $afterEventData = [
            'share' => $share,
            'share_data' => $shareData,
        ];
        $this->getEventsManager()->dispatch('WeShop_Social::share_after', $afterEventData);

        return $share;
    }

    public function getShareCount(int $productId, ?string $platform = null): int
    {
        if ($productId <= 0) {
            return 0;
        }

        $share = $this->createShareModel();
        $share->reset()
            ->fields('COUNT(*) as count')
            ->where(SocialShare::schema_fields_PRODUCT_ID, $productId);

        $platform = $this->normalizePlatform((string) $platform);
        if ($platform !== '') {
            $share->where(SocialShare::schema_fields_PLATFORM, $platform);
        }

        $result = $share->find()->fetchArray();
        return max(0, (int) ($result['count'] ?? 0));
    }

    /**
     * @param array<int, int|string> $productIds
     * @return array<int, int>
     */
    public function getShareCounts(array $productIds): array
    {
        $counts = [];
        foreach (array_values(array_unique(array_map('intval', $productIds))) as $productId) {
            if ($productId <= 0) {
                continue;
            }
            $counts[$productId] = $this->getShareCount($productId);
        }

        return $counts;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<int, array<string, string>>
     */
    public function getFooterSocialLinks(array $context = []): array
    {
        $links = [];
        foreach (self::FOOTER_LINK_DEFINITIONS as $platform => $definition) {
            $url = trim((string) ($context[$platform] ?? $this->readConfigValue($definition['config'])));
            if ($url === '') {
                continue;
            }

            $links[] = [
                'platform' => $platform,
                'label' => (string) __($definition['label']),
                'icon' => $definition['icon'],
                'url' => $url,
            ];
        }

        return $links;
    }

    /**
     * @param array<int, string> $platforms
     * @return array<int, array<string, string>>
     */
    public function getProductShareUrls(string $targetUrl, string $title = '', array $platforms = []): array
    {
        $targetUrl = trim($targetUrl);
        if ($targetUrl === '') {
            return [];
        }

        $platforms = $platforms === [] ? ['facebook', 'x', 'linkedin', 'whatsapp', 'pinterest'] : $platforms;
        $encodedUrl = rawurlencode($targetUrl);
        $encodedTitle = rawurlencode(trim($title));

        $links = [];
        foreach ($platforms as $platform) {
            $normalizedPlatform = $this->normalizePlatform((string) $platform);
            $shareUrl = match ($normalizedPlatform) {
                'facebook' => "https://www.facebook.com/sharer/sharer.php?u={$encodedUrl}",
                'x', 'twitter' => "https://twitter.com/intent/tweet?url={$encodedUrl}&text={$encodedTitle}",
                'linkedin' => "https://www.linkedin.com/sharing/share-offsite/?url={$encodedUrl}",
                'whatsapp' => "https://api.whatsapp.com/send?text={$encodedTitle}%20{$encodedUrl}",
                'pinterest' => "https://pinterest.com/pin/create/button/?url={$encodedUrl}&description={$encodedTitle}",
                default => '',
            };

            if ($shareUrl === '') {
                continue;
            }

            $links[] = [
                'platform' => $normalizedPlatform,
                'label' => ucfirst($normalizedPlatform),
                'url' => $shareUrl,
            ];
        }

        return $links;
    }

    protected function createShareModel(): SocialShare
    {
        if ($this->shareModel !== null) {
            return clone $this->shareModel;
        }

        return ObjectManager::getInstance(SocialShare::class);
    }

    protected function getEventsManager(): EventsManager
    {
        if ($this->eventsManager !== null) {
            return $this->eventsManager;
        }

        return ObjectManager::getInstance(EventsManager::class);
    }

    protected function readConfigValue(string $configPath): string
    {
        try {
            return trim((string) Env::getInstance()->getConfig($configPath, ''));
        } catch (\Throwable) {
            return '';
        }
    }

    private function normalizePlatform(string $platform): string
    {
        $platform = strtolower(trim($platform));

        return match ($platform) {
            'twitter' => 'x',
            'wechat' => 'wechat',
            default => preg_replace('/[^a-z0-9_]+/', '_', $platform) ?? '',
        };
    }
}
