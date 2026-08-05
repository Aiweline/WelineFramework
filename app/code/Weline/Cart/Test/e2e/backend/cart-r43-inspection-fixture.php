<?php

declare(strict_types=1);

use Weline\Cart\Service\CartV2HarnessCatalog;
use Weline\Framework\Database\Connection\Adapter\Pgsql\Connector as PgsqlConnector;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Model\SystemConfig;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

function r43_cart_require_isolated_postgresql(): string
{
    if ((string)getenv('WELINE_E2E_ISOLATED_DB') !== '1') {
        throw new RuntimeException('r43_cart_requires_WELINE_E2E_ISOLATED_DB_1');
    }
    $env = require dirname(__DIR__, 7) . '/app/etc/env.php';
    $db = is_array($env['db']['master'] ?? null)
        ? $env['db']['master']
        : (is_array($env['db'] ?? null) ? $env['db'] : []);
    $database = strtolower(trim((string)($db['database'] ?? '')));
    if (preg_match('/^mig_clone_[a-z0-9_]+$/D', $database) !== 1) {
        throw new RuntimeException('r43_cart_requires_mig_clone_database:' . $database);
    }
    $probe = ObjectManager::getInstance(SystemConfig::class, [], false);
    $connector = $probe->getConnection()->getConnector();
    if (!$connector instanceof PgsqlConnector) {
        throw new RuntimeException('r43_cart_requires_postgresql:' . get_class($connector));
    }

    return $database;
}

$input = json_decode((string)stream_get_contents(STDIN), true);
$input = is_array($input) ? $input : [];
$action = (string)($input['action'] ?? '');
$token = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($input['token'] ?? '')) ?: substr(bin2hex(random_bytes(8)), 0, 12);
$offerHash = substr(hash('sha256', 'r43-cart-inspection-' . $token), 0, 32);
$offerUuid = substr($offerHash, 0, 8) . '-'
    . substr($offerHash, 8, 4) . '-4' . substr($offerHash, 13, 3) . '-8'
    . substr($offerHash, 17, 3) . '-' . substr($offerHash, 20, 12);

try {
    $database = r43_cart_require_isolated_postgresql();
    if ($action === 'prepare') {
        CartV2HarnessCatalog::put($offerUuid, [
            'currency' => 'CNY',
            'unit_price_minor' => 4300,
            'name' => 'R43 cart inspection fixture ' . $token,
            'sku' => 'R43-CART-' . strtoupper($token),
            'stock' => 5,
            'sellable' => true,
            'found' => true,
            'product_type' => 'simple',
        ]);
        echo json_encode([
            'ok' => true,
            'database' => $database,
            'token' => $token,
            'offer_uuid' => $offerUuid,
            'provider_code' => 'product',
        ], JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    }
    if ($action === 'cleanup') {
        CartV2HarnessCatalog::delete($offerUuid);
        if (CartV2HarnessCatalog::get($offerUuid) !== null) {
            throw new RuntimeException('r43_cart_cleanup_offer_still_present');
        }
        echo json_encode(['ok'=>true,'database'=>$database,'token'=>$token,'missing'=>true], JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    }
    throw new RuntimeException('unsupported action');
} catch (Throwable $exception) {
    echo json_encode(['ok'=>false,'error'=>$exception->getMessage()], JSON_UNESCAPED_SLASHES) . "\n";
    exit(1);
}
