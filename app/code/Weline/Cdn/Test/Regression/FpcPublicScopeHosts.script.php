<?php
declare(strict_types=1);
require dirname(__DIR__,6).'/app/bootstrap.php';
use Weline\Cdn\Model\Domain;
use Weline\Cdn\Service\{FpcPolicyTargetService,FpcPolicySyncService,FpcPolicyHttpVerificationService,FpcPolicyRulePlanner,RuleManager,WarmupLocaleUrlExpander};
use Weline\Cdn\Adapter\Cloudflare;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Controller\Extra\FpcPolicySnapshot;
use Weline\Websites\Api\Catalog\{WebsiteCatalogInterface,StoreCatalogInterface,SalesChannelCatalogInterface};
use Weline\Websites\Api\Catalog\Data\{WebsiteSummary,StoreSummary,SalesChannelSummary};
try {
    $assert=static function(bool $ok,string $why):void{if(!$ok){throw new RuntimeException($why);}};
    $om=ObjectManager::getInstance();
    $websites=new class implements WebsiteCatalogInterface {
        public array $rows=[];
        public function defaultWebsiteId():int{return 0;}public function all():array{return $this->rows;}public function count():int{return count($this->rows);}
    };
    $websites->rows=[new WebsiteSummary(0,'Production default','default','https://www.changanhanfu.com'),new WebsiteSummary(1,'Other site','other','https://foreign.changanhanfu.com')];
    $stores=new class implements StoreCatalogInterface {
        public array $rows=[];
        public function byWebsite(int $id):array{return array_values(array_filter($this->rows,static fn($s)=>$s->websiteId===$id));}
        public function byCode(int $id,string $code):?StoreSummary{foreach($this->byWebsite($id) as $s){if($s->code===$code){return $s;}}return null;}
        public function byId(int $id):?StoreSummary{foreach($this->rows as $s){if($s->id===$id){return $s;}}return null;}
        public function defaultStore(int $id):?StoreSummary{return $this->byCode($id,'default');}public function all():array{return $this->rows;}
    };
    $stores->rows=[new StoreSummary(0,0,'default','Default','normal',true,true,'active',null,null),new StoreSummary(1,0,'a','A','normal',false,true,'active',null,'https://www.changanhanfu.com/shop-a'),new StoreSummary(2,0,'b','B','normal',false,true,'active',null,'https://www.changanhanfu.com/shop-b'),new StoreSummary(3,0,'outside','Outside','normal',false,true,'active',null,'https://changanhanfu.com.evil.invalid/'),new StoreSummary(4,0,'suffix','Suffix','normal',false,true,'active',null,'https://evilchanganhanfu.com/')];
    $channels=new class implements SalesChannelCatalogInterface {
        public function byStore(int $id):array{return [];}public function byCode(int $id,string $code):?SalesChannelSummary{return null;}public function byId(int $id):?SalesChannelSummary{return null;}public function defaultChannel(int $id):?SalesChannelSummary{return null;}public function defaultChannelForStore(StoreSummary $s):?SalesChannelSummary{return null;}
    };
    // 生产只读已核输入：一个Domain1/apex/site0，Website0正式public host是www。
    $domain=(new Domain())->setData(['domain_id'=>1,'site_id'=>0,'domain_name'=>'changanhanfu.com','adapter'=>'cloudflare','zone_id'=>'fixture-zone','enabled'=>1,'inherit_default'=>false]);
    $targets=new FpcPolicyTargetService(new Domain(),$websites,$stores,$om->getInstance(WarmupLocaleUrlExpander::class));
    $sync=(new ReflectionClass(FpcPolicySyncService::class))->newInstanceWithoutConstructor();
    foreach(['targets'=>$targets,'rules'=>$om->getInstance(RuleManager::class)] as $field=>$value){(new ReflectionProperty($sync,$field))->setValue($sync,$value);}
    $desiredMethod=new ReflectionMethod($sync,'desiredRules');$adapter=$om->getInstance(Cloudflare::class);
    $desired=$desiredMethod->invoke($sync,[$domain],$adapter);
    $assert(count($desired)===6,'单Domain公开别名不能把六条默认规则复制成十二条');
    foreach($desired as $rule){$assert(str_contains($rule['expression'],'"changanhanfu.com"')&&str_contains($rule['expression'],'"www.changanhanfu.com"'),'实际Website www被desired规则丢失');$assert(!str_contains($rule['expression'],'foreign.')&&!str_contains($rule['expression'],'evil'),'跨站或非法suffix混入规则');}
    $baseline=json_decode(file_get_contents(BP.'/dev/team/cdn-fpc-policy-sync/cloud-rules-baseline.json'),true,512,JSON_THROW_ON_ERROR)['rules'];
    $manual=['id'=>'human-rule-id','ref'=>'human-rule-ref','description'=>'Operator rule','enabled'=>false,'action'=>'set_cache_settings','expression'=>'http.request.uri.path eq "/manual-only"','action_parameters'=>['cache'=>false]];
    $withManual=$baseline;array_splice($withManual,2,0,[$manual]);$preserved=FpcPolicyRulePlanner::merge($withManual,$desired);
    $assert(count($preserved)===7&&$preserved[2]===$manual&&array_column($preserved,'id')===array_column($withManual,'id'),'人工规则内容/id/ref/相对位置被改动');
    $initialPlan=FpcPolicyRulePlanner::plan($baseline,$desired);$merged=$initialPlan['rules'];$legacyBindings=$initialPlan['bindings'];
    $assert(count($merged)===6&&array_column($merged,'id')===array_column($baseline,'id'),'首次六条规则认领丢ID或重复创建');
    $assert(FpcPolicyRulePlanner::equivalent($merged,FpcPolicyRulePlanner::merge($merged,$desired,$legacyBindings)),'重复公开host集合不是no-op');
    $refs=array_column($desired,'ref');$assert($refs[0]==='weline_cdn_'.substr(hash('sha256',"default:0\nchanganhanfu.com"),0,40),'既有受管ref身份公式被改变');$websites->rows[0]=new WebsiteSummary(0,'Production default','default','https://changanhanfu.com');$savedStores=$stores->rows;$stores->rows=[$stores->rows[0]];
    $withoutWww=$desiredMethod->invoke($sync,[$domain],$adapter);$assert($refs===array_column($withoutWww,'ref'),'只变公开host集合不应改变稳定受管ref');
    $afterRemoval=FpcPolicyRulePlanner::merge($merged,$withoutWww,$legacyBindings);$assert(array_column($afterRemoval,'id')===array_column($baseline,'id'),'public host变动丢已有受管ID');
    $websites->rows[0]=new WebsiteSummary(0,'Production default','default','https://www.changanhanfu.com');$stores->rows=$savedStores;
    $declaration=['module'=>'Weline_Blog','class'=>'Weline\\Blog\\Controller\\Frontend\\Index','method'=>'index','type'=>'fpc','path_pattern'=>'/blog/frontend/index','public_path_patterns'=>['/blog'],'attrs'=>['enabled'=>true,'ttl'=>600]];$id=FpcPolicySnapshot::declarationId($declaration);$declaration['declaration_id']=$id;
    $purge=$targets->targets([$declaration],null,[$domain]);$values=array_column($purge,'value');
    $assert(in_array('https://www.changanhanfu.com/blog',$values,true)&&in_array('https://changanhanfu.com/blog',$values,true),'purge缺少实际www或绑定apex URL');
    $scope=ScopeIdentity::store(0,'default','a','normal');$scoped=$targets->publicEndpoints($domain,$scope);
    $assert(array_column($scoped,'base_url')===['https://www.changanhanfu.com/shop-a','https://changanhanfu.com/shop-a'],'Store scope未精确保留自身public path');
    foreach($targets->targets([$declaration],$scope,[$domain]) as $target){$path=$target['kind']==='url'?(string)parse_url($target['value'],PHP_URL_PATH):substr($target['value'],strpos($target['value'],'/'));$assert($path==='/shop-a'||str_starts_with($path,'/shop-a/'),'Store purge退化root或带入其他Store');}
    $assert($targets->publicEndpoints($domain,ScopeIdentity::store(0,'default','outside','normal'))===[],'显式外站store URL不应退到Website或root');
    $www=(new Domain())->setData(['domain_id'=>2,'site_id'=>0,'domain_name'=>'www.changanhanfu.com']);
    $assert(!in_array('changanhanfu.com',array_column($targets->publicEndpoints($www),'host'),true),'绑定www时倒推出apex');
    $http=new FpcPolicyHttpVerificationService($targets,$websites,$stores,$channels);
    $snapshot=['declarations'=>[$id=>$declaration],'scope_chains'=>[],'overrides'=>[],'revision'=>'fixture','source_version'=>1];
    $samples=(new ReflectionMethod($http,'samples'))->invoke($http,[],[$domain],$snapshot);
    $assert(count($samples)===2&&str_starts_with($samples[0]['url'],'https://www.changanhanfu.com/')&&str_starts_with($samples[1]['url'],'https://changanhanfu.com/'),'HTTP样本没有按可信public host优先包含www与apex');
    echo "PASS actual apex/site0→www: six rules/legacy IDs/stable refs/purge/public samples; foreign hosts excluded; store prefixes preserved\n";
}catch(Throwable $error){fwrite(STDERR,'FAIL '.$error->getMessage()."\n");exit(1);}
