<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service\AI\Contract;

use GuoLaiRen\PageBuilder\Service\AI\Contract\BuildPlanContentManifestLinter;
use PHPUnit\Framework\TestCase;

final class BuildPlanContentManifestLinterTest extends TestCase
{
    public function testContentManifestCoversPageAndBlockKeys(): void
    {
        $result = (new BuildPlanContentManifestLinter())->validate($this->contract([
            'home.title' => 'AI tool platform',
            'home.desc' => 'A product website for AI teams.',
            'home.hero.title' => 'Build faster with focused AI workflows',
            'home.hero.cta' => 'Start building',
        ]));

        self::assertTrue($result['valid'], \implode("\n", $result['errors']));
    }

    public function testRejectsMissingContentKeysAndPlaceholderCopy(): void
    {
        $result = (new BuildPlanContentManifestLinter())->validate($this->contract([
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
        $contract['blocks'][0]['content_keys'][] = 'pricing.cta';

        $result = (new BuildPlanContentManifestLinter())->validate($contract);

        self::assertFalse($result['valid']);
        self::assertTrue($this->hasErrorContaining($result['errors'], 'CTA copy'));
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
                    'blocks' => ['home.hero'],
                ],
            ],
            'blocks' => [
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
