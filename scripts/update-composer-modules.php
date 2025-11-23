<?php
declare(strict_types=1);

$rootDir = dirname(__DIR__);
$modulesDir = $rootDir . '/app/code/Weline';
$composerJsonPath = $rootDir . '/composer.json';

if (!file_exists($composerJsonPath)) {
    echo "Error: Cannot find root composer.json file\n";
    exit(1);
}

$composerJson = json_decode(file_get_contents($composerJsonPath), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo "Error: Cannot parse composer.json: " . json_last_error_msg() . "\n";
    exit(1);
}

$modules = [];
$repositories = [];

if (is_dir($modulesDir)) {
    $dirs = glob($modulesDir . '/*', GLOB_ONLYDIR);
    foreach ($dirs as $dir) {
        $composerFile = $dir . '/composer.json';
        if (file_exists($composerFile)) {
            $moduleComposer = json_decode(file_get_contents($composerFile), true);
            if (json_last_error() === JSON_ERROR_NONE && isset($moduleComposer['name'])) {
                $moduleName = $moduleComposer['name'];
                $relativePath = 'app/code/Weline/' . basename($dir);
                
                $modules[$moduleName] = '@dev';
                $repositories[] = [
                    'type' => 'path',
                    'url' => $relativePath,
                    'options' => [
                        'symlink' => false
                    ]
                ];
                
                echo "Found module: {$moduleName} ({$relativePath})\n";
            }
        }
    }
}

$existingRepos = [];
if (isset($composerJson['repositories'])) {
    foreach ($composerJson['repositories'] as $repo) {
        if (isset($repo['url'])) {
            $existingRepos[$repo['url']] = $repo;
        } else {
            $existingRepos[] = $repo;
        }
    }
}

foreach ($repositories as $repo) {
    $existingRepos[$repo['url']] = $repo;
}

$composerJson['repositories'] = array_values($existingRepos);

if (!isset($composerJson['require'])) {
    $composerJson['require'] = [];
}

foreach ($modules as $moduleName => $version) {
    if (!isset($composerJson['require'][$moduleName])) {
        $composerJson['require'][$moduleName] = $version;
        echo "Added dependency: {$moduleName}\n";
    } else {
        echo "Dependency exists: {$moduleName}\n";
    }
}

$json = json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
file_put_contents($composerJsonPath, $json);
echo "\nDone! composer.json has been updated.\n";
echo "Please run: composer update\n";


