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

    public function testLowQualityDetectionRejectsNumberWordGlue(): void
    {
        $service = new AiSitePageComponentGenerationService(
            pageBlueprintService: new AiSitePageBlueprintService(),
        );

        $reason = (function (): ?string {
            return $this->detectLowQualityGeneratedSectionHtmlReason(
                "<section><h2>24Hours support</h2><p>Get 3Steps from quote to install with 7Day follow-up.</p></section>"
            );
        })->call($service);

        self::assertSame('missing whitespace between number and visible label', $reason);
    }
}
