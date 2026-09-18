<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;

/**
 * Binary API guards must not tear down process-global ob under WLS.
 */
final class BinaryOutputGuardContractTest extends TestCase
{
    public function testGuardSourceUsesFiberCaptureAndNoPersistentStackDrain(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Http/BinaryOutputGuard.php'
        );

        self::assertStringContainsString('FiberOutputBuffer::beginCapture()', $source);
        self::assertStringContainsString('FiberOutputBuffer::endCapture()', $source);
        self::assertStringContainsString('Runtime::isPersistent()', $source);
        // Persistent branch: beginCapture then return — no while(ob_get_level) inside that arm.
        self::assertMatchesRegularExpression(
            '/if\s*\(\s*Runtime::isPersistent\(\)\s*\)\s*\{\s*'
            . 'FiberOutputBuffer::beginCapture\(\);\s*'
            . 'return\s*\[/',
            $source
        );
        $persistentArm = '';
        if (\preg_match(
            '/if\s*\(\s*Runtime::isPersistent\(\)\s*\)\s*\{([\s\S]*?)\n\s*\}/',
            $source,
            $m
        )) {
            $persistentArm = $m[1];
        }
        self::assertNotSame('', $persistentArm);
        self::assertStringNotContainsString('ob_get_level', $persistentArm);
        self::assertStringNotContainsString('ini_set', $persistentArm);
    }

    public function testBinQueryAndQueryBinDelegateToBinaryOutputGuard(): void
    {
        $binQuery = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Controller/Api/BinQuery.php'
        );
        $queryBin = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Controller/Api/QueryBin.php'
        );

        self::assertStringContainsString('BinaryOutputGuard::begin(', $binQuery);
        self::assertStringContainsString('BinaryOutputGuard::end(', $binQuery);
        self::assertStringNotContainsString('while (\ob_get_level() > 0)', $binQuery);

        self::assertStringContainsString('BinaryOutputGuard::begin(', $queryBin);
        self::assertStringContainsString('BinaryOutputGuard::end(', $queryBin);
        self::assertStringNotContainsString('while (\ob_get_level() > 0)', $queryBin);
    }

    public function testPcControllerFetchJsonPersistentSkipsObClean(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Controller/PcController.php'
        );
        $pos = \strpos($source, 'function fetchJson');
        self::assertNotFalse($pos);
        $chunk = \substr($source, (int)$pos, 900);
        self::assertStringContainsString('Runtime::isPersistent()', $chunk);
        self::assertStringContainsString('FiberOutputBuffer::resetCurrent()', $chunk);
        self::assertMatchesRegularExpression(
            '/isPersistent\(\)\s*\)\s*\{[\s\S]*?resetCurrent\(\)/',
            $chunk
        );
        self::assertDoesNotMatchRegularExpression(
            '/isPersistent\(\)\s*\)\s*\{[\s\S]{0,250}?\\\\ob_clean\(\)/',
            $chunk
        );
    }
}
