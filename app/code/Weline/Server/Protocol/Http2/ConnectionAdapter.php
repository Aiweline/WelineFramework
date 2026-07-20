<?php
declare(strict_types=1);

namespace Weline\Server\Protocol\Http2;

/**
 * Stateful HTTP/2 server-side bridge for the existing WLS request pipeline.
 *
 * One adapter belongs to exactly one TLS connection. It owns HPACK order,
 * stream lifecycle and flow-control state; the Worker owns request execution.
 */
final class ConnectionAdapter
{
    public const MAX_CONCURRENT_STREAMS = 64;
    public const INITIAL_RECEIVE_WINDOW = 1048576;
    public const MAX_REQUEST_BODY_BYTES = 16777216;

    private const DEFAULT_FLOW_WINDOW = 65535;
    private const MAX_FLOW_WINDOW = 0x7fffffff;
    private const MAX_DRAIN_BYTES = 262144;

    private string $buffer = '';
    private bool $prefaceSeen = false;
    private bool $localGoaway = false;
    private bool $peerGoaway = false;
    private int $peerGoawayLastStreamId = 0x7fffffff;
    private int $lastClientStreamId = 0;
    private int $lastProcessedStreamId = 0;
    private int $continuationStreamId = 0;
    private int $connectionReceiveWindow = self::DEFAULT_FLOW_WINDOW;
    private int $connectionSendWindow = self::DEFAULT_FLOW_WINDOW;
    private int $peerInitialStreamWindow = self::DEFAULT_FLOW_WINDOW;
    private int $peerMaxFrameSize = FrameCodec::DEFAULT_MAX_FRAME_SIZE;
    private int $peerMaxConcurrentStreams = 0xffffffff;
    private HpackDecoder $decoder;

    /**
     * @var array<int,array{
     *   headers:string,
     *   decoded_headers:?array,
     *   body:string,
     *   end_headers:bool,
     *   remote_closed:bool,
     *   local_closed:bool,
     *   request_emitted:bool,
     *   receive_window:int,
     *   send_window:int
     * }>
     */
    private array $streams = [];

    /** @var array<int,array{body:string,offset:int}> */
    private array $pendingResponses = [];

    public function __construct(?HpackDecoder $decoder = null)
    {
        $this->decoder = $decoder ?? new HpackDecoder();
    }

