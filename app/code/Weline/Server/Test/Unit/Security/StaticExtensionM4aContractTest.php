<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Security;

use PHPUnit\Framework\TestCase;

/**
 * m4a must be treated as a first-class static audio asset (WLS allowlist + scan fan-out).
 */
class StaticExtensionM4aContractTest extends TestCase
{
    public function testWorkerStaticAllowlistIncludesM4aAudioMime(): void
    {
        $worker = (string)\file_get_contents(BP . 'app/code/Weline/Server/bin/worker.php');
        $workerSsl = (string)\file_get_contents(BP . 'app/code/Weline/Server/bin/worker_ssl.php');
        $policy = (string)\file_get_contents(BP . 'app/code/Weline/Server/Security/WorkerPolicyKernel.php');

        self::assertMatchesRegularExpression("/'mp3',\s*'m4a'/", $worker);
        self::assertMatchesRegularExpression("/'mp3',\s*'m4a'/", $workerSsl);
        self::assertStringContainsString("'m4a' => 'audio/mp4'", $worker);
        self::assertStringContainsString("'m4a' => 'audio/mp4'", $workerSsl);
        self::assertStringContainsString("'m4a' => true", $policy);
    }
}
