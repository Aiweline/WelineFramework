<?php
declare(strict_types=1);
namespace Weline\Framework\Setup\Data { class Setup {} }
namespace Weline\Framework\Manager {
    final class ObjectManager {
        public static array $calls=[];
        public static function getInstance(string $class): object {
            if(str_ends_with($class,'LocalDescription'))return new class {public function setup($runner,$context):void{ObjectManager::$calls[]='local_model_setup';}};
            if(str_ends_with($class,'CustomerGroupLocalSeedService'))return new class {public function seedSourceLocalsAndEnqueue():void{ObjectManager::$calls[]='customer_group_seed';}};
            if(str_ends_with($class,'WelineTheme')){self::$calls[]='wholesale_surface_seed';return new class {public function reset():self{return $this;}public function select():self{return $this;}public function fetchArray():array{return [];}};}
            return new \stdClass();
        }
        public static function make(string $class): object {return new class {public function putModel($model):void{}};}
    }
}
namespace {
    $root=dirname(__DIR__,6);
    require $root.'/app/code/Weline/Framework/Setup/Data/Context.php';
    require $root.'/app/code/Weline/Framework/Setup/UpgradeInterface.php';
    require $root.'/app/code/Weline/B2B/Setup/Upgrade.php';
    $cases=['0.0.0'=>['local_model_setup','customer_group_seed','wholesale_surface_seed'],'2.6.9'=>['local_model_setup','customer_group_seed','wholesale_surface_seed'],'2.6.46'=>['local_model_setup','customer_group_seed','wholesale_surface_seed'],'2.6.47'=>['wholesale_surface_seed'],'2.6.61'=>['wholesale_surface_seed'],'2.6.62'=>[],'2.6.90'=>[]];
    foreach($cases as $from=>$expected){
        $context=(new \ReflectionClass(\Weline\Framework\Setup\Data\Context::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($context,'from_setup_version'))->setValue($context,$from);
        \Weline\Framework\Manager\ObjectManager::$calls=[];
        (new \Weline\B2B\Setup\Upgrade())->setup(new \Weline\Framework\Setup\Data\Setup(),$context);
        $actual=\Weline\Framework\Manager\ObjectManager::$calls;
        if($actual!==$expected){fwrite(STDERR,json_encode(['from'=>$from,'expected'=>$expected,'actual'=>$actual]).PHP_EOL);exit(1);}
    }
    echo 'PASS: '.count($cases).' historical setup version cases; 2.6.90 executes zero model/seed operations.'.PHP_EOL;
}
