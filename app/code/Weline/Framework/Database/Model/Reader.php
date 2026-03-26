<?php
/*
 * 鏈枃浠剁敱 绉嬫灚闆侀 缂栧啓锛屾墍鏈夎В閲婃潈褰扐iweline鎵€鏈夈€?
 * 閭锛歛iweline@qq.com
 * 缃戝潃锛歛iweline.com
 * 璁哄潧锛歨ttps://bbs.aiweline.com
 */

namespace Weline\Framework\Database\Model;

use Weline\Framework\System\File\Data\File;
use Weline\Framework\System\File\Scanner;

class Reader extends \Weline\Framework\System\ModuleFileReader
{
    private ?array $models;

    public function __construct(
        Scanner $scanner,
                $path = 'Model' . DS
    )
    {
        parent::__construct($scanner, $path);
        $this->scanner = $scanner;
        $this->path    = $path;
    }

    /**
     * @DESC          # 璇诲彇妯″瀷 寮€鍙戞ā寮忔病鏈夌紦瀛橈紝闈炲紑鍙戞ā寮忚鍙栫紦瀛?
     *
     * @AUTH    绉嬫灚闆侀
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/6 21:34
     * 鍙傛暟鍖猴細
     * @return array
     */
    public function read(): array
    {
        if (empty($this->models)) {
            # 妯″瀷璇诲彇鍥炶皟锛堟帓闄ら潪妯″瀷鏂囦欢锛?
            $callback = fn(array $files): array => $this->filterModelTree($files);
            $this->models = $this->getFileList($callback);
        }

        return $this->models;
    }

    private function filterModelTree(array $nodes): array
    {
        $filtered = [];

        foreach ($nodes as $key => $node) {
            if ($node instanceof File) {
                if ($this->isDatabaseModelFile($node)) {
                    $filtered[$key] = $node;
                }
                continue;
            }

            if (!is_array($node)) {
                continue;
            }

            $children = $this->filterModelTree($node);
            if ($children !== []) {
                $filtered[$key] = $children;
            }
        }

        return $filtered;
    }

    private function isDatabaseModelFile(File $modelFile): bool
    {
        $modelClass = $modelFile->getNamespace() . '\\' . $modelFile->getFilename();
        $modelClass = str_replace('\\\\', '\\', $modelClass);

        try {
            $reflection = new \ReflectionClass($modelClass);
        } catch (\Throwable) {
            return false;
        }

        return $reflection->isSubclassOf(\Weline\Framework\Database\Model::class);
    }
}
