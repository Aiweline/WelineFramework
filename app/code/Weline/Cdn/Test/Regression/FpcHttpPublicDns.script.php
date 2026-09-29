<?php
declare(strict_types=1);
// 运行实际 request 的 DNS 分类和 cURL 构造，拦截 DNS/传输避免任何网络请求。
namespace Weline\Cdn\Service {
    function dns_get_record(string $host,int $type):array{return $GLOBALS['fpc_dns_fixture'];}
    function curl_init(string $url):object{$GLOBALS['fpc_curl_calls']++;return new \stdClass();}
    function curl_setopt_array(object $handle,array $options):bool{$GLOBALS['fpc_curl_options']=$options;return true;}
    function curl_exec(object $handle):bool{return true;}
    function curl_getinfo(object $handle,int $option):int{return 200;}
    function curl_error(object $handle):string{return '';}
    function curl_close(object $handle):void{}
}
namespace {
    require dirname(__DIR__,6).'/app/code/Weline/Cdn/Service/FpcPolicyHttpVerificationService.php';
    try {
        $class=new ReflectionClass(\Weline\Cdn\Service\FpcPolicyHttpVerificationService::class);
        $service=$class->newInstanceWithoutConstructor();$request=$class->getMethod('request');
        foreach(['100.64.0.1','2001:db8::1','127.0.0.1','10.0.0.1','169.254.169.254','::1','fd00::1','8.8.8.8','2606:4700:4700::1111'] as $ip){
            $GLOBALS['fpc_dns_fixture']=[str_contains($ip,':')?['ipv6'=>$ip]:['ip'=>$ip]];
            $GLOBALS['fpc_curl_calls']=0;$GLOBALS['fpc_curl_options']=[];
            $response=$request->invoke($service,'https://bound-public.example.invalid/blog');
            $public=in_array($ip,['8.8.8.8','2606:4700:4700::1111'],true);
            if(!$public && ($GLOBALS['fpc_curl_calls']!==0 || $response['error']!=='public_dns_address_unavailable')){throw new RuntimeException('Non-global DNS reached cURL: '.$ip);}
            if($public){
                $resolved='bound-public.example.invalid:443:'.(str_contains($ip,':')?'['.$ip.']':$ip);
                if($GLOBALS['fpc_curl_calls']!==1 || $response['error']!==null || ($GLOBALS['fpc_curl_options'][CURLOPT_RESOLVE]??[])!==[$resolved]){throw new RuntimeException('Global DNS was rejected or not pinned: '.$ip);}
            }
            echo 'PASS '.($public?'global DNS pinned: ':'non-global DNS blocked: ').$ip."\n";
        }
    }catch(Throwable $error){fwrite(STDERR,'FAIL '.$error->getMessage()."\n");exit(1);}
}
