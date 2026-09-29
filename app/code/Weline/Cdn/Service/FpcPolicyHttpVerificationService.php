<?php
declare(strict_types=1);
namespace Weline\Cdn\Service;

use Weline\Framework\Controller\Extra\FpcPolicySnapshot;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Websites\Api\Catalog\{WebsiteCatalogInterface,StoreCatalogInterface,SalesChannelCatalogInterface};

/** 有界的匿名公开页观察；HTTP 证据与供应商受理回执分别记录。 */
class FpcPolicyHttpVerificationService
{
    public function __construct(private readonly FpcPolicyTargetService $targets, private readonly WebsiteCatalogInterface $websites, private readonly StoreCatalogInterface $stores, private readonly SalesChannelCatalogInterface $channels) {}

    public function verify(array $job,array $domains,array $snapshot):array
    {
        $receipt=['coverage'=>'sample','requested_version'=>(int)$job['desired_version'],'snapshot_revision'=>(string)($snapshot['revision']??''),'observed_at'=>gmdate(DATE_ATOM),'status'=>'no_public_sample','verified'=>false,'samples'=>[]];
        foreach ($this->samples($job,$domains,$snapshot) as $sample) {
            $sample['observations']=[]; $sample['verified']=false; $sample['reason']='cache_evidence_not_met';
            for ($attempt=0;$attempt<3;$attempt++) {
                $observation=$this->request($sample['url']);
                $sample['observations'][]=$observation;
                if ($observation['error']!==null) { $sample['reason']='request_failed'; break; }
                if ($observation['status']>=300 && $observation['status']<400) { $sample['reason']='redirect_not_followed'; break; }
                if ($observation['has_set_cookie'] || preg_match('/\bprivate\b/i',$observation['headers']['cache-control']??'')) { $sample['reason']='private_response'; break; }
                if (($observation['headers']['cf-mitigated']??'')==='challenge') { $sample['reason']='cloudflare_challenge'; break; }
                if (count($sample['observations'])>=2 && $this->satisfied($sample)) {
                    $sample['verified']=true; $sample['reason']='sample_policy_observed'; break;
                }
            }
            $receipt['samples'][]=$sample;
        }
        if ($receipt['samples']!==[]) {
            $receipt['verified']=count(array_filter($receipt['samples'],static fn($sample)=>!$sample['verified']))===0;
            $receipt['status']=$receipt['verified']?'verified':(count(array_filter($receipt['samples'],static fn($sample)=>$sample['reason']==='request_failed'))>0?'request_failed':'unmet');
        }
        return $receipt;
    }

    private function satisfied(array $sample):bool
    {
        $observations=array_slice($sample['observations'],-2);
        foreach ($observations as $row) {
            $headers=$row['headers'];
            if ($row['status']!==200 || $row['has_set_cookie'] || $row['error']!==null || empty($row['body_sha256']) || !str_contains(strtolower($headers['content-type']??''),'text/html') || preg_match('/\bprivate\b/i',$headers['cache-control']??'') || isset($headers['www-authenticate'])) { return false; }
            if (($headers['cf-mitigated']??'')==='challenge') { return false; }
            $fpc=strtoupper($headers['x-weline-fpc']??''); $cf=strtoupper($headers['cf-cache-status']??'');
            $directives=array_intersect_key($headers,array_flip(['cdn-cache-control','cloudflare-cdn-cache-control']));
            if ($sample['enabled']) {
                if ($fpc!=='HIT' || $directives===[] || preg_match('/\bno-store\b/i',$headers['cache-control']??'')) { return false; }
                foreach ($directives as $directive) {
                    if (!preg_match('/\bpublic\b/i',$directive) || preg_match('/\b(no-store|private)\b/i',$directive) || !preg_match('/(?:^|[,\s])max-age=(\d+)/i',$directive,$match) || (int)$match[1]<=0 || (int)$match[1]>(int)$sample['ttl']) { return false; }
                }
            } else {
                if (in_array($fpc,['HIT','STALE'],true) || !in_array($cf,['BYPASS','DYNAMIC'],true) || $directives===[]) { return false; }
                foreach ($directives as $directive) { if (!preg_match('/\bno-store\b/i',$directive)) { return false; } }
            }
        }
        // HIT 才是实际边缘命中；MISS 及规则 API 受理都不能代替。
        return !$sample['enabled'] || strtoupper(end($observations)['headers']['cf-cache-status']??'')==='HIT';
    }

