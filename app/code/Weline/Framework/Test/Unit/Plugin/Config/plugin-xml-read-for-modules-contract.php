<?php
declare(strict_types=1);

namespace Weline\Framework\Exception { class Core extends \RuntimeException {} }
namespace Weline\Framework\Cache\Contract { interface CachePoolInterface { public function get(string $key): mixed; public function set(string $key, mixed $value): void; } }
namespace Weline\Framework\System\File { class Scanner {} }
namespace Weline\Framework\Xml {
    class Parser {
        /** @var array<string,int> */
        public array $parsed = [];
        public function parseFile(string $path): array {
            $this->parsed[$path] = ($this->parsed[$path] ?? 0) + 1;
            return [
                'config' => [
                    '_attribute' => ['noNamespaceSchemaLocation' => 'urn:Weline_Framework::Plugin/etc/xsd/plugin.xsd'],
                    '_value' => [],
                ],
            ];
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
        public static function getInstance(): self { return $GLOBALS['prf_env']; }
        public function getActiveModules(): array { return array_filter($this->modules, static fn(array $m): bool => (bool)($m['status'] ?? false)); }
        public function getModuleList(): array { return $this->modules; }
        public function getModuleInfo(string $name): array { return $this->modules[$name]??[]; }
        public function getModuleStatus(string $name): bool { return (bool)($this->modules[$name]['status'] ?? false); }
    }
}
namespace {
    function w_cache(string $scope): \Weline\Framework\Cache\Contract\CachePoolInterface {
        return new class implements \Weline\Framework\Cache\Contract\CachePoolInterface {
            public function get(string $key): mixed { return null; }
            public function set(string $key, mixed $value): void {}
        };
    }
    function __(string $message,...$args):string{return $message;}
    define('DS', DIRECTORY_SEPARATOR);
    require dirname(__DIR__, 4).'/Plugin/Config/PluginXmlReader.php';

    $directory=sys_get_temp_dir().'/weline-plugin-rfm-'.bin2hex(random_bytes(6));
    $prf_env=new \Weline\Framework\App\Env();
    $modules=['Fixture_Other','Fixture_Target','Fixture_Idle'];
    foreach ($modules as $module) {
        mkdir($directory.'/'.$module.'/etc', 0777, true);
        file_put_contents($directory.'/'.$module.'/etc/plugin.xml', '<config/>');
        $prf_env->modules[$module]=['name'=>$module,'base_path'=>$directory.'/'.$module,'position'=>'app','status'=>true];
    }
    try {
        $parser=new \Weline\Framework\Xml\Parser();
        $reader=new \Weline\Framework\Plugin\Config\PluginXmlReader(
            new \Weline\Framework\System\File\Scanner(),
            $parser,
            'etc/plugin.xml',
            new \Weline\Framework\Module\Service\ModuleScanService()
        );
        if (!method_exists($reader, 'getFileListForModules')) {
            throw new RuntimeException('PluginXmlReader::getFileListForModules is required for true incremental plugin XML reads');
        }
        $result=$reader->readForModules(['Fixture_Target']);
        $failures=[];
        if (count($result) !== 1) {
            $failures[]='readForModules must return exactly one module file result, got '.count($result);
        }
        $parsedPaths=array_keys($parser->parsed);
        if (count($parsedPaths) !== 1 || !str_contains($parsedPaths[0], '/Fixture_Target/')) {
            $failures[]='readForModules must parse only the target module plugin.xml';
        }
        $countHit=false;
        foreach (\Weline\Framework\Registry\Service\RegistryProgress::$counts as $line) {
            if (str_starts_with($line, 'Plugin XML incremental parse:1:')) {
                $countHit=true;
            }
        }
        if (!$countHit) {
            $failures[]='readForModules must report Plugin XML incremental parse count for target files only';
        }
        if ($failures) {
            throw new RuntimeException(implode("\n", $failures));
        }
        echo "Plugin readForModules contract passed: target-only locate/parse\n";
    } finally {
        foreach ($modules as $module) {
            $path=$directory.'/'.$module.'/etc/plugin.xml';
            if (is_file($path)) unlink($path);
            if (is_dir($directory.'/'.$module.'/etc')) rmdir($directory.'/'.$module.'/etc');
            if (is_dir($directory.'/'.$module)) rmdir($directory.'/'.$module);
        }
        if (is_dir($directory)) rmdir($directory);
    }
}