    /**
     * @return array{
     *   status:'ok'|'incomplete'|'error',
     *   write:string,
     *   requests:list<array{stream_id:int,raw_request:string}>,
     *   reset_streams:list<int>,
     *   peer_goaway:bool,
     *   error?:string,
     *   error_code?:int
     * }
     */
    public function receive(string $bytes): array
    {
        if ($bytes !== '') {
            $this->buffer .= $bytes;
        }

        $write = '';
        $requests = [];
        $resetStreams = [];
        $peerGoawayObserved = false;

        if (!$this->prefaceSeen) {
            $prefaceLength = \strlen(FrameCodec::CLIENT_CONNECTION_PREFACE);
            if (\strlen($this->buffer) < $prefaceLength) {
                return $this->result('incomplete', '', [], [], false);
            }
            if (!\str_starts_with($this->buffer, FrameCodec::CLIENT_CONNECTION_PREFACE)) {
                return $this->connectionError(
                    $write,
                    $requests,
                    $resetStreams,
                    FrameCodec::ERROR_PROTOCOL_ERROR,
                    'invalid_client_preface'
                );
            }

            $this->buffer = \substr($this->buffer, $prefaceLength);
            $this->prefaceSeen = true;
            $this->connectionReceiveWindow = self::INITIAL_RECEIVE_WINDOW;
            $write .= FrameCodec::settings([
                FrameCodec::SETTINGS_ENABLE_PUSH => 0,
                FrameCodec::SETTINGS_MAX_CONCURRENT_STREAMS => self::MAX_CONCURRENT_STREAMS,
                FrameCodec::SETTINGS_INITIAL_WINDOW_SIZE => self::INITIAL_RECEIVE_WINDOW,
                FrameCodec::SETTINGS_MAX_HEADER_LIST_SIZE => 65536,
            ]);
            $write .= FrameCodec::windowUpdate(
                0,
                self::INITIAL_RECEIVE_WINDOW - self::DEFAULT_FLOW_WINDOW
            );
        }

        while (true) {
            $frame = FrameCodec::decodeOne($this->buffer);
            if (($frame['status'] ?? '') === 'incomplete') {
                break;
            }
            if (($frame['status'] ?? '') === 'error') {
                return $this->connectionError(
                    $write,
                    $requests,
                    $resetStreams,
                    FrameCodec::ERROR_FRAME_SIZE_ERROR,
                    (string)($frame['error'] ?? 'frame_error')
                );
            }

            $this->buffer = \substr($this->buffer, (int)$frame['consumed']);
            $type = (int)$frame['type'];
            $flags = (int)$frame['flags'];
            $streamId = (int)$frame['stream_id'];
            $payload = (string)$frame['payload'];

            if ($this->continuationStreamId !== 0
                && ($type !== FrameCodec::TYPE_CONTINUATION || $streamId !== $this->continuationStreamId)
            ) {
                return $this->connectionError(
                    $write,
                    $requests,
                    $resetStreams,
                    FrameCodec::ERROR_PROTOCOL_ERROR,
                    'interleaved_header_block'
                );
            }

            try {
                if ($type === FrameCodec::TYPE_SETTINGS) {
                    $settingsError = $this->applyPeerSettings($flags, $streamId, $payload, $write);
                    if ($settingsError !== null) {
                        return $this->connectionError(
                            $write,
                            $requests,
                            $resetStreams,
                            $settingsError['code'],
                            $settingsError['error']
                        );
                    }
                    continue;
                }

                if ($type === FrameCodec::TYPE_PING) {
                    if ($streamId !== 0 || \strlen($payload) !== 8) {
                        return $this->connectionError(
                            $write,
                            $requests,
                            $resetStreams,
                            FrameCodec::ERROR_FRAME_SIZE_ERROR,
                            'invalid_ping'
                        );
                    }
                    if (($flags & FrameCodec::FLAG_ACK) !== FrameCodec::FLAG_ACK) {
                        $write .= FrameCodec::encode(FrameCodec::TYPE_PING, FrameCodec::FLAG_ACK, 0, $payload);
                    }
                    continue;
                }

                if ($type === FrameCodec::TYPE_GOAWAY) {
                    if ($streamId !== 0 || \strlen($payload) < 8) {
                        return $this->connectionError(
                            $write,
                            $requests,
                            $resetStreams,
                            FrameCodec::ERROR_FRAME_SIZE_ERROR,
                            'invalid_goaway'
                        );
                    }
                    $parts = \unpack('Nlast/Nerror', \substr($payload, 0, 8));
                    $this->peerGoaway = true;
                    $this->peerGoawayLastStreamId = ((int)($parts['last'] ?? 0)) & 0x7fffffff;
                    $peerGoawayObserved = true;
                    continue;
                }

                if ($type === FrameCodec::TYPE_WINDOW_UPDATE) {
                    $windowError = $this->applyWindowUpdate($streamId, $payload, $write, $resetStreams);
                    if ($windowError !== null) {
                        return $this->connectionError(
                            $write,
                            $requests,
                            $resetStreams,
                            $windowError['code'],
                            $windowError['error']
                        );
                    }
                    continue;
                }

                if ($type === FrameCodec::TYPE_RST_STREAM) {
                    if ($streamId <= 0 || \strlen($payload) !== 4) {
                        return $this->connectionError(
                            $write,
                            $requests,
                            $resetStreams,
                            FrameCodec::ERROR_FRAME_SIZE_ERROR,
                            'invalid_rst_stream'
                        );
                    }
                    $this->dropStream($streamId);
                    $resetStreams[] = $streamId;
                    continue;
                }

                if ($type === FrameCodec::TYPE_PRIORITY) {
                    if ($streamId <= 0 || \strlen($payload) !== 5) {
                        return $this->connectionError(
                            $write,
                            $requests,
                            $resetStreams,
                            FrameCodec::ERROR_FRAME_SIZE_ERROR,
                            'invalid_priority'
                        );
                    }
                    continue;
                }

                if ($type === FrameCodec::TYPE_PUSH_PROMISE) {
                    return $this->connectionError(
                        $write,
                        $requests,
                        $resetStreams,
                        FrameCodec::ERROR_PROTOCOL_ERROR,
                        'client_push_promise'
                    );
                }

                if ($type === FrameCodec::TYPE_HEADERS) {
                    if (!$this->openClientStream($streamId, $write, $resetStreams)) {
                        continue;
                    }
                    $this->streams[$streamId]['headers'] = $this->stripHeadersPayload($payload, $flags);
                    $this->streams[$streamId]['end_headers']
                        = (($flags & FrameCodec::FLAG_END_HEADERS) === FrameCodec::FLAG_END_HEADERS);
                    $this->streams[$streamId]['remote_closed']
                        = (($flags & FrameCodec::FLAG_END_STREAM) === FrameCodec::FLAG_END_STREAM);

                    if ($this->streams[$streamId]['end_headers']) {
                        $this->decodeStreamHeaders($streamId);
                    } else {
                        $this->continuationStreamId = $streamId;
                    }
                    $request = $this->emitRequestIfComplete($streamId);
                    if ($request !== null) {
                        $requests[] = $request;
                    }
                    continue;
                }

                if ($type === FrameCodec::TYPE_CONTINUATION) {
                    if ($streamId <= 0 || !isset($this->streams[$streamId])) {
                        return $this->connectionError(
                            $write,
                            $requests,
                            $resetStreams,
                            FrameCodec::ERROR_PROTOCOL_ERROR,
                            'continuation_without_headers'
                        );
                    }
                    $this->streams[$streamId]['headers'] .= $payload;
                    if (($flags & FrameCodec::FLAG_END_HEADERS) === FrameCodec::FLAG_END_HEADERS) {
                        $this->streams[$streamId]['end_headers'] = true;
                        $this->continuationStreamId = 0;
                        $this->decodeStreamHeaders($streamId);
                        $request = $this->emitRequestIfComplete($streamId);
                        if ($request !== null) {
                            $requests[] = $request;
                        }
                    }
                    continue;
                }

                if ($type === FrameCodec::TYPE_DATA) {
                    if ($streamId <= 0) {
                        return $this->connectionError(
                            $write,
                            $requests,
                            $resetStreams,
                            FrameCodec::ERROR_PROTOCOL_ERROR,
                            'data_on_connection_stream'
                        );
                    }
                    if (!isset($this->streams[$streamId])) {
                        $write .= FrameCodec::rstStream($streamId, FrameCodec::ERROR_STREAM_CLOSED);
                        $resetStreams[] = $streamId;
                        continue;
                    }
                    if ($this->streams[$streamId]['remote_closed']) {
                        $this->resetStream($streamId, FrameCodec::ERROR_STREAM_CLOSED, $write, $resetStreams);
                        continue;
                    }

                    $flowBytes = \strlen($payload);
                    if ($flowBytes > $this->connectionReceiveWindow
                        || $flowBytes > $this->streams[$streamId]['receive_window']
                    ) {
                        return $this->connectionError(
                            $write,
                            $requests,
                            $resetStreams,
                            FrameCodec::ERROR_FLOW_CONTROL_ERROR,
                            'receive_window_exhausted'
                        );
                    }
                    $this->connectionReceiveWindow -= $flowBytes;
                    $this->streams[$streamId]['receive_window'] -= $flowBytes;
                    $this->streams[$streamId]['body'] .= $this->stripDataPayload($payload, $flags);

                    if (\strlen($this->streams[$streamId]['body']) > self::MAX_REQUEST_BODY_BYTES) {
                        $this->connectionReceiveWindow += $flowBytes;
                        if ($flowBytes > 0) {
                            $write .= FrameCodec::windowUpdate(0, $flowBytes);
                        }
                        $this->resetStream(
                            $streamId,
                            FrameCodec::ERROR_ENHANCE_YOUR_CALM,
                            $write,
                            $resetStreams
                        );
                        continue;
                    }

                    if ($flowBytes > 0) {
                        $this->connectionReceiveWindow += $flowBytes;
                        $this->streams[$streamId]['receive_window'] += $flowBytes;
                        $write .= FrameCodec::windowUpdate(0, $flowBytes);
                        $write .= FrameCodec::windowUpdate($streamId, $flowBytes);
                    }

                    if (($flags & FrameCodec::FLAG_END_STREAM) === FrameCodec::FLAG_END_STREAM) {
                        $this->streams[$streamId]['remote_closed'] = true;
                        $request = $this->emitRequestIfComplete($streamId);
                        if ($request !== null) {
                            $requests[] = $request;
                        }
                    }
                    continue;
                }

                // Unknown extension frame types are ignored as required by RFC 7540.
            } catch (\Throwable $exception) {
                return $this->connectionError(
                    $write,
                    $requests,
                    $resetStreams,
                    FrameCodec::ERROR_COMPRESSION_ERROR,
                    $exception->getMessage()
                );
            }
        }

        return $this->result(
            $requests === [] ? 'incomplete' : 'ok',
            $write,
            $requests,
            $resetStreams,
            $peerGoawayObserved
        );
    }

