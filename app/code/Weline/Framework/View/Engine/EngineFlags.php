<?php
declare(strict_types=1);

namespace Weline\Framework\View\Engine;

use Weline\Framework\App\Env;
use Weline\Framework\Runtime\RequestContext;

/**
 * Feature flag for ViewEngine v2 cutover / rollback.
 *
 * Precedence: RequestContext override → env.php `view.engine.v2` → default true.
 * Set env `view.engine.v2` => false (or RequestContext) to roll back to Template legacy path.
 */
final class EngineFlags
{
    public const ENV_KEY = 'view.engine.v2';
    public const REQUEST_KEY = 'view.engine.v2';

    public static function isV2Enabled(): bool
    {
        if (RequestContext::has(self::REQUEST_KEY)) {
            return self::truthy(RequestContext::get(self::REQUEST_KEY));
        }

        return self::truthy(Env::get(self::ENV_KEY, true));
    }

    private static function truthy(mixed $value): bool
    {
        if ($value === false || $value === 0 || $value === '0' || $value === 'false' || $value === 'off') {
            return false;
        }

        return true;
    }
}
