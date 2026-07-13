<?php

declare(strict_types=1);

namespace Weline\Theme\Integration\Websites;

use Weline\Theme\Api\TargetTypeProviderInterface;
use Weline\Websites\Api\WebsiteTargetLookupInterface;

final class WebsiteTargetTypeProvider implements TargetTypeProviderInterface
{
    public function __construct(
        private readonly WebsiteTargetLookupInterface $websiteTargetLookup,
    ) {
    }

    public function getCode(): string
    {
        return 'website';
    }

    public function getLabel(): string
    {
        return (string)__('站点');
    }

    public function getModule(): string
    {
        return 'Weline_Websites';
    }

    public function getLayoutTypes(): array
    {
        return ['dashboard'];
    }

    public function getCapabilities(): array
    {
        return ['layout_selection', 'visual_editor_lock', 'virtual_layout', 'meta', 'preview', 'render'];
    }

    public function validate(int $targetId, array $context = []): bool
    {
        try {
            return $this->websiteTargetLookup->find($targetId) !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    public function resolve(int $targetId, array $context = []): ?array
    {
        try {
            $website = $this->websiteTargetLookup->find($targetId);
        } catch (\Throwable) {
            return null;
        }
        if ($website === null) {
            return null;
        }
        return [
            'target_type' => $this->getCode(),
            'target_id' => $targetId,
            'label' => $website['name'],
            'code' => $website['code'],
        ];
    }

    public function canUseLayoutType(string $layoutType): bool
    {
        return \strtolower(\trim($layoutType)) === 'dashboard';
    }
}
