<?php

declare(strict_types=1);

namespace Weline\I18n\Extends\Module\Weline_Seo\HeadContextProvider;

use Weline\I18n\Service\Seo\InternationalSeoContextService;
use Weline\Seo\Interface\HeadContextProviderInterface;

class InternationalSeoProvider implements HeadContextProviderInterface
{
    public function __construct(
        private readonly InternationalSeoContextService $contextService,
    ) {
    }

    /**
     * @param mixed $template
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function provide($template, array $context): array
    {
        return $this->contextService->build($template, $context);
    }
}
