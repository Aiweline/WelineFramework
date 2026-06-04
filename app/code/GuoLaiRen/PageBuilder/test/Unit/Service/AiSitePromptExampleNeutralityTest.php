<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class AiSitePromptExampleNeutralityTest extends TestCase
{
    public function testGenericPromptExamplesDoNotTeachStaleApkOrWorkflowCopy(): void
    {
        $root = \dirname(__DIR__, 3) . '/Service';
        $sources = [
            $root . '/AiSiteStageOneContractService.php',
            $root . '/AiSitePlanJsonGenerationService.php',
            $root . '/AiSiteStageOnePromptContractRenderer.php',
            $root . '/AiSitePageComponentGenerationService.php',
        ];

        $forbiddenExamples = [
            'Trusted APK Download',
            'Install the app, review the game lobby',
            'Download APK',
            'Trusted Download',
            'Fast setup with secure guidance',
            'Verified APK guidance',
            'Secure APK download badge cluster',
            'Approval workflows that stay visible from request to close',
            'Automate approvals faster',
            'workflow automation dashboard with approval cards',
            'Dashboard screen with workflow cards',
            'Latest operations guides',
            'Beginner approval-routing guide',
            'workflow motifs',
        ];

        foreach ($sources as $sourcePath) {
            $source = (string)\file_get_contents($sourcePath);
            foreach ($forbiddenExamples as $example) {
                self::assertStringNotContainsString($example, $source, $sourcePath);
            }
        }
    }
}
