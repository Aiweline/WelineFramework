<?php

declare(strict_types=1);

namespace Weline\FileManager\Service;

use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\Data\ImageUsage;
use Weline\FileManager\Api\FileAssetManagerInterface;
use Weline\Framework\Runtime\RequestContext;

final class FileImageRenderer
{
    public function __construct(
        private readonly FileAssetManagerInterface $assets,
        private readonly FileImageReferenceNormalizer $normalizer,
    ) {
    }

    public function renderFromMixed(
        mixed $usage,
        string $assetId = '',
        string $alt = '',
        bool $decorative = false,
        string $locale = '',
        string $class = '',
        ?bool $complement = null,
        mixed $width = null,
        mixed $height = null,
        string $aspectRatio = '',
    ): string {
        $requestLocale = FileAssetManager::normalizeLocale(RequestContext::getWelineUserLang());
        $locale = trim($locale) !== '' ? FileAssetManager::normalizeLocale($locale) : $requestLocale;
        if (!hash_equals($requestLocale, $locale)) {
            throw new \RuntimeException((string)__('文件图片语言必须与当前请求语言一致。'));
        }
        [$layoutWidth, $layoutHeight] = ImageUsage::layoutPairFromMixed(
            $width,
            $height,
            $aspectRatio,
        );

        $imageUsage = $this->normalizer->normalizeToUsage(
            $usage,
            $assetId,
            $alt,
            $decorative,
            $locale,
            $complement,
            $layoutWidth,
            $layoutHeight,
        );
        if ($imageUsage === null) {
            return '';
        }
        if (!hash_equals($locale, $imageUsage->localeCode)) {
            throw new \RuntimeException((string)__('图片语境语言与当前请求语言不一致。'));
        }

        $scope = RequestContext::scopeIdentity();
        if ($scope === null) {
            throw new \RuntimeException((string)__('文件图片渲染缺少显式 ScopeIdentity。'));
        }

        try {
            return $this->assets->renderImage($imageUsage, new FileAccessContext($scope, $locale), $class);
        } catch (\Throwable) {
            // Missing / inaccessible asset must not break the page.
            return '';
        }
    }
}
