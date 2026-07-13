<?php

declare(strict_types=1);

namespace Weline\Api\Api;

use Weline\Framework\Service\Query\Auth\BinQueryAuthenticationMetadataProviderInterface;

final class BinQueryAuthenticationMetadataProvider implements BinQueryAuthenticationMetadataProviderInterface
{
    public function metadata(): array
    {
        return [
            'overview_description' => 'External SDKs derive https://{domain}/bin/query from domain, default to area=frontend, and use a temporary third-party app access_token as apiKey.',
            'api_key_description' => 'Third-party app access_token; temporary, default TTL 3600 seconds.',
            'api_key_source' => 'Create and authorize a third-party app, then exchange its code through POST /api/rest/v1/apps/token.',
            'api_key_type' => 'temporary access_token',
            'api_key_ttl' => '3600 seconds',
            'refresh_token_ttl' => '2592000 seconds',
        ];
    }
}
