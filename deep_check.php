<?php
$file = 'e:\WelineFramework\DEV-workspace\app\code\Weline\Theme\Controller\Backend\Config\Partials.php';
$content = file_get_contents($file);

echo "=== Lines 125-140 ===\n";
$lines = explode("\n", $content);
for ($i = 124; $i <= 139; $i++) {
    $lineNum = $i + 1;
    $line = $lines[$i] ?? 'OUT OF RANGE';
    echo "Line $lineNum: " . $line . "\n";
}

echo "\n=== Full hex of lines 130-133 ===\n";
for ($i = 129; $i <= 132; $i++) {
    $lineNum = $i + 1;
    $line = $lines[$i] ?? 'OUT OF RANGE';
    echo "Line $lineNum hex: " . bin2hex($line) . "\n";
}

echo "\n=== Testing with token_get_all ===\n";
$tokens = @token_get_all($content);
if ($tokens === []) {
    echo "No tokens returned - syntax error!\n";
}

// Find the error position
$error = "unexpected identifier";
$pos = strpos($content, '涓婚澹充櫇鍙栧緱');
if ($pos !== false) {
    echo "\nFound problematic text at position $pos\n";
    $before = substr($content, max(0, $pos - 50), 50);
    $after = substr($content, $pos, 100);
    echo "Before: " . bin2hex($before) . "\n";
    echo "After: " . bin2hex($after) . "\n";
}
