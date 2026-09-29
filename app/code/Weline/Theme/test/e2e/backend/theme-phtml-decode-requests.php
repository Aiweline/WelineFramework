<?php
declare(strict_types=1);

// Offline observation only; no application bootstrap, HTTP, session or database.
$root = dirname(__DIR__, 7);
require $root . '/app/code/Weline/Framework/Binary/Limits.php';
require $root . '/app/code/Weline/Framework/Binary/WelineBinaryCodec.php';
$codec = new \Weline\Framework\Binary\WelineBinaryCodec();
$packets = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$result = [];
foreach ($packets as $packet) {
    $row = ['started' => $packet['started'], 'sha256' => $packet['sha256'], 'calls' => []];
    try {
        $bytes = base64_decode((string)$packet['bytes'], true);
        if ($bytes === false) {
            throw new InvalidArgumentException('Invalid observed packet encoding');
        }
        $payload = $codec->decodePacket($bytes);
        $visit = static function (mixed $value) use (&$visit, &$row): void {
            if (!is_array($value)) {
                return;
            }
            if (($value['provider'] ?? '') === 'theme' && ($value['operation'] ?? '') === 'editorRequest') {
                $params = $value['params'] ?? [];
                $row['calls'][] = [
                    'method' => strtoupper((string)($params['method'] ?? 'GET')),
                    'path' => (string)(parse_url((string)($params['url'] ?? ''), PHP_URL_PATH) ?: ''),
                ];
            }
            foreach ($value as $child) {
                $visit($child);
            }
        };
        $visit($payload);
    } catch (Throwable $error) {
        $row['decode_error'] = $error->getMessage();
    }
    $result[] = $row;
}
echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
