<?php

declare(strict_types=1);

use Weline\Cdn\Model\Account;
use Weline\Cdn\Model\ApiRule;
use Weline\Cdn\Model\Domain;
use Weline\Cdn\Model\ScopedAccountBinding;
use Weline\Cdn\Model\WarmupUrl;
use Weline\Cdn\Service\AdapterResolver;
use Weline\Framework\Manager\ObjectManager;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

/** @return array<string,mixed> */
function r43_cdn_input(): array
{
    $decoded = json_decode((string)file_get_contents('php://stdin'), true);
    if (!is_array($decoded) || array_is_list($decoded)) {
        throw new InvalidArgumentException('stdin_must_be_json_object');
    }
    return $decoded;
}

/** @param array<string,mixed> $payload */
function r43_cdn_output(array $payload, int $code = 0): never
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    exit($code);
}

/** @template T of object @param class-string<T> $class @return T */
function r43_cdn_model(string $class): object
{
    return ObjectManager::getInstance($class, [], false);
}

/** @return array{connector:string,database:string} */
function r43_cdn_assert_isolated_pgsql(): array
{
    if (getenv('WELINE_E2E_ISOLATED_DB') !== '1') {
        throw new RuntimeException('r43_cdn_requires_isolated_database_opt_in');
    }
    /** @var Domain $model */
    $model = r43_cdn_model(Domain::class);
    $connectorObject = $model->getConnection()->getConnector();
    $connector = get_class($connectorObject);
    if (!str_contains(strtolower($connector), 'pgsql') && !str_contains(strtolower($connector), 'postgres')) {
        throw new RuntimeException('r43_cdn_requires_postgresql:' . $connector);
    }
    $database = (string)$connectorObject->getConfigProvider()->getDatabase();
    if (!str_starts_with($database, 'mig_clone_')) {
        throw new RuntimeException('r43_cdn_requires_migration_clone:' . $database);
    }
    return ['connector' => $connector, 'database' => $database];
}

function r43_cdn_token(mixed $value): string
{
    $token = strtoupper(trim((string)$value));
    if (preg_match('/^[A-F0-9]{12}$/D', $token) !== 1) {
        throw new InvalidArgumentException('invalid_r43_cdn_token');
    }
    return $token;
}

/** @param class-string<object> $class */
function r43_cdn_row_exists(string $class, string $field, string|int $value): bool
{
    $model = r43_cdn_model($class);
    $row = $model->reset()->where($field, $value)->find()->fetchArray();
    return is_array($row) && $row !== [];
}

function r43_cdn_adapter(): string
{
    /** @var AdapterResolver $resolver */
    $resolver = ObjectManager::getInstance(AdapterResolver::class);
    $adapters = $resolver->getAllAdapters();
    if (isset($adapters['cloudflare'])) {
        return 'cloudflare';
    }
    $adapter = array_key_first($adapters);
    if (!is_string($adapter) || trim($adapter) === '') {
        throw new RuntimeException('r43_cdn_adapter_fixture_unavailable');
    }
    return $adapter;
}

/** @return array{website_id:int,name:string} */
function r43_cdn_website(): array
{
    $rows = w_query('websites', 'getWebsiteList');
    foreach (is_array($rows) ? $rows : [] as $row) {
        if (!is_array($row) || !array_key_exists('website_id', $row)) {
            continue;
        }
        return [
            'website_id' => (int)$row['website_id'],
            'name' => (string)($row['name'] ?? ''),
        ];
    }
    throw new RuntimeException('r43_cdn_website_fixture_unavailable');
}

function r43_cdn_delete_account(string $name): void
{
    if (!str_starts_with($name, 'R43 CDN Account ')) {
        throw new InvalidArgumentException('refusing_non_r43_cdn_account_cleanup');
    }
    /** @var Account $account */
    $account = r43_cdn_model(Account::class);
    $account->load(Account::schema_fields_NAME, $name);
    if (!$account->getId()) {
        return;
    }
    $accountId = (int)$account->getId();
    /** @var ScopedAccountBinding $bindings */
    $bindings = r43_cdn_model(ScopedAccountBinding::class);
    $bindings->where(ScopedAccountBinding::schema_fields_ACCOUNT_ID, $accountId)->delete()->fetch();
    /** @var Domain $domains */
    $domains = r43_cdn_model(Domain::class);
    $domains->where(Domain::schema_fields_ACCOUNT_ID, $accountId)->delete()->fetch();
    $account->delete()->fetch();
}

