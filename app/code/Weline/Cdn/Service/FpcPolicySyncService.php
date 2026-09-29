<?php
declare(strict_types=1);
namespace Weline\Cdn\Service;
use Weline\Cdn\Model\Domain;
use Weline\Cdn\Queue\FpcPolicySync;
use Weline\Framework\Compilation\AtomicCompiledFilePublisher;
use Weline\Framework\Runtime\ScopeEnvelope;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeEdgeCacheInvalidatorInterface;
use Weline\Framework\Runtime\RuntimeProviderResolution;
use Weline\Framework\Runtime\RuntimeProviderResolver;

/** 持久目标是权威，Queue 内容只负责唤醒同一个 Zone 作业。 */
final class FpcPolicySyncService
{
    public function __construct(private readonly FpcPolicyStateService $states, private readonly FpcPolicyPublishService $publisher, private readonly FpcPolicyTargetService $targets, private readonly RuleManager $rules, private readonly AdapterResolver $adapters, private readonly AccountManager $accounts, private readonly FpcPolicyHttpVerificationService $http) {}

    public function binding(Domain $domain): array
    {
        $adapter=(string)$domain->getData('adapter');
        $source=$this->rules->resolveCredentialSource($domain);
        $account=(int)$source['account_id'];
        $zone=(string)$domain->getData('zone_id');
        // 账户来源沿用既有 key；内联凭据按域名身份分组，不能伪装成 account_id。
        $identity=$account>0 ? (string)$account : $source['credential_identity'];
        unset($source['credentials']);
        return ['job_key'=>hash('sha256',$adapter."\n".$identity."\n".$zone),'adapter'=>$adapter,'zone_id'=>$zone]+$source;
    }

    public function requestSync(array $change): array
    {
        $groups=[];
        $ids=array_map('intval',(array)($change['domain_ids'] ?? []));
        foreach ($this->targets->domains(false) as $domain) {
            if ($ids !== [] && !in_array((int)$domain->getId(),$ids,true)) { continue; }
            if ($ids === [] && !(bool)$domain->getData('enabled')) { continue; }
            $binding=$this->binding($domain);
            if ($binding['adapter']==='' || $binding['zone_id']==='') { continue; }
            $key=$binding['job_key'];
            $groups[$key] ??= $binding+['domain_ids'=>[]];
            $groups[$key]['domain_ids']=array_values(array_unique([...$groups[$key]['domain_ids'],(int)$domain->getId()]));
            sort($groups[$key]['domain_ids'],SORT_NUMERIC);
            $groups[$key]['bound_hosts'][]=(string)$domain->getData('domain_name');
        }
        $groups['origin']=['job_key'=>'origin','adapter'=>'','account_id'=>0,'zone_id'=>'','domain_ids'=>[]];
        $jobs=$this->states->mutate(function (array &$state) use ($groups,$change): array { return $this->stage($state,$groups,$change); });
        return $this->admit($jobs);
    }

    /** 可在声明/覆盖事务中调用，确保保存与待同步状态不可分割。 */
    public function stage(array &$state, array $groups, array $change): array
    {
        $jobs=[];
        foreach ($groups as $key=>$group) {
            $job=$state['jobs'][$key] ?? ($group+['sync_id'=>$state['next_sync_id']++,'desired_version'=>0,'origin_version'=>0,'cloud_version'=>0,'purge_version'=>0,'verified_version'=>0,'pending_targets'=>[],'cloud_receipt'=>null,'purge_receipt'=>null,'http_verification'=>null,'last_error'=>'']);
            $job['domain_ids']=array_values(array_unique(array_merge($job['domain_ids'],$group['domain_ids'])));
            $version=max((int)$state['desired_version'],(int)($change['desired_version'] ?? 0));
            $targets=$change['purge_targets_by_job'][$key] ?? array_values(array_filter((array)($change['purge_targets'] ?? []),static fn($t):bool=>in_array((int)$t['domain_id'],$group['domain_ids'],true)));
            $job['desired_version']=max($job['desired_version'],$version);
            $job['verification_context']=$change['verification_context'] ?? [];
            $job['pending_targets']=FpcPolicyStateReducer::accumulateTargets($job['pending_targets'],$targets,$version);
            $job['status']='pending'; $job['updated_at']=gmdate(DATE_ATOM); $job['trigger']=$change['trigger'] ?? 'manual';
            $state['jobs'][$key]=$job; $jobs[]=$job;
        }
        return $jobs;
    }

