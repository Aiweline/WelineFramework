<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Controller;

use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Sse\SseWriter;

final class AiSiteAgentRunRegenerateBlockOperationContractTest extends TestCase
{
    public function testRunRegenerateBlockOperationIsActuallyInvokedAndInterruptedByDebugDd(): void
    {
        $tempFile = \tempnam(\sys_get_temp_dir(), 'pb-run-regenerate-');
        self::assertIsString($tempFile);
        $projectRoot = \str_replace('\\', '\\\\', (string)\BP);
        $script = <<<'PHP'
<?php
declare(strict_types=1);
define('BP', '__PROJECT_ROOT__');
require BP . '/vendor/autoload.php';

$controllerReflection = new ReflectionClass(\GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent::class);
$controller = $controllerReflection->newInstanceWithoutConstructor();
$method = $controllerReflection->getMethod('runRegenerateBlockOperation');
$method->setAccessible(true);

$sessionReflection = new ReflectionClass(\GuoLaiRen\PageBuilder\Model\AiSiteAgentSession::class);
$session = $sessionReflection->newInstanceWithoutConstructor();
$sse = new \Weline\Framework\Http\Sse\SseWriter();

$method->invoke($controller, $sse, $session, 1, 'home_page', 'header/ai-site-header', 'test refine');
echo "AFTER_CALL";
PHP;
        $script = \str_replace('__PROJECT_ROOT__', $projectRoot, $script);
        \file_put_contents($tempFile, $script);

        $cmd = \escapeshellarg((string)\PHP_BINARY) . ' ' . \escapeshellarg($tempFile) . ' 2>&1';
        $exitCode = 0;
        \ob_start();
        \passthru($cmd, $exitCode);
        $joined = (string)\ob_get_clean();

        @\unlink($tempFile);
        if ($joined !== '') {
            \fwrite(\STDOUT, "\n[runRegenerateBlockOperation dd output]\n" . $joined . "\n[/runRegenerateBlockOperation dd output]\n");
        }
        self::assertStringNotContainsString('Failed opening required', $joined, 'Sub-process bootstrap failed unexpectedly.');

        self::assertStringNotContainsString(
            'AFTER_CALL',
            $joined,
            'runRegenerateBlockOperation() should not run past the current debug dd($session).'
        );
        self::assertTrue(
            \str_contains($joined, '调试输出 (dd函数)')
            || \str_contains($joined, '程序已终止')
            || \str_contains($joined, 'AiSiteAgentSession'),
            'Expected dd() debug output signature was not found; method may not have reached dd($session).'
        );
        self::assertNotSame(
            0,
            $exitCode,
            'Expected non-zero exit when hitting dd()/debug stop in runRegenerateBlockOperation().'
        );
    }
}

