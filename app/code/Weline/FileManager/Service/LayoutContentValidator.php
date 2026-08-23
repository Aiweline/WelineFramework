<?php

declare(strict_types=1);

namespace Weline\FileManager\Service;

use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\Data\ImageUsage;
use Weline\FileManager\Api\FileAssetManagerInterface;
use Weline\FileManager\Api\LayoutContentValidatorInterface;
use Weline\Framework\Runtime\ScopeIdentity;

final class LayoutContentValidator implements LayoutContentValidatorInterface
{
    private const MAX_NODES = 10000;
    private const MAX_DEPTH = 64;

    public function __construct(
        private readonly FileAssetManagerInterface $assets,
        private readonly FileAssetReferenceIndexer $referenceIndexer,
    ) {
    }

    public function validate(array $layoutData, array $context): void
    {
        $scope = $context['scope_identity'] ?? null;
        $localeCode = trim((string)($context['locale_code'] ?? ''));
        $count = 0;
        $usages = [];
        $this->collect($layoutData, 0, $count, '$', $usages);
        $ownerType = trim((string)($context['reference_owner_type'] ?? ''));
        $ownerId = trim((string)($context['reference_owner_id'] ?? ''));
        $indexReferences = ($context['index_references'] ?? false) === true
            && $ownerType !== ''
            && $ownerId !== '';
        $referenceOnly = ($context['reference_only'] ?? false) === true;
        if ($referenceOnly && !$indexReferences) {
            throw new \InvalidArgumentException((string)__('文件布局仅索引模式必须指定完整 owner 身份。'));
        }
        if ($usages === [] && !$indexReferences) {
            return;
        }
        if (!$scope instanceof ScopeIdentity || $localeCode === '') {
            throw new \InvalidArgumentException((string)__('文件布局校验缺少显式 ScopeIdentity 或 locale_code。'));
        }
        $purpose = (string)($context['purpose'] ?? 'publish');
        if (($context['phase'] ?? null) === 'publish' || $purpose === 'publish') {
            // Theme/CMS published layouts are publicly renderable in v1. A
            // backend actor may preview a private asset, but that identity is
            // not a frontend delivery capability and must not make a public
            // layout publishable.
            $purpose = FileAccessContext::PURPOSE_PUBLIC_PUBLISH;
        }
        $accessContext = new FileAccessContext(
            $scope,
            $localeCode,
            isset($context['actor_id']) ? (int)$context['actor_id'] : null,
            is_array($context['roles'] ?? null) ? array_values($context['roles']) : [],
            $purpose,
            max(1, (int)($context['policy_revision'] ?? 1)),
        );
        $references = [];
        foreach ($usages as $item) {
            $imageUsage = $item['usage'];
            if ($referenceOnly) {
                $this->assets->validateImageReference($imageUsage, $accessContext);
            } else {
                $this->assets->validateImageUsage($imageUsage, $accessContext);
            }
            $references[] = [
                'asset_id' => $imageUsage->assetId,
                'scope_key' => $accessContext->scope->canonicalKey(),
                'locale_code' => $accessContext->localeCode,
                'field_path' => $item['field_path'],
            ];
        }
        // Validation is intentionally read-only. The derived reference index is
        // rebuilt only by Theme's durable publish transaction.
        if ($indexReferences) {
            $this->referenceIndexer->replace(
                $ownerType,
                $ownerId,
                max(1, (int)($context['owner_version'] ?? 1)),
                $references,
            );
        }
    }

    /**
     * @param array<string|int,mixed> $node
     * @param list<array{usage:ImageUsage,field_path:string}> $usages
     */
    private function collect(
        array $node,
        int $depth,
        int &$count,
        string $path,
        array &$usages,
    ): void
    {
        if (++$count > self::MAX_NODES || $depth > self::MAX_DEPTH) {
            throw new \RuntimeException((string)__('Theme 布局内容超过文件校验上限。'));
        }
        if (($node['type'] ?? null) === 'file-image') {
            $directUsage = is_array($node['usage'] ?? null);
            $usage = $directUsage ? $node['usage'] : ($node['data']['usage'] ?? null);
            if (!is_array($usage)) {
                throw new \RuntimeException((string)__('file-image 节点缺少类型化 usage。'));
            }
            $imageUsage = ImageUsage::fromArray($usage);
            $usages[] = [
                'usage' => $imageUsage,
                'field_path' => $path . ($directUsage ? '.usage.asset_id' : '.data.usage.asset_id'),
            ];
        }
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $segment = is_int($key) ? '[' . $key . ']' : '.' . str_replace(['.', '[', ']'], '_', (string)$key);
                $this->collect($value, $depth + 1, $count, $path . $segment, $usages);
            }
        }
    }
}
