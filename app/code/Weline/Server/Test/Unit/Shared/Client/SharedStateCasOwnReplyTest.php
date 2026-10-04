<?php
declare(strict_types=1);
namespace Weline\Server\Test\Unit\Shared\Client;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use ReflectionClass;
use LogicException;
use Throwable;
use Weline\Server\Shared\Contract\ConnectionPoolInterface;
use Weline\Server\Shared\Contract\PooledConnectionInterface;
use Weline\Server\Shared\Connection\PooledConnection;
use Weline\Server\Shared\Client\SharedStateClient;
use Weline\Server\Session\Server\SessionProtocol;
use Weline\Server\Session\Server\SessionStore;
use Weline\Server\Shared\Service\SharedMemoryService;
use Weline\Server\Service\MemoryStateFacade;
use Weline\Server\Service\CacheMemoryService;
use Weline\Server\Cache\Adapter\WlsMemoryAdapter;
use Weline\Framework\Service\Query\Store\AtomicCacheFrontendWorkerStateStore;
use Weline\Framework\Service\Query\FrontendQueryException;
function put(object $o,string $p,mixed $v):void{$r=new ReflectionClass($o);while(!$r->hasProperty($p)){$r=$r->getParentClass();if(!$r)throw new LogicException('fixture property missing');}$r->getProperty($p)->setValue($o,$v);}
final class BudgetRecordingConnection extends PooledConnection {
 public int $ordinaryReads=0;public array $deadlineWindows=[];
 public function read():?array {$this->ordinaryReads++;return parent::read();}
 public function readUntil(float $deadline):?array {$this->deadlineWindows[]=round(($deadline-hrtime(true)/1e9)*1000,3);return parent::readUntil($deadline);}
}
final class ConnectCountingConnection extends PooledConnection {
 public int $connectCalls=0;
 public function connect():bool {$this->connectCalls++;return false;}
}
final class FixtureLeasePool implements ConnectionPoolInterface{
 public ?PooledConnection $connection=null;public bool $busy=false;public int $invalidations=0;public int $releases=0;
 public function __construct(private int $port){}
 public function acquire(float $timeoutSec=0.05):?PooledConnectionInterface{
  if($this->busy)throw new LogicException('fixture lease already busy');$this->busy=true;
  if(!$this->connection || !$this->connection->isConnected()){
   $s=stream_socket_client('tcp://127.0.0.1:'.$this->port,$errno,$error,0.05);if(!$s){$this->busy=false;return null;}
   stream_set_blocking($s,false);$this->connection=new BudgetRecordingConnection('127.0.0.1',$this->port,0.05,0.05,'',false,'isolated_cas',false);
   put($this->connection,'socket',$s);put($this->connection,'authenticated',true);
  }return$this->connection;
 }
 public function release(PooledConnectionInterface $c):void{$this->releases++;$this->busy=false;}
 public function invalidate(PooledConnectionInterface $c):void{$this->invalidations++;$c->close();$this->busy=false;}
 public function healthCheck():bool{return true;}
 public function shutdown():void{$this->connection?->close();$this->busy=false;}
}
function scenario(string $mode):array{
 $listener=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$port=(int)substr(strrchr(stream_socket_get_name($listener,false),':'),1);
 $report=sys_get_temp_dir().'/weline-cas-red-'.getmypid().'-'.$mode.'.json';@unlink($report);$pid=pcntl_fork();
 if($pid===0){
  $store=new SessionStore(['persist_enabled'=>false,'persist_path'=>sys_get_temp_dir().'/weline-unused/']);$stats=['commits'=>0,'cas_requests'=>0,'get_requests'=>0,'ack_attempts'=>0];$start=hrtime(true);$delayed=false;
  while((hrtime(true)-$start)<2_000_000_000){
   $socket=@stream_socket_accept($listener,0.1);if(!$socket)continue;stream_set_timeout($socket,0,200000);
   while(($line=fgets($socket))!==false){
    $m=SessionProtocol::decode(trim($line));if(!$m)break;$sid=$m['sid']??'synthetic';$key=$m['key']??'synthetic';$cmd=$m['cmd']??'';
    if($cmd==='get'){$stats['get_requests']++;$response=SessionProtocol::encodeSuccess($store->get($sid,$key));}
    elseif($cmd==='cas'){
     $stats['cas_requests']++;$match=$mode!=='mismatch' || $stats['cas_requests']!==1;
     $ok=$match && $store->compareAndSet($sid,$key,$m['expected']??null,$m['val']??null,60);
     if($ok)$stats['commits']++;
     if(!$delayed && $ok && $mode!=='mismatch'){$delayed=true;
      if($mode==='eof'){fclose($socket);continue 2;}
      usleep($mode==='no_reply'?160000:80000);
      if($mode==='no_reply'){fclose($socket);continue 2;}
     }
     $response=$ok?SessionProtocol::encodeSuccess():SessionProtocol::encodeError('CAS failed: value mismatch');$stats['ack_attempts']++;
    }elseif($cmd==='set'){$response=SessionProtocol::encodeSuccess($store->set($sid,$key,$m['val']??null,60));}else{break;}
    if(in_array($mode,['get_delay','set_delay'],true) && !$delayed){$delayed=true;usleep(80000);}
    @fwrite($socket,$response);
   }if(is_resource($socket))fclose($socket);
  }file_put_contents($report,json_encode($stats));fclose($listener);exit(0);
 }
 fclose($listener);$pool=new FixtureLeasePool($port);
 $client=(new ReflectionClass(SharedStateClient::class))->newInstanceWithoutConstructor();put($client,'pool',$pool);put($client,'acquireTimeout',0.01);put($client,'throwOnTransportFailure',true);
 $memory=(new ReflectionClass(SharedMemoryService::class))->newInstanceWithoutConstructor();put($memory,'client',$client);
 $facade=(new ReflectionClass(MemoryStateFacade::class))->newInstanceWithoutConstructor();put($facade,'sharedMemoryService',$memory);put($facade,'cacheMemoryService',new CacheMemoryService($memory));put($facade,'stateClient',$client);put($facade,'runtime',[]);put($facade,'consumerCode','isolated-cas');
 $adapter=new WlsMemoryAdapter('isolated-delayed-'.$mode,[],$facade);
 $store=new AtomicCacheFrontendWorkerStateStore($adapter,'cache:wls_memory');$calls=0;$start=hrtime(true);$returned=false;$errorCode=null;
 try{if(in_array($mode,['get_delay','set_delay'],true)){$returned=is_array($client->request($mode==='get_delay'?'get':'set',['sid'=>'synthetic','key'=>'value','val'=>true]));}else $returned=$store->transaction(function(array &$state)use(&$calls){$calls++;if(!empty($state['consumed']))throw new FrontendQueryException('auth_error','Synthetic one-time credential already consumed.',401);$state['consumed']=true;return true;});}
 catch(FrontendQueryException $e){$errorCode=$e->getErrorCode();}
 catch(Throwable $e){$errorCode=get_class($e);}
 $elapsed=round((hrtime(true)-$start)/1e6,3);
 $ordinaryReads=$pool->connection instanceof BudgetRecordingConnection?$pool->connection->ordinaryReads:null;
 $deadlineWindows=$pool->connection instanceof BudgetRecordingConnection?$pool->connection->deadlineWindows:[];
 $replayCode=null;$replayCalls=0;
 if($mode==='delayed' && $returned){try{$store->transaction(function(array &$state)use(&$replayCalls){$replayCalls++;if(!empty($state['consumed']))throw new FrontendQueryException('auth_error','Synthetic independent replay.',401);return true;});}catch(FrontendQueryException $error){$replayCode=$error->getErrorCode();}}
 $pool->shutdown();pcntl_waitpid($pid,$status);$server=json_decode(file_get_contents($report),true);@unlink($report);
 $expected=$mode==='mismatch'?($returned===true&&$calls===2):($mode==='delayed'?($returned===true&&$calls===1):(!$returned&&$calls===1));
 return ['mode'=>$mode,'ordinary_reads'=>$ordinaryReads,'deadline_windows_ms'=>$deadlineWindows,'independent_replay_code'=>$replayCode,'independent_replay_calls'=>$replayCalls,'current_result'=>$returned,'error_code'=>$errorCode,'callback_calls'=>$calls,'elapsed_ms'=>$elapsed,'lease_invalidations'=>$pool->invalidations,'lease_busy_after'=>$pool->busy,'server'=>$server,'desired_contract_pass'=>$expected];
}

