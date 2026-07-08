<?php
/**
 * MediaManager AI 作图 — 需求验收脚本（CLI）
 *
 * 用法：php app/code/Weline/MediaManager/test/acceptance/media-ai-draw-acceptance.php
 * 映射：doc/AI作图-需求说明.md §10
 */
declare(strict_types=1);

\define('WELINE_MEDIA_AI_DRAW_ACCEPTANCE', true);

use Weline\Framework\Http\Sse\SseWriter;
use Weline\Framework\Manager\ObjectManager;
use Weline\MediaManager\Service\AiDrawService;
use Weline\MediaManager\Service\MediaStorageService;

$root = dirname(__DIR__, 6);
chdir($root);
putenv('WELINE_MEDIA_AI_DRAW_MOCK=1');
$_ENV['WELINE_MEDIA_AI_DRAW_MOCK'] = '1';
require $root . '/app/bootstrap.php';

$results = [];
$failed = 0;

function record(array &$results, int &$failed, string $id, string $title, bool $ok, string $detail = ''): void
{
    $results[] = ['id' => $id, 'title' => $title, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failed++;
    }
}

$adapterPath = $root . '/app/code/Weline/MediaManager/extends/module/Weline_Ai/Adapter/MediaManagerAiDrawAdapter.php';
record(
    $results,
    $failed,
    'MM-AI-01',
    '场景适配器文件存在且可加载',
    is_file($adapterPath) && class_exists(\Weline\MediaManager\Extends\Module\Weline_Ai\Adapter\MediaManagerAiDrawAdapter::class),
    $adapterPath
);

$controllerPath = $root . '/app/code/Weline/MediaManager/Controller/Backend/AiDraw.php';
record(
    $results,
    $failed,
    'MM-AI-02',
    'AiDraw 控制器存在',
    is_file($controllerPath) && class_exists(\Weline\MediaManager\Controller\Backend\AiDraw::class),
    $controllerPath
);

/** @var MediaStorageService $storage */
$storage = ObjectManager::getInstance(MediaStorageService::class);
$testDirRel = 'ai-generated/mm-ai-acceptance-' . date('YmdHis');
$testDirHash = $storage->encodeHash($testDirRel);
$testDirAbs = rtrim(PUB, '/\\') . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $testDirRel);
@mkdir($testDirAbs, 0755, true);
record(
    $results,
    $failed,
    'MM-AI-03',
    '验收目录可创建',
    is_dir($testDirAbs),
    $testDirAbs
);

/** @var AiDrawService $aiDraw */
$aiDraw = ObjectManager::getInstance(AiDrawService::class);
$sessionId = '';
$generationId = '';
$added = [];

class AcceptanceSseWriter extends SseWriter
{
    /** @var list<array{0:string,1:mixed}> */
    public array $events = [];

