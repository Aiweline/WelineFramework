<?php

declare(strict_types=1);

use LearningMcp\ModuleVersionBumpGate;
use LearningMcp\ToolException;

require dirname(__DIR__) . '/src/bootstrap.php';

$failed = false;

function gateCheck(bool $condition, string $label): void
{
    global $failed;
    fwrite($condition ? STDOUT : STDERR, sprintf("[%s] %s\n", $condition ? 'PASS' : 'FAIL', $label));
    $failed = $failed || !$condition;
}

$modulePhp = 'app/code/Weline/Demo/etc/module.php';
$controller = 'app/code/Weline/Demo/Controller/Index.php';
$serviceOnly = 'app/code/Weline/Demo/Service/Thing.php';
$preModule = "<?php\nreturn ['name' => 'Weline_Demo', 'version' => '1.0.0'];\n";
$postSame = "<?php\nreturn ['name' => 'Weline_Demo', 'version' => '1.0.0'];\n";
$postBump = "<?php\nreturn ['name' => 'Weline_Demo', 'version' => '1.0.1'];\n";
$controllerBody = "<?php\nnamespace Weline\\Demo\\Controller;\nclass Index {}\n";

gateCheck(
    ModuleVersionBumpGate::moduleOfRegistrationAffectingPath($controller) !== null,
    'Controller path is registration-affecting',
);
gateCheck(
    ModuleVersionBumpGate::moduleOfRegistrationAffectingPath($serviceOnly) === null,
    'Service path is not registration-affecting',
);
gateCheck(
    ModuleVersionBumpGate::parseVersion($preModule) === '1.0.0',
    'parseVersion reads module.php version',
);
gateCheck(
    ModuleVersionBumpGate::suggestNextPatch('1.0.0') === '1.0.1',
    'suggestNextPatch increments patch',
);

$rejectedMissing = false;
try {
    ModuleVersionBumpGate::assertPlanSatisfies(
        [$controller => $controllerBody],
        [],
    );
} catch (ToolException $e) {
    $rejectedMissing = $e->errorCode === 'EDIT_MODULE_VERSION_REQUIRED';
}
gateCheck($rejectedMissing, 'rejects Controller-only plan without module.php');

$rejectedSameVersion = false;
try {
    ModuleVersionBumpGate::assertPlanSatisfies(
        [
            $controller => $controllerBody,
            $modulePhp => $postSame,
        ],
        [
            $modulePhp => $preModule,
        ],
    );
} catch (ToolException $e) {
    $rejectedSameVersion = $e->errorCode === 'EDIT_MODULE_VERSION_REQUIRED'
        && str_contains(json_encode($e->details, JSON_THROW_ON_ERROR), 'module_php_version_not_increased');
}
gateCheck($rejectedSameVersion, 'rejects plan when version is not increased');

$accepted = true;
try {
    ModuleVersionBumpGate::assertPlanSatisfies(
        [
            $controller => $controllerBody,
            $modulePhp => $postBump,
        ],
        [
            $modulePhp => $preModule,
        ],
    );
} catch (ToolException) {
    $accepted = false;
}
gateCheck($accepted, 'accepts Controller + bumped module.php');

$serviceOk = true;
try {
    ModuleVersionBumpGate::assertPlanSatisfies(
        [$serviceOnly => "<?php\nclass Thing {}\n"],
        [],
    );
} catch (ToolException) {
    $serviceOk = false;
}
gateCheck($serviceOk, 'allows Service-only plans without version bump');

$eventRejected = false;
try {
    ModuleVersionBumpGate::assertPlanSatisfies(
        ['app/code/Weline/Demo/etc/event.xml' => "<config/>\n"],
        [],
    );
} catch (ToolException $e) {
    $eventRejected = $e->errorCode === 'EDIT_MODULE_VERSION_REQUIRED';
}
gateCheck($eventRejected, 'rejects event.xml-only plan without module.php');

echo $failed ? "FAILED module-version-bump-gate\n" : "OK module-version-bump-gate\n";
exit($failed ? 1 : 0);
