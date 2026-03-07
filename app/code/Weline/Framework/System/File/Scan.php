<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\System\File;

use Symfony\Component\Finder\Finder;
use Weline\Framework\App\Exception;
use Weline\Framework\Register\RegisterInterface;
use Weline\Framework\System\File\Data\File;

class Scan
{
    private array $dirs = [];

    private int $keepLevel = 0;

    /**
     * 已扫描目录的 realpath 集合，防止符号链接循环导致无限递归
     */
    private array $visitedRealPaths = [];

    /**
     * @DESC         |初始化
     *
     * 参数区：
     */
    public function __init()
    {
        $this->dirs = [];
        $this->keepLevel = 0;
        $this->visitedRealPaths = [];
    }

    /**
     * @DESC         |方法描述
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @param string $dirPath
     * @param int $level
     *
     * @return array
     */
    /**
     * 排除的目录列表（不扫描这些目录）
     */
    private const EXCLUDE_DIRS = [
        'node_modules',
        'vendor',
        '.git',
        '.svn',
        '.hg',
        '.idea',
        '.vscode',
        '__pycache__',
        '.pytest_cache',
        'dist',
        'build',
        '.cache',
    ];

    public function scanDirTree(string $dirPath, int $level = 0): array
    {
        $this->keepLevel += 1;
        $dirPath = rtrim($dirPath, DS);
        $rootRealPath = @realpath($dirPath);
        if ($rootRealPath !== false && isset($this->visitedRealPaths[$rootRealPath])) {
            return $this->dirs;
        }
        if ($rootRealPath !== false) {
            $this->visitedRealPaths[$rootRealPath] = true;
        }
        if (is_dir($dirPath) && $file_handler = opendir($dirPath)) {
            while (false !== ($file = readdir($file_handler))) {
                // 排除"."".."
                if ($file !== '.' && $file !== '..') {
                    $filename = $dirPath . DS . $file;
                    $relateFilename = str_replace(APP_CODE_PATH, '', $filename);
                    if (is_int(strpos($filename, VENDOR_PATH))) {
                        $relateFilename = str_replace(VENDOR_PATH, '', $filename);
                    }
                    if (IS_WIN) {
                        $relateFilename = str_replace('/', DS, $relateFilename);
                    }
                    if (is_dir($filename)) {
                        // 排除特定目录（如 node_modules）
                        if (in_array($file, self::EXCLUDE_DIRS, true)) {
                            continue;
                        }
                        // 防止符号链接循环导致无限递归
                        $realPath = @realpath($filename);
                        if ($realPath !== false && isset($this->visitedRealPaths[$realPath])) {
                            continue;
                        }
                        if ($realPath !== false) {
                            $this->visitedRealPaths[$realPath] = true;
                        }
                        // 目录层级：是否扫描
                        if ($level) {
                            if ($this->keepLevel < $level) {
                                $this->scanDirTree($filename, $level);//递归调用;
                            }
                        } else {
                            // 扫描全部目录
                            $this->scanDirTree($filename);
                        }
                    } else {
                        // 文件
                        $file = new File();
                        $pathInfo = pathinfo($filename);
                        $file->setBasename($pathInfo['basename']);
                        $file->setFilename($pathInfo['filename']);
                        $file->setDirname($pathInfo['dirname']);
                        $file->setExtension($pathInfo['extension'] ?? '');
                        $file->setOrigin($filename);
                        $file->setNamespace(str_replace('/', '\\', dirname($relateFilename)));
                        $file->setRelate($relateFilename);
                        $file->setSize(filesize($filename));
                        $file->setType(filetype($filename));
                        $this->dirs[dirname($relateFilename)][] = $file;
                    }
                }
            }
        }

        return $this->dirs;
    }

    /**
     * @DESC         |扫描目录
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @param string $dirPath
     *
     * @return array
     */
    public function scanDir(string $dirPath): array
    {
        if (!is_dir(rtrim($dirPath, DS))) {
            return [];
        }
        if ($this->dirs = (scandir($dirPath)) ? scandir($dirPath) : []) {
            // 排除"."".."
            array_shift($this->dirs);
            array_shift($this->dirs);
        }

        return $this->dirs;
    }