    public function groups(): array
    {
        $groups=['origin'=>['job_key'=>'origin','adapter'=>'','account_id'=>0,'zone_id'=>'','domain_ids'=>[]]];
        foreach ($this->targets->domains() as $domain) {
            $binding=$this->binding($domain); if ($binding['zone_id']==='') { continue; }
            $key=$binding['job_key']; $groups[$key] ??= $binding+['domain_ids'=>[]];
            $groups[$key]['domain_ids']=array_values(array_unique([...$groups[$key]['domain_ids'],(int)$domain->getId()]));
            sort($groups[$key]['domain_ids'],SORT_NUMERIC);
            $groups[$key]['bound_hosts'][]=(string)$domain->getData('domain_name');
        }
        return $groups;
    }

    public function admit(array $jobs): array
    {
        $queueIds=[]; $syncIds=[]; $errors=[];
        foreach ($jobs as $job) {
            $syncIds[]=$job['sync_id'];
            try {
                $result=w_query('queue','createIfAbsent',[
                    'class'=>FpcPolicySync::class,'name'=>'FPC 策略同步','module'=>'Weline_Cdn','auto'=>true,
                    'biz_key'=>'cdn-fpc-sync:'.$job['job_key'],'idempotency_scope'=>'Weline_Cdn:fpc-sync',
                    'idempotency_key'=>$job['job_key'].':'.$job['desired_version'],
                    'scope_envelope'=>ScopeEnvelope::of(ScopeIdentity::global())->toArray(),
                    'content'=>['schema_version'=>'cdn-fpc-sync-job.v1','job_key'=>$job['job_key'],'requested_version'=>$job['desired_version']],
                ]);
                if (!($result['success'] ?? false)) { throw new \RuntimeException('cdn_fpc_queue_admission_failed'); }
                $queueIds[]=(int)$result['queue_id'];
                // 原版本失败/已完成任务可能仍需处理手动重试与晚到的目标。
                if (!($result['created'] ?? false) && in_array($result['status'] ?? '',['error','done','stop'],true)) {
                                        $retry=w_query('queue_admin','action',['action'=>'retry','queue_id'=>(int)$result['queue_id']]);
                    if (!($retry['success'] ?? false)) { throw new \RuntimeException((string)($retry['message'] ?? $retry['msg'] ?? 'cdn_fpc_queue_retry_failed')); }
                    w_query('queue','dispatch',['queue_id'=>(int)$result['queue_id']]);
                }
            } catch (\Throwable $error) {
                $errors[]=$error->getMessage();
                $this->update($job['job_key'],static function (array &$row) use ($error): void { $row['status']='error'; $row['last_error']='queue: '.$error->getMessage(); });
            }
        }
        return ['queue_ids'=>$queueIds,'sync_ids'=>$syncIds,'admission_errors'=>$errors];
    }

    public function reconcile(): array
    {
        $jobs=array_values(array_filter($this->states->read()['jobs'],static fn($job):bool=>$job['status']!=='success' || $job['pending_targets']!==[]));
        return $this->admit($jobs);
    }

    private function update(string $key, callable $callback): void
    {
        $this->states->mutate(static function (array &$state) use ($key,$callback): void {
            if (!isset($state['jobs'][$key])) { throw new \RuntimeException('cdn_fpc_job_not_found'); }
            $callback($state['jobs'][$key]); $state['jobs'][$key]['updated_at']=gmdate(DATE_ATOM);
        });
    }

