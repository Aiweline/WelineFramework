<?php
declare(strict_types=1);

/**
 * Transport-neutral HTTP message helpers shared by the stream workers.
 *
 * Keep these functions free of listener, socket, TLS and event-loop state so
 * every transport adapter observes the same request/response wire semantics.
 */

/**
 * Parse exactly one HTTP/1.x request frame from a connection buffer.
 *
 * WLS intentionally does not decode request Transfer-Encoding. Accepting a
 * Transfer-Encoding value while a downstream parser uses Content-Length (or
 * vice versa) would create a request-smuggling boundary, so every TE form is
 * rejected. Repeated Content-Length fields are accepted only when every value
 * is the same non-negative decimal integer, as required by HTTP/1.1 framing.
 *
 * @return array{
 *     status:'incomplete'|'complete'|'error',
 *     consumed:int,
 *     request:string,
 *     error:string,
 *     status_code:int,
 *     header_bytes:int,
 *     content_length:int,
 *     method?:string,
 *     target?:string,
 *     protocol?:string,
 *     headers?:array<string,string>,
 *     body?:string
 * }
 */
function wlsParseHttpRequestFrame(
    string $buffer,
    int $maxHeaderBytes = 65536,
    int $maxBodyBytes = 16777216,
): array {
    $bufferLength = \strlen($buffer);
    $maxHeaderBytes = \max(1024, $maxHeaderBytes);
    $maxBodyBytes = \max(0, $maxBodyBytes);
    $headerEnd = \strpos($buffer, "\r\n\r\n");
    if ($headerEnd === false) {
        if ($bufferLength > $maxHeaderBytes) {
            return wlsInvalidHttpRequestFrame('header_too_large', 400);
        }

        return wlsIncompleteHttpRequestFrame();
    }

    $headerBytes = $headerEnd + 4;
    if ($headerBytes > $maxHeaderBytes) {
        return wlsInvalidHttpRequestFrame('header_too_large', 400, $headerBytes);
    }

    $headerBlock = \substr($buffer, 0, $headerEnd);
    if ($headerBlock === ''
        || \preg_match('/(?<!\r)\n|\r(?!\n)/', $headerBlock) === 1
    ) {
        return wlsInvalidHttpRequestFrame('invalid_line_ending', 400, $headerBytes);
    }

    $lines = \explode("\r\n", $headerBlock);
    $requestLine = \array_shift($lines);
    if (!\is_string($requestLine)
        || \preg_match('/^([A-Z][A-Z0-9-]{0,31})\s+(\S{1,65535})\s+HTTP\/(1\.0|1\.1)$/D', $requestLine, $requestMatch) !== 1
    ) {
        return wlsInvalidHttpRequestFrame('invalid_request_line', 400, $headerBytes);
    }

    $contentLength = null;
    $transferEncodings = [];
    $headers = [];
    foreach ($lines as $line) {
        if ($line === '' || $line[0] === ' ' || $line[0] === "\t") {
            return wlsInvalidHttpRequestFrame('invalid_header_folding', 400, $headerBytes);
        }
        $separator = \strpos($line, ':');
        if ($separator === false) {
            return wlsInvalidHttpRequestFrame('invalid_header', 400, $headerBytes);
        }

        $rawName = \substr($line, 0, $separator);
        $name = \strtolower($rawName);
        $rawValue = \substr($line, $separator + 1);
        $value = \trim($rawValue, " \t");
        if ($rawName === ''
            || $rawName !== \trim($rawName)
            || \preg_match('/^[a-z0-9!#$%&\'*+.^_`|~-]+$/iD', $rawName) !== 1
            || \preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $rawValue) === 1
        ) {
            return wlsInvalidHttpRequestFrame('invalid_header', 400, $headerBytes);
        }
        $headers[$name] = isset($headers[$name])
            ? (
                $name === 'cookie'
                    ? $headers[$name] . '; ' . $value
                    : $headers[$name] . ', ' . $value
            )
            : $value;

        if ($name === 'transfer-encoding') {
            foreach (\explode(',', $value) as $encoding) {
                $encoding = \strtolower(\trim($encoding));
                if ($encoding === '') {
                    return wlsInvalidHttpRequestFrame('invalid_transfer_encoding', 400, $headerBytes);
                }
                $transferEncodings[] = $encoding;
            }
            continue;
        }
        if ($name !== 'content-length') {
            continue;
        }

        foreach (\explode(',', $value) as $rawLength) {
            $rawLength = \trim($rawLength);
            if ($rawLength === '' || \preg_match('/^[0-9]+$/D', $rawLength) !== 1) {
                return wlsInvalidHttpRequestFrame('invalid_content_length', 400, $headerBytes);
            }
            $canonicalLength = \ltrim($rawLength, '0');
            $canonicalLength = $canonicalLength === '' ? '0' : $canonicalLength;
            if ($contentLength !== null && $contentLength !== $canonicalLength) {
                return wlsInvalidHttpRequestFrame('conflicting_content_length', 400, $headerBytes);
            }
            $contentLength = $canonicalLength;
        }
    }

    if ($transferEncodings !== [] && $contentLength !== null) {
        return wlsInvalidHttpRequestFrame(
            'transfer_encoding_with_content_length',
            400,
            $headerBytes,
        );
    }
    if ($transferEncodings !== []) {
        $reason = \in_array('chunked', $transferEncodings, true)
            ? 'unsupported_chunked_transfer_encoding'
            : 'unsupported_transfer_encoding';
        return wlsInvalidHttpRequestFrame($reason, 400, $headerBytes);
    }

    $contentLength ??= '0';
    $maxBodyString = (string)$maxBodyBytes;
    if (\strlen($contentLength) > \strlen($maxBodyString)
        || (\strlen($contentLength) === \strlen($maxBodyString)
            && \strcmp($contentLength, $maxBodyString) > 0)
    ) {
        return wlsInvalidHttpRequestFrame('body_too_large', 413, $headerBytes);
    }

    $bodyLength = (int)$contentLength;
    $consumed = $headerBytes + $bodyLength;
    if ($bufferLength < $consumed) {
        return [
            'status' => 'incomplete',
            'consumed' => 0,
            'request' => '',
            'error' => '',
            'status_code' => 0,
            'header_bytes' => $headerBytes,
            'content_length' => $bodyLength,
        ];
    }

    return [
        'status' => 'complete',
        'consumed' => $consumed,
        'request' => \substr($buffer, 0, $consumed),
        'error' => '',
        'status_code' => 0,
        'header_bytes' => $headerBytes,
        'content_length' => $bodyLength,
        'method' => (string)$requestMatch[1],
        'target' => (string)$requestMatch[2],
        'protocol' => 'HTTP/' . (string)$requestMatch[3],
        'headers' => $headers,
        'body' => \substr($buffer, $headerBytes, $bodyLength),
    ];
}

