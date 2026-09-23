<?php
declare(strict_types=1);

define('PROD', ($argv[1] ?? '') === 'prod');
require dirname(__DIR__, 7) . '/autoload.php';

$config = new class extends \Weline\SystemConfig\Model\SystemConfig {
    public function __construct() {}

    public function resolveTypedConfig(
        string $key, string $module, string $area,
        \Weline\Framework\Runtime\ScopeIdentity $identity,
        ?string $locale = null, mixed $default = null,
    ): \Weline\SystemConfig\Api\Scope\ConfigScopeValue {
        $values = ['resource_files/css_minify' => 'on', 'resource_files/js_minify' => 'off'];
        return new \Weline\SystemConfig\Api\Scope\ConfigScopeValue(
            $values[$key] ?? $default,
            \Weline\SystemConfig\Api\Scope\ConfigScopeSource::fromDefault(),
            $identity, $locale ?? 'default', [],
        );
    }
};
echo json_encode((new \Weline\Theme\Service\ThemeResourceConfig($config))->resolve(), JSON_THROW_ON_ERROR);
