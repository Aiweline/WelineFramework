<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteExecutionBlueprintService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AiSiteExecutionBlueprintServiceCopyQualityTest extends TestCase
{
    public function testStageOneContractCopyDoesNotUsePlaceholderSiteNameFallbacks(): void
    {
        $source = (string)\file_get_contents((new ReflectionClass(AiSiteExecutionBlueprintService::class))->getFileName());
        $resolver = $this->extractMethodSource($source, 'resolveStageOneContractSiteDisplayName');
        $headline = $this->extractMethodSource($source, 'buildStageOneContractBlockHeadline');
        $neonSubject = $this->extractMethodSource($source, 'stageOneNeonCardImageSubject');

        self::assertStringContainsString('$siteStrategy[\'site_display_name\']', $resolver);
        self::assertStringContainsString('$sharedPromptContext[\'site_title\']', $resolver);
        self::assertStringContainsString('$brandPositioning[\'site_name\']', $resolver);
        self::assertStringContainsString('$manifestItems[\'site.name\']', $resolver);
        self::assertStringContainsString('isChineseLocale($contentLocale)', $resolver);
        self::assertStringNotContainsString("return 'the product';", $resolver);
        self::assertStringNotContainsString("'This site'", $resolver);
        self::assertStringNotContainsString(' opens ', $headline);
        self::assertStringNotContainsString('Take the next step with ', $headline);
        self::assertStringNotContainsString(' explains ', $headline);
        self::assertStringContainsString('card-game lobby hero scene', $neonSubject);
        self::assertStringContainsString('player trust proof scene', $neonSubject);
        self::assertStringContainsString('rules and support scene', $neonSubject);
    }

    public function testSeoStrategySourceDoesNotLeakInternalChineseCopyIntoEnglishPlans(): void
    {
        $source = (string)\file_get_contents((new ReflectionClass(AiSiteExecutionBlueprintService::class))->getFileName());
        $seoStrategy = $this->extractMethodSource($source, 'buildSeoStrategy');

        self::assertStringContainsString('official website', $seoStrategy);
        self::assertStringContainsString('Meta titles should carry the primary keyword', $seoStrategy);
        self::assertStringContainsString('Use concise English slugs', $seoStrategy);
        self::assertDoesNotMatchRegularExpression('/[\x{4E00}-\x{9FFF}]/u', $seoStrategy);
        self::assertStringNotContainsString('????????', $seoStrategy);
    }

    public function testStageOneMediaRichnessPromptsRemainGuidanceOnly(): void
    {
        $source = (string)\file_get_contents((new ReflectionClass(AiSiteExecutionBlueprintService::class))->getFileName());

        self::assertStringContainsString('About/contact/blog media rhythm rule', $source);
        self::assertStringContainsString('not validation gates', $source);
        self::assertStringContainsString('substantial CSS media surface', $source);
        self::assertStringContainsString('Policy media rule', $source);
        self::assertStringContainsString('image count, image subject, and generated copy are not validation gates', $source);
    }

    private function extractMethodSource(string $source, string $methodName): string
    {
        $offset = \strpos($source, 'private function ' . $methodName);
        self::assertNotFalse($offset, $methodName . ' missing');
        $next = \strpos($source, "\n    private function ", $offset + 1);

        return \str_replace(["\r\n", "\r"], "\n", $next === false ? \substr($source, $offset) : \substr($source, $offset, $next - $offset));
    }
}
