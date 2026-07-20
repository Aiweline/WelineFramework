#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$configPath = null;
$dataDir = null;
for ($index = 1, $count = count($argv); $index < $count; ++$index) {
    $argument = (string) $argv[$index];
    if ($argument === '--config' && isset($argv[$index + 1])) {
        $configPath = (string) $argv[++$index];
        continue;
    }
    if (str_starts_with($argument, '--config=')) {
        $configPath = substr($argument, strlen('--config='));
        continue;
    }
    if ($argument === '--data-dir' && isset($argv[$index + 1])) {
        $dataDir = (string) $argv[++$index];
        continue;
    }
    if (str_starts_with($argument, '--data-dir=')) {
        $dataDir = substr($argument, strlen('--data-dir='));
    }
}

try {
    $config = \LearningMcp\Config::load($configPath, $dataDir);
    $maximum = (int) $config->get('collector.max_event_bytes', 8_388_608);
    $body = stream_get_contents(STDIN, $maximum + 1);
    if ($body === false || trim($body) === '' || strlen($body) > $maximum) {
        exit(0);
    }
    $payload = \LearningMcp\Json::object($body, 'Project routing guard Hook payload');
    $result = (new \LearningMcp\ProjectRoutingGuard($config))->handle($payload);
    if (is_array($result)) {
        fwrite(STDOUT, \LearningMcp\Json::encode($result) . "\n");
    }
} catch (\Throwable $exception) {
    [$message] = \LearningMcp\Redactor::string($exception->getMessage());
    fwrite(
        STDERR,
        'project-routing-guard (non-blocking): ' . \LearningMcp\Text::truncate($message, 500) . "\n",
    );
}

exit(0);
