<?php

declare(strict_types=1);

namespace Weline\Tax\Service\RateSync;

use Weline\Tax\Api\TaxRateRemoteProviderInterface;
use Weline\Tax\Model\TaxRule;

/**
 * Free EU VAT rates from VATcomply (TEDB). Fail-soft on network errors.
 *
 * @phpstan-type HttpGetter callable(string): array{ok:bool,status:int,body:string,error?:string}
 */
final class VatComplyEuTaxRateProvider implements TaxRateRemoteProviderInterface
{
    public const CODE = 'vatcomply';
    public const ENDPOINT = 'https://api.vatcomply.com/vat_rates';

    /** @var HttpGetter|null */
    private $httpGet;

    /**
     * @param HttpGetter|null $httpGet Test seam; defaults to file_get_contents wrapper.
     */
    public function __construct(?callable $httpGet = null)
    {
        $this->httpGet = $httpGet;
    }

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return (string)__('VATcomply 欧盟 VAT（免费）');
    }

    public function tier(): string
    {
        return self::TIER_FREE;
    }

    public function fetchCandidates(int $websiteId): array
    {
        $response = ($this->httpGet ?? [$this, 'defaultHttpGet'])(self::ENDPOINT);
        if (empty($response['ok']) || (int)($response['status'] ?? 0) < 200 || (int)$response['status'] >= 300) {
            $err = trim((string)($response['error'] ?? ('http_' . (int)($response['status'] ?? 0))));
            throw new \RuntimeException('vatcomply_fetch_failed:' . ($err !== '' ? $err : 'unknown'));
        }

        $decoded = json_decode((string)($response['body'] ?? ''), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('vatcomply_invalid_json');
        }

        $rows = $decoded;
        if (isset($decoded['rates']) && is_array($decoded['rates'])) {
            $rows = $decoded['rates'];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $country = strtoupper(trim((string)($row['country_code'] ?? '')));
            if ($country === 'EL') {
                $country = 'GR';
            }
            if (!preg_match('/^[A-Z]{2}$/', $country)) {
                continue;
            }
            $jurisdiction = $country . '|';
            if (preg_match(TaxRule::JURISDICTION_PATTERN, $jurisdiction) !== 1) {
                continue;
            }

            $standard = $this->percentToBps($row['standard_rate'] ?? null);
            if ($standard !== null) {
                $out[] = [
                    'jurisdiction_key' => $jurisdiction,
                    'class_code' => 'standard',
                    'rate_bps' => $standard,
                    'source' => self::CODE,
                    'source_meta' => [
                        'website_id' => max(0, $websiteId),
                        'country_name' => (string)($row['country_name'] ?? ''),
                    ],
                ];
            }

            $reducedBps = $this->maxReducedBps($row['reduced_rates'] ?? null);
            if ($reducedBps !== null) {
                $out[] = [
                    'jurisdiction_key' => $jurisdiction,
                    'class_code' => 'reduced',
                    'rate_bps' => $reducedBps,
                    'source' => self::CODE,
                    'source_meta' => [
                        'website_id' => max(0, $websiteId),
                        'conservative' => 'max_reduced',
                    ],
                ];
            }
        }

        return $out;
    }

    /** @return array{ok:bool,status:int,body:string,error?:string} */
    private function defaultHttpGet(string $url): array
    {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 12,
                'header' => "Accept: application/json\r\nUser-Agent: Weline-Tax-RateSync/1.0\r\n",
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        $status = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (preg_match('/^HTTP\/\S+\s+(\d{3})/', (string)$line, $m)) {
                    $status = (int)$m[1];
                    break;
                }
            }
        }
        if ($body === false) {
            return ['ok' => false, 'status' => $status, 'body' => '', 'error' => 'transport_failed'];
        }

        return ['ok' => true, 'status' => $status > 0 ? $status : 200, 'body' => (string)$body];
    }

    private function percentToBps(mixed $percent): ?int
    {
        if (!is_numeric($percent)) {
            return null;
        }
        $bps = (int)round(((float)$percent) * 100);
        if ($bps < TaxRule::RATE_BPS_MIN || $bps > TaxRule::RATE_BPS_MAX) {
            return null;
        }

        return $bps;
    }

    private function maxReducedBps(mixed $reducedRates): ?int
    {
        if (!is_array($reducedRates) || $reducedRates === []) {
            return null;
        }
        $max = null;
        foreach ($reducedRates as $rate) {
            $bps = $this->percentToBps($rate);
            if ($bps === null) {
                continue;
            }
            $max = $max === null ? $bps : max($max, $bps);
        }

        return $max;
    }
}