    /** 递归深度上限，防止异常目录结构导致栈溢出 */
    private const DIR_TO_ARRAY_MAX_DEPTH = 128;

    /**
     * @var array 已访问目录 realpath，防止 dirToArray 符号链接循环
     */
    private array $dirToArrayVisited = [];

    public function dirToArray($dir, int $depth = 0): array
    {
        if ($depth > self::DIR_TO_ARRAY_MAX_DEPTH) {
            return [];
        }
        if ($depth === 0) {
            $this->dirToArrayVisited = [];
        }
        $dir = rtrim($dir, DS);
        $realPath = @realpath($dir);
        if ($realPath !== false && isset($this->dirToArrayVisited[$realPath])) {
            return [];
        }
        if ($realPath !== false) {
            $this->dirToArrayVisited[$realPath] = true;
        }
        $contents = [];
        if (!is_dir($dir) || ($list = scandir($dir)) === false) {
            return $contents;
        }
        foreach ($list as $node) {
            if ($node === '.' || $node === '..') {
                continue;
            }
            $path = $dir . DS . $node;
            if (is_dir($path)) {
                $subReal = @realpath($path);
                if ($subReal !== false && isset($this->dirToArrayVisited[$subReal])) {
                    continue;
                }
                $contents[$node] = $this->dirToArray($path, $depth + 1);
            } else {
                $contents[] = $node;
            }
        }
        return $contents;
    }

    /** 递归深度上限，防止异常目录结构导致栈溢出 */
    private const GLOB_FILE_MAX_DEPTH = 128;

    /**
     * @var array 已访问目录 realpath，防止 globFile 符号链接循环
     */
    private array $globFileVisited = [];

    public function globFile(
        $pattern_dir,
        &$files = [],
        string $ext = '.php',
        string $remove_path = '',
        string $replace_path = '',
        bool $remove_ext = false,
        bool $class_path = false,
        string &$composer_dir = '',
        int $depth = 0
    )
    {
        if ($depth > self::GLOB_FILE_MAX_DEPTH) {
            return $files;
        }
        if ($depth === 0) {
            $this->globFileVisited = [];
        }
        $list = glob($pattern_dir);
        if ($list === false) {
            return $files;
        }
        foreach ($list as $file) {
            if (is_dir($file)) {
                $realPath = @realpath($file);
                if ($realPath !== false && isset($this->globFileVisited[$realPath])) {
                    continue;
                }
                if ($realPath !== false) {
                    $this->globFileVisited[$realPath] = true;
                }
                $this->globFile($file . DS . '*', $files, $ext, $remove_path, $replace_path, $remove_ext, $class_path, $composer_dir, $depth + 1);
            }
            if (str_ends_with($file, $ext)) {
                $file_ = $file;
                if ($remove_path) {
                    $file_ = str_replace($remove_path, $replace_path, $file_);
                }
                if ($remove_ext) {
                    $file_ = str_replace($ext, '', $file_);
                    $file_ = str_replace(strtoupper($ext), '', $file_);
                }
                if ($class_path) {
                    $file_ = str_replace('/', '\\', $file_);
                    /*if(!class_exists($file_)){
                        $file_ = $this->getClassNameFromFile($file, $composer_dir);
                    }*/
                }
                $files[] = $file_;
            }
        }
        return $files;
    }

    function getClassNameFromFile($filePath, $composerPath = '')
    {
        $directory = dirname($filePath);
        if (empty($composerPath)) {
            $composerPath = $directory;
        }

        while (!is_file($composerPath . DS . 'composer.json') && $composerPath !== '') {
            $composerPath = dirname($composerPath);
        }

        if ($composerPath === '') {
            throw new Exception(__('无法找到composer.json！加载文件：%{1}', $filePath));
        }

        $composer = json_decode(file_get_contents($composerPath . DS . 'composer.json'), true);

        $autoloads = $composer['autoload']['psr-4'] ?? [];

        foreach ($autoloads as $namespace => $path) {
            if (strpos($directory, $path) === 0) {
                $class = str_replace('/', '\\', substr($directory, strlen($composerPath . $path))) . '\\' . basename($filePath, '.php');
                return $namespace . $class;
            }
        }

        throw new Exception(__('无法在自动加载器中加载类！加载文件：%{1}', $filePath));
    }
}
