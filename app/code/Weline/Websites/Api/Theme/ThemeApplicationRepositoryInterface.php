<?php

declare(strict_types=1);

namespace Weline\Websites\Api\Theme;

interface ThemeApplicationRepositoryInterface
{
    /** @return array{reference:?ThemeApplicationReference,revision:int} */
    public function read(string $scopeKey, string $storeMode, string $area): array;

    /**
     * 在同一写入范围内校验实际修订并替换本级应用引用。
     * null 移除引用但保留修订游标，防止旧保存覆盖恢复跟随后的设置。
     * @return array{reference:?ThemeApplicationReference,revision:int}
     */
    public function compareAndSwap(
        string $scopeKey,
        string $storeMode,
        string $area,
        ?ThemeApplicationReference $reference,
        int $expectedRevision,
    ): array;
}
