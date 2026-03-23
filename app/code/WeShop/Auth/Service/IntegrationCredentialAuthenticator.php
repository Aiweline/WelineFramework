<?php

declare(strict_types=1);

namespace WeShop\Auth\Service;

use Weline\Api\Model\ApiUser;

class IntegrationCredentialAuthenticator
{
    public function __construct(
        private readonly ApiUser $apiUser
    ) {
    }

    public function authenticate(string $apiKey, string $apiSecret): ApiUser
    {
        $apiUser = $this->apiUser->reset()
            ->where(ApiUser::schema_fields_api_key, trim($apiKey))
            ->where(ApiUser::schema_fields_is_deleted, 0)
            ->find()
            ->fetch();

        if (!$apiUser->getId() || !$apiUser->verifySecret($apiSecret) || !$apiUser->getIsEnabled()) {
            throw new \RuntimeException((string) __('Invalid integration credentials.'));
        }

        return $apiUser;
    }
}
