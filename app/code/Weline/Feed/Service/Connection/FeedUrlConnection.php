<?php

declare(strict_types=1);

namespace Weline\Feed\Service\Connection;

use Weline\Feed\Api\Data\FeedChannelAccount;
use Weline\Feed\Api\Data\FeedConnectionHandle;
use Weline\Feed\Api\Data\FeedConnectionMode;
use Weline\Feed\Api\Data\FeedDefinition;
use Weline\Feed\Api\FeedConnectionInterface;
use Weline\Feed\Exception\FeedCredentialsMissing;
use Weline\Feed\Service\FeedPublicPath;

final class FeedUrlConnection implements FeedConnectionInterface
{
    public function mode(): string
    {
        return FeedConnectionMode::FEED_URL;
    }

    public function assertSecrets(FeedChannelAccount $account, FeedDefinition $definition): void
    {
        $missing = [];
        if (!$definition->enabled) {
            $missing[] = 'enabled';
        }
        if (trim($definition->publicToken) === '') {
            $missing[] = 'public_token';
        }
        if ($missing !== []) {
            throw new FeedCredentialsMissing($definition->channelCode, $this->mode(), $missing);
        }
    }

    public function open(FeedChannelAccount $account, FeedDefinition $definition): FeedConnectionHandle
    {
        $this->assertSecrets($account, $definition);

        return new FeedConnectionHandle(
            mode: $this->mode(),
            channelCode: $definition->channelCode,
            publicPath: FeedPublicPath::forToken($definition->publicToken),
        );
    }
}
