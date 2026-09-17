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

    public function testInternationalPhonesAreAccepted(): void
    {
        $samples = [
            '13800138000',
            '+86 138 0013 8000',
            '+1 (212) 555-0100',
            '212.555.0100',
            '+44 20 7946 0958',
            '+49 30 1234567',
            '03-1234-5678',
            '+91 98765 43210',
        ];
        foreach ($samples as $phone) {
            self::assertTrue(
                $this->service->isValidInternationalPhone($phone),
                'expected valid: ' . $phone,
            );
        }
    }

    public function testNonPhoneTextAndTooShortAreRejected(): void
    {
        self::assertFalse($this->service->isValidInternationalPhone('dsdadaa'));
        self::assertFalse($this->service->isValidInternationalPhone('12345'));
        self::assertFalse($this->service->isValidInternationalPhone('12+34567890'));
        self::assertFalse($this->service->isValidInternationalPhone('+1234567890123456'));
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
