<?php
declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Service\DictionaryAreaPolicy;

final class DictionaryAreaPolicyContractTest extends TestCase
{
    public function testPathEvidenceAndIntersectionUpgradeToShared(): void
    {
        self::assertSame(
            DictionaryAreaPolicy::AREA_FRONTEND,
            DictionaryAreaPolicy::evidenceFromPath('app/code/Weline/X/view/templates/frontend/a.phtml')
        );
        self::assertSame(
            DictionaryAreaPolicy::AREA_BACKEND,
            DictionaryAreaPolicy::evidenceFromPath('app/code/Weline/X/view/templates/backend/a.phtml')
        );
        // Service 等无区路径 = 无证据，不得记成 shared
        self::assertSame(
            DictionaryAreaPolicy::EVIDENCE_NONE,
            DictionaryAreaPolicy::evidenceFromPath('app/code/Weline/X/Service/Foo.php')
        );

        self::assertSame(
            DictionaryAreaPolicy::AREA_SHARED,
            DictionaryAreaPolicy::classifyFromEvidence([
                DictionaryAreaPolicy::AREA_FRONTEND,
                DictionaryAreaPolicy::AREA_BACKEND,
            ])
        );
        self::assertNull(
            DictionaryAreaPolicy::classifyFromEvidence([])
        );
        self::assertSame(
            DictionaryAreaPolicy::AREA_SHARED,
            DictionaryAreaPolicy::applyClassified(
                DictionaryAreaPolicy::AREA_FRONTEND,
                DictionaryAreaPolicy::AREA_BACKEND
            )
        );
        self::assertSame(
            DictionaryAreaPolicy::AREA_SHARED,
            DictionaryAreaPolicy::applyClassified(
                DictionaryAreaPolicy::AREA_FRONTEND,
                DictionaryAreaPolicy::AREA_SHARED
            )
        );
        // 无 exclusive 目标：保留 frontend（禁止 Service/CSV 抬升）
        self::assertSame(
            DictionaryAreaPolicy::AREA_FRONTEND,
            DictionaryAreaPolicy::applyClassified(
                DictionaryAreaPolicy::AREA_FRONTEND,
                null
            )
        );
        self::assertSame(
            DictionaryAreaPolicy::AREA_FRONTEND,
            DictionaryAreaPolicy::upgrade(
                DictionaryAreaPolicy::AREA_FRONTEND,
                DictionaryAreaPolicy::AREA_SHARED
            )
        );
        self::assertSame(
            DictionaryAreaPolicy::AREA_SHARED,
            DictionaryAreaPolicy::upgrade(
                DictionaryAreaPolicy::AREA_SHARED,
                DictionaryAreaPolicy::AREA_FRONTEND
            )
        );
    }

    public function testLocaleBagBasenames(): void
    {
        self::assertSame('en_US.shared.php', DictionaryAreaPolicy::localeBagBasename('en_US', 'shared'));
        self::assertSame('zh_Hans_CN.frontend.php', DictionaryAreaPolicy::localeBagBasename('zh_Hans_CN', 'frontend'));
    }

    public function testResolveRegistrationAreaDefaultsToSharedNotFrontendOnIsBackendZero(): void
    {
        self::assertSame(
            DictionaryAreaPolicy::AREA_SHARED,
            DictionaryAreaPolicy::resolveRegistrationArea(['is_backend' => 0])
        );
        self::assertSame(
            DictionaryAreaPolicy::AREA_BACKEND,
            DictionaryAreaPolicy::resolveRegistrationArea(['is_backend' => 1])
        );
        self::assertSame(
            DictionaryAreaPolicy::AREA_FRONTEND,
            DictionaryAreaPolicy::resolveRegistrationArea(['area' => 'frontend'])
        );
    }
}
