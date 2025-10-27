<?php

$baseDir = __DIR__ . '/app/code';
$fixed = [];

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baseDir, RecursiveDirectoryIterator::SKIP_DOTS));

foreach ($it as $file) {
    if ($file->isFile() && strpos($file->getBasename(), 'Observer') !== false && $file->getExtension() === 'php') {
        $path = $file->getPathname();
        $content = file_get_contents($path);
        
        // 检查是否缺少 : void
        if (preg_match('/public function execute\(Event &\$event\)\n\s*\{/', $content) && strpos($content, ': void') === false) {
            $content = preg_replace('/public function execute\(Event &\$event\)\n(\s*)\{/', "public function execute(Event &\$event): void\n\$1{", $content);
            file_put_contents($path, $content);
            $fixed[] = str_replace(__DIR__, '.', $path);
            echo $fixed[count($fixed)-1] . "\n";
        }
    }
}

echo "\nFixed: " . count($fixed) . " files\n";

