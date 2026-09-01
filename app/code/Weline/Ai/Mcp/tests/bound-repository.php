<?php

declare(strict_types=1);

/**
 * Bound-repository scope: MCP indexes only the attached project.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use LearningMcp\Config;
use LearningMcp\RepositoryScope;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ($condition) {
        echo "PASS {$message}\n";

        return;
    }
    ++$failures;
    echo "FAIL {$message}\n";
};

$temporary = sys_get_temp_dir() . '/weline-bound-repo-' . bin2hex(random_bytes(4));
mkdir($temporary . '/data', 0700, true);
mkdir($temporary . '/project-a', 0700, true);
mkdir($temporary . '/project-b', 0700, true);
$configPath = $temporary . '/config.yaml';
file_put_contents($configPath, <<<YAML
data_dir: {$temporary}/data
mode: local
index:
  enabled: true
  bound_repository: {$temporary}/project-a
  gc:
    purge_unbound: true
YAML);

putenv('LEARNING_MCP_BOUND_REPOSITORY');
$config = Config::load($configPath);
$assert(
    RepositoryScope::boundRepository($config) === realpath($temporary . '/project-a'),
    'config bound_repository resolves'
);
$assert(RepositoryScope::isAllowed($config, $temporary . '/project-a'), 'bound project allowed');
$assert(!RepositoryScope::isAllowed($config, $temporary . '/project-b'), 'foreign project rejected');

putenv('LEARNING_MCP_BOUND_REPOSITORY=' . $temporary . '/project-b');
$configEnv = Config::load($configPath);
$assert(
    RepositoryScope::boundRepository($configEnv) === realpath($temporary . '/project-b'),
    'env LEARNING_MCP_BOUND_REPOSITORY overrides config'
);
putenv('LEARNING_MCP_BOUND_REPOSITORY');

$dataA = RepositoryScope::projectDataDir($temporary . '/project-a', $temporary . '/home');
$dataB = RepositoryScope::projectDataDir($temporary . '/project-b', $temporary . '/home');
$assert(str_contains($dataA, '/.learning-mcp/projects/'), 'project data_dir uses projects/ prefix');
$assert($dataA !== $dataB, 'distinct repositories get distinct data_dir');

echo $failures === 0 ? "OK bound-repository\n" : "FAILED bound-repository ({$failures})\n";
exit($failures === 0 ? 0 : 1);
