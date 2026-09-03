<?php

declare(strict_types=1);

namespace Weline\Payment\Interface;

/**
 * Optional pre-authorize hook (e.g. platform sandbox credential bootstrap).
 */
interface ProviderConnectPrepareInterface
{
    /**
     * @return array{ready:bool,redirect_url:?string,message:?string}
     */
    public function prepareConnectAuthorize(string $environment, ?string $scope = null, array $context = []): array;
}
