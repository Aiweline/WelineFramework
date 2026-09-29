<?php

declare(strict_types=1);

// 仅隔离数据库和队列，运行真实 CdnAdminQueryService 的保存/启用/手动推送分支。
namespace Weline\Framework\Output {
    class Log { public function error(string $message): void {} }
}
namespace Weline\Framework\Manager {
    class ObjectManager {
        public static object $management;
        public static function getInstance(string $class, array $args = [], bool $shared = true): object {
            return $class === \Weline\Cdn\Model\Domain::class ? new \Weline\Cdn\Model\Domain() : self::$management;
        }
    }
}
namespace Weline\Cdn\Model {
    class Domain {
        public const schema_fields_DOMAIN_ID='domain_id', schema_fields_DOMAIN_NAME='domain_name', schema_fields_SITE_ID='site_id', schema_fields_ADAPTER='adapter', schema_fields_ZONE_ID='zone_id', schema_fields_ACCOUNT_ID='account_id', schema_fields_INHERIT_DEFAULT='inherit_default', schema_fields_WARMUP_INTERVAL_SECONDS='warmup_interval_seconds', schema_fields_ENABLED='enabled';
        public static array $rows = [7 => ['domain_id' => 7, 'domain_name' => 'old.example', 'site_id' => 0, 'adapter' => 'cloudflare', 'zone_id' => 'zone-old', 'account_id' => 2, 'inherit_default' => 0, 'enabled' => 1, 'credentials' => 'never return']];
        private array $data = [];
        public function reset(): self { $this->data=[]; return $this; }
        public function load(int $id): self { $this->data=self::$rows[$id] ?? []; return $this; }
        public function where(mixed ...$args): self { return $this; }
        public function find(): self { return $this; }
        public function fetch(): self { return $this; }
        public function getId(): int { return (int)($this->data['domain_id'] ?? 0); }
        public function getData(string $field): mixed { return $this->data[$field] ?? null; }
        public function setData(string $field, mixed $value): self { $this->data[$field]=$value; return $this; }
        public function save(): self { $this->data['domain_id'] ??= 8; self::$rows[$this->getId()]=$this->data; return $this; }
    }
}
namespace {
    function __(string $message, mixed ...$args): string { return $message; }
    require dirname(__DIR__, 3) . '/Service/CdnAdminQueryService.php';
    function check(bool $ok, string $message): void { if (!$ok) { throw new \RuntimeException($message); } }
    $management = new class {
        public array $changes=[];
        public function notifyDomainChange(array $change): array {
            $this->changes[]=$change;
            return ['success'=>true,'message'=>'accepted','data'=>['changed'=>$change['before']!==$change['after'],'queue_ids'=>[9],'sync_ids'=>[4]]];
        }
        public function requestManualSync(array $params): array {
            return ['success'=>true,'message'=>'accepted','data'=>['domain_id'=>$params['domain_id'],'status'=>'pending','http_verification'=>null]];
        }
    };
    \Weline\Framework\Manager\ObjectManager::$management=$management;
    $service=new \Weline\Cdn\Service\CdnAdminQueryService(new \Weline\Framework\Output\Log());
    $result=$service->saveDomain(['domain_id'=>7,'site_id'=>0,'adapter'=>'cloudflare','domain_name'=>'new.example','zone_id'=>'zone-new','account_id'=>3,'enabled'=>true]);
    check(($result['success'] ?? false) === true, 'save failed');
    check(count($management->changes) === 1, 'saved binding was not scheduled');
    check($management->changes[0] === [
        'domain_id'=>7,
        'before'=>['domain_name'=>'old.example','site_id'=>0,'adapter'=>'cloudflare','zone_id'=>'zone-old','account_id'=>2,'inherit_default'=>0,'enabled'=>1],
        'after'=>['domain_name'=>'new.example','site_id'=>0,'adapter'=>'cloudflare','zone_id'=>'zone-new','account_id'=>3,'inherit_default'=>0,'enabled'=>1],
    ], 'binding change lost old target or exposed secrets');
    check(($result['data']['sync']['data']['queue_ids'] ?? []) === [9], 'save did not return pending receipt');
    $result=$service->toggleDomainEnable(['domain_id'=>7,'enabled'=>0]);
    check(($management->changes[1]['before']['enabled'] ?? null) === 1 && ($management->changes[1]['after']['enabled'] ?? null) === 0, 'disable change lost old enabled state');
    check(($result['data']['sync']['data']['sync_ids'] ?? []) === [4], 'toggle did not return receipt');
    $result=$service->pushDomainRules(['domain_id'=>7]);
    check($result === ['success'=>true,'message'=>'accepted','data'=>['domain_id'=>7,'status'=>'pending','http_verification'=>null]], 'manual push pretended cloud completion or bypassed unified sync');
    check(($service->pushDomainRules(['domain_id'=>0])['success'] ?? true) === false, 'invalid domain was submitted');
    echo "OK domain save/disable notifications and manual pending receipt (7 checks)\n";
}