    protected function samples(array $job,array $domains,array $snapshot):array
    {
        $context=$job['verification_context']??[];
        $declarations=array_filter($snapshot['declarations']??[],static function(array $row)use($context):bool{
            $attrs=$row['attrs']??[];
            return ($attrs['enabled']??true) && (int)($attrs['ttl']??600)>0
                && !str_contains((string)($row['class']??''),'\\Backend\\')
                && (empty($context['declaration_ids']) || in_array($row['declaration_id'],$context['declaration_ids'],true));
        });
        $sites=[];foreach($this->websites->all() as $website){$sites[$website->id]=$website;}
        $affected=[];foreach($job['pending_targets']??[] as $pending){if(($pending['target']['kind']??'')==='url'){$affected[$pending['target']['value']]=true;}}
        $affectedDomains=[];foreach($job['pending_targets']??[] as $pending){$affectedDomains[(int)$pending['target']['domain_id']]=true;}
        $out=[]; $sampledHosts=[];
        foreach($domains as $domain){
            if(!(bool)$domain->getData('enabled')){continue;}
            if(($job['trigger']??'')==='domain_change' && !isset($affectedDomains[(int)$domain->getId()]) && $context===[]){continue;}
            $site=(int)$domain->getData('site_id');$website=$sites[$site]??null;if($website===null){continue;}
            $publicHosts=array_values(array_unique(array_column($this->targets->publicEndpoints($domain),'host')));
            $rows=$this->targets->targets($declarations,null,[$domain]);
            foreach($rows as $target){
                if($target['kind']!=='url' || ($affected!==[] && !isset($affected[$target['value']]))){continue;}
                $url=$target['value'];$parts=parse_url($url);
                if(!is_array($parts)||isset($parts['pass'])||isset($parts['user'])||isset($parts['query'])||isset($parts['fragment'])||!in_array(strtolower($parts['scheme']??''),['http','https'],true)||!in_array(strtolower($parts['host']??''),$publicHosts,true)){continue;}
                $host=strtolower($parts['host']);if(isset($sampledHosts[$host])){continue;}
                $store=$this->stores->defaultStore($site);$base=$website->url;$longest=-1;
                foreach($this->stores->byWebsite($site) as $candidate){
                    if(!$candidate->enabled){continue;}
                    $candidateScope=ScopeIdentity::store($site,$website->code,$candidate->code,$candidate->storeMode);
                    foreach($this->targets->publicEndpoints($domain,$candidateScope) as $endpoint){
                        $candidateBase=$endpoint['base_url'];
                        if(($url===$candidateBase || str_starts_with($url,$candidateBase.'/')) && strlen($candidateBase)>$longest){$store=$candidate;$base=$candidateBase;$longest=strlen($candidateBase);}
                    }
                }
                $scope=ScopeIdentity::website($site,$website->code);
                if($store!==null){$channel=$this->channels->defaultChannelForStore($store);$scope=$channel!==null?ScopeIdentity::channel($site,$website->code,$store->code,$channel->code,$store->storeMode):ScopeIdentity::store($site,$website->code,$store->code,$store->storeMode);}
                $mode=$scope->storeMode??'normal';
                if(isset($context['store_mode'])&&$context['store_mode']!==$mode){continue;}
                $chain=$snapshot['scope_chains'][$mode][$scope->canonicalKey()]??[$scope->canonicalKey(),ScopeIdentity::global()->canonicalKey()];
                if(isset($context['scope_key'])&&!in_array($context['scope_key'],$chain,true)){continue;}
                $path=FpcPolicySnapshot::normalizePath($url,(string)$base);
                if(preg_match('#^/(?:customer|login|register|checkout|cart|api|admin|backend|account)(?:/|$)#i',rawurldecode($path))){continue;}
                $policy=FpcPolicySnapshot::resolve($snapshot,$path,$scope);
                if($policy===null||!isset($declarations[$policy['declaration_id']])){continue;}
                $out[]=['url'=>$url,'domain_id'=>(int)$domain->getId(),'declaration_id'=>$policy['declaration_id'],'scope'=>$scope->toArray(),'scope_key'=>$scope->canonicalKey(),'store_mode'=>$mode,'policy_fingerprint'=>$policy['policy_fingerprint'],'enabled'=>$policy['enabled'],'ttl'=>$policy['ttl']];
                $sampledHosts[$host]=true;
                // 正式 public base 排在绑定 host fallback 前；两个不同 host 样本上限不变。
                if(count($out)>=2){break;}
            }
            if(count($out)>=2){break;}
        }
        return $out;
    }

