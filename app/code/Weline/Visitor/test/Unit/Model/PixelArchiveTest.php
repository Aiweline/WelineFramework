<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Model;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Visitor\Model\PixelArchive;

/**
 * G07：冷归档表 schema 契约（纯反射，不查库）。
 */
final class PixelArchiveTest extends TestCase
{
    public function testTableAndPixelIdUniqueForIdempotentMigrate(): void
    {
        self::assertSame('pixel_archive', PixelArchive::schema_table);
        self::assertSame('pixel_archive_id', PixelArchive::schema_primary_key);

        $reflection = new ReflectionClass(PixelArchive::class);
        self::assertNotEmpty($reflection->getAttributes(Table::class));

        $uniqueColumns = [];
        foreach ($reflection->getAttributes(Index::class) as $attr) {
            $args = $attr->getArguments();
            if (strtoupper((string)($args['type'] ?? '')) === 'UNIQUE') {
                $uniqueColumns = $args['columns'] ?? [];
            }
        }
        self::assertSame(['pixel_id'], $uniqueColumns);
    }

    public function testHasSiteCreatedIndexForColdQuery(): void
    {
        $reflection = new ReflectionClass(PixelArchive::class);
        $found = false;
        foreach ($reflection->getAttributes(Index::class) as $attr) {
            $args = $attr->getArguments();
            if (($args['columns'] ?? null) === ['website_id', 'created_at']) {
                $found = true;
                break;
            }
        }
        self::assertTrue($found, '冷查依赖索引 (website_id, created_at)');
    }

    public function testHotMirrorFieldsAndArchivedAt(): void
    {
        self::assertContains('pixel_id', PixelArchive::HOT_MIRROR_FIELDS);
        self::assertContains('channel_code', PixelArchive::HOT_MIRROR_FIELDS);
        self::assertContains('session_id', PixelArchive::HOT_MIRROR_FIELDS);
        self::assertNotContains('archived_at', PixelArchive::HOT_MIRROR_FIELDS);

        $reflection = new ReflectionClass(PixelArchive::class);
        $declared = [];
        foreach ($reflection->getReflectionConstants() as $const) {
            if (!str_starts_with($const->getName(), 'schema_fields_')) {
                continue;
            }
            if ($const->getAttributes(Col::class) === []) {
                continue;
            }
            $declared[] = (string)$const->getValue();
        }
        self::assertContains('archived_at', $declared);
        self::assertContains('created_at', $declared);
        self::assertContains('website_id', $declared);
    }
}
