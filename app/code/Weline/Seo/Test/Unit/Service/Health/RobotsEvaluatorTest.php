<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service\Health;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Service\Health\RobotsEvaluator;

final class RobotsEvaluatorTest extends TestCase
{
    private RobotsEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new RobotsEvaluator();
    }

    public function testDisallowBlocksGooglebot(): void
    {
        $robots = "User-agent: *\nDisallow: /private/\n";

        $result = $this->evaluator->evaluate($robots, 'https://example.com/private/page');

        self::assertSame('blocked', $result['state']);
        self::assertSame('/private/', $result['rule']);
        self::assertSame(2, $result['line']);
    }

    public function testLongestMatchWins(): void
    {
        $robots = "User-agent: *\nDisallow: /private/\nAllow: /private/public/\n";

        self::assertSame(
            'allowed',
            $this->evaluator->evaluate($robots, 'https://example.com/private/public/page')['state']
        );
        self::assertSame(
            'blocked',
            $this->evaluator->evaluate($robots, 'https://example.com/private/secret')['state']
        );
    }

    public function testTieFavorsAllow(): void
    {
        $robots = "User-agent: *\nDisallow: /same\nAllow: /same\n";

        self::assertSame('allowed', $this->evaluator->evaluate($robots, 'https://example.com/same')['state']);
    }

    public function testWildcardAndDollarAnchors(): void
    {
        $robots = "User-agent: *\nDisallow: /*.pdf$\n";

        self::assertSame('blocked', $this->evaluator->evaluate($robots, 'https://example.com/file.pdf')['state']);
        self::assertSame('allowed', $this->evaluator->evaluate($robots, 'https://example.com/file.pdf?x=1')['state']);
    }

    public function testEmptyDisallowMeansAllowAll(): void
    {
        $robots = "User-agent: *\nDisallow:\n";

        self::assertSame('allowed', $this->evaluator->evaluate($robots, 'https://example.com/anything')['state']);
    }

    public function testSpecificUserAgentGroup(): void
    {
        $robots = "User-agent: googlebot\nDisallow: /only-google\n\nUser-agent: *\nDisallow: /\n";

        self::assertSame('blocked', $this->evaluator->evaluate($robots, 'https://example.com/only-google')['state']);
        self::assertSame('allowed', $this->evaluator->evaluate($robots, 'https://example.com/other')['state']);
    }

    public function testNoMatchingRuleMeansAllowed(): void
    {
        $robots = "User-agent: bingbot\nDisallow: /\n";

        self::assertSame('allowed', $this->evaluator->evaluate($robots, 'https://example.com/page')['state']);
    }

    public function testEmptyRobotsMeansAllowed(): void
    {
        self::assertSame('allowed', $this->evaluator->evaluate('', 'https://example.com/page')['state']);
    }
}
