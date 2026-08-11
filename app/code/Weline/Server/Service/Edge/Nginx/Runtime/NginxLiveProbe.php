<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Nginx\Runtime;

/**
 * Sends a real HTTP request through Nginx and validates status, generation
 * headers and an optional body marker.
 */
final class NginxLiveProbe
{
    /**
     * @param array<string,string> $expectedHeaders
     * @return array{ok:bool,reason:string,attempts:int,consecutive_matches:int,status_line:string,body_sha256:string,elapsed_ms:float}
     */
    public function probeHttp(
        string $address,
        int $port,
        string $host,
        string $path,
        int $expectedStatus = 200,
        array $expectedHeaders = [],
        string $bodyContains = '',
        int $maxAttempts = 1,
        int $requiredConsecutive = 1,
        float $connectTimeoutSeconds = 0.25,
        ?float $deadlineMonotonic = null,
    ): array {
        $this->validateRequest(
            $address,
            $port,
            $host,
            $path,
            $expectedStatus,
            $maxAttempts,
            $requiredConsecutive,
            $connectTimeoutSeconds,
        );
        if ($deadlineMonotonic !== null && !\is_finite($deadlineMonotonic)) {
            throw new \InvalidArgumentException(
                'Nginx live probe deadline is invalid.',
            );
        }
        $expectedHeaders = $this->normalizeExpectedHeaders($expectedHeaders);
        $started = \hrtime(true);
        $consecutive = 0;
        $attempts = 0;
        $lastReason = 'No probe attempt completed.';
        $lastStatusLine = '';
        $lastBodyDigest = '';

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if ($this->boundedDeadlineSeconds(
                $connectTimeoutSeconds,
                $deadlineMonotonic,
            ) === null) {
                $lastReason = 'Nginx live probe lifecycle deadline was exhausted.';
                break;
            }
            $attempts = $attempt;
            $result = $this->requestOnce(
                $address,
                $port,
                $host,
                $path,
                $expectedStatus,
                $expectedHeaders,
                $bodyContains,
                $connectTimeoutSeconds,
                $deadlineMonotonic,
            );
            $lastReason = $result['reason'];
            $lastStatusLine = $result['status_line'];
            $lastBodyDigest = $result['body_sha256'];
            if ($result['ok']) {
                $consecutive++;
                if ($consecutive >= $requiredConsecutive) {
                    return [
                        'ok' => true,
                        'reason' => 'Nginx returned the expected live generation.',
                        'attempts' => $attempt,
                        'consecutive_matches' => $consecutive,
                        'status_line' => $lastStatusLine,
                        'body_sha256' => $lastBodyDigest,
                        'elapsed_ms' => (\hrtime(true) - $started) / 1_000_000,
                    ];
                }
            } else {
                $consecutive = 0;
            }
            if ($attempt < $maxAttempts
                && !$this->sleepWithinDeadline(0.1, $deadlineMonotonic)
            ) {
                $lastReason = 'Nginx live probe lifecycle deadline was exhausted.';
                break;
            }
        }

