<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Server\Service\SharedStateServiceManager;
use Weline\Server\Service\SharedStateServiceRegistry;

/**
 * Guard: registry/runtime generation skew with the same identity digest must not
 * block operator shared:stop / rotate (wire_generation bump path).
 */
final class SharedStateLifecycleGenerationSkewTest extends TestCase
{
    public function testSelectedLifecycleIsCurrentAllowsDigestMatchAcrossGenerationSkew(): void
    {
        $role = 'session_server';
        $base = [
            'role' => $role,
            'host' => '127.0.0.1',
            'port' => 26277,
            'pid' => 8187,
            'token_file_name' => 'session_server.token',
            'process_name' => 'weline-wls-session-test',
            'instance_name' => 'shared-session-test',
            'service_instance_name' => 'shared-session-test',
        ];
        $selected = SharedStateServiceRegistry::bindLifecycleGeneration($role, $base, []);
        self::assertTrue(SharedStateServiceRegistry::hasExactLifecycleBinding($role, $selected));

        $runtime = $selected;
        $runtime['lifecycle_generation'] = 33;

        $manager = new class($selected, $runtime) extends SharedStateServiceManager {
            public function __construct(
                private readonly array $registryRecord,
                private readonly array $runtimeRecord,
            ) {
            }

            protected function createRegistry(): SharedStateServiceRegistry
            {
                $record = $this->registryRecord;

                return new class($record) extends SharedStateServiceRegistry {
                    public function __construct(private readonly array $record)
                    {
                    }

                    public function getRecord(string $role): array
                    {
                        return $this->record;
                    }
                };
            }

            protected function readRuntimeFile(string $role): array
            {
                return $this->runtimeRecord;
            }
        };

        $method = new ReflectionMethod(SharedStateServiceManager::class, 'selectedLifecycleIsCurrent');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($manager, $role, $selected));
    }

    public function testMergePublishedLifecycleKeepsHigherGenerationOnSameDigest(): void
    {
        $selected = [
            'pid' => 1,
            'port' => 2,
            'lifecycle_generation' => 33,
            'lifecycle_identity_digest' => 'abc',
            'lifecycle_schema' => 'wls-shared-lifecycle/1',
            'extra_selected' => true,
        ];
        $published = [
            'pid' => 1,
            'port' => 2,
            'lifecycle_generation' => 1,
            'lifecycle_identity_digest' => 'abc',
            'lifecycle_schema' => 'wls-shared-lifecycle/1',
            'extra_published' => true,
        ];

        $method = new ReflectionMethod(
            SharedStateServiceManager::class,
            'mergePublishedLifecycleWithoutRegression'
        );
        $method->setAccessible(true);
        $merged = $method->invoke(new SharedStateServiceManager(), $selected, $published);

        self::assertSame(33, $merged['lifecycle_generation']);
        self::assertSame('abc', $merged['lifecycle_identity_digest']);
        self::assertTrue($merged['extra_published']);
        self::assertTrue($merged['extra_selected']);
    }
}
