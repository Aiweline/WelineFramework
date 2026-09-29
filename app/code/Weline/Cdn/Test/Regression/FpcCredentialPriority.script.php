<?php
declare(strict_types=1);
// 实际同步器/RuleManager/Planner；替换持久化、锁与传输边界，无数据库或网络写入。
namespace Weline\Cdn\Model {
    class Domain {
        public const schema_fields_ACCOUNT_ID='account_id',schema_fields_ADAPTER='adapter';
        public function __construct(private array $data=[]) {}
        public function setData(array $data):self {$this->data=$data;return $this;}
        public function getData(string $key):mixed{return $this->data[$key]??null;}
        public function getId():int{return (int)$this->getData('domain_id');}
        public function getCredentialsArray():array{return $this->data['credentials']??[];}
        public function isInheritDefault():bool{return (bool)$this->getData('inherit_default');}
        public function getRulesOverrideArray():array{return [];}
    }
    class Account {
        public static array $fixtures=[];
        public function __construct(private int $id=0){}
        public function reset():self{return new self();}
        public function load(int $id):self{return new self($id);}
        public function getId():int{return isset(self::$fixtures[$this->id])?$this->id:0;}
        public function getCredentialsArray():array{return self::$fixtures[$this->id]??[];}
    }
}
namespace Weline\Framework\Manager { class ObjectManager {public function getInstance(string $class):object{return new $class();}} }
namespace Weline\Framework\Compilation {
    class AtomicCompiledFilePublisher {
        public static array $held=[];
        public function __construct(int $timeout){}
        public function acquireDirectoryLock(string $path):bool {self::$held[$path]=true;return true;}
        public static function releaseDirectoryLock(string $path):void{unset(self::$held[$path]);}
    }
}
namespace Weline\Cdn\Service {
    class FpcPolicyStateService {public array $state=[];public function read():array{return $this->state;}public function mutate(callable $fn):mixed{return $fn($this->state);}}
    class FpcPolicyPublishService {public function publishLatest():array{return ['source_version'=>1];}}
    class FpcPolicyTargetService {public function __construct(public array $fixtures){}public function domains(bool $enabled=true):array{return $this->fixtures;}public function publicEndpoints(\Weline\Cdn\Model\Domain $domain):array{$host=$domain->getData('domain_name');return [['host'=>$host,'base_url'=>'https://'.$host]];}}
    class FpcPolicyHttpVerificationService {public bool $verified=false;public function verify(array $job,array $domains,array $snapshot):array{return ['coverage'=>'sample','status'=>$this->verified?'verified':'unmet','verified'=>$this->verified,'snapshot_revision'=>'fixture','samples'=>[]];}}
    class CompiledFpcPolicySnapshotProvider {public function snapshot():array{return ['revision'=>'fixture'];}}
    class AccountManager {public function getAccount(int $id):?\Weline\Cdn\Model\Account{return new \Weline\Cdn\Model\Account($id);}public function getDefaultAccount(string $adapter):?\Weline\Cdn\Model\Account{return $this->getAccount(7);}}
    class AdapterResolver {public function __construct(private object $adapter){}public function getAdapter(string $name):object{return $this->adapter;}}
}
namespace {
    use Weline\Cdn\Model\{Domain,Account};
    use Weline\Cdn\Service\{FpcPolicySyncService,FpcPolicyStateService,FpcPolicyPublishService,FpcPolicyTargetService,FpcPolicyStateReducer,FpcPolicyRulePlanner,RuleManager,AdapterResolver,AccountManager};
    use Weline\Framework\Compilation\AtomicCompiledFilePublisher;
    define('BP',dirname(__DIR__,6));
    foreach(['FpcPolicyStateReducer','FpcPolicyRulePlanner','RuleManager','FpcPolicySyncService'] as $class){require BP.'/app/code/Weline/Cdn/Service/'.$class.'.php';}
    $assert=static function(bool $condition,string $message):void{if(!$condition){throw new \RuntimeException($message);}};
    try {
        Account::$fixtures=[7=>['fixture'=>'account-seven'],8=>['fixture'=>'account-eight']];
        $base=['domain_id'=>1,'domain_name'=>'a.example.invalid','adapter'=>'cloudflare','zone_id'=>'same-zone','enabled'=>1];
        foreach([
            'inline+account'=>[['account_id'=>7,'credentials'=>['fixture'=>'inline']], 'inline'],
            'inline+default'=>[['inherit_default'=>true,'credentials'=>['fixture'=>'inline']], 'inline'],
            'explicit account'=>[['account_id'=>7], 'account-seven'],
        ] as $label=>[$config,$expected]) {
            $a=new Domain($config+$base);
            $b=new Domain(['domain_id'=>2,'domain_name'=>'b.example.invalid','account_id'=>8]+$base);
            $adapter=new class {
                public array $received=[],$written=[],$locks=[];
                public function getRules(string $zone,array $credentials):array{$this->received=$credentials;$this->locks=array_keys(AtomicCompiledFilePublisher::$held);return [['id'=>'manual-id','ref'=>'manual','expression'=>'true','action'=>'set_cache_settings','action_parameters'=>['cache'=>false]]];}
                public function putRules(string $zone,array $rules,array $credentials):array{$this->written=$rules;return ['success'=>true];}
            };
            $states=new FpcPolicyStateService();$targets=new FpcPolicyTargetService([$a,$b]);$accounts=new AccountManager();$resolver=new AdapterResolver($adapter);
            $rules=new RuleManager(new \Weline\Framework\Manager\ObjectManager(),$resolver,$accounts);
            $http=new \Weline\Cdn\Service\FpcPolicyHttpVerificationService();$http->verified=$label==='explicit account';
            $sync=new FpcPolicySyncService($states,new FpcPolicyPublishService(),$targets,$rules,$resolver,$accounts,$http);
            $binding=$sync->binding($a);
            $assert(($binding['credential_identity']??'')===($expected==='inline'?'domain:1':'account:7'),$label.': job 身份与有效凭据来源不一致');
            $assert(!array_key_exists('credentials',$binding),$label.': job 元数据不应携带凭据');
            $states->state=['next_sync_id'=>1,'desired_version'=>1,'origin_revision'=>'fixture','jobs'=>[]];
            $sync->stage($states->state,[$binding['job_key']=>$binding+['domain_ids'=>[1]]],[]);
            $sync->run($binding['job_key']);
            $assert($states->state['jobs'][$binding['job_key']]['http_verification']['coverage']==='sample','正式 run 未持久写回 HTTP 观察');
            $assert($states->state['jobs'][$binding['job_key']]['verified_version']===($http->verified?1:0),'HTTP 通过与未满足的版本晋级错误');
            $assert(($adapter->received['fixture']??null)===$expected,$label.': adapter 使用了错误的凭据来源');
            $assert(count($adapter->written)===7,$label.': 完整 Zone 应为六默认加一人工规则');
            $assert($adapter->written[0]['id']==='manual-id'&&$adapter->written[0]['ref']==='manual',$label.': 人工规则被改变');
            foreach(array_slice($adapter->written,1) as $rule){$assert(str_contains($rule['expression'],'a.example.invalid')&&str_contains($rule['expression'],'b.example.invalid'),$label.': 不同凭据域名从完整 Zone desired 丢失');}
            $assert(count($adapter->locks)===2,$label.': 缺少跨凭据的 Zone 互斥');
            $assert(AtomicCompiledFilePublisher::$held===[],$label.': 锁未释放');
            $assert($binding['job_key']!==$sync->binding($b)['job_key'],$label.': 不同有效凭据来源错误共用作业身份');
            echo 'PASS '.$label." priority + full Zone + manual preservation + locks\n";
            if($label==='explicit account'){
                $a->setData(['account_id'=>8]+$base);
                $sync->run($binding['job_key']);
                $assert(($adapter->received['fixture']??null)==='account-seven','旧绑定没有保持原账户来源');
                $assert(count($adapter->written)===7,'旧绑定作业丢失现有完整 Zone');
                echo "PASS previous account binding remains traceable\n";
            }
        }
    }catch(\Throwable $error){fwrite(STDERR,'FAIL '.$error->getMessage()."\n");exit(1);}
}
