<?php
declare(strict_types=1);
namespace Weline\Framework\Manager {
    class ObjectManager { public static function getInstance(string $class): object { return $GLOBALS['instances'][$class]; } }
}
namespace Weline\Seo\Model {
    class SeoAccount {
        const schema_fields_SCOPE='scope', schema_fields_MODULE='module', schema_fields_PROVIDER='provider', schema_fields_NAME='name';
        public function reset(): self { return $this; } public function load($id): self { return $this; }
        public function getId(): int { return 1; } public function isActive(): bool { return true; }
        public function getData($key) { return ''; } public function getConfigArray(): array { return []; }
    }
    class SeoTask {
        const TASK_TYPE_FEED_GENERATE='feed_generate', TASK_TYPE_PUSH_URLS='push_urls', TASK_TYPE_KEYWORD_EXTRACT='keyword_extract', TASK_TYPE_SITEMAP_REFRESH='sitemap_refresh';
        public array $payload = ['urls'=>['https://example.com/a','https://example.com/b'], 'provider'=>'bing','account_id'=>1,'website_id'=>0,'action'=>'delete'];
        public string $status = ''; public string $message='';
        public function getTaskType(): string { return self::TASK_TYPE_PUSH_URLS; }
        public function getPayloadArray(): array { return $this->payload; }
        public function setPayloadArray(array $payload): self { $this->payload=$payload;return $this; }
        public function markDone(string $message): self { $this->status='done';$this->message=$message;return $this; }
        public function markError(string $message): self { $this->status='error';$this->message=$message;return $this; }
    }
}
namespace Weline\Seo\Service {
    class StoreModeSeoHardGate { public function isHardNoIndexMode(?string $mode = null): bool { return $GLOBALS['hard_gate']; } }
}
namespace {
    function __(string $text, ...$args): string {return $text;}
    spl_autoload_register(function($class) { if(str_starts_with($class,'Weline\\Seo\\')) { $path=dirname(__DIR__).'/'.str_replace('\\','/',substr($class,11)).'.php'; if(is_file($path))require_once $path; } });
    require_once dirname(__DIR__).'/Interface/SearchEngineAdapterInterface.php';
    require_once dirname(__DIR__).'/Service/SearchEngineAdapterRegistry.php';
    class Adapter implements \Weline\Seo\Interface\SearchEngineAdapterInterface {
        public function getCode():string{return 'bing';} public function getLabel():string{return 'Bing';}
        public function pushUrls(array $urls,array $options=[]):array{$GLOBALS['options']=$options;$GLOBALS['push_calls']++;return $GLOBALS['response'];}
        public function submitSitemap(string $url,array $options=[]):array{return [];}
        public function getRequirements():array{return [];}public function getAccountConfigFields():array{return [];}public function isConfigured():bool{return true;}
    }
    class Registry extends \Weline\Seo\Service\SearchEngineAdapterRegistry { public function __construct(){} public function getAdapter(string $provider):?\Weline\Seo\Interface\SearchEngineAdapterInterface{return new Adapter();} }
    require_once dirname(__DIR__).'/Service/TaskProcessor.php';
    $reflection = new ReflectionClass(\Weline\Seo\Service\TaskProcessor::class);
    $processor = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('objectManager')->setValue($processor, new \Weline\Framework\Manager\ObjectManager());
    $reflection->getProperty('adapterRegistry')->setValue($processor, new Registry());
    $GLOBALS['instances']=[\Weline\Seo\Model\SeoAccount::class=>new \Weline\Seo\Model\SeoAccount(),\Weline\Seo\Service\StoreModeSeoHardGate::class=>new \Weline\Seo\Service\StoreModeSeoHardGate()];
    $hard_gate=false;$push_calls=0;$failures=[];
    $response=['success'=>false,'accepted'=>true,'status'=>'pending_verification','message'=>'Awaiting key verification','data'=>['accepted_url_list'=>['https://example.com/a','https://example.com/b']]];
    $task=new \Weline\Seo\Model\SeoTask();$processor->process($task);
    if($task->status!=='done')$failures[]='IndexNow 202 must not be retried';
    if(($options['action']??'')!=='delete'||($options['website_id']??null)!==0)$failures[]='Task passes action and default site 0 to provider';
    $response=['success'=>false,'accepted'=>true,'status'=>'partial','message'=>'Partial','data'=>['accepted_url_list'=>['https://example.com/a'],'rejected_urls'=>['https://example.com/b']]];
    $task=new \Weline\Seo\Model\SeoTask();$processor->process($task);
    if($task->payload['urls']!==['https://example.com/b']||$task->status!=='error')$failures[]='Partial task retries only unaccepted URLs';
    $hard_gate=true;$before=$push_calls;$task=new \Weline\Seo\Model\SeoTask();$processor->process($task);
    if($push_calls!==$before||$task->status!=='done'||!str_contains($task->message,'dev/test'))$failures[]='Existing dev/test hard gate skips outbound URL submission';
    if($failures){fwrite(STDERR,implode("\n",$failures)."\n");exit(1);}echo "SEO task receipt contracts passed\n";
}
