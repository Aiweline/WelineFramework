<?php

declare(strict_types=1);

namespace Weline\Websites\Extends\Module\Weline_Websites\AiSiteBuilderProvider;

use Weline\Websites\Api\AiSiteBuilderProviderInterface;

class WebsitesDefaultProvider implements AiSiteBuilderProviderInterface
{
    public function getCode(): string
    {
        return 'websites_default';
    }

    public function getName(): string
    {
        return (string)__('Websites 默认建站流程');
    }

    public function getDescription(): string
    {
        return (string)__('Weline_Websites 内置的 AI 建站工作台默认流程提供者。');
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getSortOrder(): int
    {
        return 10;
    }
}
