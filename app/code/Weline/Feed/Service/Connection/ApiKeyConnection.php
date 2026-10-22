<?php

declare(strict_types=1);

namespace Weline\Feed\Service\Connection;

use Weline\Feed\Api\Data\FeedChannelAccount;
use Weline\Feed\Api\Data\FeedConnectionHandle;
use Weline\Feed\Api\Data\FeedConnectionMode;
use Weline\Feed\Api\Data\FeedDefinition;
use Weline\Feed\Api\FeedConnectionInterface;
use Weline\Feed\Exception\FeedCredentialsMissing;

final class ApiKeyConnection implements FeedConnectionInterface
{
    public function mode(): string
    {
        return FeedConnectionMode::API_KEY;
    }

    public function assertSecrets(FeedChannelAccount $account, FeedDefinition $definition): void
    {
        if (trim((string)($account->secretFields['api_key'] ?? '')) === '') {
            throw new FeedCredentialsMissing($definition->channelCode, $this->mode(), ['api_key']);
        }
    }

    public function open(FeedChannelAccount $account, FeedDefinition $definition): FeedConnectionHandle
    {
        $this->assertSecrets($account, $definition);

        return new FeedConnectionHandle(
            mode: $this->mode(),
            channelCode: $definition->channelCode,
            publicPath: '',
        );
    }
}
