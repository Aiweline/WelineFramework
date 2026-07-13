<?php

declare(strict_types=1);

namespace Weline\Admin\Integration\Backend;

use Weline\Admin\Service\BackendLoginReturnUrlService;
use Weline\Backend\Api\Routing\LoginReturnUrlProviderInterface;
use Weline\Framework\Http\Request;

final class LoginReturnUrlProvider implements LoginReturnUrlProviderInterface
{
    public function __construct(
        private readonly BackendLoginReturnUrlService $returnUrlService,
    ) {
    }

    public function shouldCaptureCurrentRequestReturnUrl(Request $request, string $currentUrl): bool
    {
        return $this->returnUrlService->shouldCaptureCurrentRequestReturnUrl($request, $currentUrl);
    }

    public function normalizeCandidateUrl(string $candidate): ?string
    {
        return $this->returnUrlService->normalizeCandidateUrl($candidate);
    }

    public function buildLoginUrlWithReturn(string $loginUrl, string $currentUrl, string $reason = ''): string
    {
        return $this->returnUrlService->buildLoginUrlWithReturn($loginUrl, $currentUrl, $reason);
    }
}
