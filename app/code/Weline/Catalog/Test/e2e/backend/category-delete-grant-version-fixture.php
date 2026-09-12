<?php

declare(strict_types=1);

/**
 * Category delete grant-version fixture for Playwright e2e.
 * stdin JSON: {action:create|delete, grant_version, category_id?, expect_fail?}
 */

use Weline\Acl\Api\Authorization\ObjectAction;
use Weline\Acl\Api\Authorization\ObjectAuthorizationServiceInterface;
use Weline\Catalog\Service\CatalogHubService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

$raw = stream_get_contents(STDIN);
$payload = json_decode($raw ?: '{}', true);
if (!is_array($payload)) {
    echo json_encode(['ok' => false, 'error' => 'invalid_json'], JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(0);
}

try {
    $action = (string)($payload['action'] ?? '');
    $grantVersion = (int)($payload['grant_version'] ?? 0);
    $hub = ObjectManager::getInstance(CatalogHubService::class);
    $auth = ObjectManager::getInstance(ObjectAuthorizationServiceInterface::class);
    $scope = ScopeIdentity::website(0, 'default');
    $locale = 'zh_Hans_CN';

    if ($action === 'create') {
        $check = $auth->authorizeForSubmit(1, ObjectAction::CREATE, $scope, max(1, $grantVersion));
        if (!$check->allowed) {
            throw new RuntimeException('create_auth_denied:' . $check->reason);
        }
        $stamp = (string)(int)(microtime(true) * 1000);
        $result = $hub->execute('save', [
            'space' => 'product',
            'scope_level' => 'website',
            'website_id' => 0,
            'store_id' => 0,
            'channel_id' => 0,
            'locale' => $locale,
            'category_id' => 0,
            'parent_id' => 0,
            'name' => 'e2e-grant-' . $stamp,
            'code' => 'e2e-grant-' . $stamp,
            'is_active' => 1,
        ]);
        $categoryId = max(0, (int)($result['category_id'] ?? $result['id'] ?? 0));
        if ($categoryId <= 0) {
            throw new RuntimeException('create_failed');
        }
        echo json_encode(['ok' => true, 'category_id' => $categoryId], JSON_UNESCAPED_UNICODE), PHP_EOL;
        exit(0);
    }

    if ($action === 'delete') {
        $categoryId = max(0, (int)($payload['category_id'] ?? 0));
        if ($categoryId <= 0) {
            throw new RuntimeException('category_id_required');
        }
        $result = $auth->authorizeForSubmit(1, ObjectAction::DELETE, $scope, $grantVersion);
        if (!empty($payload['expect_fail'])) {
            echo json_encode([
                'ok' => true,
                'allowed' => $result->allowed,
                'reason' => $result->reason,
                'message' => $result->reason,
            ], JSON_UNESCAPED_UNICODE), PHP_EOL;
            exit(0);
        }
        if (!$result->allowed) {
            throw new RuntimeException('delete_auth_denied:' . $result->reason);
        }
        $hub->execute('delete', [
            'space' => 'product',
            'scope_level' => 'website',
            'website_id' => 0,
            'store_id' => 0,
            'channel_id' => 0,
            'locale' => $locale,
            'category_id' => $categoryId,
        ]);
        echo json_encode(['ok' => true, 'deleted' => true, 'category_id' => $categoryId], JSON_UNESCAPED_UNICODE), PHP_EOL;
        exit(0);
    }

    throw new RuntimeException('unknown_action');
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE), PHP_EOL;
}
