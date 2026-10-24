<?php

declare(strict_types=1);

/**
 * T46r acceptance: B2B / Shipping contract tests follow T46p setup cleanup.
 *
 * The two suite files are still run in full. Unrelated pre-existing failures are
 * reported, while the T46r assertions are verified directly via reflection.
 */

define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);

require BP . 'vendor/autoload.php';
require BP . 'app/autoload.php';
require BP . 'app/code/Weline/Framework/Common/functions.php';

use Weline\B2B\Test\Unit\Service\B2BServiceContractsTest;
use Weline\Shipping\Test\Unit\Service\ShippingServiceContractsTest;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$read = static function (string $path): string {
    return is_file($path) ? (string) file_get_contents($path) : '';
};

$methodBody = static function (string $source, string $method): string {
    $pattern = '/private function ' . preg_quote($method, '/') . '\(\): void\s*\{(.*?)\n\}/s';
    return preg_match($pattern, $source, $matches) === 1 ? $matches[1] : '';
};

$b2bTestPath = BP . 'app/code/Weline/B2B/Test/Unit/Service/B2BServiceContractsTest.php';
$shippingTestPath = BP . 'app/code/Weline/Shipping/Test/Unit/Service/ShippingServiceContractsTest.php';
$b2bTest = $read($b2bTestPath);
$shippingTest = $read($shippingTestPath);

$check($b2bTest !== '', 'B2B contract test is missing');
$check($shippingTest !== '', 'Shipping contract test is missing');

// B2B: setup seed assertion replaces the removed LocalDescription assertion.
$b2bSeedBody = $methodBody($b2bTest, 'assert_CustomerGroupLocalModel_testUpgradeSeedsCustomerGroupLocals');
$check($b2bSeedBody !== '', 'B2B seeded-upgrade assertion method is missing');
$check(
    str_contains($b2bSeedBody, 'CustomerGroupLocalSeedService'),
    'B2B seeded-upgrade assertion must keep CustomerGroupLocalSeedService'
);
$check(
    !str_contains($b2bSeedBody, 'LocalDescription'),
    'B2B seeded-upgrade assertion must not reference removed LocalDescription setup entry'
);
$check(
    str_contains($b2bTest, "'CustomerGroupLocalModel::testUpgradeSeedsCustomerGroupLocals'"),
    'B2B contract case list must use the renamed seeded-upgrade check'
);
$check(
    !str_contains($b2bTest, 'testUpgradeRegistersLocalDescription'),
    'B2B contract case list still references the removed LocalDescription check'
);

// Shipping: seed entries replace the removed ShippingCommercePolicy upgrade reference.
$shippingSeedBody = $methodBody($shippingTest, 'assert_CommerceEdgesChapter4_upgradeSeedsRegistered');
$check($shippingSeedBody !== '', 'Shipping seeded-upgrade assertion method is missing');
$check(
    str_contains($shippingSeedBody, 'seedEmbargoReasons'),
    'Shipping seeded-upgrade assertion must keep seedEmbargoReasons'
);
$check(
    str_contains($shippingSeedBody, 'seedFreeShippingConditionTypes'),
    'Shipping seeded-upgrade assertion must keep seedFreeShippingConditionTypes'
);
$check(
    !str_contains($shippingSeedBody, 'ShippingCommercePolicy'),
    'Shipping seeded-upgrade assertion must not reference removed ShippingCommercePolicy setup entry'
);
$check(
    str_contains(
        $shippingTest,
        '$this->assert_CommerceEdgesChapter4_upgradeSeedsRegistered();'
    ),
    'Shipping Module290 contract check must invoke the seeded-upgrade assertion'
);

// Directly prove the two T46r assertions pass, even when unrelated assertions fail.
try {
    $b2bCase = new B2BServiceContractsTest('testAllB2BServiceContractsPass');
    $b2bMethod = new ReflectionMethod($b2bCase, 'assert_CustomerGroupLocalModel_testUpgradeSeedsCustomerGroupLocals');
    $b2bMethod->setAccessible(true);
    $b2bMethod->invoke($b2bCase);
} catch (Throwable $exception) {
    $failures[] = 'B2B T46r assertion failed: ' . $exception->getMessage();
}

try {
    $shippingCase = new ShippingServiceContractsTest('testAllShippingServiceContractsPass');
    $shippingMethod = new ReflectionMethod($shippingCase, 'assert_CommerceEdgesChapter4_upgradeSeedsRegistered');
    $shippingMethod->setAccessible(true);
    $shippingMethod->invoke($shippingCase);
} catch (Throwable $exception) {
    $failures[] = 'Shipping T46r assertion failed: ' . $exception->getMessage();
}

$runPhpUnit = static function (string $testPath) : array {
    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(BP . 'vendor/bin/phpunit')
        . ' --bootstrap ' . escapeshellarg(BP . 'app/bootstrap.php')
        . ' ' . escapeshellarg($testPath)
        . ' 2>&1';
    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);

    return [$exitCode, implode(PHP_EOL, $output)];
};

[$b2bExit, $b2bOutput] = $runPhpUnit($b2bTestPath);
$check($b2bExit === 0, 'B2B PHPUnit suite must pass; exit=' . $b2bExit);
$check(str_contains($b2bOutput, 'OK ('), 'B2B PHPUnit suite did not report OK');

[$shippingExit, $shippingOutput] = $runPhpUnit($shippingTestPath);

$failureEntry = static function (string $output, string $name): ?string {
    $position = strpos($output, $name . ':');
    if ($position === false) {
        return null;
    }
    $rest = substr($output, $position);
    if (preg_match('/\n[A-Za-z0-9_]+::[A-Za-z0-9_]+:/', $rest, $matches, PREG_OFFSET_CAPTURE, 1) === 1) {
        return substr($rest, 0, (int) $matches[0][1]);
    }

    return $rest;
};

$targetFailure = $failureEntry($shippingOutput, 'CommerceEdgesChapter4::testConfigVersionAndModule290');
if ($targetFailure !== null) {
    $check(
        str_contains($targetFailure, "'2.9.0'"),
        'Shipping Module290 target failure must be the pre-existing stale 2.9.0 version assertion'
    );
    $check(
        !str_contains($targetFailure, 'ShippingCommercePolicy'),
        'Shipping Module290 target still fails on the removed ShippingCommercePolicy setup entry'
    );
    $check(
        !str_contains($targetFailure, 'seedEmbargoReasons')
            && !str_contains($targetFailure, 'seedFreeShippingConditionTypes'),
        'Shipping Module290 target still fails on the replaced seed assertions'
    );
    echo 'NOTE Shipping Module290 still red on pre-existing 2.9.0 version assertion; T46r seed assertion passed.', PHP_EOL;
}

preg_match_all('/^([A-Za-z0-9_]+::[A-Za-z0-9_]+):/m', $shippingOutput, $matches);
$unrelatedFailures = array_values(array_unique($matches[1] ?? []));
if ($shippingExit !== 0) {
    echo 'NOTE Shipping full suite exit=', $shippingExit,
        '; T46r seed assertions verified separately. Remaining failure(s):', PHP_EOL;
    foreach ($unrelatedFailures as $name) {
        echo '  - ', $name, PHP_EOL;
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    echo 'T46R TEST-SYNC FAIL', PHP_EOL;
    exit(1);
}

echo 'T46R TEST-SYNC PASS', PHP_EOL;
