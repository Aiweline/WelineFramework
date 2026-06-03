<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSitePageBlueprintService;
use GuoLaiRen\PageBuilder\Service\AiSitePageComponentGenerationService;
use PHPUnit\Framework\TestCase;

final class AiSitePageComponentGenerationServicePromptGuardTest extends TestCase
{
    public function testVisibleCopyRulesAddIdentifierAndSpacingGuards(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $prompt = (function (): string {
            return $this->buildVisibleCopyGovernancePromptAddon(
                ['content_locale' => 'en_US'],
                ['default_locale' => 'en_US', 'content_locale' => 'en_US']
            );
        })->call($service);

        self::assertStringContainsString('Visitor-visible copy must use content_locale/default_locale', $prompt);
        self::assertStringContainsString('Metadata rewrite example', $prompt);
        self::assertStringContainsString('CTX_BLOCK_GOAL', $prompt);
        self::assertStringContainsString('GOOD editable default', $prompt);
        self::assertStringContainsString('home_page, about_page, contact_page', $prompt);
        self::assertStringContainsString('treat it as leaked metadata and rewrite it', $prompt);
        self::assertStringContainsString('Number-label spacing audit', $prompt);
        self::assertStringContainsString('10 млн', $prompt);
        self::assertStringContainsString('4.8 звезды', $prompt);
        self::assertStringContainsString('10млн', $prompt);
    }

    public function testComponentGenerationFailsFastWithoutAiRepairLoop(): void
    {
        $source = (string)\file_get_contents(
            __DIR__ . '/../../../Service/AiSitePageComponentGenerationService.php'
        );

        self::assertStringNotContainsString('JSON_REPAIR_MAX_ATTEMPTS', $source);
        self::assertStringNotContainsString('buildRetryGenerationPrompt', $source);
        self::assertStringNotContainsString('requestJsonRepair', $source);
        self::assertStringNotContainsString('emitComponentRetryNotice', $source);
    }

    public function testResponsivePromptGuardsLongMobileTextInsteadOfClippingIt(): void
    {
        $source = (string)\file_get_contents(
            __DIR__ . '/../../../Service/AiSitePageComponentGenerationService.php'
        );

        self::assertStringContainsString('brand/logo text, headings, paragraphs, labels, nav items, badges/chips', $source);
        self::assertStringContainsString('overflow-wrap:anywhere', $source);
        self::assertStringContainsString('Do not use white-space:nowrap on brand/nav/badges/buttons/headings at <=420px', $source);
        self::assertStringContainsString('never to hide clipped copy or controls', $source);
    }

    public function testMediaRhythmPromptGuidesImagesWithoutContentGate(): void
    {
        $source = (string)\file_get_contents(
            __DIR__ . '/../../../Service/AiSitePageComponentGenerationService.php'
        );

        self::assertStringContainsString('Page media rhythm rule', $source);
        self::assertStringContainsString('all text/stat/card-only sections', $source);
        self::assertStringContainsString('substantial CSS media/supporting visual surface', $source);
        self::assertStringContainsString('Policy pages may remain dense legal text', $source);
        self::assertStringContainsString('This is design guidance only, not a validation gate', $source);
    }

    public function testStructuralGateAllowsVisibleCopySpacingIssues(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $reason = (function (): ?string {
            return $this->detectStructuralGeneratedSectionHtmlReason(
                "<section><h2>24Hours support</h2><p>Get 3Steps from quote to install with 7Day follow-up.</p></section>"
            );
        })->call($service);

        self::assertNull($reason);
    }

    public function testStructuralGateRejectsMismatchedHtmlTags(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $reason = (function (): ?string {
            return $this->detectStructuralGeneratedSectionHtmlReason(
                "<section><div class='pb-c-card'><h2>Player voices</h2></section>"
            );
        })->call($service);

        self::assertIsString($reason);
        self::assertStringContainsString('mismatched HTML closing tag', $reason);
    }

    public function testStructuralGateDoesNotRejectPromptLikeVisibleCopy(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $reason = (function (): ?string {
            return $this->detectStructuralGeneratedSectionHtmlReason(
                "<section><div><h2>Showcase testimonials</h2><p>Introduce trust proof and explain primary actions for users.</p></div></section>"
            );
        })->call($service);

        self::assertNull($reason);
    }
}