    public function encodeResponse(int $streamId, string $httpResponse): string
    {
        [$status, $headers, $body] = $this->parseHttpResponse($httpResponse);
        return $this->encodeSimpleResponse($streamId, $status, $headers, $body);
    }

    /**
     * @param array<string,string|int|float|list<string|int|float>> $headers
     */
    public function encodeSimpleResponse(int $streamId, int $status, array $headers, string $body): string
    {
        if (!isset($this->streams[$streamId])
            || $this->streams[$streamId]['local_closed']
            || !($this->streams[$streamId]['request_emitted'] ?? false)
        ) {
            return '';
        }

        $headerBlock = self::encodeStatusHeader($status);
        foreach ($headers as $name => $values) {
            $lower = \strtolower((string)$name);
            if (\in_array($lower, ['connection', 'keep-alive', 'proxy-connection', 'transfer-encoding', 'upgrade'], true)) {
                continue;
            }
            foreach ((array)$values as $value) {
                $headerBlock .= self::encodeLiteralHeader($lower, (string)$value);
            }
        }

        if ($body === '') {
            $frames = $this->encodeHeaderBlockFrames($streamId, $headerBlock, true);
            $this->streams[$streamId]['local_closed'] = true;
            $this->cleanupClosedStream($streamId);
            return $frames;
        }

        $frames = $this->encodeHeaderBlockFrames($streamId, $headerBlock, false);
        $this->pendingResponses[$streamId] = ['body' => $body, 'offset' => 0];

        return $frames . $this->flushPendingResponses($streamId);
    }

