<?php
declare(strict_types=1);
namespace Weline\Cdn\Service {
    function w_query(string $provider,string $operation,array $params=[]):mixed {
        if($provider==='queue'&&$operation==='createIfAbsent'){return ['success'=>true,'created'=>true,'queue_id'=>0,'status'=>'pending'];}
        throw new \RuntimeException('隔离回归禁止外部队列操作');
    }
}
namespace {
require dirname(__DIR__,6).'/app/bootstrap.php';
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Cdn\Service\FpcPolicyManagementService;
use Weline\SystemConfig\Api\Scope\ScopeIdentityCatalogInterface;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
try {
    $om=ObjectManager::getInstance();$manager=$om->getInstance(FpcPolicyManagementService::class);
    $catalog=$om->getInstance(ScopeIdentityCatalogInterface::class)->options();$hierarchy=$om->getInstance(ScopeHierarchyInterface::class);
    $chosen=null;
    foreach($catalog as $website){foreach($website['stores']??[]as $store){if($store['store_mode']==='normal'&&($store['channels']??[])!==[]){$chosen=[$website,$store,$store['channels'][0]];break 2;}}}
    if($chosen===null){throw new RuntimeException('真实Scope目录没有完整normal渠道');}
    [$website,$store,$channel]=$chosen;$wid=(int)$website['website_id'];$code=$website['code'];
    $scopes=[ScopeIdentity::global(),ScopeIdentity::website($wid,$code),ScopeIdentity::store($wid,$code,$store['code'],'normal'),ScopeIdentity::channel($wid,$code,$store['code'],$channel['code'],'normal')];
    $list=$manager->listPolicies(['page_size'=>100]);$row=current(array_filter($list['data']['items'],static fn($row)=>$row['active']&&$row['code_enabled']&&$row['code_ttl']>0));$id=$row['declaration_id'];
    $read=static function(string $target,string $mode='normal')use($manager,$id):array{
        $result=$manager->listPolicies(['target_scope'=>$target,'store_mode'=>$mode,'page_size'=>100]);
        if(!($result['success']??false)){throw new RuntimeException(json_encode($result));}
        foreach($result['data']['items']as $item){if($item['declaration_id']===$id){return $item;}}
        throw new RuntimeException('声明行丢失');
    };
    $saved=[];foreach($scopes as $scope){$target=$hierarchy->toStorageScope($scope);$saved[$target]=$read($target);}
    $testBefore=$read('','test');
    try{
        foreach($scopes as $index=>$scope){
            $target=$hierarchy->toStorageScope($scope);
            $patch=match($index){0=>['enabled'=>null,'ttl'=>67],1=>['enabled'=>false,'ttl'=>null],2=>['enabled'=>null,'ttl'=>43],3=>['enabled'=>true,'ttl'=>null]};
            $result=$manager->saveOverride(['target_scope'=>$target,'declaration_id'=>$id]+$patch);
            if(!($result['success']??false)){throw new RuntimeException(json_encode($result));}
        }
        $result=$read($hierarchy->toStorageScope($scopes[3]));
        if(!$result['effective_enabled']||$result['effective_ttl']!==43||$result['enabled_source']!==$scopes[3]->canonicalKey()||$result['ttl_source']!==$scopes[2]->canonicalKey()){throw new RuntimeException('字段未按最近各自覆盖继承');}
        $manager->restoreInheritance(['target_scope'=>$hierarchy->toStorageScope($scopes[3]),'declaration_id'=>$id,'fields'=>['enabled']]);
        $result=$read($hierarchy->toStorageScope($scopes[3]));
        if($result['effective_enabled']||$result['effective_ttl']!==43){throw new RuntimeException('单字段恢复改变了另一字段');}
        $manager->saveOverride(['declaration_id'=>$id,'store_mode'=>'test','ttl'=>19]);
        if($read('','test')['effective_ttl']!==19||$read('')['effective_ttl']!==67){throw new RuntimeException('test/normal串值');}
        echo "PASS 真实目录Scope独立字段继承、单字段恢复、test/normal隔离\n";
    }finally{
        foreach($saved as $target=>$before){$manager->saveOverride(['target_scope'=>$target,'declaration_id'=>$id,'enabled'=>$before['override_enabled'],'ttl'=>$before['override_ttl']]);}
        $manager->saveOverride(['declaration_id'=>$id,'store_mode'=>'test','enabled'=>$testBefore['override_enabled'],'ttl'=>$testBefore['override_ttl']]);
    }
}catch(Throwable $error){fwrite(STDERR,'FAIL '.$error->getMessage()."\n");exit(1);}
}
