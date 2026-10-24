<?php
/**
 * T2 acceptance: ThemeStorefrontSlugFpcPublisher must import the real ScopeHierarchyInterface.
 * Static contract check. Prints exactly one final line: ACCEPT PASS | ACCEPT FAIL: <reason>
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$target = $root . '/app/code/Weline/Theme/Service/ThemeStorefrontSlugFpcPublisher.php';
$fail = static function (string $reason): void {
    echo 'ACCEPT FAIL: ' . $reason . "\n";
    exit(1);
};

if (!is_file($target)) {
    $fail('target file missing');
}
$src = (string)file_get_contents($target);

// 1. Correct import present, wrong one gone.
if (!preg_match('/^use Weline\\\\SystemConfig\\\\Api\\\\Scope\\\\ScopeHierarchyInterface;$/m', $src)) {
    $fail('missing use Weline\\SystemConfig\\Api\\Scope\\ScopeHierarchyInterface;');
}
if (str_contains($src, 'Weline\Framework\Runtime\ScopeHierarchyInterface')) {
    $fail('wrong FQCN Weline\\Framework\\Runtime\\ScopeHierarchyInterface still referenced');
}

// 2. No other file in the repo may reference the wrong FQCN either.
$wrong = shell_exec('grep -rl "Weline\\\\Framework\\\\Runtime\\\\ScopeHierarchyInterface" ' . escapeshellarg($root . '/app') . ' 2>/dev/null');
if (trim((string)$wrong) !== '') {
    $fail('wrong FQCN still referenced in: ' . trim((string)$wrong));
}

// 3. Call site reads ScopeIdentity directly (T3 fixed the historical ->identity misread).
foreach ([
    'ObjectManager::getInstance(ScopeHierarchyInterface::class)',
    '->fromStorageScope($context->scope->storageScope, true);',
] as $needle) {
    if (!str_contains($src, $needle)) {
        $fail('call site changed: ' . $needle);
    }
}
if (str_contains($src, '$scopeContext->identity')) {
    $fail('legacy misread $scopeContext->identity is back (see T3)');
}

// 4. Pre-existing dirty edit on line 161 must be preserved (ltrim -> trim).
if (!str_contains($src, '$slug = \trim((string)$path, \'/\');')) {
    $fail('line 161 dirty edit (\trim) was reverted or lost');
}

// 5. Final-state contract (T3 later rewrote the method body; assert the end state, not the snapshot).
//    The wrong FQCN must be gone and the correct one imported exactly once.
if (substr_count($src, 'use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;') !== 1) {
    $fail('correct use statement must appear exactly once');
}
if (preg_match('/\$scopeContext\b/', $src)) {
    $fail('legacy $scopeContext variable is back (see T3)');
}
if (!str_contains($src, '$identity->websiteId === null')) {
    $fail('resolveWebsiteFace must read $identity->websiteId (see T3)');
}

// 6. Runtime resolution: register the app autoloader, then the imported interface
//    must be loadable and the wrong FQCN must NOT resolve.
$autoload = $root . '/app/autoload.php';
if (!is_file($autoload)) {
    $fail('app/autoload.php missing');
}
require $autoload;
if (!interface_exists('Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface')) {
    $fail('Weline\\SystemConfig\\Api\\Scope\\ScopeHierarchyInterface is not loadable at runtime');
}
if (interface_exists('Weline\Framework\Runtime\ScopeHierarchyInterface')) {
    $fail('wrong FQCN unexpectedly resolves at runtime');
}

echo "ACCEPT PASS\n";
exit(0);
