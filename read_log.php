<?php
$logDir = 'e:/WelineFramework/DEV-workspace/var/log/wls/default/';
$logFile = $logDir . 'wls-2026-04-08.log';
$errorFile = $logDir . 'error-2026-04-08.log';
$crashFile = $logDir . 'crash-2026-04-08.log';

echo "=== Main Log ===\n";
if (file_exists($logFile)) {
    echo filesize($logFile) . " bytes\n";
    echo substr(file_get_contents($logFile), 0, 50000);
} else {
    echo "Not found: $logFile\n";
}

echo "\n\n=== Error Log ===\n";
if (file_exists($errorFile)) {
    echo filesize($errorFile) . " bytes\n";
    echo substr(file_get_contents($errorFile), 0, 20000);
} else {
    echo "Not found: $errorFile\n";
}

echo "\n\n=== Crash Log ===\n";
if (file_exists($crashFile)) {
    echo filesize($crashFile) . " bytes\n";
    echo substr(file_get_contents($crashFile), 0, 20000);
} else {
    echo "Not found: $crashFile\n";
}
