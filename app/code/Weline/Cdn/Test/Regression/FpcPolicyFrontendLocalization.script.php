<?php
require dirname(__DIR__, 6) . '/app/bootstrap.php';
$source = file_get_contents(dirname(__DIR__, 2) . '/view/templates/Backend/ApiRules/index.phtml');
if (!preg_match('/\$texts = (\[.*?\n\]);/s', $source, $matches)) { throw new RuntimeException('missing translation data'); }
$texts = eval('return ' . $matches[1] . ';');
foreach (['seconds', 'otherPaths', 'total', 'published', 'accepted', 'purged', 'verified', 'pendingVersion'] as $key) {
    if (!str_contains($texts[$key], '%{1}')) { throw new RuntimeException($key . ' lost its runtime number placeholder'); }
}
if (!str_contains($texts['page'], '%{1}') || !str_contains($texts['page'], '%{2}')) { throw new RuntimeException('page lost its number placeholders'); }
echo "FPC frontend localization: numeric placeholders remain available for runtime values\n";
