<?php

declare(strict_types=1);

namespace Weline\Framework\Phrase;

/** 可选的模块词典批量读取；已有公共词典 Provider 无须增加实现。 */
interface ModuleGlobalDictionaryProviderInterface
{
    /**
     * 一次读取返回各模块独立词表，包含已确认无词条的模块空数组。
     * 查询失败必须抛出异常；空模块列表不表示加载完整词典。
     *
     * @param list<string> $modules
     * @return array<string, array<string, string>>
     */
    public function wordsByModule(string $locale, array $modules): array;
}
