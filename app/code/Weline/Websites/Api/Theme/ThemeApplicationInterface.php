<?php

declare(strict_types=1);

namespace Weline\Websites\Api\Theme;

/** 网站拥有的主题应用选择，与 Theme 内容编辑分别保存。 */
interface ThemeApplicationInterface
{
    /**
     * 身份及近到远的范围链由网站适配器完成权威校验后提供。
     * 不接收未经校验的浏览器声明，不从显示名称猜测祖先。
     * @param non-empty-list<string> $scopeKeys
     */
    public function resolve(array $scopeKeys, string $storeMode, string $area): ThemeApplicationResolution;

    /** @return array{reference:?ThemeApplicationReference,revision:int} */
    public function getOwn(string $scopeKey, string $storeMode, string $area): array;

    /**
     * 保存前由调用方通过 Theme 公开能力校验版本归属与可用性。
     * @return array{reference:?ThemeApplicationReference,revision:int}
     */
    public function save(string $scopeKey, string $storeMode, string $area, ThemeApplicationReference $reference, int $expectedRevision): array;

    /** 仅移除网站本级应用引用，保留 Theme 内容及历史。 */
    public function removeOwn(string $scopeKey, string $storeMode, string $area, int $expectedRevision): array;
}
