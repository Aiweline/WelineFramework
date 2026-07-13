<?php

declare(strict_types=1);

namespace Weline\Backend\Api\Routing;

use Weline\Framework\Http\Request;

/**
 * Optional backend-login return URL policy.
 *
 * Backend owns the redirect hook; an admin UI module may contribute the
 * route-aware validation policy without creating a Backend -> Admin edge.
 */
interface LoginReturnUrlProviderInterface
{
    public function shouldCaptureCurrentRequestReturnUrl(Request $request, string $currentUrl): bool;

    public function normalizeCandidateUrl(string $candidate): ?string;

    public function buildLoginUrlWithReturn(string $loginUrl, string $currentUrl, string $reason = ''): string;
}
