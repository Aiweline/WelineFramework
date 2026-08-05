<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Test\Integration;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
use Weline\Framework\Cache\Namespace\NamespaceGenerationRepository;
use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Model\Event\Outbox;
use Weline\SystemConfig\Model\SystemConfig;

final class SystemConfigResourceChangeIntegrationTest extends TestCase
{
    public function testSensitiveBatchAndOutboxRollbackTogether(): void
    {
        $config = ObjectManager::getInstance(SystemConfig::class, [], false);
        $transactions = ObjectManager::getInstance(TransactionCoordinatorInterface::class);
        $key = 'resource_change_test_' . bin2hex(random_bytes(5));
        $secret = 'must-not-enter-outbox-' . bin2hex(random_bytes(8));
        $previousArea = WelineEnv::getArea();
        WelineEnv::setArea('backend');
        $namespaces = ObjectManager::getInstance(NamespaceGenerationRepository::class);
        $configNamespace = ObjectManager::getInstance(NamespacePath::class)
            ->global('storefront', ['config']);
        $beforeGeneration = $this->generation($namespaces, $configNamespace);

        try {
            $transactions->run($config->getConnection(), function () use (
                $config,
                $key,
                $secret,
                $namespaces,
                $configNamespace,
                $beforeGeneration,
            ): void {
                $result = $config->saveScopeConfig(
                    'Weline_I18n',
                    SystemConfig::area_BACKEND,
                    [$key => $secret],
                    SystemConfig::SCOPE_GLOBAL,
                    SystemConfig::LOCALE_DEFAULT,
                    ['sensitive_keys' => [$key], 'operation' => 'resource_change_test'],
                );
                self::assertTrue((bool)($result['success'] ?? false));

                $outbox = ObjectManager::getInstance(Outbox::class, [], false);
                $row = $outbox->clearData()->clearQuery()
                    ->order(Outbox::schema_fields_ID, 'DESC')
                    ->find()->fetch()->getData();
                $payloadJson = (string)($row[Outbox::schema_fields_PAYLOAD_JSON] ?? '');
                self::assertStringNotContainsString($secret, $payloadJson);
                $payload = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
                self::assertSame('system_config', $payload['resource']['type'] ?? null);
                self::assertSame([$key], $payload['changed_fields'] ?? null);
                self::assertTrue((bool)($payload['after']['rows'][0]['value']['is_sensitive'] ?? false));
                self::assertContains(
                    'global/storefront/config',
                    $payload['impact']['namespaces'] ?? [],
                );
                $namespaces->clearSnapshot();
                self::assertGreaterThan(
                    $beforeGeneration,
                    $this->generation($namespaces, $configNamespace),
                );

                throw new SystemConfigResourceChangeRollbackProbe();
            });
            self::fail('Rollback probe must escape the managed transaction.');
        } catch (SystemConfigResourceChangeRollbackProbe) {
            $row = $config->getScopedConfigRow(
                $key,
                'Weline_I18n',
                SystemConfig::area_BACKEND,
                SystemConfig::SCOPE_GLOBAL,
                SystemConfig::LOCALE_DEFAULT,
            );
            self::assertNull($row);
            $namespaces->clearSnapshot();
            self::assertSame(
                $beforeGeneration,
                $this->generation($namespaces, $configNamespace),
            );
        } finally {
            WelineEnv::setArea($previousArea);
        }
    }

    private function generation(NamespaceGenerationRepository $repository, string $path): int
    {
        $vector = $repository->resolveVector([$path]);
        return (int)($vector['generations'][$path] ?? 0);
    }
}

final class SystemConfigResourceChangeRollbackProbe extends \RuntimeException
{
}
