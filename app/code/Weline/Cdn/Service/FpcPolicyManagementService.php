<?php
declare(strict_types=1);
namespace Weline\Cdn\Service;
use Weline\Framework\Controller\Extra\ExtraCollector;
use Weline\Framework\Controller\Extra\FpcPolicySnapshot;
use Weline\Framework\Runtime\ScopeIdentity;

/** 后台与收集器共用的策略控制面；声明资格不能由管理覆盖反转。 */
final class FpcPolicyManagementService
{
    public function __construct(private readonly FpcPolicyStateService $states, private readonly FpcPolicyScopeService $scopes, private readonly FpcPolicySyncService $sync, private readonly FpcPolicyTargetService $targets, private readonly ExtraCollector $collector) {}

    private function result(array $data, string $message = ''): array { return ['success'=>true,'message'=>$message,'data'=>$data]; }
    private function failure(\Throwable $error): array { return ['success'=>false,'message'=>$error->getMessage(),'error_code'=>$error instanceof \InvalidArgumentException ? 'invalid_argument':'fpc_policy_error','data'=>null]; }

    public function listPolicies(array $params = []): array
    {
        try {
            [$scope,$mode,$target]=$this->scopes->resolve($params);
            $state=$this->states->read(); $items=[]; $evidence=$this->publishedEvidence();
            $key=$scope->canonicalKey(); $keyword=strtolower(trim((string)($params['keyword']??'')));
            foreach ($state['declarations'] as $id=>$entry) {
                $declaration=$entry['declaration'];
                if ($keyword!=='' && !str_contains(strtolower(json_encode($declaration,JSON_UNESCAPED_UNICODE)),$keyword)) { continue; }
                // 管理查询含 Global/Website 的显式mode；纯解释器运行期从Store身份取mode。
                $matrix=['declarations'=>[$id=>$declaration],'overrides'=>$state['overrides'],'scope_chains'=>[$mode=>[$key=>$this->scopes->chain($scope)]]];
                $path=(string)$declaration['path_pattern'];
                $path=str_replace(['**','*'],['sample/path','sample'],$path);
                $effective=FpcPolicySnapshot::resolve($matrix,$path,$scope,$mode);
                $override=$state['overrides'][$mode][$key][$id]??['enabled'=>null,'ttl'=>null];
                $status='success';$error='';
                foreach($state['jobs'] as $job){if($job['status']!=='success'){$status=$job['status'];$error=$job['last_error'];if($status==='error'){break;}}}
                $items[]=$declaration+[
                    'code_enabled'=>$declaration['attrs']['enabled'],'code_ttl'=>$declaration['attrs']['ttl'],
                    'override_enabled'=>$override['enabled']??null,'override_ttl'=>$override['ttl']??null,
                    'effective_enabled'=>$effective['enabled']??false,'effective_ttl'=>$effective['ttl']??0,
                    'enabled_source'=>$effective['enabled_source']??'code','ttl_source'=>$effective['ttl_source']??'code',
                    'active'=>(bool)$entry['active'],'policy_fingerprint'=>$effective['policy_fingerprint']??'',
                    'origin_version'=>$state['origin_version'],'desired_version'=>$state['desired_version'],'sync_status'=>$status,'last_error'=>$error,
                ] + $evidence;
            }
            $page=max(1,(int)($params['page']??1));$size=max(1,min(100,(int)($params['page_size']??20)));
            return $this->result(['items'=>array_slice($items,($page-1)*$size,$size),'total'=>count($items),'page'=>$page,'page_size'=>$size,'target_scope'=>$target,'store_mode'=>$mode,'desired_version'=>$state['desired_version'],'origin_version'=>$state['origin_version']] + $evidence);
        } catch (\Throwable $error) { return $this->failure($error); }
    }