final class SharedStateCasOwnReplyTest extends TestCase
{
    public function testDelayedOriginalAckConfirmsExactlyOneConsumption(): void
    {
        $r=scenario('delayed');
        self::assertSame(1,$r['server']['commits']);
        self::assertSame(1,$r['server']['cas_requests']);
        self::assertTrue($r['current_result']);
        self::assertSame(1,$r['callback_calls']);
        self::assertFalse($r['lease_busy_after']);
        self::assertSame('auth_error',$r['independent_replay_code']);
        self::assertSame(1,$r['independent_replay_calls']);
    }
    public function testUnknownOutcomeDoesNotReplayConsumption(): void
    {
        foreach(['no_reply','eof'] as $mode) {
            $r=scenario($mode);
            self::assertSame(1,$r['server']['commits']);
            self::assertFalse($r['current_result']);
            self::assertSame('worker_store_unavailable',$r['error_code']);
            self::assertSame(1,$r['callback_calls']);
            self::assertFalse($r['lease_busy_after']);
        }
    }
    public function testOrdinaryGetAndSetKeepTheirOriginalReadBudget(): void
    {
        foreach(['get_delay','set_delay'] as $mode) {
            $r=scenario($mode);
            self::assertFalse($r['current_result']);
            self::assertSame('RuntimeException',$r['error_code']);
            self::assertSame(1,$r['ordinary_reads']);
            self::assertCount(1,$r['deadline_windows_ms']);
            self::assertLessThanOrEqual(50.0,$r['deadline_windows_ms'][0]);
            self::assertGreaterThan(40.0,$r['deadline_windows_ms'][0]);
            self::assertFalse($r['lease_busy_after']);
        }
    }
    public function testDeadlineSendNeverReconnectsClosedOrExpiredLease(): void
    {
        $c=new ConnectCountingConnection('127.0.0.1',1,0.05,0.05,'',false,'isolated',false);
        self::assertFalse($c->sendUntil('synthetic',hrtime(true)/1e9+0.12));
        self::assertSame(0,$c->connectCalls);
        self::assertFalse($c->sendUntil('synthetic',hrtime(true)/1e9-0.01));
        self::assertSame(0,$c->connectCalls);
        [$writer,$reader]=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,STREAM_IPPROTO_IP);
        stream_set_blocking($writer,false);stream_set_blocking($reader,false);
        put($c,'socket',$writer);
        try {
            self::assertFalse($c->sendUntil('synthetic',hrtime(true)/1e9-0.01));
            self::assertSame(0,$c->connectCalls);
            self::assertSame('',fread($reader,100));
            self::assertFalse($c->isConnected());
        } finally { $c->close();fclose($reader); }
        self::assertFalse($c->send('synthetic'));
        self::assertSame(1,$c->connectCalls);
    }
    public function testExplicitMismatchRemainsConflict(): void
    {
        $r=scenario('mismatch');
        self::assertTrue($r['current_result']);
        self::assertSame(2,$r['server']['cas_requests']);
        self::assertSame(1,$r['server']['commits']);
        self::assertSame(2,$r['callback_calls']);
        self::assertFalse($r['lease_busy_after']);
    }
}
