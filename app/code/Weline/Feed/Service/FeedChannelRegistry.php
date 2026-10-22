<?php

declare(strict_types=1);

namespace Weline\Feed\Service;

use Weline\Feed\Api\FeedChannelInterface;
use Weline\Feed\Service\Channel\CriteoChannel;
use Weline\Feed\Service\Channel\GoogleMerchantChannel;
use Weline\Feed\Service\Channel\MetaCatalogChannel;
use Weline\Feed\Service\Channel\MicrosoftMerchantChannel;
use Weline\Feed\Service\Channel\NaverShoppingChannel;
use Weline\Feed\Service\Channel\PinterestCatalogChannel;
use Weline\Feed\Service\Channel\SnapchatCatalogChannel;
use Weline\Feed\Service\Channel\TikTokCatalogChannel;
use Weline\Feed\Service\Channel\VkAdsChannel;
use Weline\Feed\Service\Channel\YandexMarketChannel;

/**
 * Channel registry. An omitted list is the ten catalog channels. An explicit empty list stays empty.
 */
final class FeedChannelRegistry
{
    /** @var array<string, FeedChannelInterface> */
    private array $byCode = [];

    /**
     * @param list<FeedChannelInterface>|null $channels
     */
    public function __construct(?array $channels = null)
    {
        if ($channels === null) {
            $channels = self::standardChannels();
        }
        foreach ($channels as $channel) {
            if (!$channel instanceof FeedChannelInterface) {
                continue;
            }
            $code = trim($channel->channelCode());
            if ($code === '') {
                continue;
            }
            $this->byCode[$code] = $channel;
        }
    }

    public function find(string $channelCode): ?FeedChannelInterface
    {
        return $this->byCode[trim($channelCode)] ?? null;
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_keys($this->byCode);
    }

    /**
     * @return list<FeedChannelInterface>
     */
    public static function standardChannels(): array
    {
        return [
            new GoogleMerchantChannel(),
            new MicrosoftMerchantChannel(),
            new YandexMarketChannel(),
            new MetaCatalogChannel(),
            new TikTokCatalogChannel(),
            new PinterestCatalogChannel(),
            new SnapchatCatalogChannel(),
            new CriteoChannel(),
            new VkAdsChannel(),
            new NaverShoppingChannel(),
        ];
    }
}
