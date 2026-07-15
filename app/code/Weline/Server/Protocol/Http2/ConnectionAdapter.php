<?php
declare(strict_types=1);

namespace Weline\Server\Protocol\Http2;

/**
 * HTTP/2 bridge for the existing WLS HTTP/1.1 request pipeline.
 *
 * This class has no socket side effects. The TLS Worker can feed encrypted-stream
 * bytes into it after ALPN selects h2, then pass emitted raw HTTP/1.1 requests to
 * the existing WorkerPolicyKernel/handleRequest path and encode the returned
 * HTTP/1.1 response back to HTTP/2 frames.
 */
final class ConnectionAdapter
{
    private string $buffer = '';
    private bool $prefaceSeen = false;
    private HpackDecoder $decoder;

    /** @var array<int,array{headers:string,body:string,end_headers:bool,end_stream:bool}> */
    private array $streams = [];

    public function __construct(?HpackDecoder $decoder = null)
    {
        $this->decoder = $decoder ?? new HpackDecoder();
    }

    /**
     * @return array{status:'ok'|'incomplete'|'error',write:string,requests:list<array{stream_id:int,raw_request:string}>,error?:string}
     */
    public function receive(string $bytes): array
    {
        if ($bytes !== '') {
            $this->buffer .= $bytes;
        }

        $write = '';
        $requests = [];

        if (!$this->prefaceSeen) {
            $preface = FrameCodec::CLIENT_CONNECTION_PREFACE;
            $needed = \strlen($preface);
            if (\strlen($this->buffer) < $needed) {
                return ['status' => 'incomplete', 'write' => '', 'requests' => []];
            }
            if (!\str_starts_with($this->buffer, $preface)) {
                return ['status' => 'error', 'write' => '', 'requests' => [], 'error' => 'invalid_client_preface'];
            }
            $this->buffer = \substr($this->buffer, $needed);
            $this->prefaceSeen = true;
            $write .= FrameCodec::settings([
                FrameCodec::SETTINGS_ENABLE_PUSH => 0,
                FrameCodec::SETTINGS_MAX_CONCURRENT_STREAMS => 128,
                FrameCodec::SETTINGS_INITIAL_WINDOW_SIZE => 1048576,
                FrameCodec::SETTINGS_MAX_HEADER_LIST_SIZE => 65536,
            ]);
        }

        while (true) {
            $frame = FrameCodec::decodeOne($this->buffer);
            if (($frame['status'] ?? '') === 'incomplete') {
                break;
            }
            if (($frame['status'] ?? '') === 'error') {
                return ['status' => 'error', 'write' => $write, 'requests' => $requests, 'error' => (string)($frame['error'] ?? 'frame_error')];
            }

            $this->buffer = \substr($this->buffer, (int)$frame['consumed']);
            $type = (int)$frame['type'];
            $flags = (int)$frame['flags'];
            $streamId = (int)$frame['stream_id'];
            $payload = (string)$frame['payload'];

            if ($type === FrameCodec::TYPE_SETTINGS) {
                if ($streamId !== 0) {
                    return ['status' => 'error', 'write' => $write, 'requests' => $requests, 'error' => 'settings_on_stream'];
                }
                if (($flags & FrameCodec::FLAG_ACK) !== FrameCodec::FLAG_ACK) {
                    $write .= FrameCodec::encode(FrameCodec::TYPE_SETTINGS, FrameCodec::FLAG_ACK, 0);
                }
                continue;
            }

            if ($type === FrameCodec::TYPE_PING && $streamId === 0) {
                if (($flags & FrameCodec::FLAG_ACK) !== FrameCodec::FLAG_ACK) {
                    $write .= FrameCodec::encode(FrameCodec::TYPE_PING, FrameCodec::FLAG_ACK, 0, $payload);
                }
                continue;
            }

            if ($type === FrameCodec::TYPE_WINDOW_UPDATE && $streamId === 0) {
                continue;
            }

            if ($type === FrameCodec::TYPE_GOAWAY && $streamId === 0) {
                continue;
            }

            if ($streamId <= 0) {
                return ['status' => 'error', 'write' => $write, 'requests' => $requests, 'error' => 'stream_required'];
            }

            if ($type === FrameCodec::TYPE_HEADERS) {
                $headerBlock = $this->stripHeadersPayload($payload, $flags);
                $this->streams[$streamId] = [
                    'headers' => $headerBlock,
                    'body' => '',
                    'end_headers' => (($flags & FrameCodec::FLAG_END_HEADERS) === FrameCodec::FLAG_END_HEADERS),
                    'end_stream' => (($flags & FrameCodec::FLAG_END_STREAM) === FrameCodec::FLAG_END_STREAM),
                ];
                if ($this->streams[$streamId]['end_headers'] && $this->streams[$streamId]['end_stream']) {
                    $requests[] = $this->buildRawRequest($streamId);
                }
                continue;
            }

            if ($type === FrameCodec::TYPE_CONTINUATION) {
                if (!isset($this->streams[$streamId])) {
                    return ['status' => 'error', 'write' => $write, 'requests' => $requests, 'error' => 'continuation_without_headers'];
                }
                $this->streams[$streamId]['headers'] .= $payload;
                if (($flags & FrameCodec::FLAG_END_HEADERS) === FrameCodec::FLAG_END_HEADERS) {
                    $this->streams[$streamId]['end_headers'] = true;
                    if ($this->streams[$streamId]['end_stream']) {
                        $requests[] = $this->buildRawRequest($streamId);
                    }
                }
                continue;
            }

            if ($type === FrameCodec::TYPE_DATA) {
                if (!isset($this->streams[$streamId])) {
                    return ['status' => 'error', 'write' => $write, 'requests' => $requests, 'error' => 'data_without_headers'];
                }
                $this->streams[$streamId]['body'] .= $this->stripDataPayload($payload, $flags);
                if (($flags & FrameCodec::FLAG_END_STREAM) === FrameCodec::FLAG_END_STREAM) {
                    $this->streams[$streamId]['end_stream'] = true;
                    if ($this->streams[$streamId]['end_headers']) {
                        $requests[] = $this->buildRawRequest($streamId);
                    }
                }
                continue;
            }

            if ($type === FrameCodec::TYPE_WINDOW_UPDATE || $type === FrameCodec::TYPE_PRIORITY) {
                continue;
            }

            if ($type === FrameCodec::TYPE_RST_STREAM) {
                unset($this->streams[$streamId]);
                continue;
            }
        }

        return [
            'status' => ($requests === [] ? 'incomplete' : 'ok'),
            'write' => $write,
            'requests' => $requests,
        ];
    }

