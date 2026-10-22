<?php

declare(strict_types=1);

namespace Weline\Feed\Exception;

/**
 * Missing connection material. The message lists the channel, mode, and field names only.
 */
final class FeedCredentialsMissing extends \RuntimeException
{
    /**
     * @param list<string> $missingFields
     */
    public function __construct(
        public readonly string $channelCode,
        public readonly string $connectionMode,
        public readonly array $missingFields,
    ) {
        $fields = implode(',', $missingFields);
        parent::__construct(trim($channelCode . ' ' . $connectionMode . ' ' . $fields));
    }
}
