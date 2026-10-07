<?php

declare(strict_types=1);

namespace Weline\Product\Sample\Hanfu1688;

final class PublicHttpClient
{
    private const APP_KEY = '12574478';
    private const FACTORY_API = 'mtop.com.alibaba.china.factory.card.common.fn.mtop.tpp.faas';
    private const FACTORY_API_VERSION = '1.0';
    private const FACTORY_ENDPOINT = 'https://h5api.m.1688.com/h5/mtop.com.alibaba.china.factory.card.common.fn.mtop.tpp.faas/1.0/';

    /** @var array<string,string> */
    private array $cookies = [];
    private float $lastRequestAt = 0.0;
    private readonly mixed $transport;
    private readonly mixed $sleeper;

    public function __construct(
        ?callable $transport = null,
        ?callable $sleeper = null,
        private readonly int $minimumDelayMilliseconds = 800,
        private readonly int $maximumResponseBytes = 8_388_608,
        private readonly int $detailDelayMilliseconds = 3_000,
    ) {
        if ($minimumDelayMilliseconds < 0
            || $maximumResponseBytes < 1024
            || $detailDelayMilliseconds < 0
        ) {
            throw new \InvalidArgumentException('hanfu_1688_http_config_invalid');
        }
        $this->transport = $transport;
        $this->sleeper = $sleeper ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
    }

    public function get(string $url): string
    {
        return $this->request($url, false);
    }

