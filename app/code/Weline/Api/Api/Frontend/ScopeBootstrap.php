<?php

declare(strict_types=1);

namespace Weline\Api\Api\Frontend;

/**
 * Frontend REST registration for storefront Scope hole-punch.
 *
 * API route discovery scans the module Api/ directory; this wrapper exposes
 * /api/frontend/scope-bootstrap without registering Frontend Controller/Api as PC.
 * Runtime fast-path in Router::Api also points at the Frontend implementation.
 */
class ScopeBootstrap extends \Weline\Frontend\Controller\Api\ScopeBootstrap
{
}
