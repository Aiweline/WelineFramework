<?php

declare(strict_types=1);

namespace Weline\Server\Service;

final class StaticRequestBypassDecider
{
    public static function shouldDeferToFramework(string $candidateUri): bool
    {
        $candidateUri = \trim(\str_replace('\\', '/', $candidateUri), '/');
        if ($candidateUri === '') {
            return false;
        }

        $isThemeViewAsset = \str_contains($candidateUri, '/view/theme/frontend/')
            || \str_contains($candidateUri, '/view/theme/backend/');
        if ($isThemeViewAsset) {
            return true;
        }

        if (\str_starts_with($candidateUri, 'static/') || \str_starts_with($candidateUri, 'pub/static/')) {
            return false;
        }

        return false;
    }
}
