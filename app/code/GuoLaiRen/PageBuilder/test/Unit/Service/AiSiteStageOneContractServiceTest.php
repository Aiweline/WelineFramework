<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteStageOneContractService;
use PHPUnit\Framework\TestCase;

final class AiSiteStageOneContractServiceTest extends TestCase
{
    public function testCardGameStyleDoesNotInjectDownloadBlocksWhenCurrentBriefForbidsGaming(): void
    {
        $brief = 'Build a polished AI workflow automation SaaS website for operations teams. The service helps teams design approval flows, automate task handoffs, monitor status, and book a product demo. Avoid gaming, casino, APK, reward, card, neon, and gambling visual language.';
        $contract = (new AiSiteStageOneContractService())->build([
            'design_direction_code' => 'india-card-game-apk-dark-neon',
            'brief_description' => $brief,
            'user_description' => $brief,
            'source_truth_contract' => [
                'required_home_blocks' => ['hero'],
                'must_not_do' => [
                    'Do not use excluded user term as visible site category, CTA, copy, or visual style: APK',
                    'Do not use excluded user term as visible site category, CTA, copy, or visual style: casino',
                    'Do not use excluded user term as visible site category, CTA, copy, or visual style: card',
                    'Do not use excluded user term as visible site category, CTA, copy, or visual style: gambling',
                ],
            ],
        ], ['home_page'], 'en_US', 'en_US');

        $required = $contract['page_contracts']['home_page']['required_block_keys'] ?? [];

        self::assertSame(['hero', 'final_cta'], $required);
    }

    public function testCardGameStyleStillAppliesWhenCurrentBriefHasPositiveDownloadIntent(): void
    {
        $contract = (new AiSiteStageOneContractService())->build([
            'design_direction_code' => 'india-card-game-apk-dark-neon',
            'brief_description' => 'Create a Teen Patti APK download landing page for Indian card game players.',
        ], ['home_page'], 'en_US', 'en_US');

        $required = $contract['page_contracts']['home_page']['required_block_keys'] ?? [];

        self::assertContains('hero_download', $required);
        self::assertContains('game_showcase_or_features', $required);
        self::assertContains('player_reviews', $required);
        self::assertContains('faq_or_rules', $required);
    }

    public function testCardGameStyleDoesNotInjectDownloadBlocksWithoutDownloadIntent(): void
    {
        $contract = (new AiSiteStageOneContractService())->build([
            'design_direction_code' => 'india-card-game-apk-dark-neon',
            'brief_description' => 'Create a neon card game room website with game rooms, player proof, rules, support, and a polished play CTA.',
        ], ['home_page'], 'zh_Hans_CN', 'zh_Hans_CN');

        $required = $contract['page_contracts']['home_page']['required_block_keys'] ?? [];

        self::assertContains('hero', $required);
        self::assertContains('game_showcase_or_features', $required);
        self::assertContains('trust_security', $required);
        self::assertContains('player_reviews', $required);
        self::assertContains('faq_or_rules', $required);
        self::assertContains('final_cta', $required);
        self::assertNotContains('hero_download', $required);
        self::assertNotContains('final_download_cta', $required);
    }
}
