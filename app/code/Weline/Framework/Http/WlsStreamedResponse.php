<?php

declare(strict_types=1);

namespace Weline\Framework\Http;

use Weline\Framework\Http\Sse\SseContext;
use Weline\Framework\Runtime\SchedulerSystem;

/**
 * Bounded WLS file-response bridge.
 *
 * The Worker installs a Fiber-owned non-blocking writer in SseContext for
 * every request. This bridge only queues bounded chunks; it never builds the
 * file body into the string returned by WlsRuntime.
 */
final class WlsStreamedResponse
{
    private const MARKER_PREFIX = "\0weline-wls-stream:v1:";
    private const MARKER_SUFFIX = "\0";
    private const CHUNK_BYTES = 64 * 1024;
    private const MAX_HEADERS = 128;
    private const MAX_HEADER_BYTES = 64 * 1024;
    private const MAX_COOKIES = 64;
    private const WRITE_STALL_SECONDS = 30.0;

    /**
     * @param array<string,string|array> $additionalHeaders
     * @param array<string,array<string,mixed>> $cookies
     */
    public static function streamDownload(
        DownloadException $download,
        bool $headRequest = false,
        array $additionalHeaders = [],
        array $cookies = [],
    ): string {
        if (!\is_callable(SseContext::getWriteCallback())) {
            throw new \RuntimeException((string)__('当前 WLS 传输不支持文件流式响应。'));
        }

        $path = $download->getFilePath();
        $pathStat = null;
        if ($download->shouldDeleteAfterDownload()) {
            $pathStat = @\lstat($path);
            if (!\is_array($pathStat)
                || \is_link($path)
                || (((int)($pathStat['mode'] ?? 0)) & 0170000) !== 0100000
                || (int)($pathStat['nlink'] ?? 0) !== 1
            ) {
                throw new \RuntimeException((string)__('下载临时文件身份无效。'));
            }
        }
        $stream = @\fopen($path, 'rb');
        if ($stream === false) {
            throw new \RuntimeException((string)__('下载文件不可用。'));
        }

        $headersQueued = false;
        $queuedBytes = 0;
        $complete = false;
        $cleanupFailed = false;
        $openedStat = null;
        try {
            $stat = @\fstat($stream);
            $fileBytes = \is_array($stat) ? (int)($stat['size'] ?? -1) : -1;
            $mode = \is_array($stat) ? (int)($stat['mode'] ?? 0) : 0;
            if ($fileBytes < 0 || ($mode & 0170000) !== 0100000) {
                throw new \RuntimeException((string)__('下载文件状态无效。'));
            }
            if ($pathStat !== null
                && ((int)($stat['dev'] ?? -1) !== (int)($pathStat['dev'] ?? -2)
                    || (int)($stat['ino'] ?? -1) !== (int)($pathStat['ino'] ?? -2)
                    || (int)($stat['nlink'] ?? 0) !== 1)
            ) {
                throw new \RuntimeException((string)__('下载临时文件身份无效。'));
            }
            $openedStat = $stat;

            $headerBlock = self::headerBlock(
                $download->getStatusCode(),
                $fileBytes,
                $additionalHeaders,
                $download->getHeaders(),
                $cookies,
            );
            if (!self::queue($headerBlock)) {
                $complete = false;
            } else {
                $headersQueued = true;
                $queuedBytes = \strlen($headerBlock);
                if ($headRequest) {
                    $complete = true;
                } else {
                    $remaining = $fileBytes;
                    $emptyReads = 0;
                    while ($remaining > 0) {
                        if (!SseContext::isConnectionAlive()) {
                            break;
                        }
                        $chunk = @\fread($stream, \min(self::CHUNK_BYTES, $remaining));
                        if ($chunk === false) {
                            break;
                        }
                        if ($chunk === '') {
                            if (@\feof($stream) || ++$emptyReads >= 3) {
                                break;
                            }
                            self::yieldScheduler();
                            continue;
                        }
                        $emptyReads = 0;
                        if (!self::queue($chunk)) {
                            break;
                        }
                        $bytes = \strlen($chunk);
                        $queuedBytes += $bytes;
                        $remaining -= $bytes;
                        self::yieldScheduler();
                    }
                    $complete = $remaining === 0;
                }
            }
        } catch (\Throwable $throwable) {
            if (!$headersQueued) {
                throw $throwable;
            }
            // The status line is already queued. Fail closed by ending the
            // connection through the streamed marker instead of appending a
            // second HTTP response to a partial body.
            $complete = false;
        } finally {
            if (!@\fclose($stream)) {
                $cleanupFailed = true;
            }
            if ($download->shouldDeleteAfterDownload()) {
                $cleanupFailed = !self::unlinkOpenedFile($path, $openedStat) || $cleanupFailed;
            }
        }

        return self::marker(
            $download->getStatusCode(),
            $queuedBytes,
            $complete,
            $cleanupFailed,
        );
    }

