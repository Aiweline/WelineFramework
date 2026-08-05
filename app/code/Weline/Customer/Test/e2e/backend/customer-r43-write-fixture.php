<?php

declare(strict_types=1);

use Weline\Framework\Manager\ObjectManager;
use Weline\Frontend\Api\User\FrontendUserAdministrationInterface;
use Weline\Frontend\Model\FrontendUser;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

function customer_r43_require_isolated_clone(): string
{
    if ((string)getenv('WELINE_E2E_ISOLATED_DB') !== '1') {
        throw new RuntimeException('R4.3 customer fixture requires WELINE_E2E_ISOLATED_DB=1');
    }
    $env = require BP . 'app/etc/env.php';
    $database = (string)($env['db']['master']['database'] ?? '');
    $type = strtolower((string)($env['db']['master']['type'] ?? ''));
    if (preg_match('/^mig_clone_[a-z0-9_]+$/D', $database) !== 1 || !str_contains($type, 'pgsql')) {
        throw new RuntimeException('R4.3 customer fixture refuses non-PostgreSQL clone');
    }
    return $database;
}

/** @return array<string, mixed> */
function customer_r43_input(): array
{
    $decoded = json_decode((string)stream_get_contents(STDIN), true);

    return is_array($decoded) ? $decoded : [];
}

/** @param array<string, mixed> $payload */
function customer_r43_output(array $payload, int $exitCode = 0): never
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit($exitCode);
}

function customer_r43_token(array $input): string
{
    $token = strtolower((string)($input['token'] ?? ''));
    $token = preg_replace('/[^a-z0-9]/', '', $token) ?: '';

    return $token !== '' ? substr($token, 0, 24) : substr(bin2hex(random_bytes(8)), 0, 12);
}

function customer_r43_username(string $token): string
{
    return 'r43.customer.' . $token . '@example.test';
}

function customer_r43_find(string $username): ?FrontendUser
{
    /** @var FrontendUser $user */
    $user = clone ObjectManager::getInstance()->get(FrontendUser::class);
    $user->clearData()
        ->clearQuery()
        ->where(FrontendUser::schema_fields_username, $username)
        ->find()
        ->fetch();

    return $user->getId() ? $user : null;
}

function customer_r43_cleanup(string $username): int
{
    if (!str_starts_with($username, 'r43.customer.') || !str_ends_with($username, '@example.test')) {
        throw new RuntimeException('refusing cleanup outside the R43 customer namespace');
    }
    $user = customer_r43_find($username);
    if ($user === null) {
        return 0;
    }
    $userId = (int)$user->getId();
    if ($userId <= 1) {
        throw new RuntimeException('refusing cleanup of protected frontend user');
    }
    /** @var FrontendUserAdministrationInterface $users */
    $users = ObjectManager::getInstance()->get(FrontendUserAdministrationInterface::class);
    $users->delete($userId);
    if (customer_r43_find($username) !== null) {
        throw new RuntimeException('task-owned frontend user remains after cleanup');
    }

    return 1;
}

try {
    customer_r43_require_isolated_clone();
    $input = customer_r43_input();
    $action = trim((string)($input['action'] ?? ''));
    $token = customer_r43_token($input);
    $username = customer_r43_username($token);

    if ($action === 'prepare') {
        customer_r43_cleanup($username);
        customer_r43_output([
            'ok' => true,
            'token' => $token,
            'username' => $username,
            'password' => 'R43CustomerPass9!',
            'avatar' => 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==',
        ]);
    }
    if ($action === 'inspect') {
        $user = customer_r43_find($username);
        customer_r43_output([
            'ok' => true,
            'rows' => $user === null ? [] : [[
                'user_id' => (int)$user->getId(),
                'username' => (string)$user->getData(FrontendUser::schema_fields_username),
                'avatar' => (string)$user->getData(FrontendUser::schema_fields_avatar),
                'is_sandbox' => (int)$user->getData(FrontendUser::schema_fields_is_sandbox),
            ]],
        ]);
    }
    if ($action === 'cleanup') {
        customer_r43_output(['ok' => true, 'deleted' => customer_r43_cleanup($username)]);
    }
    throw new InvalidArgumentException('unsupported action: ' . $action);
} catch (Throwable $throwable) {
    customer_r43_output(['ok' => false, 'error' => $throwable->getMessage()], 1);
}