    public function initiateGoaway(int $errorCode = FrameCodec::ERROR_NO_ERROR, string $debug = ''): string
    {
        if ($this->localGoaway) {
            return '';
        }
        $this->localGoaway = true;
        return FrameCodec::goaway($this->lastProcessedStreamId, $errorCode, \substr($debug, 0, 128));
    }

    public function hasPendingResponseData(): bool
    {
        return $this->pendingResponses !== [];
    }

    /**
     * True only while transport/request framing is genuinely incomplete.
     * Complete SETTINGS/PING/WINDOW_UPDATE frames never keep this state armed.
     */
    public function hasIncompleteRequestInput(): bool
    {
        if (!$this->prefaceSeen || $this->buffer !== '' || $this->continuationStreamId !== 0) {
            return true;
        }
        foreach ($this->streams as $stream) {
            if (!($stream['request_emitted'] ?? false)
                && (!(bool)($stream['end_headers'] ?? false)
                    || !(bool)($stream['remote_closed'] ?? false))
            ) {
                return true;
            }
        }
        return false;
    }

    /** The connection has produced at least one complete request. */
    public function hasEmittedRequest(): bool
    {
        return $this->lastProcessedStreamId > 0;
    }

    /**
     * Continue producing a bounded DATA batch after the transport write queue drains.
     *
     * A peer may grant a window larger than MAX_DRAIN_BYTES in one WINDOW_UPDATE.
     * The Worker must therefore pull another bounded batch after it has written the
     * previous one instead of waiting for an update the peer has no reason to send.
     */
    public function drainPendingResponseData(): string
    {
        return $this->flushPendingResponses();
    }

    public function hasActiveStreams(): bool
    {
        return $this->streams !== [];
    }