    /** 只连接公开地址；钉住解析 IP，禁止跳转、代理、Cookie、认证及任意协议。 */
    protected function request(string $url):array
    {
        $start=microtime(true);$row=['observed_at'=>gmdate(DATE_ATOM),'status'=>0,'headers'=>[],'has_set_cookie'=>false,'elapsed_ms'=>0,'body_sha256'=>null,'error'=>null];
        $parts=parse_url($url);$scheme=strtolower($parts['scheme']??'');$host=$parts['host']??'';$port=(int)($parts['port']??($scheme==='https'?443:80));
        $path=rawurldecode((string)($parts['path']??'/'));
        if(preg_match('#(?:^|/)(?:\.\.?)(?:/|$)|[\\\\\r\n]#',$path)){$row['error']='unsupported_public_path';return $row;}
        if(!in_array($scheme,['https','http'],true)||$host===''||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])||!in_array($port,[80,443],true)){$row['error']='unsupported_public_url';return $row;}
        $addresses=[];
        if(filter_var($host,FILTER_VALIDATE_IP)){$addresses[]=$host;}else{foreach(@dns_get_record($host,DNS_A|DNS_AAAA)?:[] as $dns){$addresses[]=$dns['ip']??$dns['ipv6']??'';}}
        $addresses=array_values(array_filter($addresses,static fn($ip)=>filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_GLOBAL_RANGE)!==false));
        if($addresses===[]){$row['error']='public_dns_address_unavailable';return $row;}
        $ip=$addresses[0];if(str_contains($ip,':')){$ip='['.$ip.']';}
        $hash=hash_init('sha256');$bytes=0;$tooLarge=false;
        $allowed=array_flip(['content-type','cache-control','cdn-cache-control','cloudflare-cdn-cache-control','cf-cache-status','cf-ray','cf-mitigated','x-weline-fpc','age','etag','last-modified','date','www-authenticate']);
        $curl=curl_init($url);
        curl_setopt_array($curl,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_PROXY=>'',CURLOPT_RESOLVE=>[$host.':'.$port.':'.$ip],CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Accept: text/html','User-Agent: Weline-Fpc-Policy-Observation/1.0'],CURLOPT_HEADERFUNCTION=>static function($ch,string $line)use(&$row,$allowed):int{
            if(str_starts_with($line,'HTTP/')){$row['headers']=[];$row['has_set_cookie']=false;}
            $pair=explode(':',$line,2);if(count($pair)===2){$name=strtolower(trim($pair[0]));if($name==='set-cookie'){$row['has_set_cookie']=true;}elseif(isset($allowed[$name])){$row['headers'][$name]=substr(trim($pair[1]),0,1024);}}
            return strlen($line);
        },CURLOPT_WRITEFUNCTION=>static function($ch,string $body)use($hash,&$bytes,&$tooLarge):int{$bytes+=strlen($body);if($bytes>2*1024*1024){$tooLarge=true;return 0;}hash_update($hash,$body);return strlen($body);}]);
        $ok=curl_exec($curl);$row['status']=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);$row['elapsed_ms']=(int)round((microtime(true)-$start)*1000);
        $row['error']=$ok===false?($tooLarge?'response_body_limit_exceeded':curl_error($curl)):null;
        $digest=hash_final($hash);$row['body_sha256']=$ok!==false?$digest:null;curl_close($curl);
        return $row;
    }
}
