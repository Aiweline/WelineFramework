#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Minimal Admin REST demo for remote translation assist.
 * Env: WELINE_BASE_URL, WELINE_ADMIN_PREFIX, WELINE_ADMIN_TOKEN
 * Optional: WELINE_WEBSITE_ID (default 0), WELINE_LOCALES (comma-separated, default en_US)
 * Optional: WELINE_REMOTE_TYPE=phrase|meta|local_model (default phrase)
 *
 * Does NOT commit or hardcode tokens. ingest/collect examples are commented.
 */

$base = rtrim((string)(getenv('WELINE_BASE_URL') ?: ''), '/');
$prefix = trim((string)(getenv('WELINE_ADMIN_PREFIX') ?: ''), '/');
$token = (string)(getenv('WELINE_ADMIN_TOKEN') ?: '');
$websiteId = (int)(getenv('WELINE_WEBSITE_ID') !== false && getenv('WELINE_WEBSITE_ID') !== ''
    ? getenv('WELINE_WEBSITE_ID')
    : 0);
$localesRaw = (string)(getenv('WELINE_LOCALES') ?: 'en_US');
$locales = array_values(array_filter(array_map('trim', explode(',', $localesRaw))));
$type = strtolower(trim((string)(getenv('WELINE_REMOTE_TYPE') ?: 'phrase')));
if (!in_array($type, ['phrase', 'meta', 'local_model'], true)) {
    fwrite(STDERR, "WELINE_REMOTE_TYPE must be phrase|meta|local_model\n");
    exit(1);
}

if ($base === '' || $prefix === '' || $token === '') {
    fwrite(STDERR, "Missing env: WELINE_BASE_URL / WELINE_ADMIN_PREFIX / WELINE_ADMIN_TOKEN\n");
    exit(1);
}
if ($locales === []) {
    fwrite(STDERR, "WELINE_LOCALES must list at least one locale\n");
    exit(1);
}

$root = $base . '/' . $prefix;

/**
 * @param array<string,string> $headers
 * @return array{code:int,body:mixed,raw:string}
 */
function weline_request(string $method, string $url, ?array $json, string $token): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }
    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . $token,
    ];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ];
    if ($json !== null) {
        $payload = json_encode($json, JSON_UNESCAPED_UNICODE);
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_HTTPHEADER] = $headers;
        $opts[CURLOPT_POSTFIELDS] = $payload;
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        throw new RuntimeException('curl error: ' . $err);
    }
    $body = json_decode($raw, true);

    return [
        'code' => $code,
        'body' => is_array($body) ? $body : null,
        'raw' => (string)$raw,
    ];
}

function weline_print_step(string $label, array $res): void
{
    echo "=== {$label} HTTP {$res['code']} ===\n";
    if (is_array($res['body'])) {
        echo json_encode($res['body'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    } else {
        echo substr($res['raw'], 0, 500) . "\n";
    }
}

try {
    echo "type={$type}\n";

    $websites = weline_request(
        'GET',
        $root . '/websites/rest/v1/remote-translation-catalog/websites',
        null,
        $token
    );
    weline_print_step('getWebsites', $websites);

    $languages = weline_request(
        'GET',
        $root . '/websites/rest/v1/remote-translation-catalog/languages?website_id=' . $websiteId,
        null,
        $token
    );
    weline_print_step('getLanguages', $languages);

    $pending = weline_request(
        'POST',
        $root . '/i18n/rest/v1/remote-translation/pending',
        [
            'website_id' => $websiteId,
            'locales' => $locales,
            'limit' => 5,
            'cursor' => null,
            'type' => $type,
        ],
        $token
    );
    weline_print_step('postPending', $pending);

    /*
     * Phrase/meta ingest example (uncomment after filling real translations):
     *
     * $ingest = weline_request('POST', $root . '/i18n/rest/v1/remote-translation/ingest', [
     *     'website_id' => $websiteId,
     *     'type' => 'phrase', // or meta
     *     'items' => [
     *         ['source' => '你好', 'locale' => 'en_US', 'translation' => 'Hello'],
     *     ],
     * ], $token);
     * weline_print_step('postIngest', $ingest);
     *
     * local_model ingest (only when pending returned rows):
     *
     * $ingest = weline_request('POST', $root . '/i18n/rest/v1/remote-translation/ingest', [
     *     'website_id' => $websiteId,
     *     'type' => 'local_model',
     *     'items' => [[
     *         'local_model' => 'Weline\\…\\FooLocalDescription',
     *         'local_id_field' => 'entity_id',
     *         'record_id' => 42,
     *         'field' => 'name',
     *         'locale' => 'en_US',
     *         'translation' => 'Hanfu top',
     *         'source' => '汉服上衣',
     *     ]],
     * ], $token);
     */

    /*
     * Optional collect — phrase/meta only (local_model → 422):
     *
     * $start = weline_request('POST', $root . '/i18n/rest/v1/remote-translation/collect-start', [
     *     'website_id' => $websiteId,
     *     'type' => 'phrase',
     * ], $token);
     * weline_print_step('postCollectStart', $start);
     */

    echo "Done (pending only, type={$type}). Uncomment ingest/collect in run.php when ready.\n";
    exit(($pending['code'] >= 200 && $pending['code'] < 300) ? 0 : 2);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
