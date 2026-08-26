<?php

declare(strict_types=1);

namespace Weline\FileManager\extends\module\Weline_Theme\Integration;

use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\Data\ImageUsage;
use Weline\FileManager\Api\FileAssetManagerInterface;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Theme\Api\Layout\HydratedLayoutValue;
use Weline\Theme\Api\Layout\LayoutValueHydratorInterface;

final class FileImageLayoutValueHydrator implements LayoutValueHydratorInterface
{
    public function __construct(private readonly FileAssetManagerInterface $assets)
    {
    }

    public function supports(array $node): bool
    {
        return ($node['type'] ?? null) === 'file-image';
    }

    public function hydrate(array $node, array $context): HydratedLayoutValue
    {
        if (!is_array($node['usage'] ?? null)) {
            throw new \RuntimeException((string)__('file-image 节点缺少类型化 usage。'));
        }
        $scope = $context['scope_identity'] ?? null;
        if (!$scope instanceof ScopeIdentity) {
            throw new \InvalidArgumentException((string)__('图片运行时解析缺少显式 ScopeIdentity。'));
        }
        $usage = ImageUsage::fromArray($node['usage']);
        $locale = trim((string)($context['locale_code'] ?? ''));
        $purpose = trim((string)($context['purpose'] ?? 'render'));
        if ($locale === '' || $locale !== $usage->localeCode) {
            // Theme editor preview may switch layout locale while media remains
            // stamped with the site-default locale. Soft-fallback for preview only;
            // publish/render stay strict so production never serves cross-locale usage.
            if ($purpose === 'preview' && $usage->localeCode !== '') {
                $locale = $usage->localeCode;
            } else {
                throw new \RuntimeException((string)__('图片语境语言与当前布局语言不一致。'));
            }
        }
        $access = new FileAccessContext(
            $scope,
            $locale,
            isset($context['actor_id']) ? (int)$context['actor_id'] : null,
            is_array($context['roles'] ?? null) ? array_values($context['roles']) : [],
            (string)($context['purpose'] ?? 'render'),
            max(1, (int)($context['policy_revision'] ?? 1)),
        );
        $resolved = $this->assets->resolveImage($usage, $access);

        return new HydratedLayoutValue($resolved->src, [
            'file_usage' => $usage->toArray(),
            'file_html' => $resolved->html,
            'file_alt' => $usage->decorative ? '' : $usage->alt,
            'file_asset_id' => $usage->assetId,
        ]);
    }
}