function r43_cdn_delete_domain(string $domainName): void
{
    if (preg_match('/^r43-(?:domain|rules)-[a-f0-9]{12}\.invalid$/D', $domainName) !== 1) {
        throw new InvalidArgumentException('refusing_non_r43_cdn_domain_cleanup');
    }
    /** @var Domain $domain */
    $domain = r43_cdn_model(Domain::class);
    $domain->load(Domain::schema_fields_DOMAIN_NAME, $domainName);
    if (!$domain->getId()) {
        return;
    }
    $domainId = (int)$domain->getId();
    /** @var WarmupUrl $warmups */
    $warmups = r43_cdn_model(WarmupUrl::class);
    $warmups->where(WarmupUrl::schema_fields_DOMAIN_ID, $domainId)->delete()->fetch();
    $domain->delete()->fetch();
}

/** @return array{domain_id:int,domain_name:string,website_id:int,adapter:string} */
function r43_cdn_create_rules_domain(string $token): array
{
    $domainName = 'r43-rules-' . strtolower($token) . '.invalid';
    r43_cdn_delete_domain($domainName);
    $website = r43_cdn_website();
    $adapter = r43_cdn_adapter();
    /** @var Domain $domain */
    $domain = r43_cdn_model(Domain::class);
    $domain->setData([
        Domain::schema_fields_SITE_ID => $website['website_id'],
        Domain::schema_fields_ADAPTER => $adapter,
        Domain::schema_fields_ZONE_ID => 'r43-zone-' . strtolower($token),
        Domain::schema_fields_DOMAIN_NAME => $domainName,
        Domain::schema_fields_INHERIT_DEFAULT => 1,
        Domain::schema_fields_RULES_OVERRIDE => '{}',
        Domain::schema_fields_WARMUP_INTERVAL_SECONDS => 300,
        Domain::schema_fields_ENABLED => 1,
    ])->save();
    if (!$domain->getId()) {
        throw new RuntimeException('r43_cdn_rules_domain_create_failed');
    }
    return [
        'domain_id' => (int)$domain->getId(),
        'domain_name' => $domainName,
        'website_id' => $website['website_id'],
        'adapter' => $adapter,
    ];
}

