<?php

declare(strict_types=1);

define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);
defined('DEV') || define('DEV', false);
defined('PROD') || define('PROD', true);

require BP . 'app/autoload.php';
require BP . 'app/code/Weline/Framework/Common/functions.php';

use Weline\Framework\Event\Event;
use Weline\Framework\Output\Cli\Printing;
use Weline\Theme\Observer\SetupUpgradeAfterDeployStatic;
use Weline\Theme\Observer\SetupUpgradeAfterPublishNotFoundStatic;
use Weline\Theme\Service\StaticPublishGate;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};
$finish = static function () use (&$failures): never {
    if ($failures !== []) {
        fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
        echo 'T49A STATIC FAIL' . PHP_EOL;
        exit(1);
    }
    echo 'T49A STATIC PASS' . PHP_EOL;
    exit(0);
};

$check(class_exists(StaticPublishGate::class), 'StaticPublishGate must be loadable');
$gateReflection = new ReflectionClass(StaticPublishGate::class);
$check($gateReflection->isFinal(), 'StaticPublishGate must be final');
$check($gateReflection->hasMethod('skipReason'), 'StaticPublishGate must expose skipReason()');
$skipMethod = $gateReflection->getMethod('skipReason');
$check($skipMethod->isStatic() && $skipMethod->isPublic(), 'StaticPublishGate::skipReason must be public static');

$deployFile = BP . 'app/code/Weline/Theme/Observer/SetupUpgradeAfterDeployStatic.php';
$notFoundFile = BP . 'app/code/Weline/Theme/Observer/SetupUpgradeAfterPublishNotFoundStatic.php';
$deploySource = is_file($deployFile) ? (string) file_get_contents($deployFile) : '';
$notFoundSource = is_file($notFoundFile) ? (string) file_get_contents($notFoundFile) : '';
$check(str_contains($deploySource, 'StaticPublishGate::skipReason'), 'deploy observer must use StaticPublishGate');
$check(str_contains($notFoundSource, 'StaticPublishGate::skipReason'), '404 observer must use StaticPublishGate');
$check(str_contains($deploySource, '静态发布已跳过'), 'deploy observer must print the skip reason');
$check(str_contains($notFoundSource, '404 静态发布已跳过'), '404 observer must print the skip reason');

$originalArgv = $_SERVER['argv'] ?? null;
$originalEnv = getenv('WELINE_SKIP_STATIC_DEPLOY');

$_SERVER['argv'] = ['bin/w', 'setup:upgrade'];
putenv('WELINE_SKIP_STATIC_DEPLOY=1');
$check(
    StaticPublishGate::skipReason() === 'WELINE_SKIP_STATIC_DEPLOY=1',
    'WELINE_SKIP_STATIC_DEPLOY=1 must skip static publication'
);
$check(
    SetupUpgradeAfterDeployStatic::skipReasonForArgs() === 'WELINE_SKIP_STATIC_DEPLOY=1',
    'SetupUpgradeAfterDeployStatic::skipReasonForArgs must delegate to StaticPublishGate'
);
putenv('WELINE_SKIP_STATIC_DEPLOY=true');
$check(
    StaticPublishGate::skipReason() === 'WELINE_SKIP_STATIC_DEPLOY=1',
    'WELINE_SKIP_STATIC_DEPLOY=true must skip static publication'
);
putenv('WELINE_SKIP_STATIC_DEPLOY=0');
$check(StaticPublishGate::skipReason() === null, 'WELINE_SKIP_STATIC_DEPLOY=0 must not skip');
putenv('WELINE_SKIP_STATIC_DEPLOY');
$check(StaticPublishGate::skipReason() === null, 'unset WELINE_SKIP_STATIC_DEPLOY must not skip');

$_SERVER['argv'] = ['bin/w', 'setup:upgrade', '--skip-static-deploy'];
$check(
    StaticPublishGate::skipReason() === '--skip-static-deploy',
    '--skip-static-deploy in argv must skip'
);
$_SERVER['argv'] = ['bin/w', 'setup:upgrade'];
$check(
    StaticPublishGate::skipReason(['skip-static-deploy' => true]) === '--skip-static-deploy',
    'skip-static-deploy args entry must skip'
);

// Both setup-after observers must return immediately (without touching the
// static tree or DB) when the explicit switch is present.
$_SERVER['argv'] = ['bin/w', 'setup:upgrade'];
putenv('WELINE_SKIP_STATIC_DEPLOY=1');

SetupUpgradeAfterDeployStatic::resetHasRunFlag();
$deployObserver = new SetupUpgradeAfterDeployStatic(new Printing());
$deployEvent = new Event(['args' => [], 'is_partial_upgrade' => false]);
ob_start();
$deployStarted = microtime(true);
$deployObserver->execute($deployEvent);
$deployOutput = (string) ob_get_clean();
$deployElapsed = microtime(true) - $deployStarted;
$check(str_contains($deployOutput, '静态发布已跳过'), 'deploy observer must emit the skip note');
$check($deployElapsed < 5.0, 'deploy observer must return without publishing the static tree');

$notFoundReflection = new ReflectionClass(SetupUpgradeAfterPublishNotFoundStatic::class);
$notFoundObserver = $notFoundReflection->newInstanceWithoutConstructor();
$printingProperty = $notFoundReflection->getProperty('printing');
$printingProperty->setValue($notFoundObserver, new Printing());
$notFoundEvent = new Event(['args' => [], 'is_partial_upgrade' => false]);
ob_start();
$notFoundStarted = microtime(true);
$notFoundObserver->execute($notFoundEvent);
$notFoundOutput = (string) ob_get_clean();
$notFoundElapsed = microtime(true) - $notFoundStarted;
$check(str_contains($notFoundOutput, '404 静态发布已跳过'), '404 observer must emit the skip note');
$check($notFoundElapsed < 5.0, '404 observer must return without publishing 404 static pages');

// Same switch via an event arg (for commands that can pass an option).
putenv('WELINE_SKIP_STATIC_DEPLOY');
SetupUpgradeAfterDeployStatic::resetHasRunFlag();
$argEvent = new Event(['args' => ['skip-static-deploy' => true], 'is_partial_upgrade' => false]);
ob_start();
$deployObserver->execute($argEvent);
$argOutput = (string) ob_get_clean();
$check(str_contains($argOutput, '静态发布已跳过'), 'deploy observer must honour the skip arg');

if ($originalArgv === null) {
    unset($_SERVER['argv']);
} else {
    $_SERVER['argv'] = $originalArgv;
}
if ($originalEnv === false) {
    putenv('WELINE_SKIP_STATIC_DEPLOY');
} else {
    putenv('WELINE_SKIP_STATIC_DEPLOY=' . $originalEnv);
}

$finish();
