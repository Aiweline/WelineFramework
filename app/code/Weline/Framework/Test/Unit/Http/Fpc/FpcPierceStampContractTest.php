<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http\Fpc;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Fpc\FpcBypassEvaluator;
use Weline\Framework\Http\Fpc\FpcBypassFactsBuilder;
use Weline\Framework\Http\Fpc\FpcPierceStamp;

final class FpcPierceStampContractTest extends TestCase
{
    private string $secretPath = '';

    protected function setUp(): void
    {
        if (!\defined('BP')) {
            \define('BP', \dirname(__DIR__, 6) . \DIRECTORY_SEPARATOR);
        }
        FpcPierceStamp::clearMemo();
        FpcBypassEvaluator::clearCache();
        $this->secretPath = FpcPierceStamp::secretFilePath();
        if (\is_file($this->secretPath)) {
            @\rename($this->secretPath, $this->secretPath . '.bak-pierce-test');
        }
    }

    protected function tearDown(): void
    {
        FpcPierceStamp::clearMemo();
        FpcBypassEvaluator::clearCache();
        if (\is_file($this->secretPath)) {
            @\unlink($this->secretPath);
        }
        $bak = $this->secretPath . '.bak-pierce-test';
        if (\is_file($bak)) {
            @\rename($bak, $this->secretPath);
        }
    }

    public function testEnsureAndValidateCookieOrQuery(): void
    {
        $secret = FpcPierceStamp::ensureSecret();
        self::assertNotSame('', $secret);
        self::assertTrue(FpcPierceStamp::isValidRequest(
            FpcPierceStamp::COOKIE_NAME . '=' . $secret,
            [],
        ));
        self::assertTrue(FpcPierceStamp::isValidRequest(
            null,
            [FpcPierceStamp::QUERY_NAME => $secret],
        ));
        self::assertFalse(FpcPierceStamp::isValidRequest(null, ['_wb_nc' => '1']));
        self::assertFalse(FpcPierceStamp::isValidRequest(
            FpcPierceStamp::COOKIE_NAME . '=deadbeef',
            [],
        ));
    }

    public function testDiagQueryBypassRequiresPierceStamp(): void
    {
        $secret = FpcPierceStamp::ensureSecret();
        $without = FpcBypassFactsBuilder::build('/products?nocache=1', ['nocache' => '1'], '');
        self::assertFalse((bool)($without['pierce_stamp_valid'] ?? false));
        self::assertFalse(FpcBypassEvaluator::shouldBypass($without, FpcBypassEvaluator::builtinFallbackRules()));

        $with = FpcBypassFactsBuilder::build(
            '/products?nocache=1&' . FpcPierceStamp::QUERY_NAME . '=' . $secret,
            ['nocache' => '1', FpcPierceStamp::QUERY_NAME => $secret],
            '',
        );
        self::assertTrue((bool)($with['pierce_stamp_valid'] ?? false));
        self::assertTrue(FpcBypassEvaluator::shouldBypass($with, FpcBypassEvaluator::builtinFallbackRules()));
    }

    public function testPreviewPathStillBypassesWithoutPierceStamp(): void
    {
        $facts = FpcBypassFactsBuilder::build('/~preview/abc123/en_US/', [], '');
        self::assertFalse((bool)($facts['pierce_stamp_valid'] ?? false));
        self::assertTrue(FpcBypassEvaluator::shouldBypass($facts, FpcBypassEvaluator::builtinFallbackRules()));
    }
}