    public function run(string $jobKey): array
    {
        if ($jobKey!=='origin' && preg_match('/^[a-f0-9]{64}$/D',$jobKey)!==1) { throw new \InvalidArgumentException('cdn_fpc_invalid_job_key'); }
        $directory=BP.'/var/locks/cdn-fpc-jobs/'.$jobKey;
        $lock=new AtomicCompiledFilePublisher(1000); $locked=$lock->acquireDirectoryLock($directory);
        $stage='origin'; $zoneDirectory=null; $zoneLocked=false;
        try {
            $job=$this->states->read()['jobs'][$jobKey] ?? throw new \RuntimeException('cdn_fpc_job_not_found');
            $version=(int)$job['desired_version'];
            $this->update($jobKey,static function (array &$row):void {$row['status']='running';$row['last_error']='';});
            $published=$this->publisher->publishLatest();
            $this->update($jobKey,static function(array &$row)use($published):void{$row['origin_version']=$published['source_version'];});
            if ($jobKey!=='origin') {
                $stage='cloud';
                // 不同凭据作业也会改同一个完整 Ruleset，按 Zone 再串行化 GET/PUT/purge。
                $zoneIdentity=hash('sha256',$job['adapter']."\n".$job['zone_id']);
                $zoneDirectory=BP.'/var/locks/cdn-fpc-zones/'.$zoneIdentity;
                $zoneLocked=$lock->acquireDirectoryLock($zoneDirectory);
                $domains=[]; $credentialDomain=null;
                $identity=$job['credential_identity'] ?? ((int)$job['account_id']>0 ? 'account:'.$job['account_id'] : null);
                foreach ($this->targets->domains(false) as $domain) {
                    if ((string)$domain->getData('adapter')!==$job['adapter']) { continue; }
                    // 凭据选择不能截断同 Zone 的完整 desired 集合。
                    if ((string)$domain->getData('zone_id')===$job['zone_id'] && (bool)$domain->getData('enabled')) { $domains[]=$domain; }
                    $source=$this->rules->resolveCredentialSource($domain);
                    if ($identity!==null && $source['credential_identity']===$identity) { $credentialDomain ??= $domain; }
                    // 兼容旧版 account=0 作业：只追溯原 domain_ids 中的域名自定义来源。
                    if ($identity===null && (int)$source['account_id']===0 && in_array((int)$domain->getId(),$job['domain_ids'],true)) {
                        $credentialDomain ??= $domain;
                    }
                }
                if ($credentialDomain===null && (int)$job['account_id']>0) {
                    // 账户绑定改变后仍用作业记录的旧账户处理累计旧目标。
                    $credentialDomain=(new Domain())->setData(['adapter'=>$job['adapter'],'account_id'=>(int)$job['account_id'],'inherit_default'=>false]);
                }
                if ($credentialDomain===null) { throw new \RuntimeException('cdn_fpc_previous_binding_credentials_unavailable'); }
                $adapter=$this->adapters->getAdapter($job['adapter']) ?? throw new \RuntimeException('cdn_fpc_adapter_unavailable');
                $credentials=$this->rules->getCredentials($credentialDomain);
                if ($credentials===[]) { throw new \RuntimeException('cdn_fpc_credentials_unavailable'); }
                $desired=$this->desiredRules($domains,$adapter);
                $existing=$adapter->getRules($job['zone_id'],$credentials);
                $bindings=$this->states->read()['cloud_rule_bindings'][$zoneIdentity] ?? [];
                $plan=FpcPolicyRulePlanner::plan($existing,$desired,$bindings);
                if ($plan['bindings']!==$bindings) {
                    // Zone锁内先持久化public id归属；短事务仅合入该字段，保留并发desired/purge/receipt。
                    $this->states->mutate(static function(array &$state)use($zoneIdentity,$plan):void{
                        $state['cloud_rule_bindings'][$zoneIdentity]=$plan['bindings'];
                    });
                }
                $merged=$plan['rules'];
                $changed=!FpcPolicyRulePlanner::equivalent($existing,$merged);
                $receipt=$changed ? $adapter->putRules($job['zone_id'],$merged,$credentials) : ['success'=>true,'changed'=>false];
                if (!($receipt['success']??false)) { throw new \RuntimeException((string)($receipt['message']??'cdn_fpc_cloud_rejected')); }
                $cloud=['accepted'=>true,'changed'=>$changed,'at'=>gmdate(DATE_ATOM),'rules_count'=>count($merged),'ruleset_id'=>$receipt['data']['id']??null,'ruleset_version'=>$receipt['data']['version']??null];
                $this->update($jobKey,static function(array &$row)use($version,$cloud):void{$row['cloud_version']=$version;$row['cloud_receipt']=$cloud;});
                $stage='purge';
                $this->invalidateLocalEdge($jobKey,$job,$version);
                $byKind=[];
                foreach ($job['pending_targets'] as $pending) { $byKind[$pending['target']['kind']][]=$pending['target']; }
                foreach ($byKind as $kind=>$targets) {
                    foreach (array_chunk($targets,100) as $batch) {
                        $values=array_column($batch,'value');
                        if ($kind==='prefix' && !method_exists($adapter,'purgePrefixes')) {
                            throw new \RuntimeException('cdn_fpc_adapter_prefix_purge_unsupported');
                        }
                        $result=match($kind) {
                            'url'=>$adapter->purgeUrls($job['zone_id'],$values,$credentials),
                            'host'=>$adapter->purgeHosts($job['zone_id'],$values,$credentials),
                            'prefix'=>$adapter->purgePrefixes($job['zone_id'],$values,$credentials),
                        };
                        if (!($result['success']??false)) { throw new \RuntimeException((string)($result['message']??'cdn_fpc_purge_rejected')); }
                        $purgeIds=array_values(array_map('strval',(array)($result['purge_ids']??[])));
                        $this->update($jobKey,static function(array &$row)use($batch,$version,$purgeIds):void{
                            $row['pending_targets']=FpcPolicyStateReducer::acknowledgeTargets($row['pending_targets'],$batch,$version);
                            foreach ($batch as $target) {
                                $row['purge_receipt']['targets'][FpcPolicyStateReducer::targetKey($target)]=['target'=>$target,'accepted_version'=>$version,'accepted_at'=>gmdate(DATE_ATOM),'purge_ids'=>$purgeIds];
                            }
                        });
                    }
                }
            }
            $this->update($jobKey,static function(array &$row)use($version):void{$row['purge_version']=$version;});
            $verification=null;
            if ($jobKey!=='origin') {
                try {
                    $verification=$this->http->verify($job,$domains,(new CompiledFpcPolicySnapshotProvider())->snapshot());
                } catch (\Throwable $error) {
                    $verification=['coverage'=>'sample','requested_version'=>$version,'observed_at'=>gmdate(DATE_ATOM),'status'=>'request_failed','verified'=>false,'samples'=>[],'error'=>$error->getMessage()];
                }
                $current=$this->states->read();
                if ((int)$current['desired_version']!==$version || ($current['origin_revision']??'')!==($verification['snapshot_revision']??'')) {
                    $verification['verified']=false;
                    $verification['reason']='policy_changed_during_observation';
                    if ($verification['status']==='verified') { $verification['status']='unmet'; }
                }
            }
            $this->update($jobKey,static function(array &$row)use($version,$verification):void{
                if ($verification!==null) {
                    $row['http_verification']=$verification;
                    if ($verification['verified'] && (int)$row['desired_version']===$version) { $row['verified_version']=$version; }
                }
                $row['status']=$row['desired_version']>$version || $row['pending_targets']!==[] ? 'pending':'success';
                $row['last_error']='';
            });
            $latest=$this->states->read()['jobs'][$jobKey];
            if ($latest['status']==='pending') { $this->admit([$latest]); }
            return $latest;
        } catch (\Throwable $error) {
            $this->update($jobKey,static function(array &$row)use($stage,$error):void{$row['status']='error';$row['last_error']=$stage.': '.$error->getMessage();$row['attempt_errors'][]=['at'=>gmdate(DATE_ATOM),'stage'=>$stage,'message'=>$error->getMessage(),'desired_version'=>$row['desired_version']];$row['attempt_errors']=array_slice($row['attempt_errors'],-20);});
            throw $error;
        } finally {
            if ($zoneLocked && $zoneDirectory!==null) { AtomicCompiledFilePublisher::releaseDirectoryLock($zoneDirectory); }
            if ($locked) { AtomicCompiledFilePublisher::releaseDirectoryLock($directory); }
        }
    }

