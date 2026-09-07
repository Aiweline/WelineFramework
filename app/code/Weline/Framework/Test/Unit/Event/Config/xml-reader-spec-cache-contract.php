<?php
declare(strict_types=1);

namespace Weline\Framework\Cache\Contract { interface CachePoolInterface { public function setCustom(string $key, mixed $value): void; } }
namespace Weline\Framework\System\File { class Scanner {} }
namespace Weline\Framework\Xml {
    class Parser {
        public array $events = ['Fixture_Owner::first','Fixture_Owner::second'];
        public function parseFile(string $path): array {
            return ['config'=>['_attribute'=>['noNamespaceSchemaLocation'=>'urn:Weline_Framework::Event/etc/xsd/event.xsd'], '_value'=>['event'=>array_map(static fn($event)=>['_attribute'=>['name'=>$event], '_value'=>['observer'=>['_attribute'=>['name'=>'listener', 'instance'=>'stdClass']]]],$this->events)]]];
        }
    }
}
namespace Weline\Framework\Config\Reader {
    class XmlReader {
        protected \Weline\Framework\Xml\Parser $parser;
        public function __construct($scanner, $parser, $path) { $this->parser=$parser; }
    }
}
namespace Weline\Framework\Module\Service {
    class ModuleScanService {
        public function resolveFile(string $base, string $relative): ?string { $path=$base.'/'.$relative; return is_file($path)?$path:null; }
    }
}
namespace Weline\Framework\Registry\Service { class RegistryProgress { public static function module(...$args):void {} public static function count(...$args):void {} } }
namespace Weline\Framework\App {
    class Env {
        public array $modules=[];
        public static function getInstance(): self { return $GLOBALS['spec_cache_env']; }
        public function getActiveModules(): array { return $this->modules; }
        public function getModuleInfo(string $name): array { return $this->modules[$name]??[]; }
    }
}
namespace {
    function w_cache(string $scope): \Weline\Framework\Cache\Contract\CachePoolInterface {
        return new class implements \Weline\Framework\Cache\Contract\CachePoolInterface { public function setCustom(string $key, mixed $value):void {} };
    }
    function __(string $message,...$args):string{return $message;}
    function w_log_warning(string $message,...$args):void{$GLOBALS['spec_warnings'][]=$message;}
    function w_log_error(string $message,...$args):void{throw new RuntimeException('Unexpected event XML error');}
    define('DS', DIRECTORY_SEPARATOR);
    require dirname(__DIR__, 4).'/Event/Config/XmlReader.php';
    $directory=sys_get_temp_dir().'/weline-event-spec-contract-'.bin2hex(random_bytes(6));
    $spec_cache_env=new \Weline\Framework\App\Env(); $spec_includes=[]; $spec_warnings=[];
    $modules=['Fixture_Empty','Fixture_Owner','Fixture_Listener'];
    foreach($modules as $module){mkdir($directory.'/'.$module.'/etc',0777,true);$spec_cache_env->modules[$module]=['name'=>$module,'base_path'=>$directory.'/'.$module,'position'=>'app'];}
    $writeSpec=static function(string $module,array $events)use($directory):void{
        file_put_contents($directory.'/'.$module.'/event.php','<?php $GLOBALS["spec_includes"]["'.$module.'"] = ($GLOBALS["spec_includes"]["'.$module.'"] ?? 0) + 1; return '.var_export(array_fill_keys($events,[]),true).';');
    };
    try {
        $writeSpec('Fixture_Empty',[]);$writeSpec('Fixture_Owner',['Fixture_Owner::first','Fixture_Owner::second']);$writeSpec('Fixture_Listener',[]);
        file_put_contents($directory.'/Fixture_Listener/etc/event.xml','<config/>');
        $parser=new \Weline\Framework\Xml\Parser();
        $reader=new \Weline\Framework\Event\Config\XmlReader(new \Weline\Framework\System\File\Scanner(),$parser,'etc/event.xml',new \Weline\Framework\Module\Service\ModuleScanService());
        $first=$reader->read();$firstCounts=$spec_includes;
        $failures=[];
        foreach($modules as $module){if(($firstCounts[$module]??0)!==1)$failures[]='One read must include '.$module.' exactly once, including empty specs; got '.($firstCounts[$module]??0);}
        if(count(reset($first))!==2 || $spec_warnings!==[])$failures[]='Valid event declarations and both observers must remain effective';
        // A module with empty specs gains an event between reads: an explicit fresh read must discover it.
        $writeSpec('Fixture_Empty',['Fixture_Empty::new']);$parser->events[]='Fixture_Empty::new';
        $second=$reader->read();
        if(count(reset($second))!==3 || $spec_warnings!==[])$failures[]='Next read must reload changed empty specs and discover the new observer';
        foreach($modules as $module){if(($spec_includes[$module]??0)!==($firstCounts[$module]??0)+1)$failures[]='Next read must include '.$module.' once again';}
        // Disabled modules still leave the next file list and event result.
        unset($spec_cache_env->modules['Fixture_Listener']);
        if($reader->read()!==[])$failures[]='Next read must respect active module changes';
        if($failures){throw new RuntimeException(implode("\n",$failures));}
        echo "Event spec read cache contract passed: one include per module, empty specs cached, next read refreshed, inactive module removed\n";
    } finally {
        foreach($modules as $module){foreach(['event.php','etc/event.xml']as$file){$path=$directory.'/'.$module.'/'.$file;if(is_file($path))unlink($path);}rmdir($directory.'/'.$module.'/etc');rmdir($directory.'/'.$module);}rmdir($directory);
    }
}