    public function peerGoawayReceived(): bool
    {
        return $this->peerGoaway;
    }

    /** @return array<string,int|bool|list<int>> */
    public function diagnostics(): array
    {
        return [
            'preface_seen' => $this->prefaceSeen,
            'local_goaway' => $this->localGoaway,
            'peer_goaway' => $this->peerGoaway,
            'peer_goaway_last_stream_id' => $this->peerGoawayLastStreamId,
            'last_client_stream_id' => $this->lastClientStreamId,
            'last_processed_stream_id' => $this->lastProcessedStreamId,
            'active_streams' => $this->activeStreamCount(),
            'pending_response_streams' => \array_values(\array_map('intval', \array_keys($this->pendingResponses))),
            'connection_receive_window' => $this->connectionReceiveWindow,
            'connection_send_window' => $this->connectionSendWindow,
            'peer_initial_stream_window' => $this->peerInitialStreamWindow,
            'peer_max_frame_size' => $this->peerMaxFrameSize,
            'peer_max_concurrent_streams' => $this->peerMaxConcurrentStreams,
            'local_max_concurrent_streams' => self::MAX_CONCURRENT_STREAMS,
        ];
    }

    /**
     * @return array{code:int,error:string}|null
     */
    private function applyPeerSettings(int $flags, int $streamId, string $payload, string &$write): ?array
    {
        if ($streamId !== 0) {
            return ['code' => FrameCodec::ERROR_PROTOCOL_ERROR, 'error' => 'settings_on_stream'];
        }
        if (($flags & FrameCodec::FLAG_ACK) === FrameCodec::FLAG_ACK) {
            return $payload === ''
                ? null
                : ['code' => FrameCodec::ERROR_FRAME_SIZE_ERROR, 'error' => 'settings_ack_payload'];
        }
        if ((\strlen($payload) % 6) !== 0) {
            return ['code' => FrameCodec::ERROR_FRAME_SIZE_ERROR, 'error' => 'settings_payload_size'];
        }

        for ($offset = 0, $length = \strlen($payload); $offset < $length; $offset += 6) {
            $setting = \unpack('nid/Nvalue', \substr($payload, $offset, 6));
            $id = (int)($setting['id'] ?? 0);
            $value = (int)($setting['value'] ?? 0);

            if ($id === FrameCodec::SETTINGS_ENABLE_PUSH && $value > 1) {
                return ['code' => FrameCodec::ERROR_PROTOCOL_ERROR, 'error' => 'invalid_enable_push'];
            }
            if ($id === FrameCodec::SETTINGS_MAX_CONCURRENT_STREAMS) {
                $this->peerMaxConcurrentStreams = $value;
                continue;
            }
            if ($id === FrameCodec::SETTINGS_INITIAL_WINDOW_SIZE) {
                if ($value > self::MAX_FLOW_WINDOW) {
                    return ['code' => FrameCodec::ERROR_FLOW_CONTROL_ERROR, 'error' => 'invalid_initial_window'];
                }
                $delta = $value - $this->peerInitialStreamWindow;
                foreach ($this->streams as $openStreamId => $state) {
                    $newWindow = $state['send_window'] + $delta;
                    if ($newWindow > self::MAX_FLOW_WINDOW || $newWindow < -self::MAX_FLOW_WINDOW) {
                        return ['code' => FrameCodec::ERROR_FLOW_CONTROL_ERROR, 'error' => 'stream_window_overflow'];
                    }
                    $this->streams[$openStreamId]['send_window'] = $newWindow;
                }
                $this->peerInitialStreamWindow = $value;
                continue;
            }
            if ($id === FrameCodec::SETTINGS_MAX_FRAME_SIZE) {
                if ($value < FrameCodec::DEFAULT_MAX_FRAME_SIZE || $value > 0x00ffffff) {
                    return ['code' => FrameCodec::ERROR_PROTOCOL_ERROR, 'error' => 'invalid_max_frame_size'];
                }
                $this->peerMaxFrameSize = $value;
            }
        }

        $write .= FrameCodec::settingsAck();
        $write .= $this->flushPendingResponses();
        return null;
    }