    /** 托管本地边缘与供应商必须共同完成；已完成回执覆盖重试中的剩余子集。 */
    private function invalidateLocalEdge(string $jobKey,array $job,int $version): void
    {
        if ($job['pending_targets']===[]) { return; }
        $covered=[]; $hosts=[];
        foreach ($job['pending_targets'] as $key=>$pending) {
            $covered[$key]=(int)$pending['version'];
            $target=$pending['target'];
            $value=(string)$target['value'];
            $host=$target['kind']==='host' ? $value : parse_url($target['kind']==='url' ? $value : 'https://'.$value,PHP_URL_HOST);
            $host=strtolower(rtrim((string)$host,'.'));
            if ($host==='' || filter_var($host,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME)===false) {
                throw new \RuntimeException('cdn_fpc_local_edge_invalid_target_host');
            }
            $hosts[$host]=true;
        }
        ksort($covered,SORT_STRING); $hosts=array_keys($hosts); sort($hosts,SORT_STRING);
        $previous=$job['local_edge_receipt'] ?? [];
        $reusable=($previous['success']??false)===true && ($previous['completed']??false)===true
            && (int)($previous['requested_version']??-1)===$version;
        foreach ($covered as $key=>$targetVersion) {
            if ((int)($previous['target_versions'][$key]??-1)<$targetVersion) { $reusable=false; break; }
        }
        if ($reusable) { return; }
        // 使用完整目标版本摘要，而非剩余批次/当前时间，崩溃重放仍是同一个操作。
        $operationId=FpcPolicyStateReducer::hash(['job_key'=>$jobKey,'version'=>$version,'targets'=>$covered]);
        $receipt=['success'=>false,'completed'=>false,'applicable'=>true,'operation_id'=>$operationId,
            'backend'=>'managed_nginx','granularity'=>'host','hosts'=>$hosts,'generation_by_host'=>[],
            'reason'=>'','error_code'=>'','message'=>''];
        try {
            $resolution=ObjectManager::getInstance(RuntimeProviderResolver::class)->resolveDetailed(RuntimeEdgeCacheInvalidatorInterface::class);
            if ($resolution->status===RuntimeProviderResolution::NOT_CONFIGURED) {
                $receipt=array_replace($receipt,['success'=>true,'completed'=>true,'applicable'=>false,'reason'=>'not_configured']);
            } elseif ($resolution->status===RuntimeProviderResolution::CONFIGURED_UNAVAILABLE) {
                $receipt['error_code']=$resolution->errorCode ?: 'provider_unavailable';
                $receipt['message']=$resolution->error;
            } elseif ($resolution->provider instanceof RuntimeEdgeCacheInvalidatorInterface) {
                $receipt=array_replace($receipt,$resolution->provider->invalidateHosts($hosts,$operationId));
                if (($receipt['operation_id']??'')!==$operationId || ($receipt['hosts']??[])!==$hosts) {
                    $receipt=array_replace($receipt,['success'=>false,'completed'=>false,'error_code'=>'invalid_provider_receipt','message'=>'Local edge receipt identity mismatch']);
                }
            } else {
                $receipt['error_code']='provider_contract_mismatch';
            }
        } catch (\Throwable $error) {
            $receipt=array_replace($receipt,['success'=>false,'completed'=>false,'error_code'=>'local_edge_exception','message'=>$error->getMessage()]);
        }
        $receipt['requested_version']=$version; $receipt['target_versions']=$covered; $receipt['recorded_at']=gmdate(DATE_ATOM);
        $this->update($jobKey,static function(array &$row)use($receipt):void{$row['local_edge_receipt']=$receipt;});
        if (($receipt['success']??false)!==true || ($receipt['completed']??false)!==true) {
            throw new \RuntimeException('cdn_fpc_local_edge: '.($receipt['error_code'] ?: 'incomplete').' '.$receipt['message']);
        }
    }

