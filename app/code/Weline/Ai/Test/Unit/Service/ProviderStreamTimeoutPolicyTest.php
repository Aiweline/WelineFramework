<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Service\Provider\AnthropicProvider;
use Weline\Ai\Service\Provider\OpenAiProvider;
use Weline\Ai\Service\Provider\ProviderTimeoutPolicy;

class ProviderStreamTimeoutPolicyTest extends TestCase
{
    /**
     * @dataProvider providerClasses
     */
    public function testStreamTimeoutDefaultsToUnlimited(string $providerClass): void
    {
        $provider = new $providerClass();

        $timeout = $this->invokePrivate($provider, 'resolveStreamTimeout', [
            [],
            ['timeout' => ProviderTimeoutPolicy::DEFAULT_REQUEST_TIMEOUT],
        ]);

        $this->assertSame(0, $timeout);
    }

    /**
     * @dataProvider providerClasses
     */
    public function testStreamTimeoutCanStillBeExplicitlyEnforced(string $providerClass): void
    {
        $provider = new $providerClass();

        $explicitTimeout = $this->invokePrivate($provider, 'resolveStreamTimeout', [
            ['enforce_timeout_in_stream' => true, 'timeout' => 120],
            ['timeout' => ProviderTimeoutPolicy::DEFAULT_REQUEST_TIMEOUT],
        ]);
        $configTimeout = $this->invokePrivate($provider, 'resolveStreamTimeout', [
            ['enforce_timeout_in_stream' => true],
            ['timeout' => 90],
        ]);

        $this->assertSame(120, $explicitTimeout);
        $this->assertSame(90, $configTimeout);
    }

    /**
     * @dataProvider providerClasses
     */
    public function testPageBuilderCanDisableProviderTimeout(string $providerClass): void
    {
        $provider = new $providerClass();

        $timeout = $this->invokePrivate($provider, 'resolveStreamTimeout', [
            ['disable_ai_timeout' => true, 'enforce_timeout_in_stream' => true],
            ['timeout' => ProviderTimeoutPolicy::DEFAULT_REQUEST_TIMEOUT],
        ]);

        $this->assertSame(0, $timeout);
    }

    /**
     * @dataProvider timeoutOptionCases
     */
    public function testBuildCurlTimeoutOptionsPreservesStreamAndNonStreamPolicies(
        string $providerClass,
        array $expectedStreamOptions,
        array $expectedNonStreamZeroOptions,
        array $expectedNonStreamPositiveOptions
    ): void {
        $provider = new $providerClass();

        $this->assertSame(
            $expectedStreamOptions,
            $this->invokePrivate($provider, 'buildCurlTimeoutOptions', [ProviderTimeoutPolicy::DEFAULT_REQUEST_TIMEOUT, true])
        );
        $this->assertSame(
            $expectedNonStreamZeroOptions,
            $this->invokePrivate($provider, 'buildCurlTimeoutOptions', [0, false])
        );
        $this->assertSame(
            $expectedNonStreamPositiveOptions,
            $this->invokePrivate($provider, 'buildCurlTimeoutOptions', [45, false])
        );
    }

    public static function providerClasses(): array
    {
        return [
            'openai' => [OpenAiProvider::class],
            'anthropic' => [AnthropicProvider::class],
        ];
    }

    public static function timeoutOptionCases(): array
    {
        return [
            'openai' => [
                OpenAiProvider::class,
                [
                    CURLOPT_TIMEOUT => 0,
                    CURLOPT_CONNECTTIMEOUT => 60,
                    CURLOPT_LOW_SPEED_LIMIT => 0,
                    CURLOPT_LOW_SPEED_TIME => 0,
                ],
                [
                    CURLOPT_TIMEOUT => 0,
                    CURLOPT_CONNECTTIMEOUT => 60,
                    CURLOPT_LOW_SPEED_LIMIT => 1,
                    CURLOPT_LOW_SPEED_TIME => 120,
                ],
                [
                    CURLOPT_TIMEOUT => 45,
                    CURLOPT_CONNECTTIMEOUT => 45,
                ],
            ],
            'anthropic' => [
                AnthropicProvider::class,
                [
                    CURLOPT_TIMEOUT => 0,
                    CURLOPT_CONNECTTIMEOUT => 60,
                ],
                [
                    CURLOPT_TIMEOUT => 0,
                    CURLOPT_CONNECTTIMEOUT => 60,
                ],
                [
                    CURLOPT_TIMEOUT => 45,
                    CURLOPT_CONNECTTIMEOUT => 45,
                ],
            ],
        ];
    }

    /**
     * @dataProvider providerClasses
     */
    public function testRequestTimeoutFallsBackToUnifiedDefault(string $providerClass): void
    {
        $timeout = ProviderTimeoutPolicy::resolveRequestTimeout([], []);

        $this->assertSame(ProviderTimeoutPolicy::DEFAULT_REQUEST_TIMEOUT, $timeout);
        $this->assertTrue(class_exists($providerClass));
    }

    private function invokePrivate(object $object, string $method, array $arguments)
    {
        $reflectionMethod = new \ReflectionMethod($object, $method);
        $reflectionMethod->setAccessible(true);

        return $reflectionMethod->invokeArgs($object, $arguments);
    }
}