    public function encodeResponse(int $streamId, string $httpResponse): string
    {
        [$status, $headers, $body] = $this->parseHttpResponse($httpResponse);
        $headerBlock = self::encodeStatusHeader($status);
        foreach ($headers as $name => $values) {
            $lower = \strtolower($name);
            if (\in_array($lower, ['connection', 'keep-alive', 'proxy-connection', 'transfer-encoding', 'upgrade'], true)) {
                continue;
            }
            foreach ($values as $value) {
                $headerBlock .= self::encodeLiteralHeader($lower, $value);
            }
        }
        return FrameCodec::encode(FrameCodec::TYPE_HEADERS, FrameCodec::FLAG_END_HEADERS, $streamId, $headerBlock)
            . FrameCodec::encode(FrameCodec::TYPE_DATA, FrameCodec::FLAG_END_STREAM, $streamId, $body);
    }

    private function buildRawRequest(int $streamId): array
    {
        $stream = $this->streams[$streamId] ?? null;
        if ($stream === null) {
            throw new \UnexpectedValueException('HTTP/2 stream is missing.');
        }
        unset($this->streams[$streamId]);

        $headers = $this->decoder->decode($stream['headers']);
        $pseudo = [];
        $regular = [];
        foreach ($headers as $header) {
            $name = \strtolower((string)$header['name']);
            $value = (string)$header['value'];
            if (\str_starts_with($name, ':')) {
                $pseudo[$name] = $value;
            } else {
                $regular[$name][] = $value;
            }
        }

        $method = \strtoupper((string)($pseudo[':method'] ?? ''));
        $path = (string)($pseudo[':path'] ?? '');
        $scheme = (string)($pseudo[':scheme'] ?? 'https');
        $authority = (string)($pseudo[':authority'] ?? ($regular['host'][0] ?? ''));
        if ($method === '' || $path === '' || $authority === '') {
            throw new \UnexpectedValueException('HTTP/2 request is missing required pseudo headers.');
        }

        $raw = $method . ' ' . $path . " HTTP/1.1\r\n";
        $raw .= 'Host: ' . $authority . "\r\n";
        $raw .= 'X-Forwarded-Proto: ' . ($scheme !== '' ? $scheme : 'https') . "\r\n";
        foreach ($regular as $name => $values) {
            if ($name === 'host') {
                continue;
            }
            foreach ($values as $value) {
                $raw .= $name . ': ' . $value . "\r\n";
            }
        }
        if ($stream['body'] !== '' && !isset($regular['content-length'])) {
            $raw .= 'content-length: ' . \strlen($stream['body']) . "\r\n";
        }
        $raw .= "\r\n" . $stream['body'];

        return ['stream_id' => $streamId, 'raw_request' => $raw];
    }