    public function saveOverride(array $params): array
    {
        try {
            [$scope,$mode]=$this->scopes->resolve($params);
            $id=(string)($params['declaration_id']??'');
            foreach (['enabled','ttl'] as $field) {
                if (!array_key_exists($field,$params) || $params[$field]===null) { continue; }
                if ($field==='enabled' && !is_bool($params[$field])) { throw new \InvalidArgumentException((string)__('enabled 必须是布尔值或 null')); }
                if ($field==='ttl' && (!is_int($params[$field]) || $params[$field]<1)) { throw new \InvalidArgumentException((string)__('TTL 必须是正整数或 null')); }
            }
            $groups=$this->sync->groups();
            $known=$this->states->read()['declarations'][$id]??null;
            if ($known===null || !$known['active']) { throw new \InvalidArgumentException((string)__('FPC 声明不存在或已撤回')); }
            $purge=$this->targets->targets([$known['declaration']],$scope);
            $chain=$this->scopes->chain($scope);
            $jobs=[];
            $data=$this->states->mutate(function(array &$state)use($scope,$mode,$id,$params,$groups,$purge,$chain,&$jobs):array{
                $entry=$state['declarations'][$id]??null;
                if ($entry===null || !$entry['active']) { throw new \InvalidArgumentException((string)__('FPC 声明不存在或已撤回')); }
                $attrs=$entry['declaration']['attrs'];
                if (($params['enabled']??null)===true && (!$attrs['enabled'] || $attrs['ttl']===0)) { throw new \InvalidArgumentException((string)__('代码禁止缓存的声明不可开启')); }
                $key=$scope->canonicalKey();
                $before=$state['overrides'][$mode][$key][$id]??['enabled'=>null,'ttl'=>null];$after=$before;
                foreach(['enabled','ttl'] as $field){if(array_key_exists($field,$params)){$after[$field]=$params[$field];}}
                if($before===$after){return $this->changeData($state,false);}
                if ($after['enabled']===null && $after['ttl']===null) {
                    unset($state['overrides'][$mode][$key][$id]);
                    if(empty($state['overrides'][$mode][$key])){unset($state['overrides'][$mode][$key]);}
                    if(empty($state['overrides'][$mode])){unset($state['overrides'][$mode]);}
                } else { $state['overrides'][$mode][$key][$id]=$after; }
                $state['scope_chains'][$mode][$key]=$chain;
                $state['desired_version']++;
                $jobs=$this->sync->stage($state,$groups,['trigger'=>$params['_trigger']??'override_save','desired_version'=>$state['desired_version'],'purge_targets'=>$purge,'verification_context'=>['declaration_ids'=>[$id],'scope_key'=>$scope->canonicalKey(),'store_mode'=>$mode]]);
                return $this->changeData($state,true);
            });
            return $this->finishChange($data,$jobs);
        } catch (\Throwable $error) { return $this->failure($error); }
    }

    private function changeData(array $state,bool $changed):array{return ['changed'=>$changed,'desired_version'=>$state['desired_version'],'origin_version'=>$state['origin_version'],'queue_ids'=>[],'sync_ids'=>[]];}

    private function finishChange(array $data,array $jobs):array
    {
        if ($jobs!==[]) {
            $this->states->afterCommit('cdn-fpc-admit:'.$data['desired_version'],function()use($jobs,&$data):void{$data=array_replace($data,$this->sync->admit($jobs));});
        }
        return $this->result($data);
    }

    public function restoreInheritance(array $params): array
    {
        $fields=$params['fields']??['enabled','ttl'];
        if(!is_array($fields)||array_diff($fields,['enabled','ttl'])!==[]){return $this->failure(new \InvalidArgumentException((string)__('恢复字段只能是 enabled 或 ttl')));}
        foreach($fields as $field){$params[$field]=null;}
        $params['_trigger']='override_restore';
        return $this->saveOverride($params);
    }

