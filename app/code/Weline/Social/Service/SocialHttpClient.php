<?php

declare(strict_types=1);

namespace Weline\Social\Service;

class SocialHttpClient
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|string|null $body
     * @return array{ok:bool,status:int,json:array<string,mixed>,raw:string,error:string}
     */
    public function request(
        string $method,
        string $url,
        array $headers = [],
        array|string|null $body = null,
        int $timeout = 30
    ): array {
        $method = \strtoupper(\trim($method));
        $ch = \curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0, 'json' => [], 'raw' => '', 'error' => 'curl_init_failed'];
        }

        $headerLines = [];
        foreach ($headers as $key => $value) {
            $headerLines[] = $key . ': ' . $value;
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => \max(5, $timeout),
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
        ];

        if ($body !== null && $method !== 'GET' && $method !== 'HEAD') {
            if (\is_array($body)) {
                $contentType = '';
                foreach ($headers as $key => $value) {
                    if (\strtolower((string)$key) === 'content-type') {
                        $contentType = \strtolower((string)$value);
                        break;
                    }
                }
                if (\str_contains($contentType, 'application/json')) {
                    $options[CURLOPT_POSTFIELDS] = \json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
                } else {
                    $options[CURLOPT_POSTFIELDS] = \http_build_query($body);
                }
            } else {
                $options[CURLOPT_POSTFIELDS] = $body;
            }
        }

        \curl_setopt_array($ch, $options);
        $raw = \curl_exec($ch);
        $status = (int)\curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = \curl_error($ch);
        \curl_close($ch);

        if (!\is_string($raw)) {
            return ['ok' => false, 'status' => $status, 'json' => [], 'raw' => '', 'error' => $error !== '' ? $error : 'empty_response'];
        }

        $decoded = \json_decode($raw, true);
        $json = \is_array($decoded) ? $decoded : [];

        return [
            'ok' => $status >= 200 && $status < 300 && $error === '',
            'status' => $status,
            'json' => $json,
            'raw' => $raw,
            'error' => $error,
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     * @return array{ok:bool,status:int,json:array<string,mixed>,raw:string,error:string}
     */
    public function get(string $url, array $query = [], array $headers = []): array
    {
        if ($query !== []) {
            $url .= (\str_contains($url, '?') ? '&' : '?') . \http_build_query($query);
        }

        return $this->request('GET', $url, $headers);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @return array{ok:bool,status:int,json:array<string,mixed>,raw:string,error:string}
     */
    public function postForm(string $url, array $body, array $headers = []): array
    {
        $headers = \array_merge(['Content-Type' => 'application/x-www-form-urlencoded'], $headers);

        return $this->request('POST', $url, $headers, $body);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @return array{ok:bool,status:int,json:array<string,mixed>,raw:string,error:string}
     */
    public function postJson(string $url, array $body, array $headers = []): array
    {
        $headers = \array_merge(['Content-Type' => 'application/json'], $headers);

        return $this->request('POST', $url, $headers, $body);
    }
}
