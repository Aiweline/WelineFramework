<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Duplicate;

/**
 * Lightweight HTTP fetch for duplicate-content scanning.
 */
class DuplicatePageFetcher
{
    public function __construct(private readonly int $timeout = 8)
    {
    }

    /**
     * @return array{ok:bool,status:int,body:string,error:string}
     */
    public function fetch(string $url): array
    {
        if (!\function_exists('curl_init')) {
            return $this->fetchByStream($url);
        }

        $ch = \curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'curl init failed'];
        }
        \curl_setopt_array($ch, [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_FOLLOWLOCATION => true,
            \CURLOPT_MAXREDIRS => 3,
            \CURLOPT_CONNECTTIMEOUT => $this->timeout,
            \CURLOPT_TIMEOUT => $this->timeout,
            \CURLOPT_USERAGENT => 'WelineSeoDuplicateScanner/1.0',
            \CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml'],
        ]);
        $body = \curl_exec($ch);
        $status = (int)\curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);
        $error = \curl_error($ch);
        \curl_close($ch);
        if ($body === false || $status >= 400 || $status === 0) {
            return ['ok' => false, 'status' => $status, 'body' => '', 'error' => $error !== '' ? $error : 'http ' . $status];
        }

        return ['ok' => true, 'status' => $status, 'body' => (string)$body, 'error' => ''];
    }

    /**
     * @return array{ok:bool,status:int,body:string,error:string}
     */
    private function fetchByStream(string $url): array
    {
        $ctx = \stream_context_create([
            'http' => [
                'timeout' => $this->timeout,
                'follow_location' => 1,
                'header' => "User-Agent: WelineSeoDuplicateScanner/1.0\r\nAccept: text/html\r\n",
            ],
        ]);
        $body = @\file_get_contents($url, false, $ctx);
        if ($body === false) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'stream fetch failed'];
        }

        return ['ok' => true, 'status' => 200, 'body' => $body, 'error' => ''];
    }
}
