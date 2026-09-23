<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

/** 一次渲染持有的结构与配置快照，发布指针变化不影响已开始的请求。 */
final readonly class EntityRenderBinding
{
    public function __construct(
        public int $themeId,
        public string $scope,
        public string $identityKey,
        public string $entityKey,
        public string $structureKey,
        public string $configKey,
        public string $source,
        public string $templatePath,
        public string $configPath,
        public string $assetsPath,
        public string $structurePath,
        public string $shellPath,
    ) {
    }

    public function cacheKey(): string
    {
        return hash('sha256', json_encode([
            $this->themeId, $this->scope, $this->identityKey, $this->entityKey,
            $this->source, $this->structureKey, $this->configKey,
        ], JSON_THROW_ON_ERROR));
    }
}
