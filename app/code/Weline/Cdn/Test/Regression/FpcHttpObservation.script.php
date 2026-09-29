<?php
declare(strict_types=1);
require dirname(__DIR__,6).'/app/bootstrap.php';
use Weline\Cdn\Service\FpcPolicyHttpVerificationService;
try {
    if(!class_exists(FpcPolicyHttpVerificationService::class)){throw new RuntimeException('缺少真实 HTTP 观察服务');}
    $service=new class extends FpcPolicyHttpVerificationService {
        public array $responses=[];
        public int $requests=0;
        public bool $enabled=true;
        public function __construct(){}
        protected function samples(array $job,array $domains,array $snapshot):array{return [['url'=>'https://public.example.invalid/blog','domain_id'=>1,'declaration_id'=>'decl','scope'=>['level'=>'website'],'scope_key'=>'website:1','store_mode'=>'normal','policy_fingerprint'=>'fingerprint','enabled'=>$this->enabled,'ttl'=>60]];}
        protected function request(string $url):array{$this->requests++;return array_shift($this->responses);}
    };
    $response=static fn(string $fpc,string $cf,int $ttl=60):array=>['status'=>200,'headers'=>['content-type'=>'text/html','x-weline-fpc'=>$fpc,'cf-cache-status'=>$cf,'cdn-cache-control'=>'public, max-age='.$ttl],'has_set_cookie'=>false,'elapsed_ms'=>1,'body_sha256'=>hash('sha256','public fixture'),'error'=>null];
    $job=['desired_version'=>8];$snapshot=['revision'=>'fixture-revision'];
    $service->responses=[$response('MISS','MISS'),$response('MISS','MISS'),$response('MISS','MISS')];
    $out=$service->verify($job,[],$snapshot);
    if($out['status']!=='unmet'||$out['verified']||count($out['samples'][0]['observations'])!==3){throw new RuntimeException('三次 MISS 不能晋级，且必须记录实际观察');}
    $service->responses=[$response('HIT','MISS'),$response('HIT','HIT')];
    $out=$service->verify($job,[],$snapshot);
    if(!$out['verified']||$out['coverage']!=='sample'||$out['samples'][0]['declaration_id']!=='decl'){throw new RuntimeException('符合策略的真实 HIT 证据没有记录为有限抽样通过');}
    $service->responses=[$response('HIT','HIT',300),$response('HIT','HIT',300),$response('HIT','HIT',300)];
    if($service->verify($job,[],$snapshot)['verified']){throw new RuntimeException('TTL 超标被误报通过');}
    $failure=$response('','');$failure['status']=0;$failure['error']='fixture connection failed';
    $service->responses=[$failure];$out=$service->verify($job,[],$snapshot);
    if($out['status']!=='request_failed'||$out['verified']){throw new RuntimeException('HTTP 失败必须如实记录');}
    $redirect=$response('HIT','HIT');$redirect['status']=302;
    $service->responses=[$redirect,$redirect];if($service->verify($job,[],$snapshot)['verified']){throw new RuntimeException('重定向不是公开正文命中');}
    $cookie=$response('HIT','HIT');$cookie['has_set_cookie']=true;
    $service->responses=[$cookie,$cookie,$cookie];if($service->verify($job,[],$snapshot)['verified']){throw new RuntimeException('Set-Cookie 响应不能视为公开缓存验证');}
    $service->enabled=false;
    $disabled=$response('','DYNAMIC');$disabled['headers']['cdn-cache-control']='no-store';
    $service->responses=[$disabled,$disabled];
    if(!$service->verify($job,[],$snapshot)['verified']){throw new RuntimeException('两次公开 no-store/BYPASS 观察没有记录禁用策略符合');}
    $request=new ReflectionMethod(FpcPolicyHttpVerificationService::class,'request');
    foreach(['http://127.0.0.1/admin','https://user:password@example.invalid/','file:///etc/passwd','https://example.invalid/blog/../admin'] as $unsafe){
        $blocked=$request->invoke($service,$unsafe);if($blocked['error']===null||$blocked['status']!==0){throw new RuntimeException('非法/私网目标未在传输前拒绝');}
    }
    $om=\Weline\Framework\Manager\ObjectManager::getInstance();
    $targets=$om->getInstance(\Weline\Cdn\Service\FpcPolicyTargetService::class);
    $configured=new FpcPolicyHttpVerificationService($targets,$om->getInstance(\Weline\Websites\Api\Catalog\WebsiteCatalogInterface::class),$om->getInstance(\Weline\Websites\Api\Catalog\StoreCatalogInterface::class),$om->getInstance(\Weline\Websites\Api\Catalog\SalesChannelCatalogInterface::class));
    $snapshot=(new \Weline\Cdn\Service\CompiledFpcPolicySnapshotProvider())->snapshot();
    $pick=new ReflectionMethod($configured,'samples');$domains=$targets->domains();
    $samples=$pick->invoke($configured,[], $domains,$snapshot);
    if($samples===[]){throw new RuntimeException('真实公开目录未选出任何可用样本');}
    $unknown=$pick->invoke($configured,['verification_context'=>['declaration_ids'=>['not-a-declaration']]],$domains,$snapshot);
    if($unknown!==[]){throw new RuntimeException('被修改的声明无可访问literal时，不能任取其他主页晋级');}
    $wrongMode=$pick->invoke($configured,['verification_context'=>['store_mode'=>'test']],$domains,$snapshot);
    foreach($wrongMode as $sample){if($sample['store_mode']!=='test'){throw new RuntimeException('抽样跨 mode');}}
    $wrongScope=$pick->invoke($configured,['verification_context'=>['scope_key'=>'unrelated-scope']],$domains,$snapshot);
    if($wrongScope!==[]){throw new RuntimeException('抽样落入无关 Scope');}
    echo "PASS actual verifier: MISS/TTL/transport/redirect/private do not verify; HIT evidence carries sample policy and body hash\n";
}catch(Throwable $error){fwrite(STDERR,'FAIL '.$error->getMessage()."\n");exit(1);}
