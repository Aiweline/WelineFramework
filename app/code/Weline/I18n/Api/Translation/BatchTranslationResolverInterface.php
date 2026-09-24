<?php
declare(strict_types=1);

namespace Weline\I18n\Api\Translation;

/** 可选批量翻译能力；不要求第三方单条解析器实现新方法。 */
interface BatchTranslationResolverInterface extends TranslationResolverInterface
{
    /** @param list<string> $sources @return array<string,string> 按去空白后的源词返回结果。 */
    public function translateMany(array $sources, string $localeCode, array $preferredModules = []): array;
}
