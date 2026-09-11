<?php
declare(strict_types=1);

namespace Weline\Framework\Cache\Contract { interface CachePoolInterface { public function setCustom(string $key, mixed $value): void; } }
namespace Weline\Framework\System\File { class Scanner {} }
namespace Weline\Framework\Xml {
    class Parser {
        public function parseFile(string $path): array {
            return ['config'=>[
                '_attribute'=>['noNamespaceSchemaLocation'=>'urn:Weline_Framework::Event/etc/xsd/event.xsd'],
                '_value'=>['event'=>[
                    '_attribute'=>['name'=>'Weline_Server::start_after'],
                    '_value'=>['observer'=>['_attribute'=>['name'=>'listener','instance'=>'stdClass']]],
                ]],
            ]];
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
        public function resolveFile(string $base, string $relative): ?string {
            $path=$base.'/'.$relative;
            return is_file($path)?$path:null;
        }
    }
}
namespace Weline\Framework\Registry\Service {
    class RegistryProgress {
        public static function module(...$args):void {}
        public static function count(...$args):void {}
    }
}
namespace Weline\Framework\App {
    class Env {
        public array $modules=[];
        public static function getInstance(): self { return $GLOBALS['own_env']; }
        public function getActiveModules(): array { return $this->modules; }
        public function getModuleList(): array { return $this->modules; }
        public function getModuleInfo(string $name): array { return $this->modules[$name]??[]; }
    }
}
namespace {
    function w_cache(string $scope): \Weline\Framework\Cache\Contract\CachePoolInterface {
        return new class implements \Weline\Framework\Cache\Contract\CachePoolInterface {
            public function setCustom(string $key, mixed $value):void {}
        };
    }
    function __(string $message,...$args):string{return $message;}
    function w_log_warning(string $message,...$args):void{ $GLOBALS['own_warnings'][]=$message; }
    function w_log_error(string $message,...$args):void{ throw new RuntimeException($message); }
    define('DS', DIRECTORY_SEPARATOR);
    // Intentionally NO BP — forces owner-only path without generated registry.
    require dirname(__DIR__, 4).'/Event/Config/XmlReader.php';

    $directory=sys_get_temp_dir().'/weline-event-owner-'.bin2hex(random_bytes(6));
    $own_env=new \Weline\Framework\App\Env();
    $own_warnings=[];
    $includeCounts=[];
    $modules=['Weline_Framework','Weline_Server','Weline_Theme','Weline_Heavy'];
    foreach ($modules as $module) {
        mkdir($directory.'/'.$module.'/etc', 0777, true);
        $own_env->modules[$module]=['name'=>$module,'base_path'=>$directory.'/'.$module,'position'=>'app','status'=>true];
        // Heavy / Framework would be catastrophic if executed for every missing event.
        file_put_contents(
            $directory.'/'.$module.'/event.php',
            '<?php $GLOBALS["includeCounts"]["'.$module.'"]=($GLOBALS["includeCounts"]["'.$module.'"]??0)+1; usleep(' . ($module==='Weline_Heavy'?'200000':'1000') . '); return [];'
        );
    }
    // Declared owner key without requiring include (source scan).
    file_put_contents(
        $directory.'/Weline_Server/event.php',
        '<?php $GLOBALS["includeCounts"]["Weline_Server"]=($GLOBALS["includeCounts"]["Weline_Server"]??0)+1; usleep(1000); return [\'Weline_Server::other\' => []];'
    );
    file_put_contents($directory.'/Weline_Theme/etc/event.xml', '<config/>');

    try {
        $reader=new \Weline\Framework\Event\Config\XmlReader(
            new \Weline\Framework\System\File\Scanner(),
            new \Weline\Framework\Xml\Parser(),
            'etc/event.xml',
            new \Weline\Framework\Module\Service\ModuleScanService()
        );
        $t0=microtime(true);
        $reader->readForModules(['Weline_Theme']);
        $elapsed=microtime(true)-$t0;
        $failures=[];
        if (($includeCounts['Weline_Heavy'] ?? 0) !== 0) {
            $failures[]='missing-event validation must not include unrelated heavy modules';
        }
        if (($includeCounts['Weline_Framework'] ?? 0) !== 0) {
            $failures[]='undeclared Weline_Server::* must not include Weline_Framework event.php';
        }
        if (($includeCounts['Weline_Server'] ?? 0) !== 0) {
            $failures[]='source scan miss must not include owner event.php; counts='.json_encode($includeCounts);
        }
        if ($elapsed > 0.5) {
            $failures[]='owner-only validation must stay fast, elapsed='.$elapsed;
        }
        if ($failures) {
            throw new RuntimeException(implode("\n",$failures)."\ncounts=".json_encode($includeCounts));
        }
        echo "Event owner-only defining lookup contract passed\n";
    } finally {
        foreach ($modules as $module) {
            foreach (['event.php','etc/event.xml'] as $file) {
                $path=$directory.'/'.$module.'/'.$file;
                if (is_file($path)) unlink($path);
            }
            if (is_dir($directory.'/'.$module.'/etc')) rmdir($directory.'/'.$module.'/etc');
            if (is_dir($directory.'/'.$module)) rmdir($directory.'/'.$module);
        }
        if (is_dir($directory)) rmdir($directory);
    }
}
