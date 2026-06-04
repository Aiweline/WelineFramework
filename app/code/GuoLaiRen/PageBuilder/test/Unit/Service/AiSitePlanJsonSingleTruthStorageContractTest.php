<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class AiSitePlanJsonSingleTruthStorageContractTest extends TestCase
{
    public function testPlanJsonIsNotStoredAsASessionArtifact(): void
    {
        $source = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Service/AiSiteAgentSessionArtifactService.php');
        self::assertIsString($source);

        self::assertStringContainsString('private const ARTIFACT_DEFINITIONS = [', $source);
        self::assertStringNotContainsString("'plan_json' => [\n            'stage' => AiSiteAgentSession::STAGE_PLAN", $source);
        self::assertStringNotContainsString("'plan_json',\n            'content_manifest'", $source);
        self::assertStringNotContainsString("\$artifactKey === 'plan_json'", $source);
    }

    public function testScopeStorageNeverStripsInlinePlanJson(): void
    {
        $source = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Model/AiSiteAgentSession.php');
        self::assertIsString($source);

        self::assertStringContainsString('private const ARTIFACT_BACKED_SCOPE_PATHS = [', $source);
        self::assertStringNotContainsString("'plan_json' => [['plan_json'], []]", $source);
        self::assertStringContainsString('plan_json remains inline in scope_json as the pre-publish truth tree.', $source);
    }

    public function testScopeManifestPolicyDoesNotDehydratePlanJson(): void
    {
        $source = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Service/AiSiteScopeManifestPolicy.php');
        self::assertIsString($source);

        self::assertStringContainsString('public const INLINE_ARTIFACT_KEYS = [', $source);
        self::assertStringNotContainsString("'plan_json',\n        'content_manifest'", $source);
        self::assertStringNotContainsString('$scope[\'plan_json\'] = $this->stripBlockPayloadFromPlanJson(', $source);
    }
}
