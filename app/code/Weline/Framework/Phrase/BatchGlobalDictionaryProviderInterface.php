<?php

declare(strict_types=1);

namespace Weline\Framework\Phrase;

/** 可选的精确词批量接口；不要求已有公共词典 Provider 修改实现。 */
interface BatchGlobalDictionaryProviderInterface
{
    /**
     * 按输入原词返回译文；缺少的键表示确认不存在，查询失败必须抛出异常。
     *
     * @param list<string> $words
     * @return array<string, string>
     */
    public function exactWords(string $locale, array $words): array;
}
