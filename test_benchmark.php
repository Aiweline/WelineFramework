<?php

$url = 'https://p11005ce4.weline.local:443/_wls/health';
$concurrency = 10;
$totalRequests = 100;
$ssl = true;

$results = [];
$errors = 0;
$workerHits = [];
$startTime = microtime(true);

$baseOpts = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_FORBID_REUSE => false,
    CURLOPT_FRESH_CONNECT => false,
    CURLOPT_TCP_KEEPALIVE => 1,
    CURLOPT_TCP_KEEPIDLE => 60,
    CURLOPT_TCP_KEEPINTVL => 30,
    CURLOPT_HTTPHEADER => [
        'Connection: keep-alive',
        'Keep-Alive: timeout=60, max=1000',
    ],
];

if ($ssl) {
    $baseOpts[CURLOPT_SSL_VERIFYPEER] = false;
    $baseOpts[CURLOPT_SSL_VERIFYHOST] = 0;
}

$mh = curl_multi_init();

$sh = curl_share_init();
curl_share_setopt($sh, CURLSHOPT_SHARE, CURL_LOCK_DATA_CONNECT);
curl_share_setopt($sh, CURLSHOPT_SHARE, CURL_LOCK_DATA_DNS);

if (defined('CURLPIPE_MULTIPLEX')) {
    curl_multi_setopt($mh, CURLMOPT_PIPELINING, CURLPIPE_MULTIPLEX);
}
if (defined('CURLMOPT_MAX_HOST_CONNECTIONS')) {
    curl_multi_setopt($mh, CURLMOPT_MAX_HOST_CONNECTIONS, $concurrency);
}

$handlePool = [];
$activeHandles = [];
$headerBuffers = [];
$completed = 0;
$requestsSent = 0;

$batchSize = min($concurrency, $totalRequests);

// Initialize handle pool
for ($i = 0; $i < $batchSize; $i++) {
    $ch = curl_init();
    curl_setopt_array($ch, $baseOpts);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_SHARE, $sh);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($chRef, string $line) use (&$headerBuffers): int {
        $headerBuffers[(int)$chRef] = ($headerBuffers[(int)$chRef] ?? '') . $line;
        return strlen($line);
    });
    $handlePool[$i] = $ch;
}

// Add initial batch
for ($i = 0; $i < $batchSize; $i++) {
    $ch = $handlePool[$i];
    curl_multi_add_handle($mh, $ch);
    $activeHandles[(int)$ch] = [
        'handle' => $ch,
        'start' => microtime(true),
        'poolIndex' => $i,
    ];
    $requestsSent++;
}

$running = null;
$lastProgress = 0;

echo "Starting benchmark with $batchSize concurrent handles...\n";

do {
    do {
        $status = curl_multi_exec($mh, $running);
    } while ($status == CURLM_CALL_MULTI_PERFORM);
    
    // Check completed requests
    while ($info = curl_multi_info_read($mh)) {
        $ch = $info['handle'];
        $key = (int)$ch;
        
        if (isset($activeHandles[$key])) {
            $elapsed = (microtime(true) - $activeHandles[$key]['start']) * 1000; // ms
            $poolIndex = $activeHandles[$key]['poolIndex'];
            
            echo "[Request $completed] Result: " . $info['result'] . " (HTTP " . curl_getinfo($ch, CURLINFO_HTTP_CODE) . ")\n";
            
            if ($info['result'] === CURLE_OK) {
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($httpCode >= 200 && $httpCode < 400) {
                    $results[] = $elapsed;
                    echo "  ✓ Success\n";
                } else {
                    $errors++;
                    echo "  ✗ HTTP Error: $httpCode\n";
                }
            } else {
                $errors++;
                echo "  ✗ CURL Error: " . curl_strerror($info['result']) . "\n";
            }
            
            $completed++;
            
            // Progress
            $progress = (int)($completed / $totalRequests * 100);
            if ($progress >= $lastProgress + 10) {
                echo "Progress: $progress% ($completed/$totalRequests)\n";
                $lastProgress = $progress;
            }
            
            curl_multi_remove_handle($mh, $ch);
            unset($activeHandles[$key]);
            $headerBuffers[$key] = '';
            
            // Reuse handle if more requests to send
            if ($requestsSent < $totalRequests) {
                curl_multi_add_handle($mh, $ch);
                $activeHandles[(int)$ch] = [
                    'handle' => $ch,
                    'start' => microtime(true),
                    'poolIndex' => $poolIndex,
                ];
                $requestsSent++;
            }
        }
    }
    
    // Wait for activity
    if ($running > 0) {
        curl_multi_select($mh, 0.01);
    }
    
} while ($running > 0 || count($activeHandles) > 0);

// Cleanup
foreach ($handlePool as $ch) {
    curl_close($ch);
}
curl_multi_close($mh);
curl_share_close($sh);

$endTime = microtime(true);
$totalTime = $endTime - $startTime;

$successCount = count($results);
$totalCompleted = $successCount + $errors;
$qps = $totalTime > 0 ? $successCount / $totalTime : 0;
$errorRate = $totalCompleted > 0 ? ($errors / $totalCompleted) * 100 : 0;

echo "\n=== Report ===\n";
echo "Total Requests: $totalCompleted\n";
echo "Success: $successCount\n";
echo "Errors: $errors\n";
echo "Error Rate: " . round($errorRate, 2) . "%\n";
echo "Total Time: " . round($totalTime, 3) . "s\n";
echo "QPS: " . round($qps, 2) . "\n";