/** @return array{status:'incomplete',consumed:int,request:string,error:string,status_code:int,header_bytes:int,content_length:int} */
function wlsIncompleteHttpRequestFrame(): array
{
    return [
        'status' => 'incomplete',
        'consumed' => 0,
        'request' => '',
        'error' => '',
        'status_code' => 0,
        'header_bytes' => 0,
        'content_length' => 0,
    ];
}

/** @return array{status:'error',consumed:int,request:string,error:string,status_code:int,header_bytes:int,content_length:int} */
function wlsInvalidHttpRequestFrame(string $reason, int $statusCode = 400, int $headerBytes = 0): array
{
    return [
        'status' => 'error',
        'consumed' => 0,
        'request' => '',
        'error' => $reason,
        'status_code' => $statusCode === 413 ? 413 : 400,
        'header_bytes' => $headerBytes,
        'content_length' => 0,
    ];
}

function wlsHttpFramingErrorResponse(int $statusCode = 400): string
{
    if ($statusCode === 413) {
        $body = 'Request Entity Too Large';
        return "HTTP/1.1 413 Request Entity Too Large\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n"
            . 'Content-Length: ' . \strlen($body) . "\r\n"
            . "Connection: close\r\n\r\n"
            . $body;
    }

    $body = 'Bad Request';
    return "HTTP/1.1 400 Bad Request\r\n"
        . "Content-Type: text/plain; charset=utf-8\r\n"
        . 'Content-Length: ' . \strlen($body) . "\r\n"
        . "Connection: close\r\n\r\n"
        . $body;
}

