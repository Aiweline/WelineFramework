<?php

declare(strict_types=1);

namespace Weline\Framework\Service\Query\Auth;

interface BinQueryAuthenticationMetadataProviderInterface
{
    /**
     * @return array{
     *     overview_description?: string,
     *     api_key_description?: string,
     *     api_key_source?: string,
     *     api_key_type?: string,
     *     api_key_ttl?: string,
     *     refresh_token_ttl?: string
     * }
     */
    public function metadata(): array;
}
