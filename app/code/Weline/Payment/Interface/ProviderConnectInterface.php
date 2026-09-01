<?php

declare(strict_types=1);

namespace Weline\Payment\Interface;

/**
 * Optional OAuth / one-click connect for a payment method.
 * Shell schedules via method_code; Provider owns gateway-specific OAuth only.
 */
interface ProviderConnectInterface
{
    /**
     * @param array{website_code?:string,store_code?:string,channel_code?:string,locale?:string} $context
     * @return array{authorization_url:string,state:string,environment:string}
     */
    public function startConnect(string $environment = 'sandbox', ?string $scope = null, array $context = []): array;

    /**
     * Must be idempotent for the same OAuth state replay.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function completeConnect(array $params): array;

    public function ownsOAuthState(string $state): bool;

    /**
     * Only shell-unified Return URLs (never Provider-private paths).
     *
     * @return list<string>
     */
    public function suggestedRedirectUris(): array;

    /**
     * @param array{scope?:string,website_code?:string,store_code?:string,channel_code?:string,locale?:string} $context
     */
    public function connectConfigUrl(?string $scope = null, array $context = []): string;

    /**
     * @return array{success:bool,message:string,details?:array<string,mixed>}
     */
    public function testConnect(string $environment = 'sandbox', ?string $scope = null): array;

    public function revokeConnect(string $environment = 'sandbox', ?string $scope = null): void;
}
