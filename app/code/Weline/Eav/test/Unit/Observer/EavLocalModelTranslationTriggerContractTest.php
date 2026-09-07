<?php

declare(strict_types=1);

namespace Weline\Eav\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;

final class EavLocalModelTranslationTriggerContractTest extends TestCase
{
    public function testEavSaveEventsFeedTheCanonicalLocalModelQueue(): void
    {
        $root = dirname(__DIR__, 3);
        $observer = (string)file_get_contents($root . '/Observer/EavLocalModelTranslationTrigger.php');
        $events = (string)file_get_contents($root . '/etc/event.xml');

        self::assertStringContainsString('LocalModelTranslationQueueService', $observer);
        self::assertStringContainsString("->enqueue(self::REQUESTED_BY)", $observer);
        self::assertStringContainsString('Context::hasCurrent()', $observer);
        self::assertStringContainsString('RequestContext::has', $observer);

        foreach ([
            'Weline_Eav_Model_EavEntity_model_save_after',
            'Weline_Eav_Model_EavAttribute_Set_model_save_after',
            'Weline_Eav_Model_EavAttribute_Group_model_save_after',
            'Weline_Eav_Model_EavAttribute_model_save_after',
            'Weline_Eav_Model_EavAttribute_Option_model_save_after',
        ] as $eventName) {
            self::assertStringContainsString($eventName, $events);
        }
        self::assertStringContainsString('EavLocalModelTranslationTrigger', $events);
    }

    public function testEavLocalDescriptionsUseThePublicLocalModelContract(): void
    {
        $root = dirname(__DIR__, 3);
        foreach ([
            '/Model/EavEntity/LocalDescription.php',
            '/Model/EavAttribute/Set/LocalDescription.php',
            '/Model/EavAttribute/Group/LocalDescription.php',
            '/Model/EavAttribute/LocalDescription.php',
            '/Model/EavAttribute/Option/LocalDescription.php',
        ] as $relativePath) {
            $path = $root . $relativePath;
            $source = (string)file_get_contents($path);
            self::assertStringContainsString(
                'Weline\\I18n\\Api\\Localization\\LocalModel',
                $source,
                $path,
            );
            self::assertStringContainsString('extends LocalModel', $source, $path);
        }
    }
}