try {
    $input = r43_cdn_input();
    $action = strtolower(trim((string)($input['action'] ?? '')));
    $isolation = r43_cdn_assert_isolated_pgsql();
    $base = ['connector' => $isolation['connector'], 'database' => $isolation['database']];

    if ($action === 'prepare_account') {
        $token = strtoupper(bin2hex(random_bytes(6)));
        r43_cdn_output([
            'ok' => true,
            ...$base,
            'token' => $token,
            'name' => 'R43 CDN Account ' . $token,
            'description' => 'R43 browser account ' . $token,
            'adapter' => r43_cdn_adapter(),
            'api_token' => 'r43-token-' . strtolower($token),
        ]);
    }
    if ($action === 'inspect_account') {
        $token = r43_cdn_token($input['token'] ?? '');
        /** @var Account $account */
        $account = r43_cdn_model(Account::class);
        $account->load(Account::schema_fields_NAME, 'R43 CDN Account ' . $token);
        $credentials = $account->getId() ? $account->getCredentialsArray() : [];
        $ok = $account->getId()
            && (string)$account->getData(Account::schema_fields_ADAPTER) === (string)($input['adapter'] ?? '')
            && (string)$account->getData(Account::schema_fields_DESCRIPTION) === 'R43 browser account ' . $token
            && (string)$account->getData(Account::schema_fields_STATUS) === Account::STATUS_ACTIVE
            && (string)($credentials['api_token'] ?? '') === 'r43-token-' . strtolower($token);
        r43_cdn_output(['ok' => (bool)$ok, ...$base, 'account_id' => (int)$account->getId(), 'credentials_sealed' => str_starts_with((string)$account->getData(Account::schema_fields_CREDENTIALS), 'secret_ref:')], $ok ? 0 : 1);
    }
    if ($action === 'cleanup_account') {
        $token = r43_cdn_token($input['token'] ?? '');
        $name = 'R43 CDN Account ' . $token;
        r43_cdn_delete_account($name);
        $cleaned = !r43_cdn_row_exists(Account::class, Account::schema_fields_NAME, $name);
        r43_cdn_output(['ok' => $cleaned, ...$base, 'cleaned' => $cleaned], $cleaned ? 0 : 1);
    }

    if ($action === 'prepare_domain') {
        $token = strtoupper(bin2hex(random_bytes(6)));
        $website = r43_cdn_website();
        r43_cdn_output([
            'ok' => true,
            ...$base,
            'token' => $token,
            'website_id' => $website['website_id'],
            'website_name' => $website['name'],
            'adapter' => r43_cdn_adapter(),
            'domain_name' => 'r43-domain-' . strtolower($token) . '.invalid',
            'zone_id' => 'r43-zone-' . strtolower($token),
        ]);
    }
    if ($action === 'inspect_domain') {
        $token = r43_cdn_token($input['token'] ?? '');
        $domainName = 'r43-domain-' . strtolower($token) . '.invalid';
        /** @var Domain $domain */
        $domain = r43_cdn_model(Domain::class);
        $domain->load(Domain::schema_fields_DOMAIN_NAME, $domainName);
        $ok = $domain->getId()
            && (int)$domain->getData(Domain::schema_fields_SITE_ID) === (int)($input['website_id'] ?? -1)
            && (string)$domain->getData(Domain::schema_fields_ADAPTER) === (string)($input['adapter'] ?? '')
            && (string)$domain->getData(Domain::schema_fields_ZONE_ID) === 'r43-zone-' . strtolower($token)
            && (int)$domain->getData(Domain::schema_fields_ENABLED) === 1;
        r43_cdn_output(['ok' => (bool)$ok, ...$base, 'domain_id' => (int)$domain->getId(), 'domain_name' => $domainName], $ok ? 0 : 1);
    }
    if ($action === 'cleanup_domain') {
        $token = r43_cdn_token($input['token'] ?? '');
        $domainName = 'r43-domain-' . strtolower($token) . '.invalid';
        r43_cdn_delete_domain($domainName);
        $cleaned = !r43_cdn_row_exists(Domain::class, Domain::schema_fields_DOMAIN_NAME, $domainName);
        r43_cdn_output(['ok' => $cleaned, ...$base, 'cleaned' => $cleaned], $cleaned ? 0 : 1);
    }

    if ($action === 'prepare_rules') {
        $token = strtoupper(bin2hex(random_bytes(6)));
        r43_cdn_output(['ok' => true, ...$base, 'token' => $token, ...r43_cdn_create_rules_domain($token)]);
    }
    if ($action === 'inspect_rules') {
        $token = r43_cdn_token($input['token'] ?? '');
        $domainName = 'r43-rules-' . strtolower($token) . '.invalid';
        /** @var Domain $domain */
        $domain = r43_cdn_model(Domain::class);
        $domain->load(Domain::schema_fields_DOMAIN_NAME, $domainName);
        $rules = $domain->getId() ? $domain->getRulesOverrideArray() : [];
        $expected = [
            'browser_case' => 'CK-R43-CDN-WRITE-003',
            'token' => $token,
            'cache_ttl' => 321,
        ];
        $ok = $domain->getId() && $rules === $expected;
        r43_cdn_output(['ok' => (bool)$ok, ...$base, 'domain_id' => (int)$domain->getId(), 'rules' => $rules], $ok ? 0 : 1);
    }
    if ($action === 'cleanup_rules') {
        $token = r43_cdn_token($input['token'] ?? '');
        $domainName = 'r43-rules-' . strtolower($token) . '.invalid';
        r43_cdn_delete_domain($domainName);
        $cleaned = !r43_cdn_row_exists(Domain::class, Domain::schema_fields_DOMAIN_NAME, $domainName);
        r43_cdn_output(['ok' => $cleaned, ...$base, 'cleaned' => $cleaned], $cleaned ? 0 : 1);
    }

    if ($action === 'prepare_api_rule') {
        $token = strtoupper(bin2hex(random_bytes(6)));
        /** @var ApiRule $rule */
        $rule = r43_cdn_model(ApiRule::class);
        $rule->setData([
            ApiRule::schema_fields_MODULE => 'Weline_R43_' . $token,
            ApiRule::schema_fields_CLASS => 'Weline\\R43\\Cdn\\Fixture' . $token,
            ApiRule::schema_fields_METHOD => 'fixture' . $token,
            ApiRule::schema_fields_ROUTE => '/r43/cdn/' . strtolower($token),
            ApiRule::schema_fields_EXPRESSION => 'true',
            ApiRule::schema_fields_ACTION => '{}',
            ApiRule::schema_fields_DESCRIPTION => 'CK-R43-CDN-WRITE-004 ' . $token,
            ApiRule::schema_fields_ENABLED => 1,
            ApiRule::schema_fields_TRIGGER => 'cron',
            ApiRule::schema_fields_PRIORITY => 430,
            ApiRule::schema_fields_CREATED_AT => date('Y-m-d H:i:s'),
            ApiRule::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
        ])->save();
        r43_cdn_output(['ok' => true, ...$base, 'token' => $token, 'rule_id' => (int)$rule->getId(), 'target_enabled' => 0]);
    }
    if ($action === 'inspect_api_rule') {
        $token = r43_cdn_token($input['token'] ?? '');
        /** @var ApiRule $rule */
        $rule = r43_cdn_model(ApiRule::class);
        $rule->load((int)($input['rule_id'] ?? 0));
        $ok = $rule->getId()
            && (string)$rule->getData(ApiRule::schema_fields_MODULE) === 'Weline_R43_' . $token
            && (int)$rule->getData(ApiRule::schema_fields_ENABLED) === (int)($input['expected_enabled'] ?? -1);
        r43_cdn_output(['ok' => (bool)$ok, ...$base, 'rule_id' => (int)$rule->getId(), 'enabled' => (int)$rule->getData(ApiRule::schema_fields_ENABLED)], $ok ? 0 : 1);
    }
    if ($action === 'cleanup_api_rule') {
        $token = r43_cdn_token($input['token'] ?? '');
        /** @var ApiRule $rule */
        $rule = r43_cdn_model(ApiRule::class);
        $rule->load((int)($input['rule_id'] ?? 0));
        if ($rule->getId() && (string)$rule->getData(ApiRule::schema_fields_MODULE) === 'Weline_R43_' . $token) {
            $rule->delete()->fetch();
        }
        $cleaned = !r43_cdn_row_exists(ApiRule::class, ApiRule::schema_fields_RULE_ID, (int)($input['rule_id'] ?? 0));
        r43_cdn_output(['ok' => $cleaned, ...$base, 'cleaned' => $cleaned], $cleaned ? 0 : 1);
    }

    if ($action === 'prepare_warmup') {
        $token = strtoupper(bin2hex(random_bytes(6)));
        /** @var WarmupUrl $warmup */
        $warmup = r43_cdn_model(WarmupUrl::class);
        $warmup->setData([
            WarmupUrl::schema_fields_MODULE => 'Weline_R43_' . $token,
            WarmupUrl::schema_fields_PROVIDER => 'fixture',
            WarmupUrl::schema_fields_URL => 'https://r43.invalid/cdn/' . strtolower($token),
            WarmupUrl::schema_fields_STATUS => WarmupUrl::STATUS_PENDING,
            WarmupUrl::schema_fields_TARGET_COUNT => 1,
            WarmupUrl::schema_fields_PROCESSED_COUNT => 0,
            WarmupUrl::schema_fields_SUCCESS_COUNT => 0,
            WarmupUrl::schema_fields_FAIL_COUNT => 0,
            WarmupUrl::schema_fields_RETRIES => 0,
            WarmupUrl::schema_fields_ENABLED => 1,
        ])->save();
        r43_cdn_output(['ok' => true, ...$base, 'token' => $token, 'warmup_url_id' => (int)$warmup->getId(), 'target_enabled' => 0]);
    }
    if ($action === 'inspect_warmup') {
        $token = r43_cdn_token($input['token'] ?? '');
        /** @var WarmupUrl $warmup */
        $warmup = r43_cdn_model(WarmupUrl::class);
        $warmup->load((int)($input['warmup_url_id'] ?? 0));
        $ok = $warmup->getId()
            && (string)$warmup->getData(WarmupUrl::schema_fields_MODULE) === 'Weline_R43_' . $token
            && (int)$warmup->getData(WarmupUrl::schema_fields_ENABLED) === (int)($input['expected_enabled'] ?? -1);
        r43_cdn_output(['ok' => (bool)$ok, ...$base, 'warmup_url_id' => (int)$warmup->getId(), 'enabled' => (int)$warmup->getData(WarmupUrl::schema_fields_ENABLED)], $ok ? 0 : 1);
    }
    if ($action === 'cleanup_warmup') {
        $token = r43_cdn_token($input['token'] ?? '');
        /** @var WarmupUrl $warmup */
        $warmup = r43_cdn_model(WarmupUrl::class);
        $warmup->load((int)($input['warmup_url_id'] ?? 0));
        if ($warmup->getId() && (string)$warmup->getData(WarmupUrl::schema_fields_MODULE) === 'Weline_R43_' . $token) {
            $warmup->delete()->fetch();
        }
        $cleaned = !r43_cdn_row_exists(WarmupUrl::class, WarmupUrl::schema_fields_WARMUP_URL_ID, (int)($input['warmup_url_id'] ?? 0));
        r43_cdn_output(['ok' => $cleaned, ...$base, 'cleaned' => $cleaned], $cleaned ? 0 : 1);
    }

    throw new InvalidArgumentException('unknown_action:' . $action);
} catch (Throwable $throwable) {
    r43_cdn_output(['ok' => false, 'error' => $throwable->getMessage()], 1);
}
