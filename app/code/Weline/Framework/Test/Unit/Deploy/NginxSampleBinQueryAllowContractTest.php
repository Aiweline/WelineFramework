<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Deploy;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Test\ProjectRoot;

/**
 * PHP-FPM / 宝塔样例 nginx.conf 不得把站外 BinQuery `/bin/query` 与 CLI `bin/` 一并 404。
 *
 * Exact `location = /bin/query` must win over `location ~ ^/bin/ { return 404; }`.
 */
final class NginxSampleBinQueryAllowContractTest extends TestCase
{
    public function testSampleNginxConfAllowsBinQueryWhileDenyingCliBinTree(): void
    {
        ProjectRoot::defineBp(__DIR__);
        $conf = BP . DIRECTORY_SEPARATOR . 'nginx.conf';
        self::assertFileExists($conf);
        $src = \file_get_contents($conf);
        self::assertIsString($src);

        self::assertMatchesRegularExpression(
            '/location\s*=\s*\/bin\/query\s*\{/',
            $src,
            'Sample nginx must exact-match /bin/query into the front controller.',
        );
        self::assertStringContainsString('rewrite ^/(.*)$ /index.php?s=$1 last;', $src);
        self::assertMatchesRegularExpression(
            '/location\s*~\s*\^\/bin\/\s*\{\s*return\s+404;/',
            $src,
            'CLI bin/ tree must remain web-denied.',
        );

        $exactPos = \strpos($src, 'location = /bin/query');
        $denyPos = \strpos($src, 'location ~ ^/bin/');
        self::assertIsInt($exactPos);
        self::assertIsInt($denyPos);
        self::assertLessThan(
            $denyPos,
            $exactPos,
            'Exact /bin/query must appear before the regex deny (readable order; = still wins).',
        );
    }
}
