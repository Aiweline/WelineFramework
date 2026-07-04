<?php

declare(strict_types=1);

namespace Weline\Framework\Http\test;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\GuardHeaders;
use Weline\Framework\Http\RequestInterface;

class GuardHeadersTest extends TestCase
{
    public function testIsIdempotentRetryRecognizesTruthyValues(): void
    {
        foreach (['1', 'true', 'TRUE', 'Yes', 'on'] as $value) {
            $request = new GuardHeadersFakeRequest([GuardHeaders::IDEMPOTENT => $value]);
            $this->assertTrue(GuardHeaders::isIdempotentRetry($request), "value={$value} should be truthy");
        }
    }

    public function testIsIdempotentRetryReturnsFalseForFalsyOrMissing(): void
    {
        foreach (['0', 'false', '', 'no', 'random'] as $value) {
            $request = new GuardHeadersFakeRequest([GuardHeaders::IDEMPOTENT => $value]);
            $this->assertFalse(GuardHeaders::isIdempotentRetry($request), "value={$value} should be falsy");
        }

        $missingRequest = new GuardHeadersFakeRequest([]);
        $this->assertFalse(GuardHeaders::isIdempotentRetry($missingRequest));
    }

    public function testIsCacheBypassReadsCorrectHeader(): void
    {
        $request = new GuardHeadersFakeRequest([GuardHeaders::CACHE_BYPASS => '1']);
        $this->assertTrue(GuardHeaders::isCacheBypass($request));
        $this->assertFalse(GuardHeaders::isIdempotentRetry($request));
    }

    public function testIdempotencyKeyTrimsAndDefaultsToEmpty(): void
    {
        $request = new GuardHeadersFakeRequest([GuardHeaders::IDEMPOTENCY_KEY => '  abc-123  ']);
        $this->assertSame('abc-123', GuardHeaders::getIdempotencyKey($request));

        $missing = new GuardHeadersFakeRequest([]);
        $this->assertSame('', GuardHeaders::getIdempotencyKey($missing));
    }

    public function testWriteCacheStatusCallsResponseSetHeaderWhenAvailable(): void
    {
        $response = new GuardHeadersFakeResponse();
        GuardHeaders::writeCacheStatus($response, GuardHeaders::STATUS_HIT);
        GuardHeaders::writeUrlGuardDecision($response, GuardHeaders::URL_GUARD_REJECTED, 'product_id_max');
        GuardHeaders::writeHotKey($response, true);

        $this->assertSame(GuardHeaders::STATUS_HIT, $response->headers[GuardHeaders::CACHE_STATUS] ?? null);
        $this->assertSame('rejected:product_id_max', $response->headers[GuardHeaders::URL_GUARD] ?? null);
        $this->assertSame('1', $response->headers[GuardHeaders::HOT_KEY] ?? null);
    }

    public function testWriteCacheStatusSilentlyIgnoresResponseWithoutSetHeader(): void
    {
        $response = new GuardHeadersBareResponse();
        GuardHeaders::writeCacheStatus($response, GuardHeaders::STATUS_MISS);
        $this->expectNotToPerformAssertions();
    }
}

final class GuardHeadersFakeRequest implements RequestInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(private array $headers)
    {
    }

    public function getHeader(string $key = ''): array|string|null
    {
        if ($key === '') {
            return $this->headers;
        }

        foreach ($this->headers as $name => $value) {
            if (\strtolower($name) === \strtolower($key)) {
                return $value;
            }
        }
        return null;
    }

    public function getServer()
    {
        return [];
    }

    public function getUri(): string
    {
        return '';
    }

    public function getContentType()
    {
        return '';
    }

    public function getAuth(string $auth_type = self::auth_TYPE_BEARER)
    {
        return null;
    }

    public function getApiKey(string $key): string
    {
        return '';
    }

    public function getParam(string $key)
    {
        return null;
    }

    public function getParams()
    {
        return [];
    }

    public function getBodyParam($key)
    {
        return null;
    }

    public function getBodyParams()
    {
        return [];
    }

    public function isPost(): bool
    {
        return false;
    }

    public function isGet(): bool
    {
        return true;
    }

    public function isPut(): bool
    {
        return false;
    }

    public function isDelete(): bool
    {
        return false;
    }

    public function getMethod(): string
    {
        return 'GET';
    }

    public function getBaseUri(): string
    {
        return '';
    }

    public function getBaseHost(): string
    {
        return '';
    }

    public function getModuleName(): string
    {
        return '';
    }
}

final class GuardHeadersFakeResponse
{
    /** @var array<string, string> */
    public array $headers = [];

    public function setHeader(string $name, string $value): void
    {
        $this->headers[$name] = $value;
    }
}

final class GuardHeadersBareResponse
{
}
