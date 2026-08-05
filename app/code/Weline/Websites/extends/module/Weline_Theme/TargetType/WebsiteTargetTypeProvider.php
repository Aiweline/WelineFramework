<?php

declare(strict_types=1);

namespace Weline\Websites\Extends\Module\Weline_Theme\TargetType;

use Weline\Theme\Api\TargetTypeProviderInterface;
use Weline\Websites\Model\Website;

class WebsiteTargetTypeProvider implements TargetTypeProviderInterface
{
    public function __construct(
        private readonly Website $website
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
        if ($targetId <= 0) {
            return false;
        }

        try {
            $row = $this->website->clearQuery()->clearData()
                ->where(Website::schema_fields_ID, $targetId)
                ->find()
                ->fetchArray();
            return is_array($row) && (int)($row[Website::schema_fields_ID] ?? 0) === $targetId;
        } catch (\Throwable) {
            return false;
        }
    }

    public function resolve(int $targetId, array $context = []): ?array
    {
        if (!$this->validate($targetId, $context)) {
            return null;
        }

        try {
            $row = $this->website->clearQuery()->clearData()
                ->where(Website::schema_fields_ID, $targetId)
                ->find()
                ->fetchArray();
        } catch (\Throwable) {
            return null;
        }

        return [
            'target_type' => $this->getCode(),
            'target_id' => $targetId,
            'label' => (string)($row[Website::schema_fields_NAME] ?? ('#' . $targetId)),
            'code' => (string)($row[Website::schema_fields_CODE] ?? ''),
        ];
    }

    public function canUseLayoutType(string $layoutType): bool
    {
        return strtolower(trim($layoutType)) === 'dashboard';
    }
}
