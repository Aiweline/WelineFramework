<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Service {
    if (!\function_exists(__NAMESPACE__ . '\\__')) {
        /**
         * @param mixed $text
         */
        function __($text, mixed ...$args): string
        {
            return \is_string($text) ? $text : (string)$text;
        }
    }
}

namespace Weline\SystemConfig\Test\Unit\Service {

use PHPUnit\Framework\TestCase;
use Weline\SystemConfig\Service\SystemConfigCenterService;

/**
 * Multiselect / JSON list posts must validate each option, not (string)$array === "Array".
 */
final class SystemConfigMultiselectInOptionsContractTest extends TestCase
{
    public function testInOptionsAcceptsMultiselectArrayWithinOptions(): void
    {
        $method = $this->validateMethod();
        $service = $this->service();

        $error = $method->invoke($service, ['USD', 'EUR'], [
            'type' => 'multiselect',
            'value-type' => 'json',
            'options' => 'USD:USD,EUR:EUR,GBP:GBP',
            'validation' => 'required|in_options',
        ]);

        self::assertSame('', $error);
    }

    public function testInOptionsRejectsUnknownMultiselectItem(): void
    {
        $method = $this->validateMethod();
        $service = $this->service();

        $error = $method->invoke($service, ['USD', 'CNY'], [
            'type' => 'multiselect',
            'value-type' => 'json',
            'options' => 'USD:USD,EUR:EUR',
            'validation' => 'in_options',
        ]);

        self::assertNotSame('', $error);
    }

    public function testInOptionsNoLongerFailsOnArrayCastToStringArray(): void
    {
        $method = $this->validateMethod();
        $service = $this->service();

        // Regression: casting list to string became "Array" and always failed in_options.
        $error = $method->invoke($service, ['USD'], [
            'type' => 'multiselect',
            'options' => 'USD:USD,EUR:EUR',
            'validation' => 'in_options',
        ]);

        self::assertSame('', $error);
        self::assertStringContainsString('validationCandidateValues', (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/SystemConfigCenterService.php'
        ));
    }

    public function testRequiredRejectsEmptyMultiselectArray(): void
    {
        $method = $this->validateMethod();
        $service = $this->service();

        $error = $method->invoke($service, [], [
            'type' => 'multiselect',
            'options' => 'USD:USD',
            'validation' => 'required|in_options',
        ]);

        self::assertNotSame('', $error);
    }

    private function service(): SystemConfigCenterService
    {
        return (new \ReflectionClass(SystemConfigCenterService::class))->newInstanceWithoutConstructor();
    }

    private function validateMethod(): \ReflectionMethod
    {
        $method = new \ReflectionMethod(SystemConfigCenterService::class, 'validateFieldValue');
        $method->setAccessible(true);

        return $method;
    }
}

}
