<?php

declare(strict_types=1);

namespace Weline\Framework\Deploy;

use Weline\Framework\App\Env;

/**
 * Whether storefront static URLs must use production public form (/static/{ns}/...).
 *
 * App DEV/PROD are define-once. Mode\Set updates env.php while long-lived WLS workers
 * may still have DEV=true until reload — prefer Env::system('deploy') when present.
 * Staging bake also forces production URLs while the parent process constants stay DEV.
 */
final class DeployPublicUrlMode
{
    public static function useProduction(): bool
    {
        if (DeployStagingSession::forcesProductionPublicUrls()) {
            return true;
        }

        try {
            $deploy = strtolower(trim((string)(Env::system('deploy') ?? '')));
            if ($deploy === 'prod') {
                return true;
            }
            if ($deploy === 'dev') {
                return false;
            }
        } catch (\Throwable) {
        }

        return defined('PROD') && PROD;
    }
}
