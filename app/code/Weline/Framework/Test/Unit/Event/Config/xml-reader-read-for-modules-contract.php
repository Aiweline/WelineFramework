<?php
declare(strict_types=1);

namespace Weline\Framework\Cache\Contract { interface CachePoolInterface { public function setCustom(string $key, mixed $value): void; } }
namespace Weline\Framework\System\File { class Scanner {} }
namespace Weline\Framework\Xml {
    class Parser {
        /** @var array<string,int> */
        public array $parsed = [];
        public function parseFile(string $path): array {
            $this->parsed[$path] = ($this->parsed[$path] ?? 0) + 1;
            return ['config'=>['_attribute'=>['noNamespaceSchemaLocation'=>'urn:Weline_Framework::Event/etc/xsd/event.xsd'], '_value'=>['event'=>['_attribute'=>['name'=>'Fixture_Target::ping'], '_value'=>['observer'=>['_attribute'=>['name'=>'listener', 'instance'=>'stdClass']]]]]]];
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
namespace Weline\Framework\Registry\Service {
    class RegistryProgress {
        /** @var list<string> */
        public static array $counts = [];
        public static function module(...$args):void {}
        public static function count(string $scope, int $count, string $label):void { self::$counts[] = $scope.':'.$count.':'.$label; }
    }
}
namespace Weline\Framework\App {
    class Env {
        public array $modules=[];
        public static function getInstance(): self { return $GLOBALS['rfm_env']; }
        public function getActiveModules(): array { return $this->modules; }
        public function getModuleList(): array { return $this->modules; }
        public function getModuleInfo(string $name): array { return $this->modules[$name]??[]; }
    }
}
namespace {
    function w_cache(string $scope): \Weline\Framework\Cache\Contract\CachePoolInterface {
        return new class implements \Weline\Framework\Cache\Contract\CachePoolInterface {
            public function setCustom(string $key, mixed $value):void { $GLOBALS['rfm_cache'][$key]=$value; }
        };
    }
    function __(string $message,...$args):string{return $message;}
    function w_log_warning(string $message,...$args):void{}
    function w_log_error(string $message,...$args):void{throw new RuntimeException($message);}
    define('DS', DIRECTORY_SEPARATOR);
    require dirname(__DIR__, 4).'/Event/Config/XmlReader.php';

    $directory=sys_get_temp_dir().'/weline-event-rfm-'.bin2hex(random_bytes(6));
    $rfm_env=new \Weline\Framework\App\Env();
    $rfm_cache=[];
    $modules=['Fixture_Other','Fixture_Target','Fixture_Idle'];
    foreach ($modules as $module) {
        mkdir($directory.'/'.$module.'/etc', 0777, true);
        file_put_contents($directory.'/'.$module.'/etc/event.xml', '<config/>');
        file_put_contents($directory.'/'.$module.'/event.php', "<?php return ['Fixture_Target::ping'=>[]];");
        $rfm_env->modules[$module]=['name'=>$module,'base_path'=>$directory.'/'.$module,'position'=>'app','status'=>true];
    }
    try {
        $parser=new \Weline\Framework\Xml\Parser();
        $reader=new \Weline\Framework\Event\Config\XmlReader(
            new \Weline\Framework\System\File\Scanner(),
            $parser,
            'etc/event.xml',
            new \Weline\Framework\Module\Service\ModuleScanService()
        );
        if (!method_exists($reader, 'readForModules')) {
            throw new RuntimeException('XmlReader::readForModules is required for true incremental event XML reads');
        }
        $result=$reader->readForModules(['Fixture_Target']);
        $failures=[];
        if (count($result) !== 1) {
            $failures[]='readForModules must return exactly one module file result, got '.count($result);
        }
        $parsedPaths=array_keys($parser->parsed);
        if (count($parsedPaths) !== 1 || !str_contains($parsedPaths[0], '/Fixture_Target/')) {
            $failures[]='readForModules must parse only the target module event.xml';
        }
        if (isset($rfm_cache['event'])) {
            $failures[]='readForModules must not overwrite the full event observer cache with a partial map';
        }
        $countHit=false;
        foreach (\Weline\Framework\Registry\Service\RegistryProgress::$counts as $line) {
            if (str_starts_with($line, 'Event XML incremental parse:1:')) {
                $countHit=true;
            }
        }
        if (!$countHit) {
            $failures[]='readForModules must report Event XML incremental parse count for target files only';
        }
        if ($failures) {
            throw new RuntimeException(implode("\n", $failures));
        }
        echo "Event readForModules contract passed: target-only parse, no full cache overwrite\n";
    } finally {
        foreach ($modules as $module) {
            foreach (['event.php','etc/event.xml'] as $file) {
                $path=$directory.'/'.$module.'/'.$file;
                if (is_file($path)) {
                    unlink($path);
                }
            }
            if (is_dir($directory.'/'.$module.'/etc')) {
                rmdir($directory.'/'.$module.'/etc');
            }
            if (is_dir($directory.'/'.$module)) {
                rmdir($directory.'/'.$module);
            }
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
}
