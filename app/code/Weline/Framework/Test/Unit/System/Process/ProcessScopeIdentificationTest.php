<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\System\Process;

use PHPUnit\Framework\TestCase;
use Weline\Framework\System\Process\Processer;

/**
 * @group server-cross-project-isolation
 */
final class ProcessScopeIdentificationTest extends TestCase
{
    public function testExtractsProjectScopeTokenFromScopedProcessName(): void
    {
        self::assertSame(
            'p16330cac',
            Processer::extractProjectScopeFromProcessName('weline-wls-dispatcher-default-p16330cac')
        );
    }

    public function testExtractsProjectScopeTokenFromWorkerWithSlot(): void
    {
        self::assertSame(
            'p16330cac',
            Processer::extractProjectScopeFromProcessName('weline-wls-worker-default-p16330cac-3')
        );
    }

    public function testExtractsProjectScopeTokenFromHttpRedirect(): void
    {
        self::assertSame(
            'pabcdef12',
            Processer::extractProjectScopeFromProcessName('weline-wls-redirect-default-pabcdef12')
        );
    }

    public function testExtractsProjectScopeTokenFromCommandLineFlag(): void
    {
        self::assertSame(
            'p16330cac',
            Processer::extractProjectScopeFromProcessName('--name=weline-wls-dispatcher-default-p16330cac')
        );
    }

    public function testExtractsProjectScopeTokenFromFullCommandLine(): void
    {
        $cmd = '/usr/bin/php /srv/site/app/code/Weline/Server/bin/dispatcher.php '
            . '--name=weline-wls-dispatcher-default-p16330cac --port=9981';

        self::assertSame('p16330cac', Processer::extractProjectScopeFromProcessName($cmd));
    }

    public function testReturnsEmptyForLegacyUnscopedProcessName(): void
    {
        self::assertSame(
            '',
            Processer::extractProjectScopeFromProcessName('weline-master-default-worker-1')
        );
    }

    public function testReturnsEmptyForNonWelineCommand(): void
    {
        self::assertSame(
            '',
            Processer::extractProjectScopeFromProcessName('php worker.php')
        );
    }

    public function testReturnsEmptyForBlankInput(): void
    {
        self::assertSame('', Processer::extractProjectScopeFromProcessName(''));
    }

    public function testIgnoresUppercaseScopeMimic(): void
    {
        self::assertSame(
            '',
            Processer::extractProjectScopeFromProcessName('weline-wls-worker-default-PABCDEF12-1')
        );
    }

    public function testRequiresExactlyEightHexAfterPPrefix(): void
    {
        self::assertSame(
            '',
            Processer::extractProjectScopeFromProcessName('weline-wls-worker-default-pabc')
        );
        self::assertSame(
            '',
            Processer::extractProjectScopeFromProcessName('weline-wls-worker-default-pabcdef123')
        );
    }
}
