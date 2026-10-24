<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Security;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Fpc\FpcPierceStamp;
use Weline\Server\Security\WorkerPolicyKernel;

final class PierceStampUnbanPolicyTest extends TestCase
{
    private function secret(): string
    {
        $secret = FpcPierceStamp::readSecret();
        if ($secret === '') {
            self::markTestSkipped('pierce secret unavailable');
        }
        return $secret;
    }

    public function testHasValidStampAcceptsQueryStamp(): void
    {
        self::assertTrue(FpcPierceStamp::hasValidStamp([
            FpcPierceStamp::QUERY_NAME => $this->secret(),
        ], ''));
    }

    public function testHasValidStampAcceptsCookieStampAndWebsiteSuffix(): void
    {
        $secret = $this->secret();
        self::assertTrue(FpcPierceStamp::hasValidStamp([], FpcPierceStamp::COOKIE_NAME . '=' . $secret));
        self::assertTrue(FpcPierceStamp::hasValidStamp([], 'a=1; ' . FpcPierceStamp::COOKIE_NAME . '_w0=' . $secret));
    }

    public function testHasValidStampFailsClosed(): void
    {
        $secret = $this->secret();
        self::assertFalse(FpcPierceStamp::hasValidStamp([FpcPierceStamp::QUERY_NAME => ''], ''));
        self::assertFalse(FpcPierceStamp::hasValidStamp([FpcPierceStamp::QUERY_NAME => ['array']], ''));
        self::assertFalse(FpcPierceStamp::hasValidStamp([FpcPierceStamp::QUERY_NAME => $secret . 'x'], ''));
        self::assertFalse(FpcPierceStamp::hasValidStamp([], ''));
    }

    public function testKernelSkipsSharedBanWhenPiercePasses(): void
    {
        $src = $this->kernelSource();
        self::assertStringContainsString('$piercePass', $src);
        self::assertTrue(\strpos($src, '$piercePass') < \strpos($src, "'shared_ban'"));
    }

    public function testKernelUnbansOnPiercePass(): void
    {
        $src = $this->kernelSource();
        self::assertMatchesRegularExpression('/\$piercePass[\s\S]{0,200}clearSecurityBans/', $src);
    }

    public function testKernelSkipsAttackRulesOnPiercePass(): void
    {
        $src = $this->kernelSource();
        self::assertStringContainsString('if ($piercePass || ($whitelisted', $src);
        self::assertStringContainsString('if (!$probeMode && !$piercePass)', $src);
    }

    private function kernelSource(): string
    {
        return (string) \file_get_contents(BP . '/app/code/Weline/Server/Security/WorkerPolicyKernel.php');
    }
}
