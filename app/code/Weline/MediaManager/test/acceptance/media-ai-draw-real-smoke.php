<?php
/**
 * MediaManager AI 作图 — 真实文生图冒烟（非 Mock）
 *
 * 用法：php app/code/Weline/MediaManager/test/acceptance/media-ai-draw-real-smoke.php
 */
declare(strict_types=1);

use Weline\Framework\Http\Sse\SseWriter;
use Weline\Framework\Manager\ObjectManager;
use Weline\MediaManager\Service\AiDrawService;
use Weline\MediaManager\Service\MediaStorageService;

$root = dirname(__DIR__, 6);
chdir($root);
if (\getenv('WELINE_MEDIA_AI_DRAW_MOCK') === '1' || ($_ENV['WELINE_MEDIA_AI_DRAW_MOCK'] ?? '') === '1') {
    \fwrite(STDERR, "请关闭 WELINE_MEDIA_AI_DRAW_MOCK 后再运行真实冒烟。\n");
    exit(2);
}
require $root . '/app/bootstrap.php';

class RealSmokeSseWriter extends SseWriter
{
    /** @var list<array{0:string,1:array<string,mixed>}> */
    public array $events = [];

    public function sendEvent(string $event, mixed $data = null, ?int $id = null): self
    {
        $payload = \is_array($data) ? $data : [];
        if ($event === 'preview' && isset($payload['preview_data_url']) && \is_string($payload['preview_data_url'])) {
            $payload['preview_data_url'] = '[omitted]';
        }
        $this->events[] = [$event, $payload];
        \fwrite(STDOUT, '[' . $event . '] ' . \json_encode($payload, JSON_UNESCAPED_UNICODE) . PHP_EOL);

        return $this;
    }

    public function start(): self
    {
        return $this;
    }

    public function close(): void
    {
    }
}

/** @var AiDrawService $aiDraw */
$aiDraw = ObjectManager::getInstance(AiDrawService::class);
/** @var MediaStorageService $storage */
$storage = ObjectManager::getInstance(MediaStorageService::class);

$config = $aiDraw->getConfigStatus();
echo "config: " . \json_encode($config, JSON_UNESCAPED_UNICODE) . PHP_EOL;
if (empty($config['ready'])) {
    \fwrite(STDERR, "AI 作图未就绪: " . (string)($config['message'] ?? 'unknown') . PHP_EOL);
    exit(1);
}
if (!empty($config['mock'])) {
    \fwrite(STDERR, "仍在 Mock 模式，无法做真实验证。\n");
    exit(2);
}

$testDirRel = 'ai-generated/mm-ai-real-smoke-' . \date('YmdHis');
$testDirHash = $storage->encodeHash($testDirRel);
$testDirAbs = \rtrim(PUB, '/\\') . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . \str_replace('/', DIRECTORY_SEPARATOR, $testDirRel);
@\mkdir($testDirAbs, 0755, true);

$started = \microtime(true);
$sse = new RealSmokeSseWriter();
try {
    $aiDraw->streamGenerate($sse, 1, [
        'mode' => 'text2image',
        'prompt' => '一张简洁的蓝色渐变网站 Banner 背景，无文字，16:9',
        'target' => $testDirHash,
        'size' => '1024x576',
        'aspect_ratio' => '16:9',
        'output_format' => 'png',
    ]);
} catch (\Throwable $e) {
    \fwrite(STDERR, "生成失败: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

$generationId = '';
$sessionId = '';
$hasError = false;
$errorMessage = '';
foreach ($sse->events as [$name, $data]) {
    if ($name === 'preview') {
        $generationId = (string)($data['generation_id'] ?? '');
    }
    if ($name === 'complete') {
        $sessionId = (string)($data['session_id'] ?? '');
    }
    if ($name === 'error') {
        $hasError = true;
        $errorMessage = (string)($data['message'] ?? 'unknown error');
    }
}
if ($hasError || $generationId === '' || $sessionId === '') {
    \fwrite(STDERR, "SSE 未返回有效结果: " . ($errorMessage !== '' ? $errorMessage : 'missing preview/complete') . PHP_EOL);
    exit(1);
}

try {
    $saveResult = $aiDraw->save(1, [
        'session_id' => $sessionId,
        'generation_id' => $generationId,
        'save_mode' => 'save_as',
        'target' => $testDirHash,
    ]);
} catch (\Throwable $e) {
    \fwrite(STDERR, "保存失败: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

$added = $saveResult['added'][0] ?? null;
if (!\is_array($added)) {
    \fwrite(STDERR, "保存结果为空\n");
    exit(1);
}

$filePath = \rtrim(PUB, '/\\') . '/media/' . ($added['path'] ?? '');
$fileSize = \is_file($filePath) ? (int)\filesize($filePath) : 0;
$elapsed = \round(\microtime(true) - $started, 2);

echo PHP_EOL . "=== REAL SMOKE PASS ===" . PHP_EOL;
echo "model: " . (string)($config['model']['model_code'] ?? '') . PHP_EOL;
echo "elapsed_sec: {$elapsed}" . PHP_EOL;
echo "file: {$filePath}" . PHP_EOL;
echo "size_bytes: {$fileSize}" . PHP_EOL;
echo "mime: " . (string)($added['mime'] ?? '') . PHP_EOL;

if ($fileSize < 1024) {
    \fwrite(STDERR, "文件过小，可能不是真实图片输出。\n");
    exit(1);
}

exit(0);
