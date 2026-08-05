<?php
declare(strict_types=1);

/**
 * TEST-P1D-02 夹具：创建 A/B Website Scope 用 CDN/媒体账户，清理绑定行。
 *
 * stdin JSON: { "action": "prepare"|"cleanup", "token"?: string, "account_ids"?: int[] }
 * stdout JSON only.
 */

use Weline\Cdn\Model\Account;
use Weline\Cdn\Model\ScopedAccountBinding;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Service\SystemConfigScopeResolver;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

const P1D02_PREFIX = 'e2e_p1d02_';

/**
 * @return array<string, mixed>
 */
function p1d02_read_input(): array
{
    $raw = \file_get_contents('php://stdin');
    if ($raw === false || \trim($raw) === '') {
        throw new \InvalidArgumentException('empty stdin');
    }
    $data = \json_decode($raw, true);
    if (!\is_array($data)) {
        throw new \InvalidArgumentException('stdin must be JSON object');
    }

    return $data;
}

/**
 * @param array<string, mixed> $payload
 */
function p1d02_output(array $payload): void
{
    echo \json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
}

function p1d02_fail(string $message, int $code = 1): never
{
    p1d02_output(['ok' => false, 'error' => $message]);
    exit($code);
}

function p1d02_create_account(string $adapter, string $name, array $credentials = ['token' => 'fixture']): Account
{
    $om = ObjectManager::getInstance();
    /** @var Account $account */
    $account = clone $om->get(Account::class);
    $account->clear()
        ->setData(Account::schema_fields_ADAPTER, $adapter)
        ->setData(Account::schema_fields_NAME, $name)
        ->setData(Account::schema_fields_DESCRIPTION, 'TEST-P1D-02 fixture')
        ->setData(Account::schema_fields_IS_DEFAULT, 0)
        ->setData(Account::schema_fields_STATUS, Account::STATUS_ACTIVE)
        ->setCredentialsArray($credentials)
        ->save(true);
    $id = (int)$account->getId();
    if ($id <= 0) {
        throw new \RuntimeException('failed to create account ' . $name);
    }

    return $account;
}

/**
 * @param list<int> $accountIds
 */
function p1d02_delete_accounts(array $accountIds): void
{
    $om = ObjectManager::getInstance();
    foreach ($accountIds as $id) {
        $id = (int)$id;
        if ($id <= 0) {
            continue;
        }
        /** @var Account $account */
        $account = clone $om->get(Account::class);
        $account->clear()->load($id);
        if ($account->getId()) {
            $account->delete();
        }
    }
}

/**
 * @param list<string> $storageScopes
 */
function p1d02_delete_bindings(array $storageScopes): void
{
    $om = ObjectManager::getInstance();
    /** @var ScopedAccountBinding $model */
    $model = clone $om->get(ScopedAccountBinding::class);
    foreach ($storageScopes as $scope) {
        $rows = (clone $model)->clear()
            ->where(ScopedAccountBinding::schema_fields_STORAGE_SCOPE, $scope)
            ->select()
            ->fetch()
            ->getItems();
        foreach ($rows as $row) {
            if ($row instanceof ScopedAccountBinding && $row->getId()) {
                $row->delete();
            }
        }
    }
}