function isRequestComplete(string $data): bool
{
    return wlsParseHttpRequestFrame($data)['status'] === 'complete';
}

function isKeepAlive(string $rawRequest): bool
{
    $protocol = '';
    $requestLineEnd = \strpos($rawRequest, "\r\n");
    $requestLine = $requestLineEnd === false
        ? $rawRequest
        : \substr($rawRequest, 0, $requestLineEnd);
    if (\preg_match('/\s+(HTTP\/1\.[01])$/D', $requestLine, $requestLineMatch) === 1) {
        $protocol = (string)$requestLineMatch[1];
    }

    $connectionValues = [];
    if (\stripos($rawRequest, "\r\nConnection:") !== false) {
        $headerEnd = \strpos($rawRequest, "\r\n\r\n");
        $headerBlock = $headerEnd === false ? $rawRequest : \substr($rawRequest, 0, $headerEnd);
        foreach (\explode("\r\n", $headerBlock) as $line) {
            $separator = \strpos($line, ':');
            if ($separator === false
                || \strtolower(\substr($line, 0, $separator)) !== 'connection'
            ) {
                continue;
            }
            $connectionValues[] = \trim(\substr($line, $separator + 1), " \t");
        }
    }

    return \Weline\Framework\Http\ConnectionSemantics::shouldKeepAlive(
        $protocol,
        \implode(',', $connectionValues),
    );
}

function getHeaderValue(string $rawRequest, string $headerName): ?string
{
    $pattern = '/^' . \preg_quote($headerName, '/') . ':\s*([^\r\n]+)/im';
    if (\preg_match($pattern, $rawRequest, $matches)) {
        $value = \trim($matches[1]);
        return $value === '' ? null : $value;
    }
    return null;
}

/**
 * Resolve one RFC 9110 byte range without allocating the resource body.
 *
 * WLS deliberately supports a single range only. Multipart ranges add a large
 * response-building surface and provide no benefit to the framework's static
 * asset use cases. A syntactically valid Range is ignored when If-Range does
 * not match; malformed or unsatisfiable ranges receive 416.
 *
 * @return array{status:'none'|'range'|'unsatisfiable',start:int,end:int,length:int}
 */
function wlsResolveStaticByteRange(
    ?string $rangeHeader,
    ?string $ifRangeHeader,
    int $fileSize,
    string $etag,
    int $mtime,
): array {
    $none = [
        'status' => 'none',
        'start' => 0,
        'end' => max(0, $fileSize - 1),
        'length' => max(0, $fileSize),
    ];
    $rangeHeader = \trim((string)$rangeHeader);
    if ($rangeHeader === '') {
        return $none;
    }

    $ifRangeHeader = \trim((string)$ifRangeHeader);
    if ($ifRangeHeader !== '') {
        if ($ifRangeHeader[0] === '"' || str_starts_with($ifRangeHeader, 'W/')) {
            if (str_starts_with($ifRangeHeader, 'W/') || !hash_equals($etag, $ifRangeHeader)) {
                return $none;
            }
        } else {
            $ifRangeTime = strtotime($ifRangeHeader);
            if ($ifRangeTime === false || $mtime > $ifRangeTime) {
                return $none;
            }
        }
    }

    if ($fileSize <= 0
        || preg_match('/^bytes=([0-9]*)-([0-9]*)$/D', $rangeHeader, $matches) !== 1
        || ($matches[1] === '' && $matches[2] === '')
    ) {
        return ['status' => 'unsatisfiable', 'start' => 0, 'end' => 0, 'length' => 0];
    }

    $rawStart = (string)$matches[1];
    $rawEnd = (string)$matches[2];
    if (($rawStart !== '' && strlen($rawStart) > 18)
        || ($rawEnd !== '' && strlen($rawEnd) > 18)
    ) {
        return ['status' => 'unsatisfiable', 'start' => 0, 'end' => 0, 'length' => 0];
    }

    if ($rawStart === '') {
        $suffixLength = (int)$rawEnd;
        if ($suffixLength <= 0) {
            return ['status' => 'unsatisfiable', 'start' => 0, 'end' => 0, 'length' => 0];
        }
        $length = min($suffixLength, $fileSize);
        $start = $fileSize - $length;
        $end = $fileSize - 1;
    } else {
        $start = (int)$rawStart;
        if ($start >= $fileSize) {
            return ['status' => 'unsatisfiable', 'start' => 0, 'end' => 0, 'length' => 0];
        }
        $end = $rawEnd === '' ? $fileSize - 1 : min((int)$rawEnd, $fileSize - 1);
        if ($end < $start) {
            return ['status' => 'unsatisfiable', 'start' => 0, 'end' => 0, 'length' => 0];
        }
        $length = $end - $start + 1;
    }

    return ['status' => 'range', 'start' => $start, 'end' => $end, 'length' => $length];
}

