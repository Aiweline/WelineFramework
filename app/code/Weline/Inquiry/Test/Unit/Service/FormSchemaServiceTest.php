<?php

declare(strict_types=1);

namespace Weline\Inquiry\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Inquiry\Service\FormSchemaService;

final class FormSchemaServiceTest extends TestCase
{
    public function testNormalizesAndSortsFields(): void
    {
        $schema = (new FormSchemaService())->normalize(['fields' => [
            ['key' => 'company', 'type' => 'text', 'sort_order' => 20],
            ['key' => 'email', 'type' => 'email', 'required' => true, 'sort_order' => 10],
        ]]);
        self::assertSame('email', $schema['fields'][0]['key']);
        self::assertTrue($schema['fields'][0]['required']);
        self::assertSame('company_website', $schema['honeypot']);
    }

    public function testRejectsDuplicateKeysAndEmptyOptions(): void
    {
        $service = new FormSchemaService();
        $this->expectException(\InvalidArgumentException::class);
        $service->normalize(['fields' => [
            ['key' => 'dealer', 'type' => 'text'],
            ['key' => 'dealer', 'type' => 'select'],
        ]]);
    }
}
