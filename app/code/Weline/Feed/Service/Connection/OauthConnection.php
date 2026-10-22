<?php

declare(strict_types=1);

namespace Weline\Feed\Service\Connection;

use Weline\Feed\Api\Data\FeedChannelAccount;
use Weline\Feed\Api\Data\FeedConnectionHandle;
use Weline\Feed\Api\Data\FeedConnectionMode;
use Weline\Feed\Api\Data\FeedDefinition;
use Weline\Feed\Api\FeedConnectionInterface;
use Weline\Feed\Exception\FeedCredentialsMissing;

final class OauthConnection implements FeedConnectionInterface
{
    public function mode(): string
    {
        return FeedConnectionMode::OAUTH;
    }

    public function assertSecrets(FeedChannelAccount $account, FeedDefinition $definition): void
    {
        $missing = [];
        foreach (['refresh_token', 'access_token'] as $field) {
            if (trim((string)($account->secretFields[$field] ?? '')) === '') {
                $missing[] = $field;
            }
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
            publicPath: '',
        );
    }
}
