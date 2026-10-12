<?php

declare(strict_types=1);

/**
 * Contract: PluginsManager must not ReflectionClass empty/missing interceptor instance.
 * Guards the Class "" does not exist crash seen when processed/raw plugin maps mix.
 */
namespace Weline\Framework\Plugin\Test\Unit;

use PHPUnit\Framework\TestCase;

final class PluginsManagerEmptyInstanceContractTest extends TestCase
{
    public function testScanPluginsGuardsEmptyInstanceBeforeReflection(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 2) . '/PluginsManager.php'
        );
        self::assertStringContainsString('isProcessedPluginsMap', $src);
        self::assertStringContainsString('isRawInterceptorMap', $src);
        self::assertStringContainsString("trim((string)(\$row['instance'] ?? '')) !== ''", $src);
        self::assertStringContainsString('pluginInstanceClass', $src);
        self::assertDoesNotMatchRegularExpression(
            '/new \\\\ReflectionClass\(\$type_plugin\[\'instance\'\]\);/',
            $src,
            'Must not ReflectionClass raw $type_plugin[instance] without empty guard'
        );
    }

    public function testPluginXmlReaderDoesNotDumpRawInterceptorNodes(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 2) . '/Config/PluginXmlReader.php'
        );
        self::assertStringContainsString('禁止把原始 XML 节点原样塞进注册表', $src);
        self::assertStringNotContainsString(
            "\$module_plugin_interceptors[\$pluginName][] = \$item_interceptor;",
            $src
        );
    }
}
