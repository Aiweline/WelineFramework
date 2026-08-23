<?php

declare(strict_types=1);

namespace Weline\FileManager\Service;

use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\Data\ImageUsage;
use Weline\FileManager\Api\FileAssetManagerInterface;
use Weline\Framework\Runtime\RequestContext;

final class FileImageRenderer
{
    public function __construct(private readonly FileAssetManagerInterface $assets)
    {
    }

    public function renderFromMixed(
        mixed $usage,
        string $assetId = '',
        string $alt = '',
        bool $decorative = false,
        string $locale = '',
        string $class = '',
    ): string {
        if (is_string($usage) && trim($usage) !== '') {
            try {
                $usage = json_decode($usage, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new \InvalidArgumentException((string)__('图片 usage JSON 无效。'), 0, $exception);
            }
        }
        $requestLocale = FileAssetManager::normalizeLocale(RequestContext::getWelineUserLang());
        $locale = trim($locale) !== '' ? FileAssetManager::normalizeLocale($locale) : $requestLocale;
        if (!hash_equals($requestLocale, $locale)) {
            throw new \RuntimeException((string)__('文件图片语言必须与当前请求语言一致。'));
        }
        if (is_array($usage)) {
            $imageUsage = ImageUsage::fromArray($usage);
            if (!hash_equals($locale, $imageUsage->localeCode)) {
                throw new \RuntimeException((string)__('图片语境语言与当前请求语言不一致。'));
            }
        } else {
            $imageUsage = new ImageUsage(trim($assetId), $locale, $decorative ? '' : trim($alt), ImageUsage::ALT_CONFIRMED, $decorative);
        }
        $scope = RequestContext::scopeIdentity();
        if ($scope === null) {
            throw new \RuntimeException((string)__('文件图片渲染缺少显式 ScopeIdentity。'));
        }
        return $this->assets->renderImage($imageUsage, new FileAccessContext($scope, $locale), $class);
    }
}
