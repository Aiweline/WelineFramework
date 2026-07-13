<?php

declare(strict_types=1);

namespace Weline\AppStore\Api;

interface AppStorePlatformUrlResolverInterface
{
    /**
     * @return array{platform_url:string,source:string,environment:string}
     */
    public function resolve(): array;

    public function resolveUrl(): string;
}
