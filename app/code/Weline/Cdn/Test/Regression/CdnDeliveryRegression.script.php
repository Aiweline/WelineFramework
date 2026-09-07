<?php
declare(strict_types=1);

/** Isolated regression: real CDN code, fake HTTP/ORM boundaries; never contacts a provider. */
namespace Weline\Framework\Exception { class Core extends \RuntimeException {} }
namespace Weline\Framework\Database {
    class Model {
        protected array $data = [];
        private array $filters = [];
        public static array $rows = [];
        public function setData(string $key, mixed $value): static { $this->data[$key] = $value; return $this; }
        public function getData(?string $key = null): mixed { return $key === null ? $this->data : ($this->data[$key] ?? null); }
        public function reset(): static { $this->data = $this->filters = []; return $this; }
        public function where(string $key, mixed $value): static { $this->filters[$key] = $value; return $this; }
        public function select(): static { return $this; }
        public function find(): static { $rows = $this->getItems(); $this->data = $rows === [] ? [] : $rows[0]->getData(); return $this; }
        public function fetch(): static { return $this; }
        public function getItems(): array { return array_values(array_filter(self::$rows, function (Model $row): bool { foreach ($this->filters as $key => $value) { if ($row->getData($key) !== $value) return false; } return true; })); }
    }
}
namespace Weline\Framework\Event {
    interface ObserverInterface { public function execute(Event &$event): void; }
}
namespace Weline\Framework\DataObject {
    class DataObject {
        protected array $_data = [];
        public function __construct(array $data = []) { $this->_data = $data; }
        public function _getData(string $key): mixed { return $this->_data[$key] ?? null; }
        public function getData(string $key = '', mixed $index = null): mixed { return $key === '' ? $this->_data : ($this->_data[$key] ?? null); }
        public function setData(array|string $key, mixed $value = null): static { if (is_array($key)) $this->_data = $key + $this->_data; else $this->_data[$key] = $value; return $this; }
    }
}
namespace Weline\Cdn\Adapter {
    function curl_init(string $url): object { return (object)['url' => $url, 'options' => [], 'reply' => []]; }
    function curl_setopt(object $handle, int $key, mixed $value): bool { $handle->options[$key] = $value; return true; }
    function curl_setopt_array(object $handle, array $options): bool { $handle->options = $options + $handle->options; return true; }
    function curl_exec(object $handle): string|false {
        $handle->reply = array_shift(\CdnFixture::$replies) ?? ['status' => 200, 'body' => ['success' => true, 'errors' => [], 'result' => ['id' => 'purge-id']]];
        \CdnFixture::$requests[] = ['url' => $handle->url, 'method' => $handle->options[CURLOPT_CUSTOMREQUEST] ?? 'GET', 'data' => json_decode($handle->options[CURLOPT_POSTFIELDS] ?? '{}', true)];
        return is_string($handle->reply['body']) ? $handle->reply['body'] : json_encode($handle->reply['body']);
    }
    function curl_getinfo(object $handle, int $key): int { return $handle->reply['status']; }
    function curl_error(object $handle): string { return ''; }
    function curl_close(object $handle): void {}
}
namespace {
    define('BP', dirname(__DIR__, 6) . '/');
    function __(string $message, mixed $args = []): string {
        foreach ((array)$args as $key => $value) { $message = str_replace('%{' . (is_int($key) ? $key + 1 : $key) . '}', (string)$value, $message); }
        return $message;
    }
    function w_log_warning(mixed ...$args): void {}
    function w_env_website_id(): int { return 7; }
    final class CdnFixture { public static array $requests = []; public static array $replies = []; }
    require BP . 'app/code/Weline/Framework/Cache/Contract/EdgeCacheAdapterInterface.php';
    require BP . 'app/code/Weline/Framework/Event/Event.php';
    foreach (['Api/Event/AsyncObserverInterface.php', 'Event/Async/Exception/AsyncEventValidationException.php', 'Event/Async/Exception/NonRetryableAsyncEventException.php', 'Event/ResourceChange/ResourceChange.php'] as $file) {
        require BP . 'app/code/Weline/Framework/' . $file;
    }
    foreach (['StoreCatalogInterface.php', 'WebsiteCatalogInterface.php', 'Data/StoreSummary.php', 'Data/WebsiteSummary.php'] as $file) {
        require BP . 'app/code/Weline/Websites/Api/Catalog/' . $file;
    }
    foreach (['Api/AdapterInterface.php', 'Model/Domain.php', 'Adapter/Cloudflare.php', 'Service/AccountManager.php', 'Service/UrlSiteResolver.php', 'Service/CachePurger.php', 'Observer/CdnRequest.php', 'Observer/ScopeChanged.php', 'Observer/ResourceChanged.php'] as $file) {
        require dirname(__DIR__, 2) . '/' . $file;
    }
    function check(bool $ok, string $message): void { if (!$ok) { throw new \RuntimeException($message); } }
    function domain(int $id, string $host): \Weline\Cdn\Model\Domain {
        return (new \Weline\Cdn\Model\Domain())->setData('domain_id', $id)->setData('domain_name', $host);
    }
    final class CdnTestPurger extends \Weline\Cdn\Service\CachePurger {
        public array $calls = [];
        public function __construct() {}
        public function purge($domain, string $mode, array $data = []): array { $this->calls[] = [$domain, $mode, $data]; return ['success' => true]; }
    }
    $cases = [];
    $cases['101 URLs are split into accepted batches without changing their hosts'] = static function (): void {
        $urls = array_map(static fn(int $i): string => 'https://www.example.com/p/' . $i, range(1, 101));
        $result = (new \Weline\Cdn\Adapter\Cloudflare())->purgeUrls('zone', $urls, ['api_token' => 'fixture']);
        check($result['success'] === true && count(CdnFixture::$requests) === 2, 'expected two successful batches');
        check(count(CdnFixture::$requests[0]['data']['files']) === 100 && CdnFixture::$requests[1]['data']['files'] === ['https://www.example.com/p/101'], 'batch boundaries or public URL changed');
    };
    $cases['truthy non-boolean provider success is not accepted'] = static function (): void {
        CdnFixture::$replies = [['status' => 200, 'body' => ['success' => 'false', 'errors' => []]]];
        $result = (new \Weline\Cdn\Adapter\Cloudflare())->purgeEverything('zone', ['api_token' => 'fixture']);
        check($result['success'] === false, 'string false became successful purge');
    };
    $cases['redirect HTTP response cannot become purge success'] = static function (): void {
        CdnFixture::$replies = [['status' => 302, 'body' => ['success' => true, 'errors' => []]]];
        try { $result = (new \Weline\Cdn\Adapter\Cloudflare())->purgeEverything('zone', ['api_token' => 'fixture']); }
        catch (\Weline\Framework\Exception\Core) { return; }
        check($result['success'] === false, 'HTTP 302 accepted');
    };
    $cases['partial batch failure reports failure and completed URL count'] = static function (): void {
        CdnFixture::$replies = [
            ['status' => 200, 'body' => ['success' => true, 'errors' => [], 'result' => ['id' => 'first']]],
            ['status' => 200, 'body' => ['success' => false, 'errors' => [['code' => 1000, 'message' => 'denied']]]],
        ];
        $result = (new \Weline\Cdn\Adapter\Cloudflare())->purgeUrls('zone', array_map(static fn(int $i): string => 'https://example.com/p/' . $i, range(1, 101)), ['api_token' => 'fixture']);
        check($result['success'] === false && ($result['purged_count'] ?? -1) === 100, 'partial failure reported full success');
    };
    $cases['connection verifies token and zone through GET without purging'] = static function (): void {
        CdnFixture::$replies = [
            ['status' => 200, 'body' => ['success' => true, 'result' => ['id' => 'token-id', 'status' => 'active']]],
            ['status' => 200, 'body' => ['success' => true, 'result' => ['id' => 'zone', 'name' => 'example.com', 'status' => 'active']]],
        ];
        $result = (new \Weline\Cdn\Adapter\Cloudflare())->testConnection(['api_token' => 'fixture'], 'zone', 'www.example.com');
        check($result['success'] === true && ($result['zone_verified'] ?? false) && ($result['purge_verified'] ?? true) === false, 'connection scope result incorrect');
        check(array_column(CdnFixture::$requests, 'method') === ['GET', 'GET'], 'connection test mutated external state');
    };
    $cases['blank credential edits preserve token while explicit replacement works'] = static function (): void {
        $existing = ['api_token' => 'saved-token', 'account_id' => 'saved-account'];
        $merged = \Weline\Cdn\Service\AccountManager::mergeCredentials($existing, ['api_token' => '', 'account_id' => 'new-account']);
        check($merged === ['api_token' => 'saved-token', 'account_id' => 'new-account'], 'blank edit erased saved secret');
        check(\Weline\Cdn\Service\AccountManager::mergeCredentials($existing, ['api_token' => 'replacement'])['api_token'] === 'replacement', 'replacement ignored');
    };
    $cases['www maps to parent zone while more specific configured hosts win'] = static function (): void {
        $domains = [domain(1, 'example.com'), domain(2, 'shop.example.com')];
        check(\Weline\Cdn\Service\UrlSiteResolver::matchDomainByHost($domains, 'WWW.EXAMPLE.COM')->getData('domain_id') === 1, 'www did not map to parent');
        check(\Weline\Cdn\Service\UrlSiteResolver::matchDomainByHost($domains, 'shop.example.com')->getData('domain_id') === 2, 'specific host lost to parent');
        check(\Weline\Cdn\Service\UrlSiteResolver::matchDomainByHost($domains, 'badexample.com') === null, 'suffix boundary ignored');
    };
    $cases['invalid explicit website ID becomes event error response'] = static function (): void {
        $observer = (new \ReflectionClass(\Weline\Cdn\Observer\CdnRequest::class))->newInstanceWithoutConstructor();
        $event = (new \Weline\Framework\Event\Event())->setData('website_id', -1)->setData('action', 'check_capability');
        $observer->execute($event);
        check(($event->getData('response')['success'] ?? null) === false, 'invalid site ID escaped response handling');
    };
    $cases['website zero routes URL hosts to their own configured domains'] = static function (): void {
        \Weline\Framework\Database\Model::$rows = [
            domain(10, 'example.com')->setData('site_id', 0)->setData('enabled', 1),
            domain(11, 'images.example.com')->setData('site_id', 0)->setData('enabled', 1),
            domain(70, 'example.com')->setData('site_id', 7)->setData('enabled', 1),
        ];
        $observer = (new \ReflectionClass(\Weline\Cdn\Observer\CdnRequest::class))->newInstanceWithoutConstructor();
        $purger = new CdnTestPurger();
        (new \ReflectionProperty($observer, 'cachePurger'))->setValue($observer, $purger);
        (new \ReflectionProperty($observer, 'domainModel'))->setValue($observer, new \Weline\Cdn\Model\Domain());
        $event = (new \Weline\Framework\Event\Event())->setData('website_id', 0)->setData('action', 'purge_urls')->setData('data', ['urls' => ['https://www.example.com/p/1', 'https://images.example.com/p.jpg']]);
        $observer->execute($event);
        check(($event->getData('response')['success'] ?? false) === true, 'zero site request failed');
        check($purger->calls === [[10, 'urls', ['urls' => ['https://www.example.com/p/1']]], [11, 'urls', ['urls' => ['https://images.example.com/p.jpg']]]], 'URLs were sent to the wrong domain or website');
    };
    $cases['store update clears old URL and default website URL when inheritance is restored'] = static function (): void {
        \Weline\Framework\Database\Model::$rows = [domain(10, 'example.com')->setData('site_id', 0)->setData('enabled', 1)];
        $request = (new \ReflectionClass(\Weline\Cdn\Observer\CdnRequest::class))->newInstanceWithoutConstructor();
        $purger = new CdnTestPurger();
        (new \ReflectionProperty($request, 'cachePurger'))->setValue($request, $purger);
        (new \ReflectionProperty($request, 'domainModel'))->setValue($request, new \Weline\Cdn\Model\Domain());
        $stores = new class implements \Weline\Websites\Api\Catalog\StoreCatalogInterface {
            public function byWebsite(int $websiteId): array { return []; }
            public function byCode(int $websiteId, string $storeCode): ?\Weline\Websites\Api\Catalog\Data\StoreSummary { return null; }
            public function byId(int $storeId): ?\Weline\Websites\Api\Catalog\Data\StoreSummary { return null; }
            public function defaultStore(int $websiteId): ?\Weline\Websites\Api\Catalog\Data\StoreSummary { return null; }
            public function all(): array { return []; }
        };
        $websites = new class implements \Weline\Websites\Api\Catalog\WebsiteCatalogInterface {
            public function defaultWebsiteId(): int { return 0; }
            public function all(): array { return [new \Weline\Websites\Api\Catalog\Data\WebsiteSummary(0, 'Default', 'default', 'https://www.example.com/')]; }
            public function count(): int { return 1; }
        };
        $event = new \Weline\Framework\Event\Event(['data' => ['website_id' => 0, 'store_id' => 0, 'store' => ['url' => null], 'before' => ['url' => 'https://www.example.com/old-store/']]]);
        (new \Weline\Cdn\Observer\ScopeChanged($stores, $websites, $request))->execute($event);
        check($purger->calls === [[10, 'hosts', ['hosts' => ['www.example.com']]], [10, 'prefixes', ['prefixes' => ['www.example.com/old-store/']]]], 'store URL range inheritance failed or old range was lost');
    };
    $cases['unconfigured old URL does not prevent configured new URL purge'] = static function (): void {
        \Weline\Framework\Database\Model::$rows = [domain(10, 'example.com')->setData('site_id', 0)->setData('enabled', 1)];
        $request = (new \ReflectionClass(\Weline\Cdn\Observer\CdnRequest::class))->newInstanceWithoutConstructor();
        $purger = new CdnTestPurger();
        (new \ReflectionProperty($request, 'cachePurger'))->setValue($request, $purger);
        (new \ReflectionProperty($request, 'domainModel'))->setValue($request, new \Weline\Cdn\Model\Domain());
        $event = new \Weline\Framework\Event\Event(['data' => ['website_id' => 0, 'action' => 'purge_urls', 'data' => ['urls' => ['https://old.invalid/', 'https://www.example.com/']]]]);
        $request->execute($event);
        check($purger->calls === [[10, 'urls', ['urls' => ['https://www.example.com/']]]], 'unconfigured old host blocked the configured URL');
        check($event->getData('response')['success'] === false, 'unmatched URL was reported as purged');
    };
    $cases['explicit subdomain purge uses requested host with parent zone mapping'] = static function (): void {
        \Weline\Framework\Database\Model::$rows = [domain(10, 'example.com')->setData('site_id', 0)->setData('enabled', 1)];
        $request = (new \ReflectionClass(\Weline\Cdn\Observer\CdnRequest::class))->newInstanceWithoutConstructor();
        $purger = new CdnTestPurger();
        (new \ReflectionProperty($request, 'cachePurger'))->setValue($request, $purger);
        (new \ReflectionProperty($request, 'domainModel'))->setValue($request, new \Weline\Cdn\Model\Domain());
        $event = new \Weline\Framework\Event\Event(['data' => ['website_id' => 0, 'domain' => 'shop.example.com', 'action' => 'purge_all']]);
        $request->execute($event);
        check($purger->calls === [[10, 'hosts', ['hosts' => ['shop.example.com']]]], 'parent zone host replaced the requested subdomain');
    };
    $cases['website change purges public range while product change only purges actual URLs'] = static function (): void {
        \Weline\Framework\Database\Model::$rows = [domain(10, 'example.com')->setData('site_id', 0)->setData('enabled', 1)];
        foreach (['website', 'product_search_projection'] as $type) {
            $purger = new CdnTestPurger();
            $change = \Weline\Framework\Event\ResourceChange\ResourceChange::fromArray([
                'schema_version' => 1, 'event_id' => str_repeat('a', 32), 'event_name' => \Weline\Framework\Event\ResourceChange\ResourceChange::EVENT_NAME,
                'occurred_at' => '2026-09-05T00:00:00.000000Z', 'resource' => ['type' => $type, 'id' => '1', 'revision' => 1, 'action' => 'upsert'],
                'website' => ['id' => 0, 'code' => 'default', 'previous_code' => null, 'site_id' => 0],
                'impact' => ['urls' => ['https://www.example.com/shop/'], 'previous_urls' => [], 'namespaces' => [], 'previous_namespaces' => []],
                'changed_fields' => ['name'], 'before' => [], 'after' => [],
                'origin' => ['area' => 'backend', 'entry' => 'fixture', 'request_id' => '', 'instance' => '', 'trigger_by' => ['type' => 'system', 'id' => null]],
                'context' => ['website_id' => 0, 'website_code' => 'default', 'lang' => 'en_US', 'currency' => 'USD', 'area' => 'backend', 'timezone' => 'UTC', 'user' => ['type' => 'system', 'id' => null]],
            ]);
            $event = new \Weline\Framework\Event\Event(['data' => ['data' => $change]]);
            (new \Weline\Cdn\Observer\ResourceChanged(new \Weline\Cdn\Model\Domain(), $purger))->execute($event);
            $expected = $type === 'website' ? [10, 'prefixes', ['prefixes' => ['www.example.com/shop/']]] : [10, 'urls', ['urls' => ['https://www.example.com/shop/']]];
            check($purger->calls === [$expected], 'resource type did not select its actual affected range');
        }
    };
    $failed = 0;
    foreach ($cases as $name => $case) {
        CdnFixture::$requests = CdnFixture::$replies = [];
        try { $case(); echo "PASS: {$name}\n"; } catch (\Throwable $e) { $failed++; echo "FAIL: {$name}: {$e->getMessage()}\n"; }
    }
    exit($failed ? 1 : 0);
}
