<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Fpc;

/**
 * FPC 存储适配器：仓读写失效由适配器封装；WLS 为默认实现。
 */
interface FpcStoreAdapterInterface
{
    public const EXTENDS_RELATIVE_PREFIX = 'extends/module/weline_framework/fpc/store/';

    public function code(): string;

    /** @param list<string> $urls */
    public function purgeUrls(array $urls): void;

    /**
     * Purge storefront slug leaves under host trees (locale×currency path variants).
     *
     * @param list<string> $hosts
     * @param list<string> $slugs
     */
    public function purgeSlugs(array $hosts, array $slugs): void;

    public function purgeAll(string $reason = ''): void;

    public function clearProcessCache(): void;
}