function wlsReadStaticFileSlice(string $filename, int $start, int $length): string|false
{
    if ($length < 0 || $start < 0) {
        return false;
    }
    if ($length === 0) {
        return '';
    }

    $handle = @\fopen($filename, 'rb');
    if (!\is_resource($handle)) {
        return false;
    }
    try {
        if ($start > 0 && \fseek($handle, $start, SEEK_SET) !== 0) {
            return false;
        }
        $content = '';
        while (\strlen($content) < $length && !\feof($handle)) {
            $chunk = \fread($handle, \min(65536, $length - \strlen($content)));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $content .= $chunk;
        }

        return \strlen($content) === $length ? $content : false;
    } finally {
        \fclose($handle);
    }
}

function wlsStaticRequestMethod(string $rawRequest): string
{
    $lineEnd = strpos($rawRequest, "\r\n");
    $requestLine = $lineEnd === false ? $rawRequest : substr($rawRequest, 0, $lineEnd);
    if (preg_match('/^([A-Z]+)\s+\S+\s+HTTP\/1\.[01]$/D', $requestLine, $matches) === 1) {
        return (string)$matches[1];
    }

    return 'GET';
}

/**
 * Benchmark probes may opt in to transport-neutral Worker attribution.
 *
 * The fast substring guard keeps ordinary requests away from header parsing
 * and response rewriting. Dynamic first-render probes already carry their own
 * marker, while server:benchmark uses the dedicated marker below.
 */
function wlsBenchmarkWorkerIdentityRequested(string $rawRequest): bool
{
    if (\stripos($rawRequest, 'x-wls-') === false) {
        return false;
    }

    return \stripos($rawRequest, "\r\nx-wls-benchmark-worker:") !== false
        || \stripos($rawRequest, "\r\nx-wls-dynamic-benchmark:") !== false;
}

function wlsDecorateFormattedBenchmarkWorkerIdentity(string $response, string $rawRequest): string
{
    if ($response === '' || !wlsBenchmarkWorkerIdentityRequested($rawRequest)) {
        return $response;
    }

    $workerId = (string)($_SERVER['WLS_WORKER_ID'] ?? $_ENV['WLS_WORKER_ID'] ?? \getenv('WLS_WORKER_ID') ?: '');
    $workerPort = (string)($_SERVER['WLS_PORT'] ?? $_ENV['WLS_PORT'] ?? \getenv('WLS_PORT') ?: '');

    $headers = ['X-WLS-Worker-PID' => (string)\getmypid()];
    if ($workerId !== '') {
        $headers['X-WLS-Worker-Id'] = $workerId;
    }
    if ($workerPort !== '') {
        $headers['X-WLS-Worker-Port'] = $workerPort;
    }

    return wlsSetFormattedHttpResponseHeaders($response, $headers);
}

/**
 * Performance-panel recording is opt-in on transport fast paths. Developer
 * mode alone must not turn every FPC/static hit into ObjectManager, random-id
 * and trace-store work.
 */
