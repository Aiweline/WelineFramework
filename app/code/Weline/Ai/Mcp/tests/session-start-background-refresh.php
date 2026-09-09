<?php

declare(strict_types=1);

use LearningMcp\Config;
use LearningMcp\ProjectIndex;
use LearningMcp\ProjectResolver;

require dirname(__DIR__) . '/src/bootstrap.php';

$temporary = sys_get_temp_dir() . '/weline-session-start-' . bin2hex(random_bytes(5));
$repository = $temporary . '/project';
$failures = [];
$check = static function (bool $ok, string $label) use (&$failures): void {
    fwrite($ok ? STDOUT : STDERR, ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n");
    if (!$ok) {
        $failures[] = $label;
    }
};
$removeTree = static function (string $path) use (&$removeTree): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
    }
};

try {
    mkdir($repository . '/app/code/Acme/Demo/doc', 0700, true);
    foreach (['README.md', '需求.md', '开发日志.md'] as $document) {
        file_put_contents($repository . '/app/code/Acme/Demo/doc/' . $document, "# Acme_Demo\n");
    }
    mkdir($repository . '/src', 0700, true);
    file_put_contents($repository . '/src/Example.php', "<?php\nclass Example {}\n");
    $configPath = $temporary . '/config.json';
    file_put_contents($configPath, json_encode([
        'data_dir' => $temporary . '/data',
        'analysis' => ['provider' => 'none'],
        'index' => ['sidecar_enabled' => false, 'refresh_interval' => '60s'],
        'knowledge' => [
            'auto_generate_skills' => false,
            'learning_skills' => ['enabled' => false, 'inject_on_prompt' => false],
        ],
    ], JSON_THROW_ON_ERROR));

    $payload = json_encode([
        'session_id' => 'session-start-background',
        'cwd' => $repository,
        'hook_event_name' => 'SessionStart',
        'model' => 'test',
    ], JSON_THROW_ON_ERROR) . "\n";
    $process = proc_open(
        [
            PHP_BINARY,
            dirname(__DIR__) . '/bin/learningctl',
            'hook',
            'SessionStart',
            '--config',
            $configPath,
            '--json',
            '--inject-project-context',
        ],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $repository,
        null,
        ['bypass_shell' => true],
    );
    if (!is_resource($process)) {
        $check(false, 'SessionStart hook process starts');
    } else {
        fwrite($pipes[0], $payload);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $decoded = is_string($stdout) ? json_decode($stdout, true) : null;
        $additionalContext = is_array($decoded)
            ? (string) ($decoded['hookSpecificOutput']['additionalContext'] ?? '')
            : '';
        $check($exitCode === 0, 'SessionStart hook exits successfully: ' . trim((string) $stderr));
        $check(
            str_contains($additionalContext, '[Weline Project Intelligence: automatic session bootstrap]'),
            'SessionStart injects the automatic project bootstrap context',
        );
        $check(
            str_contains($additionalContext, 'detached incremental verification/refresh'),
            'SessionStart reports that the index refresh is detached from the hook response',
        );

        $config = Config::load($configPath);
        $index = new ProjectIndex($config, ProjectResolver::resolve($repository, false));
        $completed = false;
        $deadline = microtime(true) + 10.0;
        do {
            $state = $index->state();
            $completed = ($state['phase'] ?? '') === 'idle'
                && ($state['freshness'] ?? '') === 'current'
                && trim((string) ($state['last_completed_at'] ?? '')) !== '';
            if (!$completed) {
                usleep(50_000);
            }
        } while (!$completed && microtime(true) < $deadline);
        $check($completed, 'detached SessionStart refresh reaches a durable current index');
        $index->close();
    }
} finally {
    $removeTree($temporary);
}

exit($failures === [] ? 0 : 1);
