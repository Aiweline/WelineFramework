<?php

declare(strict_types=1);

namespace Weline\Theme\Integration\Framework;

use Weline\Framework\Runtime\ThemeIdProviderInterface;
use Weline\Framework\Runtime\ThemePublishedVersionResolverInterface;
use Weline\Framework\View\ThemeSpecGateInterface;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Service\Spec\ThemeSpecValidator;
use Weline\Theme\Service\ThemePublishedVersionRuntimeResolver;

/**
 * Publishes theme identity/spec/generation read capabilities through the
 * Framework-owned contracts.
 */
final class ThemeFrameworkReadProvider implements ThemeIdProviderInterface, ThemeSpecGateInterface, ThemePublishedVersionResolverInterface
{
    public function currentThemeId(): ?int
    {
        $theme = ThemeData::getCurrentTheme();

        return $theme !== null && \method_exists($theme, 'getId') ? (int)$theme->getId() : null;
    }

    public function assertFileForDev(string $originPath): void
    {
        ThemeSpecValidator::assertFileForDev($originPath);
    }

    public function isAcceptableStorefrontGenerationId(string $clientVersionId): bool
    {
        return (new ThemePublishedVersionRuntimeResolver())
            ->isAcceptableStorefrontGenerationId($clientVersionId);
    }
}
