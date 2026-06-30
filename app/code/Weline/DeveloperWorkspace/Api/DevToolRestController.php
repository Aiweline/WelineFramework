<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Api;

use Weline\Framework\App\Controller\BackendRestController;
use Weline\Framework\Controller\AbstractRestController;

/**
 * DevTool REST APIs live under the backend REST prefix, but authorize by DEV
 * mode or the dev-tool cookie instead of requiring a backend login session.
 */
class DevToolRestController extends BackendRestController
{
    public function __construct()
    {
        AbstractRestController::__construct();
    }
}
