<?php

declare(strict_types=1);

namespace Weline\Api\Api\Framework;

/**
 * Frontend REST registration for the cacheable CSP policy document.
 *
 * API route discovery scans the Api directory; this wrapper exposes
 * /api/framework/csp-policy without registering Framework Controller/Api as PC.
 */
class CspPolicy extends \Weline\Framework\Controller\Api\CspPolicy
{
}