    /**
     * @return array{status:int,queued_bytes:int,complete:bool,cleanup_failed:bool}|null
     */
    public static function parseMarker(string $value): ?array
    {
        $pattern = '/\A' . \preg_quote(self::MARKER_PREFIX, '/')
            . '([1-5][0-9]{2}):([0-9]{1,20}):([01]):([01])'
            . \preg_quote(self::MARKER_SUFFIX, '/') . '\z/D';
        if (\preg_match($pattern, $value, $matches) !== 1) {
            return null;
        }
        $queuedBytes = \filter_var($matches[2], \FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => \PHP_INT_MAX],
        ]);
        if ($queuedBytes === false) {
            return null;
        }

        return [
            'status' => (int)$matches[1],
            'queued_bytes' => (int)$queuedBytes,
            'complete' => $matches[3] === '1',
            'cleanup_failed' => $matches[4] === '1',
        ];
    }

    /**
     * Clean a delete-after-download file when streaming could not start.
     *
     * The runtime must not call unlink() on a pathname supplied by a response
     * after following it with is_file(); a concurrent replacement could turn
     * that recovery path into a symlink deletion. Open and seal the regular
     * file identity before delegating to the same checked unlink path used by
     * a completed stream.
     */
    public static function cleanupUnstartedDownload(DownloadException $download): bool
    {
        if (!$download->shouldDeleteAfterDownload()) {
            return true;
        }
        $path = $download->getFilePath();
        if (!\file_exists($path) && !\is_link($path)) {
            return true;
        }
        $before = @\lstat($path);
        if (!\is_array($before)
            || \is_link($path)
            || (((int)($before['mode'] ?? 0)) & 0170000) !== 0100000
        ) {
            return false;
        }
        $stream = @\fopen($path, 'rb');
        if ($stream === false) {
            return false;
        }
        $opened = @\fstat($stream);
        $closed = @\fclose($stream);
        if (!$closed
            || !\is_array($opened)
            || (int)($opened['dev'] ?? -1) !== (int)($before['dev'] ?? -2)
            || (int)($opened['ino'] ?? -1) !== (int)($before['ino'] ?? -2)
        ) {
            return false;
        }

        return self::unlinkOpenedFile($path, $opened);
    }

    private static function marker(
        int $status,
        int $queuedBytes,
        bool $complete,
        bool $cleanupFailed,
    ): string {
        $status = \max(100, \min(599, $status));
        $queuedBytes = \max(0, $queuedBytes);

        return self::MARKER_PREFIX . $status . ':' . $queuedBytes . ':'
            . ($complete ? '1' : '0') . ':' . ($cleanupFailed ? '1' : '0')
            . self::MARKER_SUFFIX;
    }

    /**
     * @param array<string,string|array> $additionalHeaders
     * @param array<string,string|array> $downloadHeaders
     * @param array<string,array<string,mixed>> $cookies
     */
    private static function headerBlock(
        int $status,
        int $fileBytes,
        array $additionalHeaders,
        array $downloadHeaders,
        array $cookies,
    ): string {
        if (\count($cookies) > self::MAX_COOKIES) {
            throw new \RuntimeException((string)__('文件流式响应 Cookie 数量超过上限。'));
        }
        $headers = [];
        foreach ([...$additionalHeaders, ...$downloadHeaders] as $name => $value) {
            $normalizedName = self::headerName((string)$name);
            $lowerName = \strtolower($normalizedName);
            if (\in_array($lowerName, ['content-length', 'connection', 'transfer-encoding'], true)) {
                continue;
            }
            foreach (\is_array($value) ? $value : [$value] as $item) {
                $headers[] = [$normalizedName, self::headerValue($item)];
                if (\count($headers) > self::MAX_HEADERS) {
                    throw new \RuntimeException((string)__('文件流式响应头数量超过上限。'));
                }
            }
        }

        $response = 'HTTP/1.1 ' . $status . ' ' . self::statusText($status) . "\r\n";
        foreach ($headers as [$name, $value]) {
            $response .= $name . ': ' . $value . "\r\n";
        }
        foreach ($cookies as $cookie) {
            if (\is_array($cookie)) {
                $line = self::cookieLine($cookie);
                if ($line !== null) {
                    $response .= 'Set-Cookie: ' . $line . "\r\n";
                }
            }
        }
        $response .= 'Content-Length: ' . $fileBytes . "\r\nConnection: close\r\n\r\n";
        if (\strlen($response) > self::MAX_HEADER_BYTES) {
            throw new \RuntimeException((string)__('文件流式响应头大小超过上限。'));
        }

        return $response;
    }

    private static function headerName(string $name): string
    {
        $name = \trim($name);
        if (\preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]{1,128}$/D', $name) !== 1) {
            throw new \RuntimeException((string)__('文件流式响应头名称无效。'));
        }
        return $name;
    }

    private static function headerValue(mixed $value): string
    {
        if (!\is_scalar($value) && $value !== null) {
            throw new \RuntimeException((string)__('文件流式响应头值无效。'));
        }
        $value = (string)$value;
        if (\strlen($value) > 8192 || \preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) === 1) {
            throw new \RuntimeException((string)__('文件流式响应头值无效。'));
        }
        return $value;
    }

    /** @param array<string,mixed> $cookie */
    private static function cookieLine(array $cookie): ?string
    {
        $name = (string)($cookie['name'] ?? '');
        if (\preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]{1,128}$/D', $name) !== 1) {
            return null;
        }
        $parts = [\rawurlencode($name) . '=' . \rawurlencode((string)($cookie['value'] ?? ''))];
        $expire = (int)($cookie['expire'] ?? 0);
        if ($expire > 0) {
            $parts[] = 'Expires=' . \gmdate('D, d M Y H:i:s T', $expire);
        }
        $path = self::cookieAttribute($cookie['path'] ?? '/');
        if ($path !== '') {
            $parts[] = 'Path=' . $path;
        }
        $domain = self::cookieAttribute($cookie['domain'] ?? '');
        if ($domain !== '') {
            $parts[] = 'Domain=' . $domain;
        }
        if (!empty($cookie['secure'])) {
            $parts[] = 'Secure';
        }
        if (($cookie['httpOnly'] ?? true) !== false) {
            $parts[] = 'HttpOnly';
        }
        $sameSite = \ucfirst(\strtolower(self::cookieAttribute($cookie['sameSite'] ?? 'Lax')));
        if (\in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
            $parts[] = 'SameSite=' . $sameSite;
        }
        return \implode('; ', $parts);
    }

    private static function cookieAttribute(mixed $value): string
    {
        $value = \trim((string)$value);
        return \strlen($value) <= 1024 && \preg_match('/[\x00-\x20\x7F;,]/', $value) !== 1
            ? $value
            : '';
    }

    private static function queue(string $data): bool
    {
        $deadline = \hrtime(true) + (int)(self::WRITE_STALL_SECONDS * 1_000_000_000);
        do {
            if (!SseContext::isConnectionAlive()) {
                return false;
            }
            if (SseContext::writeNonBlocking($data)) {
                return true;
            }
            self::yieldScheduler();
        } while (\hrtime(true) < $deadline);

        return false;
    }

    private static function yieldScheduler(): void
    {
        if (SchedulerSystem::isSchedulerActive()) {
            SchedulerSystem::yield();
        }
    }

    /** @param array<string|int,mixed>|null $openedStat */
    private static function unlinkOpenedFile(string $path, ?array $openedStat): bool
    {
        if (!\file_exists($path) && !\is_link($path)) {
            return true;
        }
        $current = @\lstat($path);
        if (!\is_array($openedStat)
            || !\is_array($current)
            || \is_link($path)
            || (((int)($current['mode'] ?? 0)) & 0170000) !== 0100000
            || (int)($current['nlink'] ?? 0) !== 1
            || (int)($current['dev'] ?? -1) !== (int)($openedStat['dev'] ?? -2)
            || (int)($current['ino'] ?? -1) !== (int)($openedStat['ino'] ?? -2)
        ) {
            return false;
        }

        return @\unlink($path);
    }

    private static function statusText(int $status): string
    {
        return match ($status) {
            200 => 'OK',
            206 => 'Partial Content',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            416 => 'Range Not Satisfiable',
            500 => 'Internal Server Error',
            503 => 'Service Unavailable',
            default => 'Response',
        };
    }
}
