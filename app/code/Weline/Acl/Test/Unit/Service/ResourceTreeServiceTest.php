<?php

declare(strict_types=1);

namespace Weline\Acl\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Acl\Model\Acl;
use Weline\Acl\Service\ResourceTreeService;

final class ResourceTreeServiceTest extends TestCase
{
    public function testGetEnabledBackendMenuRoutesUsesIteratorForUnboundedSelect(): void
    {
        $aclModel = new class([
            [Acl::schema_fields_ROUTE => '/admin/system/menus/'],
            [Acl::schema_fields_ROUTE => ''],
            [Acl::schema_fields_ROUTE => 'admin/system/config'],
        ]) extends Acl {
            public function __construct(private readonly array $rows)
            {
            }

            public function reset(): static
            {
                return $this;
            }

            public function where(...$args): static
            {
                return $this;
            }

            public function select(): static
            {
                return $this;
            }

            public function fetchArray(): array
            {
                throw new \RuntimeException('fetchArray should not be used for backend menu route loading.');
            }

            public function fetchIterator(string $model_class = '', int $batchSize = 1): \Generator
            {
                foreach ($this->rows as $row) {
                    yield $row;
                }
            }
        };

        $service = new class($aclModel) extends ResourceTreeService {
            public function __construct(private readonly Acl $aclModel)
            {
            }

            protected function newAclModel(): Acl
            {
                return $this->aclModel;
            }
        };

        self::assertSame(
            ['admin/system/menus', 'admin/system/config'],
            $service->getEnabledBackendMenuRoutes()
        );
    }
}
