<?php

declare(strict_types=1);

namespace Weline\FileManager\Extends\Module\Weline_Widget\Integration;

use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\Data\ImageUsage;
use Weline\FileManager\Api\FileAssetManagerInterface;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Widget\Api\Param\FileImagePreviewResolverInterface;

final class WidgetFileImagePreviewResolver implements FileImagePreviewResolverInterface
{
    public function __construct(private readonly FileAssetManagerInterface $assets)
    {
    }

    public function resolvePreviewUrl(array $node): string
    {
        if (($node['type'] ?? null) !== 'file-image' || !is_array($node['usage'] ?? null)) {
            return '';
        }

        try {
            $usage = ImageUsage::fromArray($node['usage']);
            // Theme Editor param forms often lack a request ScopeIdentity; editor
            // preview still needs a public/temporary URL for the config thumbnail.
            $scope = RequestContext::scopeIdentity() ?? ScopeIdentity::global();

            $resolved = $this->assets->resolveImage(
                $usage,
                new FileAccessContext(
                    $scope,
                    $usage->localeCode,
                    null,
                    [],
                    'preview',
                    1,
                ),
            );

            return trim($resolved->src);
        } catch (\Throwable) {
            return '';
        }
    }
}
