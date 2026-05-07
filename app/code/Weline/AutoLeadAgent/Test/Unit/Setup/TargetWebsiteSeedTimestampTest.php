<?php

declare(strict_types=1);

namespace Weline\AutoLeadAgent\Test\Unit\Setup;

use PHPUnit\Framework\TestCase;
use Weline\AutoLeadAgent\Model\TargetWebsite;
use Weline\AutoLeadAgent\Setup\Install;

class TargetWebsiteSeedTimestampTest extends TestCase
{
    public function testTargetWebsiteSeedWritesRequiredTimestamps(): void
    {
        $source = (string) file_get_contents((new \ReflectionClass(Install::class))->getFileName());

        $this->assertStringContainsString('TargetWebsite::schema_fields_CREATED_AT', $source);
        $this->assertStringContainsString('TargetWebsite::schema_fields_UPDATED_AT', $source);
        $this->assertSame('created_at', TargetWebsite::schema_fields_CREATED_AT);
        $this->assertSame('updated_at', TargetWebsite::schema_fields_UPDATED_AT);
    }
}
