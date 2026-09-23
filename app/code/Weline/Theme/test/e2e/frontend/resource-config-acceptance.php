<?php
declare(strict_types=1);
require dirname(__DIR__, 7) . '/app/bootstrap.php';
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\SystemConfig\Service\SystemConfigScopeResolver;

// CLI acceptance failures must stay machine-readable instead of the framework ANSI renderer.
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, json_encode(['success' => false, 'error' => get_class($error), 'message' => $error->getMessage()], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
});

$action = $argv[1] ?? 'snapshot';
$stateFile = $argv[2] ?? '';
if ($stateFile === '') { throw new RuntimeException('An acceptance state file is required.'); }
$config = ObjectManager::getInstance(SystemConfig::class);
$identity = ScopeIdentity::website(0, 'default');
$scope = ObjectManager::getInstance(SystemConfigScopeResolver::class)->toStorageScope($identity);
$keys = array_map(static fn(string $key): string => 'resource_files/' . $key,
    ['css_minify', 'js_minify', 'css_merge', 'js_merge', 'css_merge_start', 'js_merge_start']);
$read = static function () use ($config, $scope, $keys): array {
    $rows = [];
    foreach ($keys as $key) { $rows[$key] = $config->getScopedConfigRow($key, 'Weline_Theme', 'frontend', $scope, 'default'); }
    return $rows;
};
if ($action === 'snapshot') {
    $state = ['run_id' => bin2hex(random_bytes(12)), 'scope' => $scope, 'area' => 'frontend', 'locale' => 'default', 'rows' => $read()];
    file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo json_encode($state, JSON_THROW_ON_ERROR);
    exit;
}
$state = json_decode((string)file_get_contents($stateFile), true, 512, JSON_THROW_ON_ERROR);
if ($action === 'prepare-ui') {
    $key = (string)($argv[3] ?? '');
    if (!in_array($key, $keys, true)) { throw new RuntimeException('Unexpected UI configuration key.'); }
    $versions = $config->getConfigVersions('Weline_Theme', 'frontend', $scope, 'default', 1);
    $state['ui_save'] = ['key' => $key, 'value' => (string)($argv[4] ?? ''), 'after_version_id' => (int)($versions[0]['version_id'] ?? 0)];
    file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo json_encode(['prepared' => true], JSON_THROW_ON_ERROR);
    exit;
}
$matchesUiVersion = static function (array $detail) use ($state, $scope): bool {
    $expected = $state['ui_save'] ?? [];
    $changes = $detail['changes'] ?? [];
    if ($expected === [] || (int)($detail['version_id'] ?? 0) <= $expected['after_version_id']
        || ($detail['module'] ?? '') !== 'Weline_Theme' || ($detail['area'] ?? '') !== 'frontend'
        || ($detail['scope'] ?? '') !== $scope || ($detail['locale'] ?? '') !== 'default'
        || ($detail['reason'] ?? '') !== 'config_embed_immediate_save' || count($changes) !== 1) { return false; }
    $change = reset($changes);
    return ($change['key'] ?? '') === $expected['key']
        && (string)($change['new_row'][SystemConfig::schema_fields_VALUE] ?? '') === $expected['value']
        && ($change['old_row'] ?? null) === $state['rows'][$expected['key']];
};
if ($action === 'record-ui-version') {
    $versionId = (int)($argv[3] ?? 0);
    $detail = $config->getConfigVersionDetail($versionId);
    if ($detail === null || !$matchesUiVersion($detail)) { throw new RuntimeException('UI save version does not match the original state and requested change.'); }
    $state['version_id'] = $versionId;
    file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo json_encode(['recorded' => $versionId], JSON_THROW_ON_ERROR);
    exit;
}
if ($action === 'apply') {
    $versions = [];
    foreach ($state['rows'] as $key => $row) { $versions[$key] = (int)($row['version'] ?? 0); }
    $values = array_combine($keys, ['on', 'on', 'on', 'on', 6, 7]);
    $result = $config->saveScopeConfig('Weline_Theme', 'frontend', $values, $scope, 'default', [
        'scope_identity' => $identity, 'base_versions' => $versions,
        'actor_name' => 'widget-resource-e2e', 'reason' => 'Temporary resource acceptance ' . $state['run_id'],
    ]);
    if (empty($result['success'])) { throw new RuntimeException(json_encode($result)); }
    $state['version_id'] = $result['version_id'] ?? null;
    file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo json_encode($result, JSON_THROW_ON_ERROR);
    exit;
}
if ($action === 'restore') {
    if (empty($state['version_id']) && !empty($state['ui_save'])) {
        $candidates = [];
        foreach ($config->getConfigVersions('Weline_Theme', 'frontend', $scope, 'default', 50) as $version) {
            if ((int)$version['version_id'] <= $state['ui_save']['after_version_id']) { continue; }
            $detail = $config->getConfigVersionDetail((int)$version['version_id']);
            if ($detail !== null && $matchesUiVersion($detail)) { $candidates[] = (int)$version['version_id']; }
        }
        if (count($candidates) > 1) { throw new RuntimeException('Ambiguous UI save recovery; refusing to roll back another writer.'); }
        if ($candidates !== []) {
            $state['version_id'] = $candidates[0];
            file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
    }
    if (empty($state['version_id'])) {
        foreach ($config->getConfigVersions('Weline_Theme', 'frontend', $scope, 'default', 20) as $version) {
            if (($version['reason'] ?? '') === 'Temporary resource acceptance ' . $state['run_id']) { $state['version_id'] = (int)$version['version_id']; break; }
        }
    }
    if (!empty($state['version_id'])) {
        $result = $config->rollbackScopeConfigVersion((int)$state['version_id'], ['actor_name' => 'widget-resource-e2e', 'reason' => 'Restore pre-acceptance resource configuration']);
        if (empty($result['success'])) { throw new RuntimeException(json_encode($result)); }
    }
    $after = $read();
    $fields = [SystemConfig::schema_fields_VALUE, 'value_type', 'is_active', 'is_sensitive', 'metadata'];
    foreach ($state['rows'] as $key => $before) {
        if (($before === null) !== ($after[$key] === null)) { throw new RuntimeException('Override presence not restored: ' . $key); }
        foreach ($fields as $field) {
            if (($before[$field] ?? null) !== ($after[$key][$field] ?? null)) { throw new RuntimeException('Configuration not restored: ' . $key . '/' . $field); }
        }
    }
    $state['restored'] = true;
    file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo json_encode(['restored' => true, 'rows' => $after], JSON_THROW_ON_ERROR);
    exit;
}
throw new RuntimeException('Unknown acceptance action');
