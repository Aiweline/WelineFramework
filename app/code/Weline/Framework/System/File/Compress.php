<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\System\File;

use Weline\Framework\App\Exception;

class Compress
{
    private \ZipArchive $zipArchive;

    private string $base_path = '';

    /**
     * Compress 初始函数...
     *
     * @param \ZipArchive $zipArchive
     */
    public function __construct(
        \ZipArchive $zipArchive
    )
    {
        $this->zipArchive = $zipArchive;
    }

    private array $files = [];

    private array $string_files = [];

    /**
     * @DESC         |压缩文件
     *
     * 参数区：
     *
     * @param string      $path
     * @param string|null $to_path
     * @param string|null $zip_base_path
     *
     * @return string
     * @throws Exception
     */
    public function compression(string $path, string $to_path = null, string $zip_base_path = null)
    {
        $path            = rtrim($path, DS);
        $this->base_path = $zip_base_path ? $zip_base_path : dirname($path);
        $parent_dir      = dirname($path);
        $filename        = $parent_dir . DS . '.zip';
        if ($to_path) {
            $parent_dir_arr = explode(DS, $path);
            $filename       = $to_path . '.zip';
        }
        if ($this->zipArchive->open($filename, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true) {
            try {
                if ($string_files = $this->string_files) {
                    foreach ($string_files as $file => $string) {
                        $this->zipArchive->addFromString($file, $string);// 添加字符串压缩
                    }
                }
                if ($files = $this->files) {
                    $this->files = [];
                    foreach ($files as $file) {
                        $this->addFileToZip($file); // 添加文件
                    }
                }
                $this->addFileToZip($path);
            } catch (\Exception $e) {
                throw new Exception(__('压缩文件时发生异常') . $e->getMessage());
            } finally {
                $this->zipArchive->close();
            }
        } else {
            throw new Exception(__('创建压缩文件时发生异常'));
        }

        return $filename;
    }

    /**
     * @DESC         |添加文件到压缩包
     *
     * 参数区：
     *
     * @param $path
     * @param $zip
     *
     * @return $this
     */
    public function addFileToZip($path)
    {
        // 如果是文件直接加入
        if (is_file($path)) {
            $this->zipArchive->addFile($path);
        } else {
            $handler = opendir($path); //打开当前文件夹由$path指定。
            /*
            循环的读取文件夹下的所有文件和文件夹
            其中$filename = readdir($handler)是每次循环的时候将读取的文件名赋值给$filename，
            为了不陷于死循环，所以还要让$filename !== false。
            一定要用!==，因为如果某个文件名如果叫'0'，或者某些被系统认为是代表false，用!=就会停止循环
            */
            while (($filename = readdir($handler)) !== false) {
                if ($filename !== '.' && $filename !== '..') {//文件夹文件名字为'.'和‘..’，不要对他们进行操作
                    $filename = $path . DS . $filename;
                    if (is_dir($filename)) {// 如果读取的某个对象是文件夹，则递归
                        $this->addFileToZip($filename);
                    } elseif (is_file($filename)) { //将文件加入zip对象
                        $this->zipArchive->addFile($filename, trim(str_replace($this->base_path, '', $filename), DS));
                    }
                }
            }
            @closedir($handler);
        }

        return $this;
    }

    /**
     * @DESC         |添加字符串到压缩包里
     *
     * 参数区：
     *
     * @param string $file_name 添加的字符串在压缩包里的命名 支持路径命名 比如：add/string.txt
     * @param string $string    添加的字符串文本内容 比如：'Is file content'
     *
     * @return $this
     */
    public function addString(string $file_name, string $string)
    {
        $this->string_files[$file_name] = $string;

        return $this;
    }

    /**
     * @DESC         |解压文件
     *
     * 参数区：
     *
     * @param string      $zip_file
     * @param string|null $out_dir
     *
     * @return $this
     */
    public function deCompression(string $zip_file, string $out_dir = null)
    {
        /*
         通过ZipArchive的对象处理zip文件
         $zip->open这个方法的参数表示处理的zip文件名。
         如果对zip文件对象操作成功，$zip->open这个方法会返回TRUE
         */
        $out_dir = $out_dir ? $out_dir : dirname($zip_file);
        if ($this->zipArchive->open($zip_file) === true) {
            $this->zipArchive->extractTo($out_dir);//假设解压缩到在当前路径下images文件夹的子文件夹php
            $this->zipArchive->close();            //关闭处理的zip文件
        } else {
            throw new Exception(__('解压文件打开异常：') . $zip_file);
        }

        return $this;
    }

    public function setPassword(string $password)
    {
        $this->zipArchive->setPassword($password);

        return $this;
    }

    /**
     * @DESC         |获取驱动库
     *
     * 参数区：
     *
     * @return \ZipArchive
     */
    public function getDriver()
    {
        return $this->zipArchive;
    }

    /**
     * @DESC         |添加文件
     *
     * 参数区：
     *
     * @param $file
     *
     * @throws Exception
     */
    public function addFiles($file)
    {
        if (is_array($file)) {
            $this->files = array_unique(array_merge($this->files, $file));
        } else {
            if (is_file($file)) {
                $this->files[] = $file;
            } else {
                throw new Exception(__('不存在的文件！'));
            }
        }
    }
}
