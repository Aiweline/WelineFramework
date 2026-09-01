<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Service\TaglibLocalFormService;

/**
 * @group integration
 */
final class TaglibLocalFormServiceIntegrationTest extends TestCase
{
    public function testBuildFormPayloadReturnsInstalledLocales(): void
    {
        if (!is_file(dirname(__DIR__, 5) . '/app/bootstrap.php')) {
            self::markTestSkipped('Framework bootstrap unavailable.');
        }

        require_once dirname(__DIR__, 5) . '/app/bootstrap.php';

        /** @var TaglibLocalFormService $service */
        $service = ObjectManager::getInstance(TaglibLocalFormService::class);
        $result = $service->buildFormPayload(
            'Weline\\Eav\\Model\\EavAttribute\\LocalDescription',
            'name',
            '1',
            '测试',
        );

        self::assertTrue($result['success'] ?? false, (string)($result['message'] ?? 'build failed'));
        $locales = $result['data']['locales'] ?? [];
        self::assertIsArray($locales);
        self::assertGreaterThanOrEqual(1, count($locales), 'expected at least one installed locale');
    }
}
