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

        return \str_contains($candidateUri, '/view/theme/frontend/')
            || \str_contains($candidateUri, '/view/theme/backend/');
    }
}