    private function desiredRules(array $domains, object $adapter): array
    {
        $groups=[];
        foreach ($domains as $domain) {
            // cron/realtime 是触发条件，完整 desired 永远不按触发来源截断。
            $publicHosts=array_values(array_unique(array_column($this->targets->publicEndpoints($domain),'host')));
            if ($publicHosts===[]) { continue; }
            $rules=$this->rules->planRulesForEdgePush($domain,null,true);
            foreach ($rules as $index=>$rule) {
                $identity=(string)($rule['_weline_identity']??('rule:'.$index));
                unset($rule['_weline_identity']);
                if (method_exists($adapter,'formatRulesForApi')) { $rule=$adapter->formatRulesForApi([$rule])[0]??null; }
                if ($rule===null) { continue; }
                $content=FpcPolicyStateReducer::hash($rule);
                $key=$identity.':'.$content;
                $groups[$key] ??= ['rule'=>$rule,'identity'=>$identity,'hosts'=>[],'bound_hosts'=>[]];
                $groups[$key]['hosts']=array_merge($groups[$key]['hosts'],$publicHosts);
                $groups[$key]['bound_hosts'][]=(string)$domain->getData('domain_name');
            }
        }
        $desired=[];
        foreach ($groups as $group) {
            $hosts=array_values(array_unique($group['hosts']));sort($hosts);
            $rule=$group['rule'];
            $rule['_legacy_rule']=$rule;
            $rule['expression']='(http.host in {'.implode(' ',array_map(static fn($host):string=>json_encode($host,JSON_THROW_ON_ERROR),$hosts)).'}) and ('.$rule['expression'].')';
            $boundHosts=array_values(array_unique($group['bound_hosts']));sort($boundHosts);
            // 沿用旧版绑定 Domain host 的身份公式；公开别名只改表达式，不换现有受管 ref。
            $rule['ref']='weline_cdn_'.substr(hash('sha256',$group['identity']."\n".implode("\n",$boundHosts)),0,40);
            $desired[]=$rule;
        }
        return $desired;
    }
}
