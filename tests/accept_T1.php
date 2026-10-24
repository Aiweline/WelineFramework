<?php
/**
 * T1 acceptance: hanfu mini-cart drawer primary CTA must inherit theme on-primary text token.
 * Static contract check (no browser): parse hanfu-ui-sot.css.
 * Prints exactly one final line: ACCEPT PASS | ACCEPT FAIL: <reason>
 */
declare(strict_types=1);

$cssPath = __DIR__ . '/../app/design/Weline/hanfu/frontend/assets/css/hanfu-ui-sot.css';
if (!is_file($cssPath)) {
    fwrite(STDERR, "missing css: $cssPath\n");
    echo "ACCEPT FAIL: css file missing\n";
    exit(1);
}
$css = (string)file_get_contents($cssPath);
$fail = static function (string $reason): void {
    echo 'ACCEPT FAIL: ' . $reason . "\n";
    exit(1);
};

// 1. The ink !important rule must exclude drawer buttons (all three anchor selectors).
if (!preg_match('/\.header-actions a:not\(\.mini-cart-drawer__btn\)/', $css)) {
    $fail('header-actions a still matches drawer CTA (no :not exclusion)');
}
if (!preg_match('/\.header-cart a:not\(\.mini-cart-drawer__btn\)/', $css)) {
    $fail('header-cart a still matches drawer CTA (no :not exclusion)');
}
if (!preg_match('/\.header-user a:not\(\.mini-cart-drawer__btn\)/', $css)) {
    $fail('header-user a still matches drawer CTA (no :not exclusion)');
}

// 2. The ink !important rule itself must survive for non-drawer anchors.
if (!preg_match('/\.header-actions a:not\(\.mini-cart-drawer__btn\),\s*\n\s*body \.weline-header\.hanfu-atelier-chrome \.header-cart,/', $css)) {
    $fail('ink rule structure changed beyond the :not exclusion');
}
if (!preg_match('/color:\s*var\(--hanfu-sot-ink,\s*#0f1111\)\s*!important;/', $css)) {
    $fail('ink !important declaration lost for non-drawer anchors');
}

// 3. Explicit drawer CTA text-color rules bound to shared component tokens (no !important, no hex).
if (!preg_match('/body \.weline-header\.hanfu-atelier-chrome \.mini-cart-drawer a\.mini-cart-drawer__btn--primary\s*\{\s*color:\s*var\(--amz-drawer-cta-text,\s*var\(--color-on-primary\)\);/', $css)) {
    $fail('drawer primary CTA not bound to --amz-drawer-cta-text');
}
if (!preg_match('/body \.weline-header\.hanfu-atelier-chrome \.mini-cart-drawer a\.mini-cart-drawer__btn--secondary\s*\{\s*color:\s*var\(--amz-drawer-secondary-text,\s*var\(--weline-chrome-body-text\)\);/', $css)) {
    $fail('drawer secondary CTA not bound to --amz-drawer-secondary-text');
}
if (preg_match('/mini-cart-drawer__btn--(?:primary|secondary)[^{]*\{[^}]*!important/', $css)) {
    $fail('drawer CTA rules must not use !important');
}
if (preg_match('/mini-cart-drawer__btn--primary[^{]*\{[^}]*#[0-9a-fA-F]{3,8}/', $css)) {
    $fail('drawer CTA rules must not hard-code hex colors');
}

// 4. No unrelated rule may keep forcing ink onto the drawer CTA with !important.
$inkImportant = static function (string $selectorList) use ($css): bool {
    foreach (explode(',', $selectorList) as $sel) {
        $sel = trim($sel);
        if ($sel === '') {
            continue;
        }
        $pattern = '/' . preg_quote($sel, '/') . '\s*(?:,[^{]*)?\{[^}]*!important[^}]*\}/s';
        if (preg_match($pattern, $css)) {
            return true;
        }
    }
    return false;
};
foreach ([
    '.weline-header.hanfu-atelier-chrome .header-cart .mini-cart-drawer__btn--primary',
    '.weline-header.hanfu-atelier-chrome .header-actions .mini-cart-drawer__btn--primary',
    '.weline-header.hanfu-atelier-chrome .mini-cart-drawer a.mini-cart-drawer__btn--primary',
] as $sel) {
    if ($inkImportant($sel)) {
        $fail('an !important ink rule still targets ' . $sel);
    }
}

// 5. Everything else in the sheet must stay byte-identical except the one rule block + the two new rules.
$beforePath = __DIR__ . '/../dev/session/hanfu-mini-cart-cta-hotfix/before/hanfu-ui-sot.css';
if (is_file($beforePath)) {
    $before = (string)file_get_contents($beforePath);
    $after = $css;
    // Expected delta: three :not() additions + two appended rule blocks.
    $expectedAfter = str_replace(
        [
            "body .weline-header.hanfu-atelier-chrome .header-actions a,",
            "body .weline-header.hanfu-atelier-chrome .header-cart a,",
            "body .weline-header.hanfu-atelier-chrome .header-user a {",
        ],
        [
            "body .weline-header.hanfu-atelier-chrome .header-actions a:not(.mini-cart-drawer__btn),",
            "body .weline-header.hanfu-atelier-chrome .header-cart a:not(.mini-cart-drawer__btn),",
            "body .weline-header.hanfu-atelier-chrome .header-user a:not(.mini-cart-drawer__btn) {",
        ],
        $before
    );
    $newRules = "\n/* 迷你车主/次 CTA：文字色继承共享组件的 on-primary / secondary 合同（止血：墨字压朱砂不可读）。\n"
        . "   共享 pack 在前、本主题在后，无需 !important；不得改底色与间距。 */\n"
        . "body .weline-header.hanfu-atelier-chrome .mini-cart-drawer a.mini-cart-drawer__btn--primary {\n"
        . "    color: var(--amz-drawer-cta-text, var(--color-on-primary));\n"
        . "}\n"
        . "body .weline-header.hanfu-atelier-chrome .mini-cart-drawer a.mini-cart-drawer__btn--secondary {\n"
        . "    color: var(--amz-drawer-secondary-text, var(--weline-chrome-body-text));\n"
        . "}\n";
    $anchor = "body .weline-header.hanfu-atelier-chrome .header-actions svg,";
    $pos = strpos($expectedAfter, $anchor);
    if ($pos === false) {
        $fail('anchor rule missing in before snapshot');
    }
    $expectedAfter = substr($expectedAfter, 0, $pos) . $newRules . substr($expectedAfter, $pos);
    if ($after !== $expectedAfter) {
        $fail('unexpected diff outside the approved rule block');
    }
}

echo "ACCEPT PASS\n";
exit(0);
