<?php
/**
 * Simple backend route smoke test helper.
 */
require __DIR__ . '/../../../../../../../app/bootstrap.php';

$routes = [
    'manager/index' => 'AI Management',
    'model/index' => 'AI Models',
    'adapter/index' => 'Scenario Adapters',
    'provider/index' => 'Provider Accounts',
];

$total = count($routes);
$success = 0;
$errors = 0;
$warnings = 0;
$results = [];

foreach ($routes as $route => $description) {
    $fullRoute = "ai/backend/{$route}";
    $cmd = "php bin/w http:request {$fullRoute} -b --login -n=1 2>&1";
    $outputLines = [];
    $returnCode = 0;
    exec($cmd, $outputLines, $returnCode);
    $output = implode("\n", $outputLines);

    $status = 'UNKNOWN';
    $message = '';
    if (stripos($output, 'Fatal error') !== false || stripos($output, 'Uncaught') !== false) {
        $status = 'ERROR';
        $errors++;
        $message = 'Runtime error';
    } elseif (stripos($output, 'SQLSTATE') !== false || stripos($output, 'Column not found') !== false) {
        $status = 'SQL_ERROR';
        $errors++;
        $message = 'SQL query error';
    } elseif (stripos($output, 'Template') !== false && stripos($output, 'not found') !== false) {
        $status = 'NO_TEMPLATE';
        $warnings++;
        $message = 'Missing template file';
    } elseif (stripos($output, 'HTTP/2 200') !== false || stripos($output, '200') !== false) {
        $status = 'OK';
        $success++;
        $message = 'OK';
    } else {
        $status = 'UNKNOWN';
        $warnings++;
        $message = 'Unknown response';
    }

    $results[] = [
        'route' => $fullRoute,
        'description' => $description,
        'status' => $status,
        'message' => $message,
    ];
}

file_put_contents(__DIR__ . '/batch-test-results.json', json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo json_encode([
    'total' => $total,
    'success' => $success,
    'errors' => $errors,
    'warnings' => $warnings,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";

exit($errors > 0 ? 1 : 0);
