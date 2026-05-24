<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteBlockMorphologyRegistry;
use PHPUnit\Framework\TestCase;

final class AiSiteBlockMorphologyRegistryTest extends TestCase
{
    public function testAllReturnsExecutableMorphologyDefinitions(): void
    {
        $items = (new AiSiteBlockMorphologyRegistry())->all();

        self::assertGreaterThanOrEqual(15, \count($items));
        foreach ($items as $id => $definition) {
            self::assertSame($id, $definition['id'] ?? null);
            self::assertNotEmpty($definition['roles'] ?? []);
            self::assertArrayHasKey('supports_image', $definition);
            self::assertNotEmpty($definition['acceptance_checks'] ?? []);
            self::assertNotEmpty($definition['required_html_signals'] ?? []);
            self::assertNotEmpty($definition['css_signals'] ?? []);
        }
    }

    public function testSelectCandidatesReturnsProofMorphologies(): void
    {
        $candidates = (new AiSiteBlockMorphologyRegistry())->selectCandidates('home_page', 'proof', []);
        $ids = \array_column($candidates, 'id');

        self::assertContains('metric_proof_strip', $ids);
        self::assertContains('quote_rail', $ids);
        self::assertContains('logo_partner_wall', $ids);
        foreach ($candidates as $candidate) {
            self::assertContains('proof', $candidate['roles'] ?? []);
        }
    }

    public function testImageRequiredCandidatesSupportImages(): void
    {
        $candidates = (new AiSiteBlockMorphologyRegistry())->selectCandidates('services_page', 'details', [
            'needs_image' => true,
        ]);

        self::assertNotEmpty($candidates);
        foreach ($candidates as $candidate) {
            self::assertTrue((bool)($candidate['supports_image'] ?? false), (string)($candidate['id'] ?? ''));
        }
    }
}