function wlsExplicitPerformanceDiagnosticsRequested(string $rawRequest): bool
{
    if (\stripos($rawRequest, 'performance-diagnostics') === false) {
        return false;
    }

    foreach (['X-WLS-Performance-Diagnostics', 'X-Weline-Performance-Diagnostics'] as $header) {
        $value = getHeaderValue($rawRequest, $header);
        if (\is_string($value)
            && \in_array(\strtolower(\trim($value)), ['1', 'true', 'yes', 'on'], true)
        ) {
            return true;
        }
    }

    return false;
}

function wlsPerformancePanelAllowed(string $rawRequest = ''): bool
{
    if (!wlsExplicitPerformanceDiagnosticsRequested($rawRequest)) {
        return false;
    }

    try {
        return \Weline\Framework\Manager\ObjectManager::getInstance(
            \Weline\Framework\Runtime\DeveloperAccessPolicy::class
        )->canAccessRawHttp($rawRequest);
    } catch (\Throwable) {
        return false;
    }
}

function wlsPerformancePanelRequestId(string $rawRequest): string
{
    foreach (['X-Weline-Request-Id', 'X-Request-Id'] as $header) {
        $value = getHeaderValue($rawRequest, $header);
        if (\is_string($value) && \preg_match('/^[a-zA-Z0-9_.:-]{8,128}$/', $value)) {
            return $value;
        }
    }

    try {
        return \bin2hex(\random_bytes(8)) . '-' . \dechex((int)(\microtime(true) * 1000000));
    } catch (\Throwable) {
        return \str_replace('.', '', \uniqid('wls', true));
    }
}

function wlsDecorateFormattedFpcFastResponseForPerformancePanel(
    string $response,
    string $rawRequest,
    float $elapsedMs,
    int $workerId,
    int $port,
    string $source = 'worker_fastpath',
): string {
    if (!wlsPerformancePanelAllowed($rawRequest)) {
        return $response;
    }

    $requestId = wlsPerformancePanelRequestId($rawRequest);
    $response = wlsSetFormattedHttpResponseHeaders($response, [
        'X-Weline-Request-Id' => $requestId,
        'X-WLS-Performance-Total' => (string)\round($elapsedMs, 2),
        'X-WLS-Performance-FPC-Fastpath' => 'worker',
    ]);
    wlsRecordFormattedFpcFastResponseForPerformancePanel(
        $rawRequest,
        $requestId,
        $elapsedMs,
        $workerId,
        $port,
        $source,
    );

    return $response;
}

function wlsDecorateFormattedStaticResponseForPerformancePanel(
    string $response,
    string $rawRequest,
    float $elapsedMs,
    int $workerId,
    int $port,
    array $cacheInfo = []
): string {
    if (!wlsPerformancePanelAllowed($rawRequest)) {
        return $response;
    }

    $cacheStatus = \strtolower((string)($cacheInfo['status'] ?? 'miss'));
    $source = match ($cacheStatus) {
        'hit' => 'static_memory',
        'missing' => 'static_missing',
        default => 'static_file',
    };
    $requestId = wlsPerformancePanelRequestId($rawRequest);
    $response = wlsSetFormattedHttpResponseHeader($response, 'X-Weline-Request-Id', $requestId);
    wlsRecordFormattedFpcFastResponseForPerformancePanel(
        $rawRequest,
        $requestId,
        $elapsedMs,
        $workerId,
        $port,
        $source,
        wlsFormattedHttpStatusCode($response)
    );

    return $response;
}

