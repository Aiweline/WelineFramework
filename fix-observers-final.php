<?php

$baseDir = __DIR__ . '/app/code';
$files = [];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($baseDir, RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if ($file->isFile() && strpos($file->getBasename(), 'Observer') !== false && $file->getExtension() === 'php') {
        $filepath = $file->getPathname();
        $content = file_get_contents($filepath);
        
        // 查找所有 public function execute(Event &$event) { 的情况（缺少 : void）
        $pattern = '/public function execute\(Event &\$event\)\s*\{/';
        if (preg_match($pattern, $content) && strpos($content, 'public function execute(Event &$event): void') === false) {
            $content = preg_replace($pattern, 'public function execute(Event &$event): void' . "\n    {", $content);
            file_put_contents($filepath, $content);
            $files[] = str_replace(__DIR__, '.', $filepath);
            echo "✓ " . str_replace(__DIR__, '.', $filepath) . "\n";
        }
    }
}

echo "\n修复了 " . count($files) . " 个文件\n";