    private function stripHeadersPayload(string $payload, int $flags): string
    {
        if (($flags & FrameCodec::FLAG_PADDED) === FrameCodec::FLAG_PADDED) {
            if ($payload === '') {
                throw new \UnexpectedValueException('Invalid padded HEADERS frame.');
            }
            $padLength = \ord($payload[0]);
            $payload = \substr($payload, 1);
            if ($padLength > \strlen($payload)) {
                throw new \UnexpectedValueException('Invalid HEADERS padding.');
            }
            $payload = \substr($payload, 0, \strlen($payload) - $padLength);
        }
        if (($flags & FrameCodec::FLAG_PRIORITY) === FrameCodec::FLAG_PRIORITY) {
            if (\strlen($payload) < 5) {
                throw new \UnexpectedValueException('Invalid priority HEADERS frame.');
            }
            $payload = \substr($payload, 5);
        }
        return $payload;
    }

    private function stripDataPayload(string $payload, int $flags): string
    {
        if (($flags & FrameCodec::FLAG_PADDED) !== FrameCodec::FLAG_PADDED) {
            return $payload;
        }
        if ($payload === '') {
            throw new \UnexpectedValueException('Invalid padded DATA frame.');
        }
        $padLength = \ord($payload[0]);
        $payload = \substr($payload, 1);
        if ($padLength > \strlen($payload)) {
            throw new \UnexpectedValueException('Invalid DATA padding.');
        }
        return \substr($payload, 0, \strlen($payload) - $padLength);
    }

    /** @return array{0:int,1:array<string,list<string>>,2:string} */
    private function parseHttpResponse(string $response): array
    {
        $parts = \explode("\r\n\r\n", $response, 2);
        $head = $parts[0] ?? '';
        $body = $parts[1] ?? '';
        $lines = \explode("\r\n", $head);
        $statusLine = \array_shift($lines) ?: 'HTTP/1.1 500 Internal Server Error';
        $status = 500;
        if (\preg_match('/^HTTP\/\d\.\d\s+(\d{3})/', $statusLine, $m)) {
            $status = (int)$m[1];
        }
        $headers = [];
        foreach ($lines as $line) {
            if (!\str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = \explode(':', $line, 2);
            $name = \strtolower(\trim($name));
            if ($name === '') {
                continue;
            }
            $headers[$name][] = \trim($value);
        }
        return [$status, $headers, $body];
    }

    private static function encodeStatusHeader(int $status): string
    {
        return match ($status) {
            200 => self::encodeIndexedHeader(8),
            204 => self::encodeIndexedHeader(9),
            206 => self::encodeIndexedHeader(10),
            304 => self::encodeIndexedHeader(11),
            400 => self::encodeIndexedHeader(12),
            404 => self::encodeIndexedHeader(13),
            500 => self::encodeIndexedHeader(14),
            default => self::encodeLiteralHeader(':status', (string)$status, 8),
        };
    }

    private static function encodeIndexedHeader(int $index): string
    {
        if ($index < 1 || $index > 127) {
            throw new \InvalidArgumentException('Only small HPACK indexes are supported here.');
        }
        return \chr(0x80 | $index);
    }

    private static function encodeLiteralHeader(string $name, string $value, int $nameIndex = 0): string
    {
        if ($nameIndex > 0) {
            return self::encodeInteger($nameIndex, 4, 0x00) . self::encodeString($value);
        }
        return "\x00" . self::encodeString($name) . self::encodeString($value);
    }

    private static function encodeString(string $value): string
    {
        return self::encodeInteger(\strlen($value), 7, 0x00) . $value;
    }

    private static function encodeInteger(int $value, int $prefixBits, int $firstByteMask): string
    {
        $maxPrefix = (1 << $prefixBits) - 1;
        if ($value < $maxPrefix) {
            return \chr($firstByteMask | $value);
        }
        $out = \chr($firstByteMask | $maxPrefix);
        $value -= $maxPrefix;
        while ($value >= 128) {
            $out .= \chr(($value % 128) + 128);
            $value = intdiv($value, 128);
        }
        return $out . \chr($value);
    }
}