    public function collectDeclarations(array $params = []): array
    {
        try {
            $rows=$this->collector->loadSidecar();
            if($rows===null){throw new \RuntimeException((string)__('FPC 声明侧车不存在，请先完成路由收集'));}
            $projection=[];
            foreach($rows as $row){if(strtolower((string)($row['type']??''))==='fpc'){$row=FpcPolicyStateReducer::declaration($row);$projection[$row['declaration_id']]=['active'=>true,'declaration'=>$row];}}
            $chains=$this->scopes->chains();$groups=$this->sync->groups();
            $old=$this->states->read();$paths=[];
            foreach($old['declarations'] as $id=>$entry){if($entry['active']&&($projection[$id]??null)!==$entry){$paths[]=$entry['declaration'];}}
            foreach($projection as $id=>$entry){if(($old['declarations'][$id]??null)!==$entry){$paths[]=$entry['declaration'];}}
            $purge=$this->targets->targets($paths);$jobs=[];
            $data=$this->states->mutate(function(array &$state)use($projection,$chains,$groups,$purge,$paths,&$jobs):array{
                $next=$state['declarations'];
                foreach($next as $id=>&$entry){if(!isset($projection[$id])){$entry['active']=false;}}unset($entry);
                $next=array_replace($next,$projection);ksort($next);
                if($next===$state['declarations']&&$chains===$state['scope_chains']){return $this->changeData($state,false);}
                $state['declarations']=$next;$state['scope_chains']=$chains;$state['desired_version']++;
                $jobs=$this->sync->stage($state,$groups,['trigger'=>'route_collect','desired_version'=>$state['desired_version'],'purge_targets'=>$purge,'verification_context'=>['declaration_ids'=>array_values(array_unique(array_column($paths,'declaration_id')))]]);
                return $this->changeData($state,true);
            });
            return $this->finishChange($data,$jobs);
        } catch(\Throwable $error){return $this->failure($error);}
    }

