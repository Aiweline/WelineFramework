<?php

declare(strict_types=1);

namespace Weline\AutoLeadAgent\Test\Unit\Setup;

use PHPUnit\Framework\TestCase;
use Weline\AutoLeadAgent\Model\TargetWebsite;
use Weline\AutoLeadAgent\Setup\Install;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Setup\Data\Context;

final class TargetWebsiteSeedTimestampTest extends TestCase
{
    public function testDefaultTargetWebsiteSeedSetsRequiredTimestamps(): void
    {
        if (!class_exists(\Symfony\Component\Intl\Locales::class)) {
            class_alias(SymfonyIntlLocalesStub::class, \Symfony\Component\Intl\Locales::class);
        }

        $savedRows = [];
        $currentRow = [];
        $hasCheckedExistingRows = false;

        $targetWebsite = $this->getMockBuilder(TargetWebsite::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['clear', 'count', 'setData', 'save'])
            ->getMock();
        $targetWebsite->method('clear')->willReturnCallback(
            function () use ($targetWebsite, &$currentRow, &$hasCheckedExistingRows): TargetWebsite {
                if ($hasCheckedExistingRows) {
                    $currentRow = [];
                }
                $hasCheckedExistingRows = true;

                return $targetWebsite;
            }
        );
        $targetWebsite->method('count')->willReturn(0);
        $targetWebsite->method('setData')->willReturnCallback(
            function (string $key, mixed $value) use ($targetWebsite, &$currentRow): TargetWebsite {
                $currentRow[$key] = $value;

                return $targetWebsite;
            }
        );
        $targetWebsite->method('save')->willReturnCallback(
            function () use (&$savedRows, &$currentRow): bool {
                $savedRows[] = $currentRow;

                return true;
            }
        );

        $printer = $this->getMockBuilder(Printing::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setup', 'success'])
            ->getMock();
        $context = $this->getMockBuilder(Context::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPrinter'])
            ->getMock();
        $context->method('getPrinter')->willReturn($printer);

        $method = new \ReflectionMethod(Install::class, 'initDefaultTargetWebsites');
        $method->setAccessible(true);
        $method->invoke(new Install(), $targetWebsite, $context);

        $this->assertSame('created_at', TargetWebsite::schema_fields_CREATED_AT);
        $this->assertSame('updated_at', TargetWebsite::schema_fields_UPDATED_AT);
        $this->assertCount(10, $savedRows);

        foreach ($savedRows as $row) {
            $this->assertArrayHasKey(TargetWebsite::schema_fields_CREATED_AT, $row);
            $this->assertArrayHasKey(TargetWebsite::schema_fields_UPDATED_AT, $row);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $row[TargetWebsite::schema_fields_CREATED_AT]);
            $this->assertSame($row[TargetWebsite::schema_fields_CREATED_AT], $row[TargetWebsite::schema_fields_UPDATED_AT]);
        }
    }
}

final class SymfonyIntlLocalesStub
{
    public static function getLocales(): array
    {
        return ['zh_Hans_CN', 'en_US'];
    }

    public static function getNames(string $displayLocale = 'en'): array
    {
        return [
            'zh_Hans_CN' => 'Chinese (Simplified, China)',
            'en_US' => 'English (United States)',
        ];
    }

    public static function exists(string $locale): bool
    {
        return in_array($locale, self::getLocales(), true);
    }

    public static function getName(string $locale, string $displayLocale = 'en'): string
    {
        return self::getNames($displayLocale)[$locale] ?? $locale;
    }
}