    /**
     * @return array{code:int,error:string}|null
     */
    private function applyWindowUpdate(
        int $streamId,
        string $payload,
        string &$write,
        array &$resetStreams
    ): ?array {
        if (\strlen($payload) !== 4) {
            return ['code' => FrameCodec::ERROR_FRAME_SIZE_ERROR, 'error' => 'window_update_size'];
        }
        $decoded = \unpack('Nincrement', $payload);
        $increment = ((int)($decoded['increment'] ?? 0)) & 0x7fffffff;
        if ($increment === 0) {
            if ($streamId === 0) {
                return ['code' => FrameCodec::ERROR_PROTOCOL_ERROR, 'error' => 'zero_connection_window_update'];
            }
            $this->resetStream($streamId, FrameCodec::ERROR_PROTOCOL_ERROR, $write, $resetStreams);
            return null;
        }

        if ($streamId === 0) {
            if ($this->connectionSendWindow > self::MAX_FLOW_WINDOW - $increment) {
                return ['code' => FrameCodec::ERROR_FLOW_CONTROL_ERROR, 'error' => 'connection_window_overflow'];
            }
            $this->connectionSendWindow += $increment;
            $write .= $this->flushPendingResponses();
            return null;
        }

        if (!isset($this->streams[$streamId])) {
            return null;
        }
        if ($this->streams[$streamId]['send_window'] > self::MAX_FLOW_WINDOW - $increment) {
            $this->resetStream($streamId, FrameCodec::ERROR_FLOW_CONTROL_ERROR, $write, $resetStreams);
            return null;
        }
        $this->streams[$streamId]['send_window'] += $increment;
        $write .= $this->flushPendingResponses($streamId);
        return null;
    }

    private function openClientStream(int $streamId, string &$write, array &$resetStreams): bool
    {
        if ($streamId <= 0 || ($streamId & 1) === 0) {
            throw new \UnexpectedValueException('client_stream_id_must_be_odd');
        }
        if (isset($this->streams[$streamId])) {
            $this->resetStream($streamId, FrameCodec::ERROR_PROTOCOL_ERROR, $write, $resetStreams);
            return false;
        }
        if ($streamId <= $this->lastClientStreamId) {
            throw new \UnexpectedValueException('client_stream_id_not_increasing');
        }

        $this->lastClientStreamId = $streamId;
        if ($this->localGoaway || $this->peerGoaway || $this->activeStreamCount() >= self::MAX_CONCURRENT_STREAMS) {
            $write .= FrameCodec::rstStream($streamId, FrameCodec::ERROR_REFUSED_STREAM);
            $resetStreams[] = $streamId;
            return false;
        }

        $this->streams[$streamId] = [
            'headers' => '',
            'decoded_headers' => null,
            'body' => '',
            'end_headers' => false,
            'remote_closed' => false,
            'local_closed' => false,
            'request_emitted' => false,
            'receive_window' => self::INITIAL_RECEIVE_WINDOW,
            'send_window' => $this->peerInitialStreamWindow,
        ];
        return true;
    }

    private function decodeStreamHeaders(int $streamId): void
    {
        if (!isset($this->streams[$streamId]) || $this->streams[$streamId]['decoded_headers'] !== null) {
            return;
        }
        $this->streams[$streamId]['decoded_headers'] = $this->decoder->decode(
            $this->streams[$streamId]['headers']
        );
        $this->streams[$streamId]['headers'] = '';
    }

    /** @return array{stream_id:int,raw_request:string}|null */
    private function emitRequestIfComplete(int $streamId): ?array
    {
        if (!isset($this->streams[$streamId])) {
            return null;
        }
        $stream = $this->streams[$streamId];
        if (!$stream['end_headers'] || !$stream['remote_closed'] || $stream['request_emitted']) {
            return null;
        }

        $request = $this->buildRawRequest($streamId);
        $this->streams[$streamId]['request_emitted'] = true;
        $this->streams[$streamId]['body'] = '';
        $this->streams[$streamId]['decoded_headers'] = [];
        $this->lastProcessedStreamId = \max($this->lastProcessedStreamId, $streamId);
        return $request;
    }