        return [
            'ok' => false,
            'reason' => $lastReason,
            'attempts' => $attempts,
            'consecutive_matches' => $consecutive,
            'status_line' => $lastStatusLine,
            'body_sha256' => $lastBodyDigest,
            'elapsed_ms' => (\hrtime(true) - $started) / 1_000_000,
        ];
    }

    /**
     * @param array<string,string> $expectedHeaders
     * @return array{ok:bool,reason:string,status_line:string,body_sha256:string}
     */
    private function requestOnce(
        string $address,
        int $port,
        string $host,
        string $path,
        int $expectedStatus,
        array $expectedHeaders,
        string $bodyContains,
        float $connectTimeoutSeconds,
        ?float $deadlineMonotonic,
    ): array {
        $connectTimeoutSeconds = $this->boundedDeadlineSeconds(
            $connectTimeoutSeconds,
            $deadlineMonotonic,
        );
        if ($connectTimeoutSeconds === null) {
            return [
                'ok' => false,
                'reason' => 'Nginx live probe lifecycle deadline was exhausted.',
                'status_line' => '',
                'body_sha256' => '',
            ];
        }
        $errno = 0;
        $error = '';
        $socket = @\stream_socket_client(
            'tcp://' . $this->formatAddress($address, $port),
            $errno,
            $error,
            $connectTimeoutSeconds,
            \STREAM_CLIENT_CONNECT,
        );
        if (!\is_resource($socket)) {
            return [
                'ok' => false,
                'reason' => 'Nginx listener connection failed: ' . ($error !== '' ? $error : (string)$errno),
                'status_line' => '',
                'body_sha256' => '',
            ];
        }
        if (!$this->setSocketTimeoutWithinDeadline(
            $socket,
            1.0,
            $deadlineMonotonic,
        )) {
            @\fclose($socket);
            return [
                'ok' => false,
                'reason' => 'Nginx live probe lifecycle deadline was exhausted.',
                'status_line' => '',
                'body_sha256' => '',
            ];
        }
        $request = 'GET ' . $path . " HTTP/1.1\r\n"
            . 'Host: ' . $host . "\r\n"
            . "Connection: close\r\n"
            . "Cache-Control: no-cache\r\n\r\n";
        $written = @\fwrite($socket, $request);
        if (!\is_int($written) || $written !== \strlen($request)) {
            @\fclose($socket);
            return [
                'ok' => false,
                'reason' => 'Nginx live probe request could not be written completely.',
                'status_line' => '',
                'body_sha256' => '',
            ];
        }
        $response = '';
        while (!\feof($socket) && \strlen($response) < 1_048_576) {
            if (!$this->setSocketTimeoutWithinDeadline(
                $socket,
                1.0,
                $deadlineMonotonic,
            )) {
                break;
            }
            $chunk = @\fread($socket, 16_384);
            if (!\is_string($chunk) || $chunk === '') {
                break;
            }
            $response .= $chunk;
        }
        @\fclose($socket);

        $separator = \strpos($response, "\r\n\r\n");
        $separatorLength = 4;
        if ($separator === false) {
            $separator = \strpos($response, "\n\n");
            $separatorLength = 2;
        }
        if ($separator === false) {
            return [
                'ok' => false,
                'reason' => 'Nginx live probe returned an incomplete HTTP response.',
                'status_line' => '',
                'body_sha256' => '',
            ];
        }
        $headerText = \substr($response, 0, $separator);
        $body = \substr($response, $separator + $separatorLength);
        $lines = \preg_split('/\r?\n/', $headerText) ?: [];
        $statusLine = (string)\array_shift($lines);
        if (\preg_match('/\AHTTP\/1\.[01]\s+' . $expectedStatus . '(?:\s|$)/D', $statusLine) !== 1) {
            return [
                'ok' => false,
                'reason' => 'Nginx live probe returned an unexpected status.',
                'status_line' => $statusLine,
                'body_sha256' => \hash('sha256', $body),
            ];
        }
        $headers = [];
        foreach ($lines as $line) {
            $delimiter = \strpos($line, ':');
            if ($delimiter === false) {
                continue;
            }
            $name = \strtolower(\trim(\substr($line, 0, $delimiter)));
            $headers[$name][] = \trim(\substr($line, $delimiter + 1));
        }
        foreach ($expectedHeaders as $name => $value) {
            $values = $headers[$name] ?? [];
            if (\count($values) !== 1 || !\hash_equals($value, (string)$values[0])) {
                return [
                    'ok' => false,
                    'reason' => 'Nginx live probe header mismatch: ' . $name,
                    'status_line' => $statusLine,
                    'body_sha256' => \hash('sha256', $body),
                ];
            }
        }
        if ($bodyContains !== '' && !\str_contains($body, $bodyContains)) {
            return [
                'ok' => false,
                'reason' => 'Nginx live probe body marker is missing.',
                'status_line' => $statusLine,
                'body_sha256' => \hash('sha256', $body),
            ];
        }

        return [
            'ok' => true,
            'reason' => 'matched',
            'status_line' => $statusLine,
            'body_sha256' => \hash('sha256', $body),
        ];
    }

    /** @param array<string,string> $headers @return array<string,string> */
    private function normalizeExpectedHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $name = \strtolower(\trim((string)$name));
            $value = \trim((string)$value);
            if (\preg_match('/\A[a-z0-9-]+\z/D', $name) !== 1
                || $value === ''
                || \str_contains($value, "\r")
                || \str_contains($value, "\n")
            ) {
                throw new \InvalidArgumentException('Nginx live probe expected header is invalid.');
            }
            $normalized[$name] = $value;
        }
        return $normalized;
    }

    private function validateRequest(
        string $address,
        int $port,
        string $host,
        string $path,
        int $expectedStatus,
        int $maxAttempts,
        int $requiredConsecutive,
        float $connectTimeoutSeconds,
    ): void {
        if (\filter_var($address, FILTER_VALIDATE_IP) === false
            || $port < 1
            || $port > 65535
            || $expectedStatus < 100
            || $expectedStatus > 599
            || $maxAttempts < 1
            || $maxAttempts > 300
            || $requiredConsecutive < 1
            || $requiredConsecutive > $maxAttempts
            || $connectTimeoutSeconds <= 0
            || $connectTimeoutSeconds > 10
            || \trim($host) === ''
            || \str_contains($host, "\r")
            || \str_contains($host, "\n")
            || \preg_match('#\A/[^\s]*\z#D', $path) !== 1
        ) {
            throw new \InvalidArgumentException('Nginx live probe request contract is invalid.');
        }
    }

    private function formatAddress(string $address, int $port): string
    {
        return \str_contains($address, ':') ? '[' . $address . ']:' . $port : $address . ':' . $port;
    }

    private function boundedDeadlineSeconds(
        float $maximumSeconds,
        ?float $deadlineMonotonic,
    ): ?float {
        if ($deadlineMonotonic === null) {
            return $maximumSeconds;
        }
        $remaining = $deadlineMonotonic - (\hrtime(true) / 1_000_000_000);
        if ($remaining <= 0.0) {
            return null;
        }
        return \min($maximumSeconds, $remaining);
    }

    /** @param resource $socket */
    private function setSocketTimeoutWithinDeadline(
        mixed $socket,
        float $maximumSeconds,
        ?float $deadlineMonotonic,
    ): bool {
        $timeout = $this->boundedDeadlineSeconds(
            $maximumSeconds,
            $deadlineMonotonic,
        );
        if ($timeout === null) {
            return false;
        }
        $seconds = (int)\floor($timeout);
        $microseconds = (int)\ceil(($timeout - $seconds) * 1_000_000);
        if ($microseconds >= 1_000_000) {
            $seconds++;
            $microseconds = 0;
        } elseif ($seconds === 0 && $microseconds < 1) {
            $microseconds = 1;
        }
        return @\stream_set_timeout($socket, $seconds, $microseconds);
    }

    private function sleepWithinDeadline(
        float $seconds,
        ?float $deadlineMonotonic,
    ): bool {
        $delay = $this->boundedDeadlineSeconds($seconds, $deadlineMonotonic);
        if ($delay === null) {
            return false;
        }
        \usleep((int)\max(1, \ceil($delay * 1_000_000)));
        return $deadlineMonotonic === null
            || (\hrtime(true) / 1_000_000_000) < $deadlineMonotonic;
    }
}
