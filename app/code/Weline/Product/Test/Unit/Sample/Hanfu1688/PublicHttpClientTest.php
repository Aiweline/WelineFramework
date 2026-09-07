<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Sample\Hanfu1688;

use PHPUnit\Framework\TestCase;
use Weline\Product\Sample\Hanfu1688\PublicHttpClient;

final class PublicHttpClientTest extends TestCase
{
    public function testSignedFactoryPaginationUsesAnonymousPublicToken(): void
    {
        $urls = [];
        $transport = static function (string $url, array $headers) use (&$urls): array {
            $urls[] = ['url' => $url, 'headers' => $headers];
            parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
            if (!isset($query['sign'])) {
                return [
                    'status' => 200,
                    'headers' => ['set-cookie' => ['_m_h5_tk=publictoken_1893456000000; Path=/; Secure']],
                    'body' => json_encode(['ret' => ['FAIL_SYS_TOKEN_EMPTY::令牌为空'], 'data' => []]),
                ];
            }
            $expected = md5('publictoken&' . $query['t'] . '&12574478&' . $query['data']);
            TestCase::assertSame($expected, $query['sign']);
            TestCase::assertNotEmpty(array_filter(
                $headers,
                static fn(string $header): bool => str_starts_with($header, 'Cookie: _m_h5_tk='),
            ));
            return [
                'status' => 200,
                'headers' => [],
                'body' => json_encode([
                    'ret' => ['SUCCESS::调用成功'],
                    'data' => ['total' => '1', 'page' => '2', 'pageSize' => '20', 'data' => []],
                ]),
            ];
        };

        $response = (new PublicHttpClient($transport, static fn(int $microseconds): null => null, 0))
            ->factoryOffers('b2b-123', 2, 20);

        self::assertSame('1', $response['data']['total']);
        self::assertCount(2, $urls);
    }

    public function testSignedOfferDetailUsesOfficialApiAndRequiredUseCase(): void
    {
        $signedQuery = [];
        $sleeps = [];
        $transport = static function (string $url, array $headers) use (&$signedQuery): array {
            parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
            if (!isset($query['sign'])) {
                return [
                    'status' => 200,
                    'headers' => ['set-cookie' => ['_m_h5_tk=detailtoken_1893456000000; Path=/; Secure']],
                    'body' => json_encode(['ret' => ['FAIL_SYS_TOKEN_EMPTY::令牌为空'], 'data' => []]),
                ];
            }
            $signedQuery = $query;
            TestCase::assertSame(
                md5('detailtoken&' . $query['t'] . '&12574478&' . $query['data']),
                $query['sign'],
            );
            return [
                'status' => 200,
                'headers' => [],
                'body' => json_encode([
                    'ret' => ['SUCCESS::调用成功'],
                    'data' => ['tempModel' => ['offerId' => '1002105110427']],
                ]),
            ];
        };
        $client = new PublicHttpClient(
            $transport,
            static function (int $microseconds) use (&$sleeps): void {
                $sleeps[] = $microseconds;
            },
            0,
            8_388_608,
            25,
        );

        $response = $client->offerDetail('1002105110427');

        self::assertSame(
            'mtop.1688.wosc.queryWirelessOfferDetail',
            $signedQuery['api'],
        );
        self::assertSame('1.0', $signedQuery['v']);
        self::assertSame([
            'offerId' => '1002105110427',
            'useCase' => 'default',
        ], json_decode($signedQuery['data'], true, 512, JSON_THROW_ON_ERROR));
        self::assertContains(25_000, $sleeps);
        self::assertSame('1002105110427', $response['data']['tempModel']['offerId']);
    }

    public function testOfferDetailFailsClosedOnPublicValidationChallenge(): void
    {
        $transport = static function (string $url, array $headers): array {
            parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
            if (!isset($query['sign'])) {
                return [
                    'status' => 200,
                    'headers' => ['set-cookie' => ['_m_h5_tk=detailtoken_1893456000000; Path=/; Secure']],
                    'body' => json_encode(['ret' => ['FAIL_SYS_TOKEN_EMPTY::令牌为空'], 'data' => []]),
                ];
            }
            return [
                'status' => 200,
                'headers' => [],
                'body' => json_encode(['ret' => ['FAIL_SYS_USER_VALIDATE::RGV587'], 'data' => []]),
            ];
        };

        $this->expectExceptionMessage('hanfu_1688_mtop_detail_validation_required');
        (new PublicHttpClient(
            $transport,
            static fn(int $microseconds): null => null,
            0,
            8_388_608,
            0,
        ))->offerDetail('1002105110427');
    }

    public function testPublicDocumentRequestSendsStandardBrowserUserAgent(): void
    {
        $capturedHeaders = [];
        $transport = static function (string $url, array $headers) use (&$capturedHeaders): array {
            $capturedHeaders = $headers;
            return ['status' => 200, 'headers' => [], 'body' => '<html>public offer</html>'];
        };

        $body = (new PublicHttpClient($transport, static fn(int $microseconds): null => null, 0))
            ->get('https://detail.1688.com/offer/123456.html?forcePC=1');

        self::assertSame('<html>public offer</html>', $body);
        self::assertNotEmpty(array_filter(
            $capturedHeaders,
            static fn(string $header): bool => str_starts_with($header, 'User-Agent: Mozilla/5.0 '),
        ));
    }

    public function testUntrustedHostIsRejectedBeforeTransport(): void
    {
        $this->expectExceptionMessage('hanfu_1688_http_host_forbidden');
        (new PublicHttpClient(static fn(): array => [], static fn(int $microseconds): null => null, 0))
            ->get('https://example.com/offer');
    }
}