    public function listSyncRecords(array $params = []):array
    {
        try {
            $state = $this->states->read();
            $evidence = $this->publishedEvidence();
            $domainNames = [];
            foreach ($this->targets->domains(false) as $domain) {
                $domainNames[(int)$domain->getId()] = (string)$domain->getData('domain_name');
            }
            $keyword = mb_strtolower(trim((string)($params['keyword'] ?? '')), 'UTF-8');
            $items = [];
            foreach ($state['jobs'] as $job) {
                $job['domain_names'] = array_values(array_filter(array_map(
                    static fn(int $id): string => $domainNames[$id] ?? '',
                    array_map('intval', $job['domain_ids']),
                )));
                $searchFields = array_intersect_key($job, array_flip([
                    'sync_id', 'job_key', 'adapter', 'account_id', 'zone_id',
                    'domain_ids', 'domain_names', 'status', 'last_error',
                ]));
                $searchText = mb_strtolower(json_encode(array_values($searchFields), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'UTF-8');
                if ($keyword !== '' && !str_contains($searchText, $keyword)) {
                    continue;
                }
                $items[] = $job + $evidence;
            }
            usort($items, static fn(array $a, array $b): int => $b['sync_id'] <=> $a['sync_id']);
            $page = max(1, (int)($params['page'] ?? 1));
            $size = max(1, min(100, (int)($params['page_size'] ?? 20)));
            return $this->result([
                'items' => array_slice($items, ($page - 1) * $size, $size),
                'total' => count($items), 'page' => $page, 'page_size' => $size,
            ] + $evidence);
        }catch(\Throwable $error){return $this->failure($error);}
    }

    /** 已确认版本与实际文件版本分开呈现；损坏文件不能妨碍控制面诊断和修复。 */
    private function publishedEvidence(): array
    {
        try {
            $snapshot = (new CompiledFpcPolicySnapshotProvider())->snapshot();
            return [
                'snapshot_version' => (int)($snapshot['source_version'] ?? 0),
                'snapshot_revision' => (string)($snapshot['revision'] ?? ''),
                'snapshot_error' => null,
            ];
        } catch (\Throwable $error) {
            return ['snapshot_version' => null, 'snapshot_revision' => null, 'snapshot_error' => $error->getMessage()];
        }
    }

    public function retrySync(array $params):array
    {
        try{
            foreach($this->states->read()['jobs'] as $job){if($job['sync_id']===(int)($params['sync_id']??0)){return $this->result($this->sync->admit([$job])+['status'=>'pending']);}}
            throw new \InvalidArgumentException((string)__('同步记录不存在'));
        }catch(\Throwable $error){return $this->failure($error);}
    }

    public function requestManualSync(array $params):array
    {
        try{
            $id=(int)($params['domain_id']??0);if($id<1){throw new \InvalidArgumentException((string)__('域名不存在'));}
            $found=false;foreach($this->targets->domains(false)as $domain){if((int)$domain->getId()===$id){$found=true;break;}}
            if(!$found){throw new \InvalidArgumentException((string)__('域名不存在'));}
            $state=$this->states->read();
            return $this->result($this->sync->requestSync(['trigger'=>'manual','desired_version'=>$state['desired_version'],'domain_ids'=>[$id],'purge_targets'=>[]])+['status'=>'pending','desired_version'=>$state['desired_version'],'origin_version'=>$state['origin_version']],(string)__('同步任务已提交'));
        }catch(\Throwable $error){return $this->failure($error);}
    }

    public function notifyDomainChange(array $change):array
    {
        try{
            if(($change['before']??null)===($change['after']??null)){return $this->result($this->changeData($this->states->read(),false));}
            $domains=[];
            foreach($this->targets->domains(false)as $domain){if((int)$domain->getId()===(int)$change['domain_id']){$domains[]=$domain;}}
            $groups=$this->sync->groups();$byJob=[];
            foreach(['before','after']as $phase){
                if(!is_array($change[$phase]??null)||empty($change[$phase]['zone_id'])){continue;}
                $domain=new \Weline\Cdn\Model\Domain();$domain->setData($change[$phase]+['domain_id'=>(int)$change['domain_id']]);
                $binding=$this->sync->binding($domain);$groups[$binding['job_key']]??=$binding+['domain_ids'=>[(int)$change['domain_id']]];
                $domains[]=$domain;
                $byJob[$binding['job_key']]=array_merge($byJob[$binding['job_key']]??[],$this->targets->targets([],null,[$domain]));
            }
            $purge=$this->targets->targets([],null,$domains);$jobs=[];
            $data=$this->states->mutate(function(array &$state)use($groups,$purge,$byJob,&$jobs):array{
                $state['desired_version']++;
                $jobs=$this->sync->stage($state,$groups,['trigger'=>'domain_change','purge_targets'=>$purge,'purge_targets_by_job'=>$byJob]);
                return $this->changeData($state,true);
            });
            return $this->finishChange($data,$jobs);
        }catch(\Throwable $error){return $this->failure($error);}
    }

    public function notifyScopeBindingChange(array $change):array
    {
        try{
            $fields=array_flip(['account_id','adapter','media_base_url','global_alias','storage_scope','store_mode']);
            if (array_intersect_key((array)($change['before']??[]),$fields)===array_intersect_key((array)($change['after']??[]),$fields)) { return $this->result($this->changeData($this->states->read(),false)); }
            [$scope,$mode]=$this->scopes->resolve($change);$chains=$this->scopes->chains();$groups=$this->sync->groups();$purge=$this->targets->targets([],$scope);$jobs=[];
            $data=$this->states->mutate(function(array &$state)use($chains,$groups,$purge,$scope,$mode,&$jobs):array{
                $state['scope_chains']=$chains;$state['desired_version']++;
                $jobs=$this->sync->stage($state,$groups,['trigger'=>'domain_change','purge_targets'=>$purge,'verification_context'=>['scope_key'=>$scope->canonicalKey(),'store_mode'=>$mode]]);return $this->changeData($state,true);
            });
            return $this->finishChange($data,$jobs);
        }catch(\Throwable $error){return $this->failure($error);}
    }
}
