<?php

declare(strict_types=1);

// 独立进程替代队列持久层，执行真实 Admission；不会加载应用或接触业务数据库。
$root = dirname(__DIR__, 6);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'Weline\\')) {
        $file = $root . '/app/code/' . str_replace('\\', '/', $class) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
function __(string $text, mixed $params = []): string { return $text; }
$rows = [];
$interleave = false;
function payload(int $seq): array {
    return ['contract' => 'search.incremental_queue.v1', 'event_seq' => $seq,
        'event_id' => str_pad((string)$seq, 32, '0', STR_PAD_LEFT), 'target_type' => 'product', 'target_id' => 301];
}
function w_query(string $provider, string $operation, array $params = [], string $area = ''): array {
    global $rows, $interleave, $admission, $scope;
    if ($provider !== 'queue') { throw new RuntimeException('unexpected_provider'); }
    if ($operation === 'createIfAbsent') {
        $key = $params['idempotency_key'];
        $created = !isset($rows[$key]);
        $rows[$key] ??= ['queue_id' => 1, 'content' => json_encode($params['content']), 'biz_key' => $key];
        return ['success' => true, 'created' => $created, 'queue_id' => 1, 'status' => 'pending', 'data' => $rows[$key]];
    }
    if ($operation === 'update') {
        if ($interleave) {
            $interleave = false;
            $admission->admit(payload(4), $scope);
        }
        $key = array_key_first($rows);
        if (isset($params['expected_content']) && $params['expected_content'] !== $rows[$key]['content']) {
            return ['success' => false, 'error_code' => 'queue_content_changed'];
        }
        $patch = $params['patch'] ?? $params;
        $rows[$key]['content'] = json_encode($patch['content']);
        return ['success' => true, 'data' => $rows[$key]];
    }
    throw new RuntimeException('unexpected_operation');
}
$dispatch = (new ReflectionClass(Weline\Queue\Service\QueueDispatchService::class))->newInstanceWithoutConstructor();
$admission = new Weline\Search\Service\SearchProjectionQueueAdmission($dispatch);
$scope = Weline\Framework\Runtime\ScopeIdentity::website(0, 'default');
$admission->admit(payload(2), $scope);
$interleave = ($argv[1] ?? '') === 'race';
$admission->admit(payload(3), $scope);
if (($argv[1] ?? '') === 'reversed') { $admission->admit(payload(2), $scope); }
$latest = json_decode(array_values($rows)[0]['content'], true);
echo json_encode(['latest' => $latest['event_seq'], 'covered' => array_column($latest['covered_events'] ?? [], 'event_seq')]);
