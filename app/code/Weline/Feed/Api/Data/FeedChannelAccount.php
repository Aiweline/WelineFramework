<?php

declare(strict_types=1);

namespace Weline\Feed\Api\Data;

final readonly class FeedChannelAccount
{
    /**
     * @param array<string, string> $secretFields decrypted field map; never written into errors or list rows
     */
    public function __construct(
        public int $accountId,
        public int $websiteId,
        public string $channelCode,
        public string $connectionMode,
        public string $displayName,
        public array $secretFields,
        public string $fingerprint,
        public string $status,
        public string $lastErrorCode = '',
    ) {
    }
}
