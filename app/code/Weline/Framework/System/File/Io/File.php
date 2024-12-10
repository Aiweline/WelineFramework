<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\System\File\Io;

use Weline\Framework\App\Exception;
use Weline\Framework\Console\ConsoleException;

class File
{
    public const mode_r = 'r';//只读。在文件的开头开始。

    public const mode_r_add = 'r+';//只读。在文件的开头开始。

    public const mode_w = 'w';//只写。打开并清空文件的内容；如果文件不存在，则创建新文件。

    public const mode_w_add = 'w+';//读/写。打开并清空文件的内容；如果文件不存在，则创建新文件。

    public const mode_a = 'a';//追加。打开并向文件文件的末端进行写操作，如果文件不存在，则创建新文件。

    public const mode_a_add = 'a+';//读/追加。通过向文件末端写内容，来保持文件内容。

    public const mode_x = 'x';//只写。创建新文件。如果文件以存在，则返回 FALSE。

    public const mode_x_add = 'x+';//读/写。创建新文件。如果文件已存在，则返回 FALSE 和一个错误。

    /**
     * @var false|resource
     */
    protected $_file;

    /**
     * @var string
     */
    protected $_filename;

    /**
     * @DESC         |打开文件流
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @param string $filename
     * @param string $mode
     *
     * @return File
     */
    public function open(string $filename, string $mode = self::mode_a_add)
    {
        $filename = str_replace('/', DS, $filename);
        $position = strrpos($filename, DS);
//        if(!$position) throw new Exception(__('系统文件路径层级表达符号错误：当前系统文件分隔符为%1,当前路径：%2',[DS,$filename]));
        $path = substr($filename, 0, $position);
        if (!file_exists($path)) {
            mkdir($path, 0770, true);
        }
        $this->_filename = $filename;
        $this->_file     = fopen($filename, $mode);
        flock($this->_file, LOCK_EX);

        return $this;
    }

    /**
     * @DESC         |写入文件流
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @param string $content
     *
     * @return File
     * @throws Exception
     */
    public function write(string $content)
    {
        if (!$this->_file) {
            if (PHP_SAPI !== 'cli') {
                throw new Exception("文件:{$this->_filename} 读取异常！");
            }

            throw new ConsoleException("文件:{$this->_filename} 读取异常！");
        }
        fwrite($this->_file, $content);

        return $this;
    }

    /**
     * @DESC         |关闭文件
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @return bool
     */
    public function close()
    {
        return fclose($this->_file);
    }

    /**
     * @DESC         |方法描述
     *
     * 参数区：
     *
     * @return false|resource
     * @throws Exception
     */
    public function getSource()
    {
        if (!$this->_file) {
            throw new Exception("文件:{$this->_filename} 读取异常！");
        }

        return $this->_file;
    }

    /**
     * @DESC         |方法描述
     *
     * 参数区：
     *
     * @param string $filename
     *
     * @return bool|string
     */
    public function create(string $filename)
    {
        if (file_exists($filename)) {
            return $filename;
        }
        $dir = dirname($filename);
        if (is_dir($dir)) {
            @mkdir($dir, 0770);
        }
        $this->open($filename, self::mode_w);
        $this->close();
        if (file_exists($filename)) {
            return $filename;
        }

        return false;
    }
}
