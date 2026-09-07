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
            ['key' => 'country', 'type' => 'country', 'required' => true, 'sort_order' => 15],
        ]]);
        self::assertSame('email', $schema['fields'][0]['key']);
        self::assertTrue($schema['fields'][0]['required']);
        self::assertSame('country', $schema['fields'][1]['key']);
        self::assertSame('country', $schema['fields'][1]['type']);
        self::assertSame('global', $schema['fields'][1]['validation']['catalog']);
        self::assertSame('country|province|city|district', $schema['fields'][1]['validation']['levels']);
        self::assertSame('single', $schema['fields'][1]['validation']['selection']);
        self::assertSame('company_website', $schema['honeypot']);
    }

    public function testCountryCatalogAcceptsTopLevelAttribute(): void
    {
        $schema = (new FormSchemaService())->normalize(['fields' => [
            ['key' => 'country', 'type' => 'country', 'required' => true, 'catalog' => 'global'],
        ]]);
        self::assertSame('global', $schema['fields'][0]['validation']['catalog']);
        self::assertSame('country|province|city|district', $schema['fields'][0]['validation']['levels']);
        self::assertSame('single', $schema['fields'][0]['validation']['selection']);
    }

    public function testCountryLevelsCanBeCountryOnly(): void
    {
        $schema = (new FormSchemaService())->normalize(['fields' => [
            [
                'key' => 'country',
                'type' => 'country',
                'required' => true,
                'validation' => ['catalog' => 'global', 'levels' => 'country', 'selection' => 'single'],
            ],
        ]]);
        self::assertSame('country', $schema['fields'][0]['validation']['levels']);
        self::assertSame('single', $schema['fields'][0]['validation']['selection']);
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
