<?php

declare(strict_types=1);

namespace Weline\Review\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Review\Service\ReviewAiModerationService;

final class ReviewAiModerationServiceTest extends TestCase
{
    private function service(): ReviewAiModerationService
    {
        $ref = new \ReflectionClass(ReviewAiModerationService::class);

        return $ref->newInstanceWithoutConstructor();
    }

    public function testParseDecisionAcceptsApproveRejectAndUncertain(): void
    {
        $svc = $this->service();

        $approve = $svc->parseDecision('{"decision":"approve","reason":"内容正常"}');
        self::assertSame(ReviewAiModerationService::DECISION_APPROVE, $approve['decision']);
        self::assertSame('内容正常', $approve['reason']);

        $reject = $svc->parseDecision("说明如下\n```json\n{\"decision\":\"reject\",\"reason\":\"广告灌水\"}\n```");
        self::assertSame(ReviewAiModerationService::DECISION_REJECT, $reject['decision']);
        self::assertSame('广告灌水', $reject['reason']);

        $uncertain = $svc->parseDecision('{"decision":"uncertain","reason":"语义暧昧"}');
        self::assertSame(ReviewAiModerationService::DECISION_UNCERTAIN, $uncertain['decision']);
    }

    public function testParseDecisionFallsBackToUncertainWhenInvalid(): void
    {
        $svc = $this->service();
        $parsed = $svc->parseDecision('不是 JSON');
        self::assertSame(ReviewAiModerationService::DECISION_UNCERTAIN, $parsed['decision']);
        self::assertNotSame('', $parsed['reason']);
    }
}
