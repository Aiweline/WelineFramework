<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service\AI\DesignDirection;

use GuoLaiRen\PageBuilder\Service\AI\DesignDirection\DesignDirectionService;
use PHPUnit\Framework\TestCase;

final class DesignDirectionServiceTest extends TestCase
{
    public function testCardGameDirectionMatchesIndianCardApkBrief(): void
    {
        $service = new DesignDirectionService(null);

        $match = $service->matchDirection(
            'BharatPlay 印度棋牌官网',
            '下载印度棋牌 app，Teen Patti APK 奖金活动',
            1
        );

        self::assertTrue($match['matched']);
        self::assertSame(
            DesignDirectionService::BUILTIN_CARD_GAME_CODE,
            $match['item']['code'] ?? null
        );
        self::assertContains('Teen Patti', $match['matched_keywords']);
    }

    public function testGenericBusinessBriefDoesNotInheritCardGameDirection(): void
    {
        $service = new DesignDirectionService(null);

        $match = $service->matchDirection(
            '高端律师事务所',
            '展示专业服务、律师团队、案例成果和预约咨询入口',
            1
        );

        self::assertFalse($match['matched']);
        self::assertNull($match['item']);
    }

    public function testLockedScopeFreezesDirectionSnapshotForLaterStages(): void
    {
        $service = new DesignDirectionService(null);

        $patch = $service->resolveSelectionForScope([
            'site_title' => 'BharatPlay 印度棋牌官网',
            'brief_description' => '印度棋牌 APK 下载，Teen Patti、Rummy、Andar Bahar 奖金活动',
            'design_direction_mode' => DesignDirectionService::MODE_AUTO,
        ], 1, true);

        self::assertSame(1, $patch['design_direction_locked']);
        self::assertSame(DesignDirectionService::BUILTIN_CARD_GAME_CODE, $patch['design_direction_code']);
        self::assertSame(1, $patch['design_direction_version']);
        self::assertNotEmpty($patch['design_direction_hash']);
        self::assertSame(
            DesignDirectionService::BUILTIN_CARD_GAME_CODE,
            $patch['design_direction_snapshot']['code'] ?? null
        );
    }
}
