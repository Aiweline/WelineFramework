#!/usr/bin/env php
<?php

declare(strict_types=1);

require \dirname(__DIR__, 5) . '/app/bootstrap.php';

$arguments = [
    'public_id' => $argv[1] ?? '',
    'admin_id' => $argv[2] ?? '',
    'execution_token' => $argv[3] ?? '',
];

/** @var \GuoLaiRen\PageBuilder\Console\AiSiteAgent\RunOperation $runner */
$runner = \Weline\Framework\Manager\ObjectManager::getInstance(
    \GuoLaiRen\PageBuilder\Console\AiSiteAgent\RunOperation::class
);

$exitCode = $runner->execute($arguments, []);
exit((int)$exitCode);
