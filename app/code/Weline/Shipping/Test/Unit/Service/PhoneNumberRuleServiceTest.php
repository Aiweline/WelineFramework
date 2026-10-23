<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Shipping\Service\PhoneNumberRuleService;

final class PhoneNumberRuleServiceTest extends TestCase
{
    private PhoneNumberRuleService $service;

    protected function setUp(): void
    {
        $this->service = new PhoneNumberRuleService();
    }

    /** @return iterable<string, array{string, string|null, bool}> */
    public static function phoneCases(): iterable
    {
        // 需求触发用例：CN 12 位不是合法国内号码
        yield 'cn 12 digits rejected' => ['185766336348', 'CN', false];
        yield 'cn mobile national' => ['13800138000', 'CN', true];
        yield 'cn mobile international' => ['+86 138 0013 8000', 'CN', true];
        yield 'cn international with redundant trunk' => ['+86 013800138000', 'CN', true];
        yield 'cn beijing landline' => ['010-12345678', 'CN', true];
        yield 'cn shenzhen landline' => ['0755-1234567', 'CN', true];
        yield 'cn dial code without plus' => ['8613800138000', 'CN', true];
        yield 'cn mobile wrong second digit' => ['12800138000', 'CN', false];
        yield 'us national with parentheses' => ['(212) 555-0100', 'US', true];
        yield 'us national with leading 1' => ['12125550100', 'US', true];
        yield 'us area code starting with 1' => ['1125550100', 'US', false];
        yield 'us number typed under cn address resolves by dial code' => ['+1 (212) 555-0100', 'CN', true];
        yield 'us number via 00 prefix' => ['0012125550100', 'CN', true];
        yield 'gb national' => ['020 7946 0958', 'GB', true];
        yield 'gb international under cn address' => ['+44 20 7946 0958', 'CN', true];
        yield 'jp mobile' => ['090-1234-5678', 'JP', true];
        yield 'jp landline' => ['03-1234-5678', 'JP', true];
        yield 'hk 8 digits' => ['9123 4567', 'HK', true];
        yield 'sg 9 digits rejected' => ['912345678', 'SG', false];
        yield 'unknown dial code falls back to e164 length' => ['+999 1234567', 'CN', true];
        yield 'no country falls back to e164 length' => ['03-1234-5678', null, true];
        yield 'too short' => ['12345', 'CN', false];
        yield 'letters' => ['dsdadaa', 'CN', false];
        yield 'plus in middle' => ['12+34567890', 'CN', false];
        yield 'over e164 max' => ['+1234567890123456', 'CN', false];
        yield 'empty' => ['', 'CN', false];
    }

    /** @dataProvider phoneCases */
    public function testIsValid(string $phone, ?string $country, bool $expected): void
    {
        self::assertSame(
            $expected,
            $this->service->isValid($phone, $country),
            sprintf('phone=%s country=%s', $phone, (string)$country),
        );
    }

    public function testAnalyzeResolvesCountryByDialCodeAndBuildsE164(): void
    {
        $result = $this->service->analyze('+44 20 7946 0958', 'CN');
        self::assertTrue($result['valid']);
        self::assertSame('GB', $result['country_code']);
        self::assertSame('+442079460958', $result['e164']);
        self::assertSame('2079460958', $result['national']);
    }

    public function testAnalyzeReportsReasonAndExampleForRejectedNumber(): void
    {
        $result = $this->service->analyze('185766336348', 'CN');
        self::assertFalse($result['valid']);
        self::assertSame('too_long', $result['reason']);
        self::assertSame('CN', $result['rule']['country_code'] ?? null);
        self::assertNotSame('', (string)($result['rule']['example'] ?? ''));
    }

    public function testSharedDialCodePrefersSelectedCountry(): void
    {
        $us = $this->service->analyze('+1 416 555 0100', 'US');
        $ca = $this->service->analyze('+1 416 555 0100', 'CA');
        self::assertSame('US', $us['country_code']);
        self::assertSame('CA', $ca['country_code']);
        self::assertTrue($us['valid']);
        self::assertTrue($ca['valid']);
    }

    public function testRuleShapeIsStableForFrontend(): void
    {
        $rule = $this->service->frontendRule('CN');
        self::assertIsArray($rule);
        self::assertSame(
            ['country_code', 'dial_code', 'trunk_prefix', 'national_min', 'national_max', 'national_pattern', 'example'],
            array_keys($rule),
        );
        self::assertSame('86', $rule['dial_code']);
        self::assertNull($this->service->frontendRule('ZZ'));
        self::assertNull($this->service->frontendRule(null));
    }

    public function testEveryConfiguredRuleHasConsistentBounds(): void
    {
        $decoded = json_decode((string)file_get_contents(
            dirname(__DIR__, 3) . '/data/phone-country-rules.json'
        ), true);
        self::assertIsArray($decoded['countries'] ?? null);
        foreach (array_keys($decoded['countries']) as $code) {
            $rule = $this->service->ruleFor((string)$code);
            self::assertIsArray($rule, $code);
            self::assertMatchesRegularExpression('/^\d{1,3}$/', $rule['dial_code'], $code . ' dial_code');
            self::assertGreaterThanOrEqual($rule['national_min'], $rule['national_max'], $code . ' bounds');
            self::assertLessThanOrEqual(15, strlen($rule['dial_code']) + $rule['national_max'], $code . ' exceeds E.164');
            if ($rule['national_pattern'] !== '') {
                self::assertNotFalse(preg_match('/' . $rule['national_pattern'] . '/', ''), $code . ' pattern compiles');
            }
            if ($rule['example'] !== '') {
                self::assertTrue($this->service->isValid($rule['example'], (string)$code), $code . ' example must validate');
            }
        }
    }
}
