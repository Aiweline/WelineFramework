<?php

declare(strict_types=1);

namespace Weline\Tax\Service\RateSync;

use Weline\SystemConfig\Api\ConfigReader;
use Weline\Tax\Api\TaxRateRemoteProviderInterface;
use Weline\Tax\Model\TaxRule;
use Weline\Tax\Service\TaxScopeConfig;

/**
 * Config-driven professional HTTP provider (base_url + optional api_key + mapping JSON).
 *
 * Mapping modes:
 * - vatcomply_eu: same shape as VATcomply /vat_rates
 * - candidates: list of {jurisdiction_key,class_code,rate_bps} (fields configurable)
 *
 * @phpstan-type HttpGetter callable(string,array<string,string>): array{ok:bool,status:int,body:string,error?:string}
 */
final class GenericHttpTaxRateProvider implements TaxRateRemoteProviderInterface
{
    public const CODE = 'generic_http';

    /** @var HttpGetter|null */
    private $httpGet;

    private ?ConfigReader $reader;

    /**
     * @param HttpGetter|null $httpGet
     */
    public function __construct(?ConfigReader $reader = null, ?callable $httpGet = null)
    {
        $this->reader = $reader;
        $this->httpGet = $httpGet;
    }

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return (string)__('通用 HTTP 专业税率源');
    }

    public function tier(): string
    {
        return self::TIER_PROFESSIONAL;
    }

    public function fetchCandidates(int $websiteId): array
    {
        $reader = $this->reader ?? new ConfigReader();
        $baseUrl = trim((string)$reader->get(
            'tax/ratesync/professional/base_url',
            TaxScopeConfig::MODULE,
            TaxScopeConfig::AREA,
            '',
        ));
        if ($baseUrl === '' || !preg_match('#^https?://#i', $baseUrl)) {
            throw new \RuntimeException('generic_http_base_url_invalid');
        }

        $apiKey = trim((string)$reader->get(
            'tax/ratesync/professional/api_key',
            TaxScopeConfig::MODULE,
            TaxScopeConfig::AREA,
            '',
        ));
        $mappingRaw = trim((string)$reader->get(
            'tax/ratesync/professional/mapping',
            TaxScopeConfig::MODULE,
            TaxScopeConfig::AREA,
            '',
        ));
        $mapping = $mappingRaw !== '' ? json_decode($mappingRaw, true) : [];
        if (!is_array($mapping)) {
            throw new \RuntimeException('generic_http_mapping_invalid_json');
        }
        $mode = strtolower(trim((string)($mapping['mode'] ?? 'vatcomply_eu')));

        $headers = ['Accept' => 'application/json', 'User-Agent' => 'Weline-Tax-RateSync/1.0'];
        if ($apiKey !== '') {
            $headerName = trim((string)($mapping['api_key_header'] ?? 'Authorization'));
            $prefix = (string)($mapping['api_key_prefix'] ?? 'Bearer ');
            $headers[$headerName !== '' ? $headerName : 'Authorization'] = $prefix . $apiKey;
        }

        $response = ($this->httpGet ?? [$this, 'defaultHttpGet'])($baseUrl, $headers);
        if (empty($response['ok']) || (int)($response['status'] ?? 0) < 200 || (int)$response['status'] >= 300) {
            $err = trim((string)($response['error'] ?? ('http_' . (int)($response['status'] ?? 0))));
            throw new \RuntimeException('generic_http_fetch_failed:' . ($err !== '' ? $err : 'unknown'));
        }

        $decoded = json_decode((string)($response['body'] ?? ''), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('generic_http_invalid_json');
        }

        return match ($mode) {
            'candidates' => $this->parseCandidates($decoded, $mapping, $websiteId),
            default => $this->parseVatComplyShape($decoded, $websiteId),
        };
    }

    /**
     * @param array<string,mixed> $decoded
     * @param array<string,mixed> $mapping
     * @return list<array{jurisdiction_key:string,class_code:string,rate_bps:int,source:string,source_meta?:array<string,mixed>}>
     */
    private function parseCandidates(array $decoded, array $mapping, int $websiteId): array
    {
        $listPath = trim((string)($mapping['list_path'] ?? ''));
        $rows = $listPath === '' ? $decoded : $this->dig($decoded, $listPath);
        if (!is_array($rows)) {
            return [];
        }
        $jField = (string)($mapping['jurisdiction_field'] ?? 'jurisdiction_key');
        $cField = (string)($mapping['class_field'] ?? 'class_code');
        $rField = (string)($mapping['rate_bps_field'] ?? 'rate_bps');
        $rateIsPercent = !empty($mapping['rate_is_percent']);

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $jurisdiction = strtoupper(trim((string)($row[$jField] ?? '')));
            if ($jurisdiction !== '' && !str_contains($jurisdiction, '|')) {
                $jurisdiction .= '|';
            }
            if (preg_match(TaxRule::JURISDICTION_PATTERN, $jurisdiction) !== 1) {
                continue;
            }
            $class = strtolower(trim((string)($row[$cField] ?? 'standard')));
            if ($class === '') {
                $class = 'standard';
            }
            $rawRate = $row[$rField] ?? null;
            $bps = $rateIsPercent ? $this->percentToBps($rawRate) : $this->intBps($rawRate);
            if ($bps === null) {
                continue;
            }
            $out[] = [
                'jurisdiction_key' => $jurisdiction,
                'class_code' => $class,
                'rate_bps' => $bps,
                'source' => self::CODE,
                'source_meta' => ['website_id' => max(0, $websiteId), 'mode' => 'candidates'],
            ];
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $decoded
     * @return list<array{jurisdiction_key:string,class_code:string,rate_bps:int,source:string,source_meta?:array<string,mixed>}>
     */
    private function parseVatComplyShape(array $decoded, int $websiteId): array
    {
        $adapter = new VatComplyEuTaxRateProvider(static function () use ($decoded): array {
            return [
                'ok' => true,
                'status' => 200,
                'body' => (string)json_encode($decoded, JSON_UNESCAPED_UNICODE),
            ];
        });
        $rows = $adapter->fetchCandidates($websiteId);
        foreach ($rows as &$row) {
            $row['source'] = self::CODE;
            $meta = is_array($row['source_meta'] ?? null) ? $row['source_meta'] : [];
            $meta['via'] = 'vatcomply_eu_mapping';
            $row['source_meta'] = $meta;
        }
        unset($row);

        return $rows;
    }

    /**
     * @param array<string,string> $headers
     * @return array{ok:bool,status:int,body:string,error?:string}
     */
    private function defaultHttpGet(string $url, array $headers): array
    {
        $headerLines = '';
        foreach ($headers as $name => $value) {
            $headerLines .= $name . ': ' . $value . "\r\n";
        }
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 15,
                'header' => $headerLines,
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

    /** @param array<string,mixed> $data */
    private function dig(array $data, string $path): mixed
    {
        $cur = $data;
        foreach (explode('.', $path) as $seg) {
            $seg = trim($seg);
            if ($seg === '' || !is_array($cur) || !array_key_exists($seg, $cur)) {
                return null;
            }
            $cur = $cur[$seg];
        }

        return $cur;
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

    private function intBps(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }
        $bps = (int)$value;
        if ($bps < TaxRule::RATE_BPS_MIN || $bps > TaxRule::RATE_BPS_MAX) {
            return null;
        }

        return $bps;
    }
}
