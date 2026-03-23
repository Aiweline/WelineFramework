<?php

declare(strict_types=1);

namespace WeShop\QA\Controller\Frontend\QA;

/**
 * Compatibility alias for older storefront endpoints.
 * Keep `/qa/ask` working by delegating to the new add flow.
 */
class Ask extends Add
{
}
