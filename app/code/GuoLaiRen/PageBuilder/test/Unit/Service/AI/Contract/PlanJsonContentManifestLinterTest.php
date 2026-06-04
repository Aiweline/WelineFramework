<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service\AI\Contract;

use GuoLaiRen\PageBuilder\Service\AI\Contract\PlanJsonContentManifestLinter;
use PHPUnit\Framework\TestCase;

final class PlanJsonContentManifestLinterTest extends TestCase
{
    public function testContentManifestCoversPageAndBlockKeys(): void
    {
        $result = (new PlanJsonContentManifestLinter())->validate($this->contract([
            'home.title' => 'AI tool platform',
            'home.desc' => 'A product website for AI teams.',
            'home.hero.title' => 'Build faster with focused AI workflows',
            'home.hero.cta' => 'Start building',
        ]));

        self::assertTrue($result['valid'], \implode("\n", $result['errors']));
    }

    public function testRejectsMissingContentKeysAndPlaceholderCopy(): void
    {
        $result = (new PlanJsonContentManifestLinter())->validate($this->contract([
            'home.title' => 'Lorem ipsum headline',
            'home.desc' => 'A product website for AI teams.',
            'home.hero.cta' => 'Start building',
        ]));

        self::assertFalse($result['valid']);
        self::assertTrue($this->hasErrorContaining($result['errors'], 'home.hero.title'));
        self::assertTrue($this->hasErrorContaining($result['errors'], 'placeholder copy'));
    }

    public function testRejectsAllGenericCtaCopy(): void
    {
        $contract = $this->contract([
            'home.title' => 'AI tool platform',
            'home.desc' => 'A product website for AI teams.',
            'home.hero.title' => 'Build faster with focused AI workflows',
            'home.hero.cta' => 'Learn more',
            'pricing.cta' => 'Learn more',
        ]);
        $contract['block_nodes'][0]['content_keys'][] = 'pricing.cta';

        $result = (new PlanJsonContentManifestLinter())->validate($contract);

        self::assertFalse($result['valid']);
        self::assertTrue($this->hasErrorContaining($result['errors'], 'CTA copy'));
    }

    public function testPolicyMetadataBrandTermsDoNotCountAsLocaleLeak(): void
    {
        $contract = $this->contract([
            'home.title' => '成都餐馆官网',
            'home.desc' => '展示招牌菜、预约入口和门店信息。',
            'home.hero.title' => '老成都川菜馆',
            'home.hero.cta' => '预订座位',
            'site.allowed_brand_terms' => 'Contract QA Restaurant',
            'site.forbidden_template_brand_terms' => 'Aster and Rye, Northstar Clinics',
        ]);
        $contract['i18n']['primary_locale'] = 'zh_Hans_CN';
        $contract['content_manifest']['primary_locale'] = 'zh_Hans_CN';

        $result = (new PlanJsonContentManifestLinter())->validate($contract);

        self::assertTrue($result['valid'], \implode("\n", $result['errors']));
    }

    public function testPortugueseTodosDoesNotCountAsTodoPlaceholder(): void
    {
        $contract = $this->contract([
            'home.title' => 'Teenipiya',
            'home.desc' => 'Um site para jogadores de Teen Patti no Brasil.',
            'home.hero.title' => 'Jogo justo para todos',
            'home.hero.copy' => 'Nossa missão é oferecer jogo justo, transparência e diversão para todos.',
            'home.hero.cta' => 'Baixar APK seguro',
        ]);
        $contract['i18n']['primary_locale'] = 'pt_BR';
        $contract['content_manifest']['primary_locale'] = 'pt_BR';
        $contract['block_nodes'][0]['content_keys'][] = 'home.hero.copy';

        $result = (new PlanJsonContentManifestLinter())->validate($contract);

        self::assertTrue($result['valid'], \implode("\n", $result['errors']));
    }

    public function testTodoMarkerStillCountsAsPlaceholder(): void
    {
        $result = (new PlanJsonContentManifestLinter())->validate($this->contract([
            'home.title' => 'Teenipiya',
            'home.desc' => 'Um site para jogadores de Teen Patti no Brasil.',
            'home.hero.title' => 'TODO: replace this headline',
            'home.hero.cta' => 'Baixar APK seguro',
        ]));

        self::assertFalse($result['valid']);
        self::assertTrue($this->hasErrorContaining($result['errors'], 'placeholder copy'));
    }

    /**
     * @param array<string, string> $items
     * @return array<string, mixed>
     */
    private function contract(array $items): array
    {
        return [
            'i18n' => ['primary_locale' => 'en_US'],
            'content_manifest' => [
                'primary_locale' => 'en_US',
                'items' => $items,
            ],
            'pages' => [
                [
                    'page_id' => 'home',
                    'title_key' => 'home.title',
                    'description_key' => 'home.desc',
                    'block_node_ids' => ['home.hero'],
                ],
            ],
            'block_nodes' => [
                [
                    'block_id' => 'home.hero',
                    'page_id' => 'home',
                    'content_keys' => ['home.hero.title', 'home.hero.cta'],
                ],
            ],
        ];
    }

    /**
     * @param list<string> $errors
     */
    private function hasErrorContaining(array $errors, string $needle): bool
    {
        foreach ($errors as $error) {
            if (\str_contains($error, $needle)) {
                return true;
            }
        }

        return false;
    }
}