try {
    $input = p1d02_read_input();
    $action = (string)($input['action'] ?? '');
    if ($action === 'prepare') {
        $token = P1D02_PREFIX . \bin2hex(\random_bytes(4));
        $resolver = new SystemConfigScopeResolver();
        $siteA = \Weline\Framework\Runtime\ScopeIdentity::website(9101, $token . '_a');
        $siteB = \Weline\Framework\Runtime\ScopeIdentity::website(9102, $token . '_b');
        $storeNormal = \Weline\Framework\Runtime\ScopeIdentity::store(
            0,
            'default',
            $token . '_s',
            \Weline\Framework\Runtime\ScopeIdentity::MODE_NORMAL,
        );
        $storeTest = \Weline\Framework\Runtime\ScopeIdentity::store(
            0,
            'default',
            $token . '_s',
            \Weline\Framework\Runtime\ScopeIdentity::MODE_TEST,
        );
        $global = \Weline\Framework\Runtime\ScopeIdentity::global();

        $storageScopes = [
            $resolver->toStorageScope($siteA),
            $resolver->toStorageScope($siteB),
            $resolver->toStorageScope($storeNormal),
            $resolver->toStorageScope($storeTest),
            $resolver->toStorageScope($global),
        ];
        p1d02_delete_bindings($storageScopes);

        $accA = p1d02_create_account('cloudflare', $token . '_cf_a', ['api_token' => 'a-secret']);
        $accB = p1d02_create_account('cloudflare', $token . '_cf_b', ['api_token' => 'b-secret']);
        $accGlobal = p1d02_create_account('cloudflare', $token . '_cf_g', ['api_token' => 'g-secret']);
        $accMediaN = p1d02_create_account('media', $token . '_media_n', ['key' => 'n-secret']);
        $accMediaT = p1d02_create_account('media', $token . '_media_t', ['key' => 't-secret']);

        p1d02_output([
            'ok' => true,
            'token' => $token,
            'account_ids' => [
                (int)$accA->getId(),
                (int)$accB->getId(),
                (int)$accGlobal->getId(),
                (int)$accMediaN->getId(),
                (int)$accMediaT->getId(),
            ],
            'accounts' => [
                'site_a' => (int)$accA->getId(),
                'site_b' => (int)$accB->getId(),
                'global' => (int)$accGlobal->getId(),
                'media_normal' => (int)$accMediaN->getId(),
                'media_test' => (int)$accMediaT->getId(),
            ],
            'scopes' => [
                'site_a' => [
                    'scope_kind' => 'website',
                    'website_id' => 9101,
                    'website_code' => $token . '_a',
                ],
                'site_b' => [
                    'scope_kind' => 'website',
                    'website_id' => 9102,
                    'website_code' => $token . '_b',
                ],
                'store_normal' => [
                    'scope_kind' => 'store',
                    'website_id' => 0,
                    'website_code' => 'default',
                    'store_code' => $token . '_s',
                    'store_mode' => 'normal',
                ],
                'store_test' => [
                    'scope_kind' => 'store',
                    'website_id' => 0,
                    'website_code' => 'default',
                    'store_code' => $token . '_s',
                    'store_mode' => 'test',
                ],
                'global' => [
                    'scope_kind' => 'global',
                ],
            ],
            'media_urls' => [
                'site_a' => 'https://cdn-a.example.test',
                'site_b' => 'https://cdn-b.example.test',
                'media_normal' => 'https://media.example.test',
                'media_test' => 'https://media-test.example.test',
                'shared' => 'https://media.example.test',
            ],
            'storage_scopes' => $storageScopes,
        ]);
        exit(0);
    }

    if ($action === 'cleanup') {
        $storageScopes = $input['storage_scopes'] ?? [];
        if (!\is_array($storageScopes)) {
            $storageScopes = [];
        }
        /** @var list<string> $storageScopes */
        $storageScopes = \array_values(\array_filter(\array_map('strval', $storageScopes)));
        if ($storageScopes !== []) {
            p1d02_delete_bindings($storageScopes);
        }
        $accountIds = $input['account_ids'] ?? [];
        if (!\is_array($accountIds)) {
            $accountIds = [];
        }
        /** @var list<int> $accountIds */
        $accountIds = \array_values(\array_map('intval', $accountIds));
        p1d02_delete_accounts($accountIds);
        p1d02_output(['ok' => true, 'cleaned' => true]);
        exit(0);
    }

    p1d02_fail('unknown action: ' . $action);
} catch (\Throwable $e) {
    p1d02_fail($e->getMessage());
}
