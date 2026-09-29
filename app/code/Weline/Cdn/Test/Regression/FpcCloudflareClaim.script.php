<?php
declare(strict_types=1);
namespace Weline\Cdn\Adapter {
    // Real Adapter; only transport isolated. Default GET ref=id is not a mutable custom ref.
    function curl_init(string $url):object{return (object)['url'=>$url,'opts'=>[],'code'=>200];}
    function curl_setopt(object $h,int $key,mixed $value):bool{$h->opts[$key]=$value;return true;}
    function curl_setopt_array(object $h,array $opts):bool{$h->opts=$opts+$h->opts;return true;}
    function curl_getinfo(object $h,int $key):int{return $h->code;}
    function curl_error(object $h):string{return '';}
    function curl_close(object $h):void{}
    function curl_exec(object $h):string {
        $method=$h->opts[CURLOPT_CUSTOMREQUEST];$body=json_decode($h->opts[CURLOPT_POSTFIELDS]??'{}',true);$GLOBALS['calls'][]=['method'=>$method,'body'=>$body];
        $fail=static function(string $why)use($h):string{$h->code=400;return json_encode(['success'=>false,'errors'=>[['message'=>$why]]]);};
        if($method==='PATCH'){return $fail('could not find a rule with a matching reference id for public id '.basename($h->url).', expected the reference to be empty');}
        if($method==='PUT'){
            if($GLOBALS['reject_put']){return $fail('fixture failed before atomic update');}
            foreach($body['rules'] as &$next){
                if(isset($next['_legacy_public_id'])){return $fail('internal metadata leaked');}
                foreach($GLOBALS['remote'] as $old){if(($next['id']??null)!==$old['id']){continue;}
                    if($old['ref']===$old['id']&&array_key_exists('ref',$next)){return $fail('expected the reference to be empty');}
                    if($old['ref']!==$old['id']&&($next['ref']??null)!==$old['ref']){return $fail('custom reference identity changed');}
                    $next['ref']=$old['ref'];
                }
                if(!isset($next['id'])){$next['id']='created-'.hash('sha256',$next['ref']);}
            }unset($next);$GLOBALS['remote']=$body['rules'];
        }elseif($method!=='GET'){throw new \RuntimeException('unexpected method');}
        return json_encode(['success'=>true,'result'=>['id'=>'fixture-ruleset','rules'=>$GLOBALS['remote']]]);
    }
}
namespace {
require dirname(__DIR__,6).'/app/bootstrap.php';
use Weline\Cdn\Adapter\Cloudflare;
use Weline\Cdn\Service\{FpcPolicyRulePlanner,FpcPolicyStateReducer};
$baseline=json_decode(file_get_contents(BP.'/dev/team/cdn-fpc-policy-sync/cloud-rules-baseline.json'),true,512,JSON_THROW_ON_ERROR)['rules'];
$desired=[];foreach($baseline as $i=>$rule){$r=array_diff_key($rule,array_flip(['id','ref','version','last_updated']));$r['ref']='weline_cdn_fixture_'.$i;$r['_legacy_rule']=$r;unset($r['_legacy_rule']['ref']);$r['expression']='(http.host in {"example.invalid" "www.example.invalid"}) and ('.$r['expression'].')';$desired[]=$r;}
$manual=['id'=>'manual-public-id','ref'=>'manual-ref','action'=>'set_cache_settings','action_parameters'=>['cache'=>false],'expression'=>'http.request.uri.path eq "/manual"','description'=>'human rule','enabled'=>true];
$input=$baseline;array_splice($input,2,0,[$manual]);$adapter=new Cloudflare();$checks=0;$failed=[];$GLOBALS['remote']=$input;$GLOBALS['calls']=[];$GLOBALS['reject_put']=false;
$check=static function(bool $ok,string $label)use(&$checks,&$failed){++$checks;if(!$ok){$failed[]=$label;}};
$plan=static fn($existing,$wanted,$bindings=[])=>method_exists(FpcPolicyRulePlanner::class,'plan')?FpcPolicyRulePlanner::plan($existing,$wanted,$bindings):['rules'=>FpcPolicyRulePlanner::merge($existing,$wanted),'bindings'=>[]];
$p=$plan($GLOBALS['remote'],$desired);$bindings=$p['bindings'];$result=$adapter->putRules('zone',$p['rules'],['api_token'=>'fixture']);
$check($result['success']===true,'existing IDs must be accepted without attempting to mutate their ref');
$check(count($bindings)===6,'six complete unique matches produce persisted ownership IDs');
$check(!in_array('PATCH',array_column($GLOBALS['calls'],'method'),true),'no reference rename PATCH');
$check(array_column($GLOBALS['remote'],'id')===array_column($input,'id')&&array_column($GLOBALS['remote'],'ref')===array_column($input,'ref'),'legacy IDs and displayed refs remain unchanged');
$check(FpcPolicyStateReducer::canonical($GLOBALS['remote'][2])===FpcPolicyStateReducer::canonical($manual),'manual rule preserved');
$again=$plan($GLOBALS['remote'],$desired,$bindings);$check(FpcPolicyRulePlanner::equivalent($GLOBALS['remote'],$again['rules']),'repeated sync no-op using persisted public IDs');
// GET reorder + a new human rule cannot change identity or relative human placement.
$reordered=array_reverse($GLOBALS['remote']);$inserted=$manual;$inserted['id']='human2';$inserted['ref']='human2-ref';array_splice($reordered,3,0,[$inserted]);$changed=$desired;$changed[0]['action_parameters']['cache']=false;
$r=$plan($reordered,$changed,$bindings);$check(array_column($r['rules'],'id')===array_column($reordered,'id'),'GET reorder/manual insertion follows public IDs and preserves current order');
$check(FpcPolicyStateReducer::canonical($r['rules'][3])===FpcPolicyStateReducer::canonical($inserted),'inserted human untouched');
$wantedId=$baseline[0]['id'];$updated=current(array_filter($r['rules'],static fn($x)=>($x['id']??null)===$wantedId));$check(($updated['action_parameters']['cache']??null)===false,'changed logical rule locates its original ID after reorder');
// Lost response / failed PUT: bindings were committed before submission, same plan retries.
$GLOBALS['remote']=$input;$GLOBALS['reject_put']=true;$bad=$adapter->putRules('zone',$p['rules'],['api_token'=>'fixture']);$GLOBALS['reject_put']=false;$retry=$plan($GLOBALS['remote'],$desired,$bindings);$accepted=$adapter->putRules('zone',$retry['rules'],['api_token'=>'fixture']);
$check(!$bad['success']&&$accepted['success']&&count($GLOBALS['remote'])===7,'failed PUT retry does not create duplicates');
// Deleted ID recreated by a human must not be silently reclaimed by content.
$deleted=array_values(array_filter($GLOBALS['remote'],static fn($x)=>$x['id']!==$wantedId));$replacement=$baseline[0];$replacement['id']='human-replacement';$replacement['ref']='human-replacement';$deleted[]=$replacement;
$d=$plan($deleted,$desired,$bindings);$new=current(array_filter($d['rules'],static fn($x)=>($x['ref']??null)===$desired[0]['ref']));
$check(!isset($new['id'])&&count($d['rules'])===count($deleted)+1,'missing owned ID creates new stable-ref rule without claiming replacement');
$check(in_array($replacement,$d['rules'],true),'human recreation preserved');
$ownedRemoval=$plan($GLOBALS['remote'],array_slice($desired,1),$bindings);$check(!in_array($wantedId,array_column($ownedRemoval['rules'],'id'),true),'removed desired rule deletes only mapped own ID');
$duplicate=$baseline[0];$duplicate['id']='duplicate';$duplicate['ref']='duplicate';$ambiguous=$plan([...$baseline,$duplicate],$desired);$check(!isset($ambiguous['bindings'][$desired[0]['ref']]),'ambiguous full match is not claimed');
$custom=$baseline;$custom[0]['ref']='existing-custom-ref';$cp=$plan($custom,$desired);$payload=$adapter->formatRulesForApi($cp['rules']);
$check($payload[0]['ref']==='existing-custom-ref'&&$payload[0]['id']===$baseline[0]['id'],'explicit nondefault custom ref preserved');
$check(!isset($payload[1]['ref'])&&$payload[1]['id']===$baseline[1]['id'],'legacy default GET ref=id omitted from update payload');
$readded=$plan($deleted,$changed,$bindings);$nextNew=current(array_filter($readded['rules'],static fn($x)=>($x['ref']??null)===$desired[0]['ref']));
$check(!isset($nextNew['id'])&&$readded['bindings']===$bindings,'successive desired changes retain deletion history and prevent re-claim');
if($failed){fwrite(STDERR,'FAIL '.implode("\nFAIL ",$failed)."\n");exit(1);}echo "PASS {$checks} actual Planner/Adapter legacy-ID ownership checks; no network.\n";
}
