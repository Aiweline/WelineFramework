<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Health;

/**
 * 单 URL HTTP 探测：状态码、重定向链、耗时、错误类型、截断后的 HTML。
 *
 * 默认用 Googlebot UA + 64KB 截断 GET，既能拿到 title/noindex/canonical，
 * 又不会把整页大图读进内存。
 */
final class HttpProbe
{
    public const DEFAULT_USER_AGENT = 'Googlebot/2.1 (+http://www.google.com/bot.html)';

    /**
     * @param array{
     *   timeout?:int, connect_timeout?:int, user_agent?:string, method?:string,
     *   max_redirects?:int, cache_bust?:string, max_body_bytes?:int,
     *   allow_insecure?:bool, headers?:array<string,string>
     * } $options
     * @return array{
     *   ok:bool, status:int, headers:array<string,string>, body:string,
     *   final_url:string, requested_url:string, redirects:list<array{status:int,location:string}>,
     *   redirect_loop:bool, error:string, error_type:string, duration_ms:int, content_type:string
     * }
     */
    public function probe(string $url, array $options = []): array
    {
        $url = trim($url);
        if (!preg_match('#^https?://#i', $url)) {
            return $this->failed($url, 'invalid url', 'invalid');
        }

        $timeout = max(1, (int)($options['timeout'] ?? 5));
        $connectTimeout = max(1, (int)($options['connect_timeout'] ?? 3));
        $method = strtoupper((string)($options['method'] ?? 'GET')) === 'HEAD' ? 'HEAD' : 'GET';
        $maxRedirects = max(0, (int)($options['max_redirects'] ?? 5));
        $maxBody = max(1024, (int)($options['max_body_bytes'] ?? 65536));
        $userAgent = (string)($options['user_agent'] ?? self::DEFAULT_USER_AGENT);
        $cacheBust = trim((string)($options['cache_bust'] ?? ''));
        $allowInsecure = (bool)($options['allow_insecure'] ?? false);
        $extraHeaders = is_array($options['headers'] ?? null) ? $options['headers'] : [];

        $requestedUrl = $url;
        if ($cacheBust !== '') {
            $url = UrlHealthKey::withProbeParam($url, $cacheBust);
        }

        $headers = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
        ];
        if ($cacheBust !== '') {
            $headers[] = 'X-Weline-Seo-Probe: ' . $cacheBust;
        }
        foreach ($extraHeaders as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        return function_exists('curl_init')
            ? $this->probeByCurl($url, $requestedUrl, $method, $timeout, $connectTimeout, $maxRedirects, $maxBody, $userAgent, $headers, $allowInsecure)
            : $this->probeByStream($url, $requestedUrl, $method, $timeout, $maxRedirects, $maxBody, $userAgent, $headers, $allowInsecure);
    }

    /**
     * @param list<string> $headers
     * @return array<string, mixed>
     */
    private function probeByCurl(
        string $url,
        string $requestedUrl,
        string $method,
        int $timeout,
        int $connectTimeout,
        int $maxRedirects,
        int $maxBody,
        string $userAgent,
        array $headers,
        bool $allowInsecure
    ): array {
        $started = microtime(true);
        $ch = curl_init($url);
        if ($ch === false) {
            return $this->failed($requestedUrl, 'curl init failed', 'connect');
        }

        $blocks = [];
        $current = ['status' => 0, 'headers' => []];
        $body = '';

        $headerFn = static function ($curl, string $line) use (&$blocks, &$current): int {
            $length = strlen($line);
            $trimmed = trim($line);
            if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $trimmed, $match)) {
                if ((int)$current['status'] > 0) {
                    $blocks[] = $current;
                }
                $current = ['status' => (int)$match[1], 'headers' => []];

                return $length;
            }
            if ($trimmed !== '' && str_contains($trimmed, ':')) {
                [$name, $value] = explode(':', $trimmed, 2);
                $current['headers'][strtolower(trim($name))] = trim($value);
            }