function wlsRecordFormattedFpcFastResponseForPerformancePanel(
    string $rawRequest,
    string $requestId,
    float $elapsedMs,
    int $workerId,
    int $port,
    string $source = 'worker_fastpath',
    int $status = 200
): void {
    try {
        if (!\class_exists(\Weline\Server\Service\WlsPerformanceTraceStore::class)) {
            return;
        }
        [$method, $target] = wlsPerformancePanelRequestLine($rawRequest);
        $host = \trim((string)(getHeaderValue($rawRequest, 'Host') ?? ''));
        $path = '/';
        $parsedPath = \parse_url($target, PHP_URL_PATH);
        if (\is_string($parsedPath) && $parsedPath !== '') {
            $path = $parsedPath;
        }
        $query = \parse_url($target, PHP_URL_QUERY);
        if (\is_string($query) && $query !== '') {
            $path .= '?' . $query;
        }
        \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Server\Service\WlsPerformanceTraceStore::class)
            ->record([], [
                'request_id' => $requestId,
                'method' => $method,
                'uri' => $path,
                'host' => $host,
                'status' => $status,
                'total_ms' => \round($elapsedMs, 2),
                'pre_telemetry_total_ms' => \round($elapsedMs, 2),
                'fpc_hit' => $source === 'static_memory'
                    || \str_contains($source, 'process')
                    || \str_contains($source, 'shared')
                    || \str_contains($source, 'formatted')
                    || $source === 'worker_fastpath',
                'fpc_source' => $source,
                'worker_id' => (string)$workerId,
                'worker_port' => (string)$port,
                'pid' => \getmypid() ?: 0,
            ]);
    } catch (\Throwable) {
    }
}

/**
 * 从 Cookie 头中解析指定 name 的值
 */
function getCookieValue(string $cookieHeader, string $name): ?string
{
    if ($cookieHeader === '') {
        return null;
    }
    $name = \preg_quote($name, '/');
    if (\preg_match('/\b' . $name . '=([^;\s]+)/', $cookieHeader, $m)) {
        $v = \trim($m[1], '"');
        return $v === '' ? null : $v;
    }
    return null;
}

/**
 * 注入 WLS 处理耗时响应头。
 * 仅添加 header，不修改 body / Content-Length，避免 Content-Length mismatch 导致浏览器 loading 挂死。
 * 前端通过 Server-Timing API 读取：performance.getEntriesByType('navigation')[0].serverTiming
 */
function injectWlsProcessTimeHeader(string $response, float $durationMs): string
{
    if ($durationMs < 500 && !\Weline\Server\Log\LogConfig::isVerboseWlsLog()) {
        return $response;
    }

    $pos = \strpos($response, "\r\n\r\n");
    if ($pos === false) {
        return $response;
    }
    $ms = \round($durationMs, 2);
    // Insert inside the original header/body separator so the previous header keeps its CRLF.
    $headers = "X-WLS-Process-Time: {$ms}\r\nServer-Timing: wls;dur={$ms};desc=\"WLS Process\"\r\n";
    return \substr_replace($response, $headers, $pos + 2, 0);
}

function wlsCompressFormattedHttpResponse(string $response, string $acceptEncoding): string
{
    if ($response === ''
        || \stripos($acceptEncoding, 'gzip') === false
        || !\function_exists('gzencode')) {
        return $response;
    }

    $headerEnd = \strpos($response, "\r\n\r\n");
    if ($headerEnd === false) {
        return $response;
    }

    $headersPart = \substr($response, 0, $headerEnd);
    $bodyPart = \substr($response, $headerEnd + 4);
    if ($bodyPart === '' || \strlen($bodyPart) < 1024) {
        return $response;
    }

    if (\preg_match('/^HTTP\/\d(?:\.\d)?\s+(204|205|304)\b/i', $headersPart)
        || \preg_match('/^Content-Encoding:/mi', $headersPart)) {
        return $response;
    }

    $contentType = '';
    if (\preg_match('/^Content-Type:\s*([^\r\n]+)/mi', $headersPart, $typeMatch)) {
        $contentType = \strtolower(\trim((string)$typeMatch[1]));
    }
    if ($contentType !== ''
        && !\str_starts_with($contentType, 'text/')
        && !\str_contains($contentType, 'application/json')
        && !\str_contains($contentType, 'application/javascript')
        && !\str_contains($contentType, 'application/xml')
        && !\str_contains($contentType, 'application/xhtml+xml')
        && !\str_contains($contentType, 'image/svg+xml')) {
        return $response;
    }

    $compressed = \gzencode($bodyPart, 6);
    if ($compressed === false) {
        return $response;
    }

    $headersPart .= "\r\nContent-Encoding: gzip";
    $headersPart = wlsSetFormattedHeader($headersPart, 'Content-Length', (string)\strlen($compressed));
    $headersPart = wlsAddFormattedVaryAcceptEncoding($headersPart);

    return $headersPart . "\r\n\r\n" . $compressed;
}

