<?php
// Test HTTP (port 80) instead of HTTPS
$ctx = stream_context_create(['http'=>['timeout'=>5]]);
$content = @file_get_contents('http://127.0.0.1/admin', false, $ctx);
echo "HTTP Response for /admin:\n";
if ($content === false) {
    echo "Failed to fetch /admin - " . error_get_last()['message'] . "\n";
} else {
    echo "Length: " . strlen($content) . " bytes\n";
    echo "First 500 chars:\n";
    echo substr($content, 0, 500) . "\n";
}
echo "\n\nAlso testing correct backend URL:\n";
$content2 = @file_get_contents('http://127.0.0.1/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/admin', false, $ctx);
if ($content2 === false) {
    echo "Failed to fetch /U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/admin - " . error_get_last()['message'] . "\n";
} else {
    echo "Correct URL Length: " . strlen($content2) . " bytes\n";
    echo "First 200 chars: " . substr($content2, 0, 200) . "\n";
}