            return $length;
        };

        $writeFn = static function ($curl, string $data) use (&$body, $maxBody): int {
            $length = strlen($data);
            $remaining = $maxBody - strlen($body);
            if ($remaining > 0) {
                $body .= substr($data, 0, $remaining);
            }

            return $length;
        };

        $options = [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => $maxRedirects > 0,
            CURLOPT_MAXREDIRS => $maxRedirects,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => $headerFn,
            CURLOPT_WRITEFUNCTION => $writeFn,
            CURLOPT_ENCODING => '',
        ];
        if ($method === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        }
        if ($allowInsecure) {
            $options[CURLOPT_SSL_VERIFYPEER] = false;
            $options[CURLOPT_SSL_VERIFYHOST] = 0;
        }
        curl_setopt_array($ch, $options);

        curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $finalUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ((int)$current['status'] > 0) {
            $blocks[] = $current;
        }

        $redirects = [];
        $redirectLoop = false;
        $seen = [];
        foreach ($blocks as $index => $block) {
            $blockStatus = (int)($block['status'] ?? 0);
            if ($blockStatus >= 300 && $blockStatus < 400) {
                $location = (string)($block['headers']['location'] ?? '');
                if ($location !== '') {
                    $absolute = $this->absoluteUrl($location, $finalUrl !== '' ? $finalUrl : $url);
                    if (isset($seen[$absolute])) {
                        $redirectLoop = true;
                    }
                    $seen[$absolute] = true;
                    $redirects[] = ['status' => $blockStatus, 'location' => $absolute];
                }
            }
            unset($index);
        }

        $errorType = '';
        if ($errno !== 0) {
            $errorType = $this->curlErrorType($errno, $error);
            if (stripos($error, 'redirect') !== false && stripos($error, 'maximum') !== false) {
                $redirectLoop = true;
                $errorType = 'redirect_loop';
            }
        }

        $finalHeaders = $blocks !== [] ? (array)($blocks[count($blocks) - 1]['headers'] ?? []) : [];

        return [
            'ok' => $errno === 0 && $status >= 200 && $status < 300,
            'status' => $status,
            'headers' => $finalHeaders,
            'body' => $method === 'HEAD' ? '' : $body,
            'final_url' => $finalUrl !== '' ? $finalUrl : $url,
            'requested_url' => $requestedUrl,
            'redirects' => $redirects,
            'redirect_loop' => $redirectLoop,
            'error' => $error,
            'error_type' => $errorType,
            'duration_ms' => (int)round((microtime(true) - $started) * 1000),
            'content_type' => $contentType,
        ];
    }

    /**
     * @param list<string> $headers
     * @return array<string, mixed>
     */
    private function probeByStream(
        string $url,
        string $requestedUrl,
        string $method,
        int $timeout,
        int $maxRedirects,
        int $maxBody,
        string $userAgent,
        array $headers,
        bool $allowInsecure
    ): array {
        $started = microtime(true);
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'timeout' => $timeout,
                'follow_location' => $maxRedirects > 0 ? 1 : 0,
                'max_redirects' => $maxRedirects,
                'ignore_errors' => true,
                'header' => 'User-Agent: ' . $userAgent . "\r\n" . implode("\r\n", $headers) . "\r\n",
            ],
            'ssl' => [
                'verify_peer' => !$allowInsecure,
                'verify_peer_name' => !$allowInsecure,
                'allow_self_signed' => $allowInsecure,
            ],
        ]);

        $body = @file_get_contents($url, false, $context, 0, $maxBody);
        $rawHeaders = $http_response_header ?? [];
        $status = 0;
        $finalHeaders = [];
        $redirects = [];
        foreach ($rawHeaders as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $line, $match)) {
                $status = (int)$match[1];
                $finalHeaders = [];

                continue;
            }
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $name = strtolower(trim($name));
                $value = trim($value);
                $finalHeaders[$name] = $value;
                if ($status >= 300 && $status < 400 && $name === 'location') {
                    $redirects[] = ['status' => $status, 'location' => $value];
                }
            }
        }

        $error = is_string($body) ? '' : 'failed to fetch';
        $errorType = '';
        if (!is_string($body)) {
            $lastError = error_get_last();
            $message = (string)($lastError['message'] ?? '');
            $errorType = $this->streamErrorType($message);
            $error = $message !== '' ? $message : 'failed to fetch';
        }

        return [
            'ok' => is_string($body) && $status >= 200 && $status < 300,
            'status' => $status,
            'headers' => $finalHeaders,
            'body' => $method === 'HEAD' ? '' : (is_string($body) ? $body : ''),
            'final_url' => $url,
            'requested_url' => $requestedUrl,
            'redirects' => $redirects,
            'redirect_loop' => false,
            'error' => $error,
            'error_type' => $errorType,
            'duration_ms' => (int)round((microtime(true) - $started) * 1000),
            'content_type' => (string)($finalHeaders['content-type'] ?? ''),
        ];
    }

    /** @return array<string, mixed> */
    private function failed(string $url, string $error, string $errorType): array
    {
        return [
            'ok' => false,
            'status' => 0,
            'headers' => [],
            'body' => '',
            'final_url' => $url,
            'requested_url' => $url,
            'redirects' => [],
            'redirect_loop' => false,
            'error' => $error,
            'error_type' => $errorType,
            'duration_ms' => 0,
            'content_type' => '',
        ];
    }

    private function curlErrorType(int $errno, string $error): string
    {
        if ($errno === 28) {
            return 'timeout';
        }
        if (in_array($errno, [5, 6], true)) {
            return 'dns';
        }
        // libcurl 的 TLS 类错误码（35/51/53/54/58/59/60/64/66/77/80/83/90/91）；
        // 用数字避免不同 PHP 构建里常量缺失导致 Error。
        if (in_array($errno, [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 83, 90, 91], true)) {
            return 'tls';
        }
        if ($errno === CURLE_COULDNT_CONNECT) {
            return 'connect';
        }
        if (stripos($error, 'timed out') !== false) {
            return 'timeout';
        }
        if (stripos($error, 'resolve') !== false || stripos($error, 'dns') !== false) {
            return 'dns';
        }
        if (stripos($error, 'ssl') !== false || stripos($error, 'certificate') !== false) {
            return 'tls';
        }

        return 'connect';
    }

    private function streamErrorType(string $message): string
    {
        $message = strtolower($message);
        if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
            return 'timeout';
        }
        if (str_contains($message, 'getaddrinfo') || str_contains($message, 'name or service not known')) {
            return 'dns';
        }
        if (str_contains($message, 'ssl') || str_contains($message, 'certificate')) {
            return 'tls';
        }

        return 'connect';
    }

    private function absoluteUrl(string $location, string $base): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $parts = parse_url($base);
        if (!is_array($parts) || empty($parts['host'])) {
            return $location;
        }
        $scheme = (string)($parts['scheme'] ?? 'https');
        $host = (string)$parts['host'];
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $scheme . '://' . $host . $port . $location;
        }
        $path = (string)($parts['path'] ?? '/');
        $directory = rtrim(str_contains($path, '/') ? substr($path, 0, strrpos($path, '/')) : '', '/');
        $directory = $directory === '' ? '' : $directory . '/';

        return $scheme . '://' . $host . $port . $directory . $location;
    }
}
