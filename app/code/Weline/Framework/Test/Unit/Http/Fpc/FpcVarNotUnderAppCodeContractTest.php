<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http\Fpc;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Fpc\FpcPayloadPathIndex;
use Weline\Framework\Test\ProjectRoot;

/**
 * FPC disk pools must live under {@code {BP}var/cache/...}, never {@code app/code/var}.
 */
final class FpcVarNotUnderAppCodeContractTest extends TestCase
{
    public function testPayloadPathIndexUsesRepoVarNotAppCodeVar(): void
    {
        ProjectRoot::defineBp(__DIR__);
        self::assertFalse(
            ProjectRoot::isAccidentalAppCodeVar((string)BP),
            'BP itself must not be app/code'
        );

        $url = 'https://contract-fpc-var.test/probe-' . \uniqid('', true);
        $payloadDir = BP . 'var' . \DIRECTORY_SEPARATOR . 'cache' . \DIRECTORY_SEPARATOR
            . 'router-fpc-payloads' . \DIRECTORY_SEPARATOR . 'cv';
        if (!\is_dir($payloadDir)) {
            \mkdir($payloadDir, 0775, true);
        }
        self::assertFalse(
            ProjectRoot::isAccidentalAppCodeVar($payloadDir),
            'router-fpc-payloads must not be under app/code/var'
        );

        $body = $payloadDir . \DIRECTORY_SEPARATOR . 'contract-body.html';
        \file_put_contents($body, '<html>ok</html>');
        FpcPayloadPathIndex::remember($url, 'k-contract-' . \uniqid('', true), $body);
        $refs = FpcPayloadPathIndex::lookup($url);
        self::assertNotEmpty($refs);
        $bodyFile = (string)($refs[\count($refs) - 1]['body_file'] ?? '');
        self::assertFalse(
            ProjectRoot::isAccidentalAppCodeVar($bodyFile),
            'indexed body_file must not be under app/code/var'
        );

        FpcPayloadPathIndex::purgePaths([$url]);
        @\unlink($body);
    }
}