    public function sendEvent(string $event, mixed $data = null, ?int $id = null): self
    {
        $this->events[] = [$event, is_array($data) ? $data : []];

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

try {
    $sse = new AcceptanceSseWriter();
    $aiDraw->streamGenerate($sse, 1, [
        'mode' => 'text2image',
        'prompt' => 'acceptance test banner',
        'target' => $testDirHash,
    ]);
    $hasPreview = false;
    $hasComplete = false;
    $previewUrl = '';
    $previewToken = '';
    $suggestedFilename = '';
    foreach ($sse->events as [$name, $data]) {
        if ($name === 'preview') {
            $hasPreview = true;
            $generationId = (string)($data['generation_id'] ?? '');
            $previewUrl = (string)($data['preview_url'] ?? '');
            $previewToken = (string)($data['preview_token'] ?? '');
            $suggestedFilename = (string)($data['suggested_filename'] ?? '');
        }
        if ($name === 'complete') {
            $hasComplete = true;
            $sessionId = (string)($data['session_id'] ?? '');
        }
    }
    $filenameFromPrompt = $suggestedFilename !== ''
        && \str_contains(\strtolower($suggestedFilename), 'acceptance-test-banner');
    record(
        $results,
        $failed,
        'MM-AI-04',
        '文生图 SSE 返回 preview/complete',
        $hasPreview && $hasComplete && $previewUrl !== '' && $previewToken !== '' && $filenameFromPrompt,
        'events=' . \count($sse->events) . ' preview_url=' . $previewUrl . ' suggested_filename=' . $suggestedFilename
    );
} catch (Throwable $e) {
    record($results, $failed, 'MM-AI-04', '文生图 SSE 返回 preview/complete', false, $e->getMessage());
}

try {
    $saveResult = $aiDraw->save(1, [
        'session_id' => $sessionId,
        'generation_id' => $generationId,
        'save_mode' => 'save_as',
        'target' => $testDirHash,
    ]);
    $added = $saveResult['added'] ?? [];
    record(
        $results,
        $failed,
        'MM-AI-05',
        '另存为新文件成功',
        is_array($added) && count($added) === 1 && is_file(rtrim(PUB, '/\\') . '/media/' . ($added[0]['path'] ?? '')),
        json_encode($added, JSON_UNESCAPED_UNICODE)
    );
} catch (Throwable $e) {
    record($results, $failed, 'MM-AI-05', '另存为新文件成功', false, $e->getMessage());
}

try {
    $sourceHash = '';
    if (!empty($added[0]['hash'])) {
        $sourceHash = (string)$added[0]['hash'];
    }
    $sse2 = new AcceptanceSseWriter();
    $aiDraw->streamGenerate($sse2, 1, [
        'mode' => 'image2image',
        'prompt' => 'make background lighter',
        'target' => $testDirHash,
        'source_file_hash' => $sourceHash,
        'session_id' => bin2hex(random_bytes(8)),
    ]);
    $gen2 = '';
    $session2 = '';
    foreach ($sse2->events as [$name, $data]) {
        if ($name === 'start') {
            $session2 = (string)($data['session_id'] ?? '');
        }
        if ($name === 'complete') {
            $gen2 = (string)($data['generation_id'] ?? '');
            if ($session2 === '') {
                $session2 = (string)($data['session_id'] ?? '');
            }
        }
    }
    $overwrite = $aiDraw->save(1, [
        'session_id' => $session2,
        'generation_id' => $gen2,
        'save_mode' => 'overwrite',
        'source_file_hash' => $sourceHash,
    ]);
    record(
        $results,
        $failed,
        'MM-AI-06',
        '图生图后覆盖原图成功',
        !empty($overwrite['updated']) && ($overwrite['updated'][0]['hash'] ?? '') === $sourceHash,
        json_encode($overwrite, JSON_UNESCAPED_UNICODE)
    );
} catch (Throwable $e) {
    record($results, $failed, 'MM-AI-06', '图生图后覆盖原图成功', false, $e->getMessage());
}

try {
    $sse3 = new AcceptanceSseWriter();
    $aiDraw->streamGenerate($sse3, 1, [
        'mode' => 'batch',
        'prompt' => 'batch acceptance',
        'batch_count' => 2,
        'target' => $testDirHash,
        'session_id' => bin2hex(random_bytes(8)),
    ]);
    $previewCount = 0;
    $ids = [];
    foreach ($sse3->events as [$name, $data]) {
        if ($name === 'preview') {
            $previewCount++;
        }
        if ($name === 'complete') {
            $ids = $data['generation_ids'] ?? [];
        }
    }
    record(
        $results,
        $failed,
        'MM-AI-07',
        '批量生成返回 2 张 preview',
        $previewCount === 2 && count($ids) === 2,
        'preview=' . $previewCount
    );
} catch (Throwable $e) {
    record($results, $failed, 'MM-AI-07', '批量生成返回 2 张 preview', false, $e->getMessage());
}

$phtml = (string)@file_get_contents($root . '/app/code/Weline/MediaManager/view/templates/Backend/Manager/manager.phtml');
record(
    $results,
    $failed,
    'MM-AI-08',
    '管理页模板包含 AI 作图按钮与弹窗',
    str_contains($phtml, 'mmf-btn-ai-draw') && str_contains($phtml, 'mmf-ai-draw-overlay') && str_contains($phtml, 'mmf-ai-ref-search') && str_contains($phtml, 'mmf-ai-draw-output'),
    'manager.phtml'
);

echo "MediaManager AI Draw Acceptance\n";
echo str_repeat('=', 40) . "\n";
foreach ($results as $row) {
    echo ($row['ok'] ? '[PASS]' : '[FAIL]') . ' ' . $row['id'] . ' ' . $row['title'];
    if ($row['detail'] !== '') {
        echo ' — ' . $row['detail'];
    }
    echo "\n";
}
echo str_repeat('=', 40) . "\n";
echo 'Total: ' . count($results) . ', Failed: ' . $failed . "\n";
exit($failed > 0 ? 1 : 0);
