<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Helper;

use Weline\Meta\Helper\MetaTranslation as MetaMetaTranslation;
use Weline\Meta\Model\MetaLocal;

/**
 * Theme 侧 Meta 翻译：委托 MetaLocal，禁止再写 I18n 词典 @meta::。
 */
class MetaTranslation
{
    public static function getTranslatedValue(string $metaKey, ?string $locale = null, ?string $defaultValue = null): string
    {
        return MetaMetaTranslation::getTranslatedValueWithScope(
            $metaKey,
            MetaLocal::DEFAULT_SCOPE,
            $locale,
            $defaultValue,
        );
    }

    public static function getTranslatedValueWithScope(
        string $metaKey,
        string $scope = MetaLocal::DEFAULT_SCOPE,
        ?string $locale = null,
        ?string $defaultValue = null,
        int $versionId = MetaLocal::DEFAULT_VERSION_ID,
    ): string {
        return MetaMetaTranslation::getTranslatedValueWithScope(
            $metaKey,
            $scope,
            $locale,
            $defaultValue,
            $versionId,
        );
    }

    public static function setTranslatedValueWithScope(
        string $metaKey,
        string $value,
        string $scope = MetaLocal::DEFAULT_SCOPE,
        ?string $locale = null,
        int $versionId = MetaLocal::DEFAULT_VERSION_ID,
    ): bool {
        return MetaMetaTranslation::setTranslatedValueWithScope(
            $metaKey,
            $value,
            $scope,
            $locale,
            $versionId,
        );
    }

    public static function findTranslatedValueWithScope(
        string $metaKey,
        string $scope = MetaLocal::DEFAULT_SCOPE,
        ?string $locale = null,
        int $versionId = MetaLocal::DEFAULT_VERSION_ID,
    ): ?string {
        return MetaMetaTranslation::findTranslatedValueWithScope(
            $metaKey,
            $scope,
            $locale,
            $versionId,
        );
    }

    public static function deleteTranslatedValueWithScope(
        string $metaKey,
        string $scope = MetaLocal::DEFAULT_SCOPE,
        ?string $locale = null,
        int $versionId = MetaLocal::DEFAULT_VERSION_ID,
    ): bool {
        return MetaMetaTranslation::deleteTranslatedValueWithScope(
            $metaKey,
            $scope,
            $locale,
            $versionId,
        );
    }
}