    /** @return array{stream_id:int,raw_request:string} */
    private function buildRawRequest(int $streamId): array
    {
        $stream = $this->streams[$streamId] ?? null;
        if ($stream === null || !\is_array($stream['decoded_headers'])) {
            throw new \UnexpectedValueException('HTTP/2 stream headers are incomplete.');
        }

        $pseudo = [];
        $regular = [];
        $regularSeen = false;
        foreach ($stream['decoded_headers'] as $header) {
            $name = \strtolower((string)$header['name']);
            $value = (string)$header['value'];
            if (\str_starts_with($name, ':')) {
                if ($regularSeen || isset($pseudo[$name])
                    || !\in_array($name, [':method', ':path', ':scheme', ':authority'], true)
                ) {
                    throw new \UnexpectedValueException('Invalid HTTP/2 pseudo-header sequence.');
                }
                $pseudo[$name] = $value;
                continue;
            }
            $regularSeen = true;
            if (\in_array($name, ['connection', 'keep-alive', 'proxy-connection', 'upgrade'], true)
                || ($name === 'transfer-encoding')
                || ($name === 'te' && \strtolower(\trim($value)) !== 'trailers')
            ) {
                throw new \UnexpectedValueException('HTTP/2 connection-specific header is forbidden.');
            }
            $regular[$name][] = $value;
        }

        $method = \strtoupper((string)($pseudo[':method'] ?? ''));
        $path = (string)($pseudo[':path'] ?? '');
        $scheme = \strtolower((string)($pseudo[':scheme'] ?? 'https'));
        $authority = (string)($pseudo[':authority'] ?? ($regular['host'][0] ?? ''));
        if ($method === '' || $path === '' || $authority === '' || !\in_array($scheme, ['http', 'https'], true)) {
            throw new \UnexpectedValueException('HTTP/2 request is missing required pseudo headers.');
        }
        if ($path !== '*' && !\str_starts_with($path, '/')) {
            throw new \UnexpectedValueException('HTTP/2 :path must be origin-form.');
        }

        if (isset($regular['content-length'])) {
            if (\count($regular['content-length']) !== 1
                || !\ctype_digit($regular['content-length'][0])
                || (int)$regular['content-length'][0] !== \strlen($stream['body'])
            ) {
                throw new \UnexpectedValueException('HTTP/2 content-length mismatch.');
            }
        }

        $raw = $method . ' ' . $path . " HTTP/1.1\r\n";
        $raw .= 'Host: ' . $authority . "\r\n";
        $raw .= 'X-Forwarded-Proto: ' . $scheme . "\r\n";
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

    private function encodeHeaderBlockFrames(int $streamId, string $headerBlock, bool $endStream): string
    {
        $chunks = \str_split($headerBlock, $this->peerMaxFrameSize);
        if ($chunks === []) {
            $chunks = [''];
        }

        $lastIndex = \count($chunks) - 1;
        $frames = '';
        foreach ($chunks as $index => $chunk) {
            if ($index === 0) {
                $flags = $endStream ? FrameCodec::FLAG_END_STREAM : 0;
                if ($index === $lastIndex) {
                    $flags |= FrameCodec::FLAG_END_HEADERS;
                }
                $frames .= FrameCodec::encode(
                    FrameCodec::TYPE_HEADERS,
                    $flags,
                    $streamId,
                    $chunk
                );
                continue;
            }

            $flags = $index === $lastIndex ? FrameCodec::FLAG_END_HEADERS : 0;
            $frames .= FrameCodec::encode(
                FrameCodec::TYPE_CONTINUATION,
                $flags,
                $streamId,
                $chunk
            );
        }

        return $frames;
    }

    private function flushPendingResponses(?int $onlyStreamId = null): string
    {
        if ($this->pendingResponses === [] || $this->connectionSendWindow <= 0) {
            return '';
        }

        $frames = '';
        $budget = self::MAX_DRAIN_BYTES;
        do {
            $progress = false;
            $streamIds = $onlyStreamId === null
                ? \array_keys($this->pendingResponses)
                : [$onlyStreamId];

            foreach ($streamIds as $streamId) {
                if ($budget <= 0 || !isset($this->pendingResponses[$streamId], $this->streams[$streamId])) {
                    continue;
                }
                $pending = $this->pendingResponses[$streamId];
                $remaining = \strlen($pending['body']) - $pending['offset'];
                if ($remaining <= 0) {
                    unset($this->pendingResponses[$streamId]);
                    $this->streams[$streamId]['local_closed'] = true;
                    $this->cleanupClosedStream($streamId);
                    continue;
                }

                $available = \min(
                    $remaining,
                    $budget,
                    $this->peerMaxFrameSize,
                    $this->connectionSendWindow,
                    $this->streams[$streamId]['send_window']
                );
                if ($available <= 0) {
                    continue;
                }

                $chunk = \substr($pending['body'], $pending['offset'], $available);
                $newOffset = $pending['offset'] + $available;
                $isLast = $newOffset >= \strlen($pending['body']);
                $frames .= FrameCodec::encode(
                    FrameCodec::TYPE_DATA,
                    $isLast ? FrameCodec::FLAG_END_STREAM : 0,
                    $streamId,
                    $chunk
                );
                $this->connectionSendWindow -= $available;
                $this->streams[$streamId]['send_window'] -= $available;
                $budget -= $available;
                $progress = true;

                if ($isLast) {
                    unset($this->pendingResponses[$streamId]);
                    $this->streams[$streamId]['local_closed'] = true;
                    $this->cleanupClosedStream($streamId);
                } else {
                    $this->pendingResponses[$streamId]['offset'] = $newOffset;
                }
            }
        } while ($progress && $budget > 0 && $this->connectionSendWindow > 0);

        return $frames;
    }

    private function resetStream(int $streamId, int $errorCode, string &$write, array &$resetStreams): void
    {
        if ($streamId <= 0) {
            return;
        }
        $write .= FrameCodec::rstStream($streamId, $errorCode);
        $this->dropStream($streamId);
        $resetStreams[] = $streamId;
    }

    private function dropStream(int $streamId): void
    {
        unset($this->streams[$streamId], $this->pendingResponses[$streamId]);
        if ($this->continuationStreamId === $streamId) {
            $this->continuationStreamId = 0;
        }
    }

    private function cleanupClosedStream(int $streamId): void
    {
        if (!isset($this->streams[$streamId])) {
            return;
        }
        if ($this->streams[$streamId]['remote_closed'] && $this->streams[$streamId]['local_closed']) {
            $this->dropStream($streamId);
        }
    }

    private function activeStreamCount(): int
    {
        return \count($this->streams);
    }

    /**
     * @param list<array{stream_id:int,raw_request:string}> $requests
     * @param list<int> $resetStreams
     * @return array<string,mixed>
     */
    private function connectionError(
        string $write,
        array $requests,
        array $resetStreams,
        int $errorCode,
        string $error
    ): array {
        if (!$this->localGoaway) {
            $this->localGoaway = true;
            $write .= FrameCodec::goaway(
                $this->lastProcessedStreamId,
                $errorCode,
                \substr($error, 0, 128)
            );
        }

        return [
            'status' => 'error',
            'write' => $write,
            'requests' => $requests,
            'reset_streams' => \array_values(\array_unique($resetStreams)),
            'peer_goaway' => false,
            'error' => $error,
            'error_code' => $errorCode,
        ];
    }

    /**
     * @param list<array{stream_id:int,raw_request:string}> $requests
     * @param list<int> $resetStreams
     * @return array<string,mixed>
     */
    private function result(
        string $status,
        string $write,
        array $requests,
        array $resetStreams,
        bool $peerGoaway
    ): array {
        return [
            'status' => $status,
            'write' => $write,
            'requests' => $requests,
            'reset_streams' => \array_values(\array_unique($resetStreams)),
            'peer_goaway' => $peerGoaway,
        ];
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
        if (\preg_match('/^HTTP\/\d\.\d\s+(\d{3})/', $statusLine, $matches)) {
            $status = (int)$matches[1];
        }

        $headers = [];
        foreach ($lines as $line) {
            if (!\str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = \explode(':', $line, 2);
            $name = \strtolower(\trim($name));
            if ($name !== '') {
                $headers[$name][] = \trim($value);
            }
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
            $value = \intdiv($value, 128);
        }
        return $out . \chr($value);
    }
}
