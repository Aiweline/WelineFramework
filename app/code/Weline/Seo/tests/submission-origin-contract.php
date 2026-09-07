<?php
declare(strict_types=1);

namespace Weline\Seo\Model {
    class SeoAccount {
        const schema_fields_ACCOUNT_ID = 'account_id', schema_fields_PROVIDER = 'provider', schema_fields_MODULE = 'module';
    }
    class SeoTask {
        const TASK_TYPE_PUSH_URLS = 'push_urls', PRIORITY_HIGH = 1, STATUS_PENDING = 'pending', STATUS_PROCESSING = 'processing';
        const schema_fields_TASK_TYPE = 'task_type', schema_fields_STATUS = 'status', schema_fields_PAYLOAD = 'payload', schema_fields_SCOPE = 'scope', schema_fields_MODULE = 'module';
        public array $saved = [];
        private array $payload = [];
        public function __call(string $method, array $args): self { return $this; }
        public function fetchArray(): array { return []; }
        public function clear(): self { $this->payload = []; return $this; }
        public function setPayloadArray(array $payload): self { $this->payload = $payload; return $this; }
        public function save(): self { $this->saved[] = $this->payload; return $this; }
        public function getId(): int { return count($this->saved); }
    }
}
namespace Weline\Seo\Service {
    class SeoWebsiteDirectory {
        public function getWebsiteById(int $id): array { return ['website_id' => $id, 'url' => 'https://main.example', 'code' => 'default']; }
    }
    class SeoWebsiteAccountBindingService {
        public function getWebsiteAccountsWithPlatforms(int $id): array { return $this->getUrlPushAccounts($id); }
        public function getUrlPushAccounts(int $id): array { return [['account_id' => 1, 'platform_code' => 'indexnow', 'account' => ['provider' => 'indexnow']]]; }
    }
    class EventDispatcher { public function dispatchTaskEnqueued(...$args): void {} }
}
namespace {
    function __(string $text, ...$args): string { return $text; }
    require dirname(__DIR__) . '/Service/UrlSubmitService.php';
    function service(\Weline\Seo\Model\SeoTask $tasks): \Weline\Seo\Service\UrlSubmitService {
        return new \Weline\Seo\Service\UrlSubmitService($tasks, new \Weline\Seo\Service\SeoWebsiteDirectory(), new \Weline\Seo\Service\SeoWebsiteAccountBindingService(), new \Weline\Seo\Service\EventDispatcher());
    }
    function origin(string $url): string {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme']);
        return $scheme . '://' . strtolower($parts['host']) . ':' . ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    }
    $tasks = new \Weline\Seo\Model\SeoTask();
    service($tasks)->requestTargets([
        ['website_id' => 0, 'url' => 'https://main.example/product'],
        ['website_id' => 0, 'url' => 'https://old.example/product'],
    ], 'product', ['action' => 'upsert']);
    if (count($tasks->saved) !== 2 || array_filter($tasks->saved, fn($p) => count($p['urls']) !== 1)) {
        throw new \RuntimeException('Resource target path should retain its existing one-URL tasks');
    }
    $tasks = new \Weline\Seo\Model\SeoTask();
    $urls = array_map(fn($n) => 'https://main.example/product/' . $n, range(1, 101));
    array_push($urls, 'https://old.example/product', 'http://main.example/product', 'https://main.example:8443/product');
    $stats = service($tasks)->requestBatch($urls, 'product', ['website_id' => 0, 'action' => 'delete']);
    $failures = [];
    if (count($tasks->saved) !== 5 || $stats['created_tasks'] !== 5) { $failures[] = 'Batch submission needs two main-origin tasks and one task for each other scheme/host/port'; }
    foreach ($tasks->saved as $payload) {
        if (count(array_unique(array_map('origin', $payload['urls']))) !== 1) { $failures[] = 'A task mixed distinct origins'; }
        if (count($payload['urls']) > 100 || $payload['website_id'] !== 0 || $payload['action'] !== 'delete') { $failures[] = 'Origin grouping must retain size, default website ID and action'; }
    }
    $queued = array_merge(...array_column($tasks->saved, 'urls'));
    sort($queued); sort($urls);
    if ($queued !== $urls) { $failures[] = 'Origin grouping lost or duplicated URLs'; }
    if ($failures) { fwrite(STDERR, implode("\n", $failures) . "\n"); exit(1); }
    echo "SEO submission origin contracts passed; target path already isolated, batch path split by scheme/host/port\n";
}
