<?php

declare(strict_types=1);

namespace Weline\Admin\Integration\Acl;

use Weline\Acl\Api\Auth\RememberLoginProviderInterface;
use Weline\Admin\Service\BackendRememberLoginService;
use Weline\Framework\Http\Request;

final class RememberLoginProvider implements RememberLoginProviderInterface
{
    public function __construct(
        private readonly BackendRememberLoginService $rememberLoginService,
    ) {
    }

    public function restoreIfNeeded(Request $request): bool
    {
        return $this->rememberLoginService->restoreIfNeeded($request);
    }

    public function consumeRestoredAclContext(): ?array
    {
        return $this->rememberLoginService->consumeRestoredAclContext();
    }

    public function consumeRestoredSession(): ?object
    {
        return $this->rememberLoginService->consumeRestoredSession();
    }
}
