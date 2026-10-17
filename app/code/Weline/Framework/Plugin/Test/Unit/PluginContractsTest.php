<?php

declare(strict_types=1);

namespace Weline\Framework\Plugin\Test\Unit;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllPluginContractsPass().
 */
final class PluginContractsTest extends TestCase
{
    public function testAllPluginContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'PluginsManagerEmptyInstance::testScanPluginsGuardsEmptyInstanceBeforeReflection' => function () {
            $this->assert_PluginsManagerEmptyInstance_testScanPluginsGuardsEmptyInstanceBeforeReflection();
        },
            'PluginsManagerEmptyInstance::testPluginXmlReaderDoesNotDumpRawInterceptorNodes' => function () {
            $this->assert_PluginsManagerEmptyInstance_testPluginXmlReaderDoesNotDumpRawInterceptorNodes();
        },
        ];
    }

private function assert_PluginsManagerEmptyInstance_testScanPluginsGuardsEmptyInstanceBeforeReflection(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework/Plugin' . '/PluginsManager.php');
    self::assertStringContainsString('isProcessedPluginsMap', $src);
    self::assertStringContainsString('isRawInterceptorMap', $src);
    self::assertStringContainsString("trim((string)(\$row['instance'] ?? '')) !== ''", $src);
    self::assertStringContainsString('pluginInstanceClass', $src);
    self::assertDoesNotMatchRegularExpression('/new \\\\ReflectionClass\(\$type_plugin\[\'instance\'\]\);/', $src, 'Must not ReflectionClass raw $type_plugin[instance] without empty guard');
}

private function assert_PluginsManagerEmptyInstance_testPluginXmlReaderDoesNotDumpRawInterceptorNodes(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework/Plugin' . '/Config/PluginXmlReader.php');
    self::assertStringContainsString('禁止把原始 XML 节点原样塞进注册表', $src);
    self::assertStringNotContainsString("\$module_plugin_interceptors[\$pluginName][] = \$item_interceptor;", $src);
}
}
