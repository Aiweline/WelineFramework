<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSitePlanJsonProjectionService;
use PHPUnit\Framework\TestCase;

final class AiSitePlanJsonProjectionServiceTest extends TestCase
{
    public function testBuildsReadOnlyProjectionFromPlanJsonContract(): void
    {
        $contract = [
            'contract_meta' => ['id' => 'plan-json-contract-1', 'version' => '2.2', 'status' => 'confirmed'],
            'site_brief' => [
                'site_name' => 'Example Site',
                'primary_goal' => 'Explain the service clearly.',
            ],
            'policy_ref' => ['policy_id' => 'premium_web_v1'],
            'content_manifest' => [
                'items' => [
                    'page.home.title' => ['text' => 'Home'],
                    'page.home.description' => ['text' => 'Explain the service clearly.'],
                    'block.hero.title' => ['text' => 'Explain the service clearly'],
                ],
            ],
            'pages' => [
                'home_page' => [
                    'page_id' => 'home_page',
                    'page_type' => 'home_page',
                    'title_key' => 'page.home.title',
                    'description_key' => 'page.home.description',
                    'hero' => [
                    'block_id' => 'home_page.hero',
                    'block_type' => 'hero',
                    'content_keys' => ['block.hero.title'],
                    'task_ids' => [],
                ],
                ],
            ],
            'design_manifest' => [],
            'policy_projection' => [],
        ];

        $projection = (new AiSitePlanJsonProjectionService())->build($contract);

        self::assertSame((string)$contract['contract_meta']['id'], $projection['source_contract_id']);
        self::assertSame('2.2', $projection['source_contract_version']);
        self::assertSame(true, $projection['never_feed_to_build']);
        self::assertSame('Example Site', $projection['site_name']);
        self::assertSame(1, $projection['page_count']);
        self::assertSame(1, $projection['block_count']);
        self::assertSame(0, $projection['task_count']);
        self::assertSame('premium_web_v1', $projection['design']['policy_id']);
        $titleKey = (string)$contract['pages']['home_page']['title_key'];
        $expectedTitle = $contract['content_manifest']['items'][$titleKey];
        if (\is_array($expectedTitle)) {
            $expectedTitle = $expectedTitle['text'] ?? $expectedTitle['value'] ?? '';
        }
        self::assertSame((string)$expectedTitle, $projection['pages'][0]['title']);
        self::assertSame('hero', $projection['pages'][0]['blocks'][0]['type']);
    }
}
