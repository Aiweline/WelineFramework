<?php

declare(strict_types=1);

namespace Weline\HelpPay\Controller;

use Weline\Framework\Router\RouterInterface;

/** Owns /h/{token} help_pay, /s/{token} selection_share, /q/{token} quick_pay. */
final class Router implements RouterInterface
{
    public static function process(string &$path, array &$rule): void
    {
        if (!empty($rule['module'])) {
            return;
        }

        // Keep original case: tokens are case-sensitive storage keys.
        $rawPath = trim(str_replace('\\', '/', $path), '/');

        if (preg_match('#^h/([A-Za-z0-9_-]{16,64})$#D', $rawPath, $m) === 1) {
            $path = 'weline_helppay/frontend/payer';
            $rule['module'] = 'Weline_HelpPay';
            $rule['token'] = (string) $m[1];
            $rule['kind'] = 'help_pay';
            self::setQuery('token', (string) $m[1]);
            self::setQuery('kind', 'help_pay');

            return;
        }

        if (preg_match('#^s/([A-Za-z0-9_-]{16,64})$#D', $rawPath, $m) === 1) {
            $path = 'weline_helppay/frontend/selectionShare';
            $rule['module'] = 'Weline_HelpPay';
            $rule['token'] = (string) $m[1];
            $rule['kind'] = 'selection_share';
            self::setQuery('token', (string) $m[1]);
            self::setQuery('kind', 'selection_share');

            return;
        }

        if (preg_match('#^q/([A-Za-z0-9_-]{16,64})$#D', $rawPath, $m) === 1) {
            $path = 'weline_helppay/frontend/quickPay';
            $rule['module'] = 'Weline_HelpPay';
            $rule['token'] = (string) $m[1];
            $rule['kind'] = 'quick_pay_self';
            self::setQuery('token', (string) $m[1]);
            self::setQuery('kind', 'quick_pay_self');
        }
    }

    private static function setQuery(string $key, string $value): void
    {
        try {
            if (class_exists(\Weline\Framework\Context::class)) {
                \Weline\Framework\Context::current()->set('input.query.' . $key, $value);
            }
            if (class_exists(\Weline\Framework\Env\WelineEnv::class)) {
                \Weline\Framework\Env\WelineEnv::setGet($key, $value);
            }
            if (\function_exists('w_env_set')) {
                \w_env_set($key, $value);
            }
        } catch (\Throwable) {
            // Unit tests may run without full request Context.
        }
    }
}
