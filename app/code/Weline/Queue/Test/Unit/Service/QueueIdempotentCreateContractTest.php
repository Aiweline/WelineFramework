<?php

declare(strict_types=1);

namespace Weline\Queue\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Queue\Model\Queue;

final class QueueIdempotentCreateContractTest extends TestCase
{
    public function testQueuePublishesNullableUniqueIdempotencyKeyWithoutChangingBizKeyIndex(): void
    {
        $constant = new \ReflectionClassConstant(Queue::class, 'schema_fields_IDEMPOTENCY_KEY');
        $attributes = $constant->getAttributes(Col::class);
        self::assertCount(1, $attributes);
        $column = $attributes[0]->newInstance();
        self::assertFalse($column->primaryKey);
        self::assertTrue($column->nullable);

        $indexes = [];
        foreach ((new \ReflectionClass(Queue::class))->getAttributes(Index::class) as $attribute) {
            $index = $attribute->newInstance();
            $indexes[$index->name] = $index;
        }
        self::assertSame('UNIQUE', $indexes['uk_idempotency_key']->type);
        self::assertSame([Queue::schema_fields_IDEMPOTENCY_KEY], $indexes['uk_idempotency_key']->columns);
        self::assertNotSame('UNIQUE', $indexes['idx_biz_key']->type);
    }

    public function testProviderKeepsLegacyCreateAndAddsExplicitAtomicOperation(): void
    {
        $path = \dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/QueueQueryProvider.php';
        $source = (string)\file_get_contents($path);

        self::assertStringContainsString("'create' => \$this->createQueue(\$params)", $source);
        self::assertStringContainsString("'createIfAbsent' => \$this->createQueueIfAbsent(\$params)", $source);
        self::assertStringContainsString("Queue::schema_fields_IDEMPOTENCY_KEY", $source);
        self::assertStringContainsString("\$this->createQueue(\$params, \$storageKey)", $source);
        self::assertStringContainsString("'created' => false", $source);
    }
}
