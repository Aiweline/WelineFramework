<?php
declare(strict_types=1);

namespace Weline\Websites\Test\Integration;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Website;
use Weline\Websites\Service\DefaultWebsiteService;

final class DefaultWebsiteConfigurationPreservationTest extends TestCase
{
    public function testRepeatedEnsurePreservesConfiguredDefaultWebsite(): void
    {
        $website = ObjectManager::getInstance(Website::class, [], false)->load(0, null, true);
        self::assertTrue($website->hasData(Website::schema_fields_ID));
        $before = $website->getData();
        $configured = [
            Website::schema_fields_NAME => 'Default configuration preservation',
            Website::schema_fields_URL => 'https://default-config-preservation.invalid:9555/shop',
            Website::schema_fields_DEFAULT_CURRENCY => 'USD',
            Website::schema_fields_DEFAULT_LANGUAGE => 'en_US',
            Website::schema_fields_DEFAULT_TIMEZONE => 'UTC',
            Website::schema_fields_SCOPE => 'catalog',
        ];
        $transactions = ObjectManager::getInstance(WriteIntentTransactionCoordinatorInterface::class);
        try {
            $transactions->runWrite($website->getConnection(), function () use ($website, $configured): void {
                foreach ($configured as $field => $value) {
                    $website->setData($field, $value);
                }
                $website->save();
                $service = ObjectManager::getInstance(DefaultWebsiteService::class);
                for ($attempt = 0; $attempt < 2; $attempt++) {
                    $actual = $service->ensureDefaultWebsite(false);
                    self::assertSame(Website::ID_DEFAULT, (int)$actual[Website::schema_fields_ID]);
                    self::assertSame(Website::CODE_DEFAULT, $actual[Website::schema_fields_CODE]);
                    foreach ($configured as $field => $value) {
                        self::assertSame($value, $actual[$field], 'Default bootstrap overwrote ' . $field);
                    }
                }
                throw new DefaultWebsiteConfigurationRollback();
            });
            self::fail('The test transaction must roll back.');
        } catch (DefaultWebsiteConfigurationRollback) {
        } finally {
            $after = ObjectManager::getInstance(Website::class, [], false)->load(0, null, true)->getData();
            foreach (array_keys($configured) as $field) {
                self::assertSame($before[$field] ?? null, $after[$field] ?? null, 'Fixture leaked ' . $field);
            }
        }
    }
}

final class DefaultWebsiteConfigurationRollback extends \RuntimeException
{
}