    /**
     * @return array<string, mixed>
     */
    public function offerDetail(string $offerId): array
    {
        $offerId = trim($offerId);
        if (preg_match('/^[1-9][0-9]{5,20}$/D', $offerId) !== 1) {
            throw new \InvalidArgumentException('hanfu_1688_offer_detail_request_invalid');
        }

        $api = 'mtop.1688.wosc.queryWirelessOfferDetail';
        $version = '1.0';
        $endpoint = 'https://h5api.m.1688.com/h5/mtop.1688.wosc.querywirelessofferdetail/1.0/';
        $data = json_encode([
            'offerId' => $offerId,
            'useCase' => 'default',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $token = $this->anonymousToken();
        if ($token === null) {
            $bootstrap = $this->mtopRequest($data, null, null, $api, $version, $endpoint);
            if ($this->isSuccess($bootstrap)) {
                return $bootstrap;
            }
            $token = $this->anonymousToken();
        }
        if ($token === null) {
            throw new \RuntimeException('hanfu_1688_mtop_detail_token_missing');
        }

        if ($this->detailDelayMilliseconds > 0) {
            ($this->sleeper)($this->detailDelayMilliseconds * 1_000);
        }
        $timestamp = (string)(int)round(microtime(true) * 1000);
        $sign = md5($token . '&' . $timestamp . '&' . self::APP_KEY . '&' . $data);
        $response = $this->mtopRequest($data, $timestamp, $sign, $api, $version, $endpoint);

        if (!$this->isSuccess($response) && $this->isTokenFailure($response)) {
            unset($this->cookies['_m_h5_tk'], $this->cookies['_m_h5_tk_enc']);
            $this->mtopRequest($data, null, null, $api, $version, $endpoint);
            $token = $this->anonymousToken();
            if ($token === null) {
                throw new \RuntimeException('hanfu_1688_mtop_detail_token_refresh_failed');
            }
            if ($this->detailDelayMilliseconds > 0) {
                ($this->sleeper)($this->detailDelayMilliseconds * 1_000);
            }
            $timestamp = (string)(int)round(microtime(true) * 1000);
            $sign = md5($token . '&' . $timestamp . '&' . self::APP_KEY . '&' . $data);
            $response = $this->mtopRequest($data, $timestamp, $sign, $api, $version, $endpoint);
        }

        if (!$this->isSuccess($response)) {
            $ret = implode('|', array_map(
                'strval',
                is_array($response['ret'] ?? null) ? $response['ret'] : [],
            ));
            if (str_contains($ret, 'RGV')
                || str_contains($ret, 'USER_VALIDATE')
                || str_contains($ret, '验证')
            ) {
                throw new \RuntimeException('hanfu_1688_mtop_detail_validation_required');
            }
            throw new \RuntimeException('hanfu_1688_mtop_detail_request_failed');
        }

        return $response;
    }

    public function getMedia(string $url): string
    {
        return $this->request($url, true);
    }

    /** @return array<string,mixed> */
    public function getJson(string $url): array
    {
        $decoded = json_decode($this->get($url), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('hanfu_1688_http_json_invalid');
        }
        return $decoded;
    }

    /** @return array<string,mixed> */
    public function factoryOffers(string $memberId, int $page, int $pageSize = 20): array
    {
        $memberId = strtolower(trim($memberId));
        if (preg_match('/^b2b-[a-z0-9-]{3,80}$/D', $memberId) !== 1
            || $page < 1
            || $pageSize < 1
            || $pageSize > 100
        ) {
            throw new \InvalidArgumentException('hanfu_1688_factory_page_request_invalid');
        }
        $params = json_encode([
            'extParam' => [
                'page' => $page,
                'pageSize' => $pageSize,
                'offerType' => 'all',
                'isReturnSingleMedia' => 'Y',
                'stickyOfferIds' => '',
                'sceneSource' => '',
                'factoryMemberId' => $memberId,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $data = json_encode([
            'serviceName' => 'recommendItemService',
            'params' => $params,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $token = $this->anonymousToken();
        if ($token === null) {
            $bootstrap = $this->mtopRequest($data, null, null);
            if ($this->isSuccess($bootstrap)) {
                return $bootstrap;
            }
            $token = $this->anonymousToken();
        }
        if ($token === null) {
            throw new \RuntimeException('hanfu_1688_mtop_token_missing');
        }

        $timestamp = (string)(int)round(microtime(true) * 1000);
        $sign = md5($token . '&' . $timestamp . '&' . self::APP_KEY . '&' . $data);
        $response = $this->mtopRequest($data, $timestamp, $sign);
        if (!$this->isSuccess($response) && $this->isTokenFailure($response)) {
            unset($this->cookies['_m_h5_tk'], $this->cookies['_m_h5_tk_enc']);
            $this->mtopRequest($data, null, null);
            $token = $this->anonymousToken();
            if ($token === null) {
                throw new \RuntimeException('hanfu_1688_mtop_token_refresh_failed');
            }
            $timestamp = (string)(int)round(microtime(true) * 1000);
            $sign = md5($token . '&' . $timestamp . '&' . self::APP_KEY . '&' . $data);
            $response = $this->mtopRequest($data, $timestamp, $sign);
        }
        if (!$this->isSuccess($response)) {
            throw new \RuntimeException('hanfu_1688_mtop_request_failed');
        }
        return $response;
    }

    public function factoryEvidenceUrl(string $memberId, int $page, int $pageSize = 20): string
    {
        return self::FACTORY_ENDPOINT . '?' . http_build_query([
            'serviceName' => 'recommendItemService',
            'memberId' => $memberId,
            'page' => $page,
            'pageSize' => $pageSize,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array<string,mixed> */
    private function mtopRequest(
        string $data,
        ?string $timestamp,
        ?string $sign,
        string $api = self::FACTORY_API,
        string $version = self::FACTORY_API_VERSION,
        string $endpoint = self::FACTORY_ENDPOINT,
    ): array {
        $query = [
            'jsv' => '2.7.0',
            'appKey' => self::APP_KEY,
            'api' => $api,
            'v' => $version,
            'type' => 'json',
            'dataType' => 'json',
            'data' => $data,
        ];
        if ($timestamp !== null && $sign !== null) {
            $query['t'] = $timestamp;
            $query['sign'] = $sign;
        }
        $url = $endpoint . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $decoded = json_decode($this->request($url, false), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('hanfu_1688_mtop_json_invalid');
        }
        return $decoded;
    }

    private function request(string $url, bool $media): string
    {
        $redirects = 0;
        while (true) {
            $this->assertUrl($url, $media);
            $this->rateLimit();
            $headers = [
                'Accept: ' . ($media ? 'image/avif,image/webp,image/jpeg,image/png,*/*;q=0.1' : 'text/html,application/json;q=0.9,*/*;q=0.1'),
                'Accept-Language: zh-CN,zh;q=0.9',
                'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) '
                    . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
            ];
            if ($this->cookies !== []) {
                $pairs = [];
                foreach ($this->cookies as $name => $value) {
                    $pairs[] = $name . '=' . $value;
                }
                $headers[] = 'Cookie: ' . implode('; ', $pairs);
            }
            $response = is_callable($this->transport)
                ? ($this->transport)($url, $headers)
                : $this->nativeRequest($url, $headers);
            if (!is_array($response)) {
                throw new \RuntimeException('hanfu_1688_http_transport_invalid');
            }
            $status = (int)($response['status'] ?? 0);
            $responseHeaders = is_array($response['headers'] ?? null) ? $response['headers'] : [];
            $this->captureCookies($responseHeaders);
            if ($status >= 300 && $status < 400) {
                if (++$redirects > 5) {
                    throw new \RuntimeException('hanfu_1688_http_redirect_limit');
                }
                $location = $this->firstHeader($responseHeaders, 'location');
                if ($location === null) {
                    throw new \RuntimeException('hanfu_1688_http_redirect_missing');
                }
                $url = $this->resolveRedirect($url, $location);
                continue;
            }
            if ($status !== 200) {
                throw new \RuntimeException('hanfu_1688_http_status_' . $status);
            }
            $body = $response['body'] ?? null;
            if (!is_string($body) || strlen($body) > $this->maximumResponseBytes) {
                throw new \RuntimeException('hanfu_1688_http_response_invalid');
            }
            return $body;
        }
    }

    /** @param list<string> $headers @return array{status:int,headers:array<string,list<string>>,body:string} */
    private function nativeRequest(string $url, array $headers): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('hanfu_1688_http_curl_missing');
        }
        $responseHeaders = [];
        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('hanfu_1688_http_init_failed');
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_ENCODING => '',
            // The local runtime may expose a stale proxy route for 1688. Keep
            // public source reads on the direct network path, matching the
            // controlled curl transport used by the browser-assisted runs.
            CURLOPT_NOPROXY => '*',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $position = strpos($line, ':');
                if ($position !== false) {
                    $name = strtolower(trim(substr($line, 0, $position)));
                    $value = trim(substr($line, $position + 1));
                    $responseHeaders[$name][] = $value;
                }
                return $length;
            },
        ]);
        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if (!is_string($body)) {
            throw new \RuntimeException('hanfu_1688_http_transport_failed:' . $error);
        }
        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $body];
    }

    private function rateLimit(): void
    {
        if ($this->minimumDelayMilliseconds <= 0) {
            $this->lastRequestAt = microtime(true);
            return;
        }
        $now = microtime(true);
        if ($this->lastRequestAt > 0) {
            $remaining = ($this->minimumDelayMilliseconds / 1000) - ($now - $this->lastRequestAt);
            if ($remaining > 0) {
                ($this->sleeper)((int)ceil($remaining * 1_000_000));
            }
        }
        $this->lastRequestAt = microtime(true);
    }

    private function assertUrl(string $url, bool $media): void
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && (int)$parts['port'] !== 443)
        ) {
            throw new \InvalidArgumentException('hanfu_1688_http_url_invalid');
        }
        $host = strtolower(rtrim((string)($parts['host'] ?? ''), '.'));
        $pageHost = $host === '1688.com' || str_ends_with($host, '.1688.com');
        $descriptionHost = in_array($host, ['itemcdn.tmall.com', 'desc.alicdn.com'], true);
        $documentHost = $pageHost || $descriptionHost;
        $mediaHost = $pageHost || str_ends_with($host, '.alicdn.com') || str_ends_with($host, '.tbcdn.cn');
        if ((!$media && !$documentHost) || ($media && !$mediaHost) || filter_var($host, FILTER_VALIDATE_IP)) {
            throw new \InvalidArgumentException('hanfu_1688_http_host_forbidden');
        }
        if (!is_callable($this->transport)) {
            $addresses = gethostbynamel($host);
            if (!is_array($addresses) || $addresses === []) {
                throw new \RuntimeException('hanfu_1688_http_dns_failed');
            }
            foreach ($addresses as $address) {
                if (filter_var(
                    $address,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
                ) === false) {
                    throw new \RuntimeException('hanfu_1688_http_private_address_forbidden');
                }
            }
        }
    }

    /** @param array<string,mixed> $headers */
    private function captureCookies(array $headers): void
    {
        foreach ($headers as $name => $values) {
            if (strtolower((string)$name) !== 'set-cookie') {
                continue;
            }
            foreach (is_array($values) ? $values : [$values] as $line) {
                $pair = trim(explode(';', (string)$line, 2)[0]);
                $position = strpos($pair, '=');
                if ($position === false) {
                    continue;
                }
                $cookieName = trim(substr($pair, 0, $position));
                $cookieValue = trim(substr($pair, $position + 1));
                if (in_array($cookieName, ['_m_h5_tk', '_m_h5_tk_enc'], true) && $cookieValue !== '') {
                    $this->cookies[$cookieName] = $cookieValue;
                }
            }
        }
    }

    /** @param array<string,mixed> $headers */
    private function firstHeader(array $headers, string $name): ?string
    {
        foreach ($headers as $headerName => $values) {
            if (strtolower((string)$headerName) !== strtolower($name)) {
                continue;
            }
            $value = is_array($values) ? ($values[0] ?? null) : $values;
            return is_string($value) && trim($value) !== '' ? trim($value) : null;
        }
        return null;
    }

    private function resolveRedirect(string $baseUrl, string $location): string
    {
        if (str_starts_with($location, 'https://')) {
            return $location;
        }
        if (str_starts_with($location, '//')) {
            return 'https:' . $location;
        }
        $parts = parse_url($baseUrl);
        if (!is_array($parts)) {
            throw new \RuntimeException('hanfu_1688_http_redirect_invalid');
        }
        $origin = 'https://' . $parts['host'];
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $directory = rtrim(dirname((string)($parts['path'] ?? '/')), '/');
        return $origin . ($directory !== '' ? $directory : '') . '/' . $location;
    }

    private function anonymousToken(): ?string
    {
        $cookie = $this->cookies['_m_h5_tk'] ?? '';
        $token = explode('_', $cookie, 2)[0];
        return $token !== '' ? $token : null;
    }

    /** @param array<string,mixed> $response */
    private function isSuccess(array $response): bool
    {
        foreach (($response['ret'] ?? []) as $ret) {
            if (str_starts_with((string)$ret, 'SUCCESS::')) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $response */
    private function isTokenFailure(array $response): bool
    {
        $ret = implode('|', array_map('strval', is_array($response['ret'] ?? null) ? $response['ret'] : []));
        return str_contains($ret, 'TOKEN') || str_contains($ret, '令牌');
    }
}
