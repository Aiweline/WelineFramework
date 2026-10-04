<?php
declare(strict_types=1);
namespace Weline\Theme\Api\Version;

/** 使用方校验准确主题内容引用的只读契约，不切换 Theme 的版本选择。 */
interface ThemeApplicationReferenceReaderInterface
{
    public function validateReference(array $reference): array;
    public function resourceReferences(array $reference): array;
    /** 仅供一次升级读取旧应用依据，正式请求不能调用此迁移入口。 */
    public function exportLegacyApplicationSnapshot(): array;
}
