<?php
$file = 'e:\WelineFramework\DEV-workspace\app\code\Weline\Theme\Controller\Backend\Config\Partials.php';
$content = file_get_contents($file);
$lines = explode("\n", $content);

// Check line 132
$line132Hex = bin2hex($lines[131]);
echo "Line 132 hex:\n$line132Hex\n\n";

// Look for the problematic Chinese chars
$search = 'e6b693e5a99aee95bde6b693e5b685e793a8e98da63f';
$pos = strpos($line132Hex, $search);
if ($pos !== false) {
    echo "Found search pattern at position $pos\n";
    echo "Context: " . substr($line132Hex, $pos, 50) . "\n";
    // Check what's after
    $after = substr($line132Hex, $pos + strlen($search), 10);
    echo "After pattern: $after\n";
    // After 63 should come either 27 (') or 3f (?)
    echo "Bytes after 63: ";
    for ($i = 0; $i < strlen($after); $i += 2) {
        echo " " . substr($after, $i, 2);
    }
    echo "\n";
}

// What should it be?
// 涓婚澹充櫇鍙栧緱')));
// hex: e6b693e5a99aee95bde6b693e5b685e793a8e98da63f272929293b
//
// But we have:
// 涓婚澹充櫇鍙栧緱?)));
// hex: e6b693e5a99aee95bde6b693e5b685e793a8e98da63f3f2929293b

echo "\nExpected ending: e6b693e5a99aee95bde6b693e5b685e793a8e98da63f272929293b\n";
echo "Actual ending: " . substr($line132Hex, -28) . "\n";
