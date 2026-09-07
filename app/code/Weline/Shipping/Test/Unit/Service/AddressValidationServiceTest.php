<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

require_once dirname(__DIR__) . '/bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Shipping\Service\AddressFormatter;
use Weline\Shipping\Service\AddressSchemaProvider;
use Weline\Shipping\Service\AddressValidationException;
use Weline\Shipping\Service\AddressValidationService;
use Weline\Shipping\Service\RegionLocalNameResolver;

final class AddressValidationServiceTest extends TestCase
{
    private AddressValidationService $service;

    protected function setUp(): void
    {
        $resolver = $this->createMock(RegionLocalNameResolver::class);
        $resolver->method('localizeAddressFields')->willReturnCallback(
            static fn (array $address): array => $address
        );
        $this->service = new AddressValidationService(
            new AddressSchemaProvider(),
            new AddressFormatter(new AddressSchemaProvider(), $resolver),
        );
    }

    public function testInvalidPhoneMapsToPhoneFormField(): void
    {
        try {
            $this->service->validate([
                'contact_name' => '张三',
                'contact_phone' => 'abc',
                'street' => '信都区',
                'country_code' => 'CN',
                'province' => '河北省',
                'city' => '邢台市',
            ], ['contact_name', 'contact_phone', 'street']);
            self::fail('Expected AddressValidationException');
        } catch (AddressValidationException $exception) {
            self::assertSame('contact_phone', $exception->getField());
            self::assertSame('phone', $exception->getFormField());
            self::assertSame(['phone' => $exception->getMessage()], $exception->toFieldErrors());
        }
    }

    public function testInvalidPostalCodeMapsToPostalCodeFormField(): void
    {
        try {
            $this->service->validate([
                'contact_name' => '张三',
                'contact_phone' => '13800138000',
                'street' => '信都区',
                'postal_code' => 'abc',
                'country_code' => 'CN',
                'province' => '河北省',
                'city' => '邢台市',
            ], ['contact_name', 'contact_phone', 'street']);
            self::fail('Expected AddressValidationException');
        } catch (AddressValidationException $exception) {
            self::assertSame('postal_code', $exception->getField());
            self::assertSame('postal_code', $exception->getFormField());
        }
    }

    public function testMultipleRequiredFieldsReturnAllFieldErrors(): void
    {
        try {
            $this->service->validate([
                'contact_name' => '',
                'contact_phone' => '',
                'street' => '',
                'country_code' => 'CN',
            ], ['contact_name', 'contact_phone', 'street']);
            self::fail('Expected AddressValidationException');
        } catch (AddressValidationException $exception) {
            $fieldErrors = $exception->toFieldErrors();
            self::assertArrayHasKey('name', $fieldErrors);
            self::assertArrayHasKey('phone', $fieldErrors);
            self::assertArrayHasKey('address1', $fieldErrors);
        }
    }
}
