<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Session;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SessionSecretLoggingContractTest extends TestCase
{
    /** @return iterable<string,array{0:string}> */
    public static function sessionRuntimeSources(): iterable
    {
        $frameworkRoot = dirname(__DIR__, 3);

        yield 'session facade' => [$frameworkRoot . '/Session/Session.php'];
        yield 'file storage' => [$frameworkRoot . '/Session/Storage/FileStorage.php'];
        yield 'wls shared storage' => [$frameworkRoot . '/Session/Storage/WlsSharedStorage.php'];
        yield 'http request command' => [$frameworkRoot . '/Http/Console/Http/Request.php'];
        yield 'backend redirect observer' => [$frameworkRoot . '/../Backend/Observer/ResponseRedirectBefore.php'];
        yield 'acl route observer' => [$frameworkRoot . '/../Acl/Observer/RouteBefore.php'];
    }

    #[DataProvider('sessionRuntimeSources')]
    public function testSessionSecretsAreNeverIncludedInLogs(string $path): void
    {
        $source = (string)file_get_contents($path);

        self::assertStringNotContainsString("'sid='", $source);
        self::assertStringNotContainsString("'sessionId='", $source);
        self::assertDoesNotMatchRegularExpression(
            '/(?:w_log_|sessionLog\()[^;]*substr\s*\(\s*\$sessionId/s',
            $source,
        );
        self::assertDoesNotMatchRegularExpression(
            '/(?:w_log_|sessionLog\()[^;]*substr\s*\(\s*\$this->sessionId/s',
            $source,
        );
    }
}
