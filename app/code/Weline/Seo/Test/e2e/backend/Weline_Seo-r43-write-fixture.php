<?php

declare(strict_types=1);

use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Model\SeoAccount;
use Weline\Seo\Model\SeoKeyword;
use Weline\Seo\Model\SeoSubject;
use Weline\Seo\Model\SeoSuggestion;
use Weline\Seo\Model\SeoWebsiteAccount;
use Weline\Seo\Model\SitemapUrl;
use Weline\Seo\Service\Admin\SeoAdminAccountService;
use Weline\Seo\Service\SeoPlatformCapabilityService;
use Weline\Seo\Service\SeoWebsiteDirectory;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

/** @return array<string,mixed> */
function r43_seo_input(): array
{
    $decoded = json_decode((string)file_get_contents('php://stdin'), true);
    if (!is_array($decoded) || array_is_list($decoded)) throw new InvalidArgumentException('stdin_must_be_json_object');
    return $decoded;
}

/** @param array<string,mixed> $payload */
function r43_seo_output(array $payload, int $code = 0): never
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    exit($code);
}

/** @template T of object @param class-string<T> $class @return T */
function r43_seo_model(string $class): object
{
    return ObjectManager::getInstance($class, [], false);
}

/** @return array{connector:string,database:string} */
function r43_seo_assert_isolated_pgsql(): array
{
    if (getenv('WELINE_E2E_ISOLATED_DB') !== '1') throw new RuntimeException('r43_seo_requires_isolated_database_opt_in');
    /** @var SeoSubject $model */
    $model = r43_seo_model(SeoSubject::class);
    $connectorObject = $model->getConnection()->getConnector();
    $connector = get_class($connectorObject);
    if (!str_contains(strtolower($connector), 'pgsql') && !str_contains(strtolower($connector), 'postgres')) {
        throw new RuntimeException('r43_seo_requires_postgresql:' . $connector);
    }
    $database = (string)$connectorObject->getConfigProvider()->getDatabase();
    if (!str_starts_with($database, 'mig_clone_')) throw new RuntimeException('r43_seo_requires_migration_clone:' . $database);
    return ['connector' => $connector, 'database' => $database];
}

function r43_seo_token(mixed $value): string
{
    $token = strtoupper(trim((string)$value));
    if (preg_match('/^[A-F0-9]{12}$/D', $token) !== 1) throw new InvalidArgumentException('invalid_r43_seo_token');
    return $token;
}

/** @return array<string,mixed>|null */
function r43_seo_find(string $class, string $field, string|int $value): ?array
{
    $model = r43_seo_model($class);
    $model->load($field, $value);
    return $model->getId() ? $model->getData() : null;
}

function r43_seo_delete_subject(string $title): void
{
    /** @var SeoSubject $subject */
    $subject = r43_seo_model(SeoSubject::class);
    $subject->load(SeoSubject::schema_fields_TITLE, $title);
    if (!$subject->getId()) return;
    $subjectId = (int)$subject->getId();
    /** @var SeoKeyword $keywords */
    $keywords = r43_seo_model(SeoKeyword::class);
    $keywords->where(SeoKeyword::schema_fields_SUBJECT_ID, $subjectId)->delete()->fetch();
    /** @var SeoSuggestion $suggestions */
    $suggestions = r43_seo_model(SeoSuggestion::class);
    $suggestions->where(SeoSuggestion::schema_fields_SUBJECT_ID, $subjectId)->delete()->fetch();
    $subject->delete()->fetch();
}

function r43_seo_delete_account(int $accountId): void
{
    if ($accountId <= 0) return;
    /** @var SeoWebsiteAccount $bindings */
    $bindings = r43_seo_model(SeoWebsiteAccount::class);
    $bindings->where(SeoWebsiteAccount::schema_fields_ACCOUNT_ID, $accountId)->delete()->fetch();
    /** @var SeoAccount $account */
    $account = r43_seo_model(SeoAccount::class);
    $account->load($accountId);
    if ($account->getId()) $account->delete()->fetch();
}

function r43_seo_platform(): string
{
    /** @var SeoPlatformCapabilityService $capabilities */
    $capabilities = ObjectManager::getInstance(SeoPlatformCapabilityService::class);
    $platforms = $capabilities->getCapabilities();
    if (isset($platforms['bing'])) return 'bing';
    $platform = array_key_first($platforms);
    if ($platform === null || trim((string)$platform) === '') throw new RuntimeException('seo_platform_fixture_unavailable');
    return (string)$platform;
}

/** @return array<string,mixed> */
function r43_seo_unbound_website(): array
{
    /** @var SeoWebsiteDirectory $directory */
    $directory = ObjectManager::getInstance(SeoWebsiteDirectory::class);
    foreach ($directory->listWebsites() as $website) {
        $websiteId = (int)($website['website_id'] ?? $website['id'] ?? -1);
        if ($websiteId < 0) continue;
        /** @var SeoWebsiteAccount $binding */
        $binding = r43_seo_model(SeoWebsiteAccount::class);
        $rows = $binding->where(SeoWebsiteAccount::schema_fields_WEBSITE_ID, $websiteId)->select()->fetchArray();
        if ($rows === []) return $website;
    }
    throw new RuntimeException('seo_unbound_website_fixture_unavailable');
}

