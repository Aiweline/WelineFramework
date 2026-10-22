<?php

declare(strict_types=1);

namespace Weline\Feed\Service\Channel;

final class GoogleMerchantChannel extends GoogleRssChannel
{
    public function channelCode(): string
    {
        return 'google_merchant';
    }

    public function displayName(): string
    {
        return 'Google Merchant Center';
    }
}
