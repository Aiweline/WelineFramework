<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\B2B\Model\CustomerGroupRecord;
use Weline\B2B\Model\CustomerGroupRecord\LocalDescription;
use Weline\I18n\Api\Localization\LocalModel;
use Weline\I18n\Service\LocalModelTranslation\LocalModelTranslationCatalog;

require_once dirname(__DIR__) . '/bootstrap.php';

final class CustomerGroupLocalModelContractTest extends TestCase
{
    public function testLocalDescriptionExtendsLocalModelAndMapsParent(): void
    {
        self::assertTrue(is_subclass_of(LocalDescription::class, LocalModel::class));
        self::assertSame(CustomerGroupRecord::schema_fields_ID, LocalDescription::schema_fields_ID);
        self::assertSame(CustomerGroupRecord::schema_fields_NAME, LocalDescription::schema_fields_NAME);
        self::assertSame(CustomerGroupRecord::schema_fields_DESCRIPTION, LocalDescription::schema_fields_DESCRIPTION);
        self::assertSame(CustomerGroupRecord::schema_fields_NAME, LocalDescription::schema_fields_name);
        self::assertSame('weline_b2b_customer_group_local', LocalDescription::schema_table);
    }

    public function testCatalogDiscoversCustomerGroupLocalFields(): void
    {
        $catalog = new LocalModelTranslationCatalog();
        $byClass = [];
        foreach ($catalog->descriptors() as $descriptor) {
            $byClass[(string)$descriptor['local_model']] = $descriptor;
        }

        self::assertArrayHasKey(LocalDescription::class, $byClass);
        self::assertSame(CustomerGroupRecord::class, $byClass[LocalDescription::class]['parent_model']);
        self::assertContains(
            CustomerGroupRecord::schema_fields_NAME,
            $byClass[LocalDescription::class]['fields'],
        );
        self::assertContains(
            CustomerGroupRecord::schema_fields_DESCRIPTION,
            $byClass[LocalDescription::class]['fields'],
        );
    }

    public function testControlCenterEditUsesLocalTagForNameAndDescription(): void
    {
        $path = BP . 'app/code/Weline/B2B/view/templates/Backend/ControlCenter/index.phtml';
        $content = (string)file_get_contents($path);
        self::assertStringContainsString('Weline\\B2B\\Model\\CustomerGroupRecord\\LocalDescription', $content);
        self::assertStringContainsString('field="name"', $content);
        self::assertStringContainsString('field="description"', $content);
        self::assertStringContainsString('data-testid="b2b-group-edit-name-local"', $content);
        self::assertStringContainsString('data-testid="b2b-group-edit-description-local"', $content);
        self::assertStringContainsString('data-testid="b2b-group-local-vault"', $content);
        self::assertStringContainsString('id="group_row_id"', $content);
        self::assertStringContainsString('data-testid="b2b-group-edit-name"', $content);
        self::assertStringContainsString('data-testid="b2b-group-edit-description"', $content);
        self::assertStringContainsString('id="b2b-group-edit-name"', $content);
        self::assertStringContainsString('id="b2b-group-edit-description"', $content);
    }

    public function testUpgradeRegistersLocalDescription(): void
    {
        $path = BP . 'app/code/Weline/B2B/Setup/Upgrade.php';
        self::assertFileExists($path);
        $content = (string)file_get_contents($path);
        self::assertStringContainsString('LocalDescription::class', $content);
        self::assertStringContainsString('CustomerGroupLocalSeedService', $content);
    }
}