/** @return array{account_id:int,name:string,platform:string} */
function r43_seo_create_account(string $token): array
{
    $name = 'R43 SEO Account ' . $token;
    $existing = r43_seo_find(SeoAccount::class, SeoAccount::schema_fields_NAME, $name);
    if ($existing !== null) r43_seo_delete_account((int)$existing[SeoAccount::schema_fields_ID]);
    $platform = r43_seo_platform();
    /** @var SeoAdminAccountService $accounts */
    $accounts = ObjectManager::getInstance(SeoAdminAccountService::class);
    $result = $accounts->saveAccount([
        'name' => $name,
        'platform' => $platform,
        'scope' => '',
        'description' => 'CK-R43 isolated prerequisite',
        'is_active' => 1,
        'enable_cron_push_urls' => false,
        'enable_cron_sitemap' => false,
        'config_json' => '{}',
    ]);
    $accountId = (int)($result['data']['account_id'] ?? 0);
    if ($accountId <= 0) throw new RuntimeException('seo_account_fixture_create_failed');
    return ['account_id' => $accountId, 'name' => $name, 'platform' => $platform];
}

try {
    $input = r43_seo_input();
    $action = strtolower(trim((string)($input['action'] ?? '')));
    $isolation = r43_seo_assert_isolated_pgsql();
    $base = ['connector' => $isolation['connector'], 'database' => $isolation['database']];

    if ($action === 'prepare_subject') {
        $token = strtoupper(bin2hex(random_bytes(6)));
        r43_seo_output(['ok' => true, ...$base, 'token' => $token, 'title' => 'R43 SEO Subject ' . $token, 'url' => 'https://example.test/r43/seo/' . strtolower($token)]);
    }
    if ($action === 'inspect_subject') {
        $token = r43_seo_token($input['token'] ?? '');
        $row = r43_seo_find(SeoSubject::class, SeoSubject::schema_fields_TITLE, 'R43 SEO Subject ' . $token);
        $ok = $row !== null
            && (string)($row[SeoSubject::schema_fields_URL] ?? '') === 'https://example.test/r43/seo/' . strtolower($token)
            && (string)($row[SeoSubject::schema_fields_DESCRIPTION] ?? '') === 'R43 browser mutation ' . $token;
        r43_seo_output(['ok' => $ok, ...$base, 'subject_id' => (int)($row[SeoSubject::schema_fields_ID] ?? 0)], $ok ? 0 : 1);
    }
    if ($action === 'cleanup_subject') {
        $token = r43_seo_token($input['token'] ?? '');
        r43_seo_delete_subject('R43 SEO Subject ' . $token);
        r43_seo_output(['ok' => true, ...$base, 'cleaned' => true]);
    }
    if ($action === 'prepare_account') {
        $token = strtoupper(bin2hex(random_bytes(6)));
        r43_seo_output(['ok' => true, ...$base, 'token' => $token, 'name' => 'R43 SEO Account ' . $token, 'platform' => r43_seo_platform()]);
    }
    if ($action === 'inspect_account') {
        $token = r43_seo_token($input['token'] ?? '');
        $row = r43_seo_find(SeoAccount::class, SeoAccount::schema_fields_NAME, 'R43 SEO Account ' . $token);
        $ok = $row !== null
            && (string)($row[SeoAccount::schema_fields_PLATFORM] ?? '') === (string)($input['platform'] ?? '')
            && (string)($row[SeoAccount::schema_fields_DESCRIPTION] ?? '') === 'R43 browser account ' . $token
            && (int)($row[SeoAccount::schema_fields_IS_ACTIVE] ?? 0) === 1;
        r43_seo_output(['ok' => $ok, ...$base, 'account_id' => (int)($row[SeoAccount::schema_fields_ID] ?? 0)], $ok ? 0 : 1);
    }
    if ($action === 'cleanup_account') {
        $token = r43_seo_token($input['token'] ?? '');
        $row = r43_seo_find(SeoAccount::class, SeoAccount::schema_fields_NAME, 'R43 SEO Account ' . $token);
        if ($row !== null) r43_seo_delete_account((int)$row[SeoAccount::schema_fields_ID]);
        r43_seo_output(['ok' => true, ...$base, 'cleaned' => true]);
    }
    if ($action === 'prepare_binding') {
        $token = strtoupper(bin2hex(random_bytes(6)));
        $website = r43_seo_unbound_website();
        $account = r43_seo_create_account($token);
        r43_seo_output(['ok' => true, ...$base, 'token' => $token, ...$account, 'website_id' => (int)($website['website_id'] ?? $website['id']), 'website_name' => (string)($website['name'] ?? '')]);
    }
    if ($action === 'inspect_binding') {
        $accountId = (int)($input['account_id'] ?? 0);
        $websiteId = (int)($input['website_id'] ?? -1);
        /** @var SeoWebsiteAccount $binding */
        $binding = r43_seo_model(SeoWebsiteAccount::class);
        $binding->where(SeoWebsiteAccount::schema_fields_WEBSITE_ID, $websiteId)->where(SeoWebsiteAccount::schema_fields_ACCOUNT_ID, $accountId)->find()->fetch();
        $ok = $binding->getId()
            && (string)$binding->getData(SeoWebsiteAccount::schema_fields_SITEMAP_FREQUENCY) === 'daily'
            && (string)$binding->getData(SeoWebsiteAccount::schema_fields_CRAWL_FREQUENCY) === 'weekly'
            && abs((float)$binding->getData(SeoWebsiteAccount::schema_fields_PRIORITY) - 0.7) < 0.0001
            && (int)$binding->getData(SeoWebsiteAccount::schema_fields_IS_AUTO_SUBMIT) === 0;
        r43_seo_output(['ok' => (bool)$ok, ...$base, 'binding_id' => (int)$binding->getId(), 'priority' => (float)$binding->getData(SeoWebsiteAccount::schema_fields_PRIORITY)], $ok ? 0 : 1);
    }
    if ($action === 'cleanup_binding') {
        $accountId = (int)($input['account_id'] ?? 0);
        r43_seo_delete_account($accountId);
        r43_seo_output(['ok' => true, ...$base, 'cleaned' => true]);
    }
    if ($action === 'prepare_sitemap') {
        $token = strtoupper(bin2hex(random_bytes(6)));
        /** @var SeoWebsiteDirectory $directory */
        $directory = ObjectManager::getInstance(SeoWebsiteDirectory::class);
        $website = $directory->listWebsites()[0] ?? null;
        if (!is_array($website)) throw new RuntimeException('seo_sitemap_website_fixture_unavailable');
        $websiteId = (int)($website['website_id'] ?? $website['id'] ?? -1);
        /** @var SitemapUrl $url */
        $url = r43_seo_model(SitemapUrl::class);
        $url->setData([
            SitemapUrl::schema_fields_WEBSITE_ID => $websiteId,
            SitemapUrl::schema_fields_MODULE => 'Weline_Seo_R43',
            SitemapUrl::schema_fields_SCOPE => 'r43_' . strtolower($token),
            SitemapUrl::schema_fields_URL_KEY => 'r43-seo-' . strtolower($token),
            SitemapUrl::schema_fields_LOCALE => '',
            SitemapUrl::schema_fields_ENTITY_TYPE => 'page',
            SitemapUrl::schema_fields_ENTITY_ID => 0,
            SitemapUrl::schema_fields_URL => 'https://example.test/r43/sitemap/' . strtolower($token),
            SitemapUrl::schema_fields_CHANGEFREQ => 'weekly',
            SitemapUrl::schema_fields_PRIORITY => '0.5',
            SitemapUrl::schema_fields_METADATA => '{}',
            SitemapUrl::schema_fields_STATUS => 1,
        ])->save();
        r43_seo_output(['ok' => true, ...$base, 'token' => $token, 'url_id' => (int)$url->getId(), 'url' => (string)$url->getData(SitemapUrl::schema_fields_URL), 'website_id' => $websiteId]);
    }
    if ($action === 'inspect_sitemap') {
        $urlId = (int)($input['url_id'] ?? 0);
        /** @var SitemapUrl $url */
        $url = r43_seo_model(SitemapUrl::class);
        $url->load($urlId);
        $ok = $url->getId()
            && (string)$url->getData(SitemapUrl::schema_fields_PRIORITY) === '0.8'
            && (string)$url->getData(SitemapUrl::schema_fields_CHANGEFREQ) === 'daily';
        r43_seo_output(['ok' => (bool)$ok, ...$base, 'priority' => (string)$url->getData(SitemapUrl::schema_fields_PRIORITY), 'changefreq' => (string)$url->getData(SitemapUrl::schema_fields_CHANGEFREQ)], $ok ? 0 : 1);
    }
    if ($action === 'cleanup_sitemap') {
        $token = r43_seo_token($input['token'] ?? '');
        $urlId = (int)($input['url_id'] ?? 0);
        /** @var SitemapUrl $url */
        $url = r43_seo_model(SitemapUrl::class);
        $url->load($urlId);
        if ($url->getId()) {
            $expectedScope = 'r43_' . strtolower($token);
            if ((string)$url->getData(SitemapUrl::schema_fields_SCOPE) !== $expectedScope) throw new RuntimeException('refusing_non_r43_sitemap_cleanup');
            $url->delete()->fetch();
        }
        r43_seo_output(['ok' => true, ...$base, 'cleaned' => true]);
    }
    throw new InvalidArgumentException('unknown_action:' . $action);
} catch (Throwable $throwable) {
    r43_seo_output(['ok' => false, 'error' => $throwable->getMessage()], 1);
}
