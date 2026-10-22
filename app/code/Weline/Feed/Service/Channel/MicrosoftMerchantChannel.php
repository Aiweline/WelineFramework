<?php

declare(strict_types=1);

namespace Weline\Feed\Service\Channel;

final class MicrosoftMerchantChannel extends GoogleRssChannel
{
    public function channelCode(): string
    {
        return 'microsoft_merchant';
    }

    public function displayName(): string
    {
        return 'Microsoft Merchant Center';
    }
}