function wlsSetFormattedHeader(string $headersPart, string $name, string $value): string
{
    $replacement = $name . ': ' . $value;
    $lines = \preg_split('/\r\n|\n|\r/', $headersPart);
    if ($lines === false) {
        return \rtrim($headersPart, "\r\n") . "\r\n" . $replacement;
    }

    $prefix = \strtolower($name) . ':';
    $kept = [];
    foreach ($lines as $line) {
        if (\str_starts_with(\strtolower(\ltrim($line)), $prefix)) {
            continue;
        }
        $kept[] = $line;
    }

    return \rtrim(\implode("\r\n", $kept), "\r\n") . "\r\n" . $replacement;
}

function wlsSetFormattedHttpResponseHeader(string $response, string $name, string $value): string
{
    $headerEnd = \strpos($response, "\r\n\r\n");
    if ($headerEnd === false) {
        return $response;
    }

    $headersPart = \substr($response, 0, $headerEnd);
    $bodyPart = \substr($response, $headerEnd + 4);

    return wlsSetFormattedHeader($headersPart, $name, $value) . "\r\n\r\n" . $bodyPart;
}

/** @param array<string, scalar> $headers */
function wlsSetFormattedHttpResponseHeaders(string $response, array $headers): string
{
    if ($headers === []) {
        return $response;
    }

    $headerEnd = \strpos($response, "\r\n\r\n");
    if ($headerEnd === false) {
        return $response;
    }

    $headersPart = \substr($response, 0, $headerEnd);
    $replacementNames = [];
    foreach ($headers as $name => $_value) {
        $replacementNames[\strtolower(\trim((string)$name))] = true;
    }

    $lines = \preg_split('/\r\n|\n|\r/', $headersPart);
    if ($lines === false) {
        return $response;
    }

    $kept = [];
    foreach ($lines as $line) {
        $colon = \strpos($line, ':');
        if ($colon !== false) {
            $lineName = \strtolower(\trim(\substr($line, 0, $colon)));
            if (isset($replacementNames[$lineName])) {
                continue;
            }
        }
        $kept[] = $line;
    }
    foreach ($headers as $name => $value) {
        $kept[] = \trim((string)$name) . ': ' . (string)$value;
    }

    return \rtrim(\implode("\r\n", $kept), "\r\n")
        . "\r\n\r\n"
        . \substr($response, $headerEnd + 4);
}

function wlsFormattedHttpStatusCode(string $response): int
{
    if (\preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d{3})\b/', $response, $matches) === 1) {
        return (int)$matches[1];
    }

    return 200;
}

function wlsPerformancePanelRequestLine(string $rawRequest): array
{
    if (\preg_match('/^([A-Z]+)\s+(\S+)\s+HTTP\/\d(?:\.\d)?/i', $rawRequest, $matches)) {
        return [\strtoupper((string)$matches[1]), (string)$matches[2]];
    }

    return ['GET', '/'];
}

function wlsAddFormattedVaryAcceptEncoding(string $headersPart): string
{
    if (!\preg_match('/^Vary:\s*([^\r\n]*)$/mi', $headersPart, $match)) {
        return \rtrim($headersPart, "\r\n") . "\r\nVary: Accept-Encoding";
    }

    $varyValue = (string)($match[1] ?? '');
    foreach (\array_map('trim', \explode(',', $varyValue)) as $part) {
        if (\strcasecmp($part, 'Accept-Encoding') === 0) {
            return $headersPart;
        }
    }

    $newValue = \trim($varyValue) === '' ? 'Accept-Encoding' : $varyValue . ', Accept-Encoding';
    return (string)\preg_replace('/^Vary:\s*[^\r\n]*$/mi', 'Vary: ' . $newValue, $headersPart, 1);
}
