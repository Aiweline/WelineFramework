<?php

declare(strict_types=1);

namespace Weline\Admin\Integration\Backend;

use Weline\Admin\Controller\BaseController;
use Weline\Backend\Api\Runtime\BackendThemeCacheInvalidatorInterface;

final class BackendThemeCacheInvalidator implements BackendThemeCacheInvalidatorInterface
{
    public function invalidate(): void
    {
        BaseController::clearRuntimeFullPageCache();
    }
}
