<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\System\File;

use Weline\Framework\App\Exception;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

class Uploader
{
    private string $media_dir = PUB . 'media' . DS;
    private string $uploader_dir = 'uploader' . DS;
    private string $module_dir = '';
    private string $module_name = '';
    private array $accepted_origins = ['http://localhost', 'http://127.0.0.1'];
    private array $ext = ['gif', 'jpg', 'png', 'jpeg'];
    private bool $headerResponse = false;
    private $headers = [];

    function setExt(array $ext): self
    {
        $this->ext = $ext;
        return $this;
    }

    function headerResponse(bool $allow = false): self
    {
        $this->headerResponse = $allow;
        return $this;
    }

    function getHeaders():array
    {
        return $this->headers;
    }

    public function checkDomain(): void
    {
        if (isset($_SERVER['HTTP_ORIGIN'])) {
            // 验证来源是否在白名单内
            if (in_array($_SERVER['HTTP_ORIGIN'], $this->accepted_origins)) {
                if($this->headerResponse){
                    header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
                }else{
                    $this->headers['Access-Control-Allow-Origin'] = $_SERVER['HTTP_ORIGIN'];
                }
            } else {
                if($this->headerResponse){
                    header('HTTP/1.1 403 Origin Denied');
                }else{
                    $this->headers['HTTP/1.1 403 Origin Denied'] = 'Origin Denied';
                }
                throw new Exception('Origin Denied');
            }
        }
    }

    /**
     * @DESC          # 添加域名白名单
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/5/3 22:26
     * 参数区：
     *
     * @param array $origin_domains
     *
     * @return $this
     */
    public function addAcceptOriginDomain(array $origin_domains): static
    {
        $this->accepted_origins = array_merge($this->accepted_origins, $origin_domains);
        return $this;
    }

    /**
     * @DESC          # 添加拓展名
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/5/3 22:22
     * 参数区：
     *
     * @param array $extends
     *
     * @return $this
     */
    public function addExt(array $extends): static
    {
        $this->ext = array_merge($this->ext, $extends);
        return $this;
    }

    /**
     * @DESC          # 检测文件名
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/5/3 22:20
     * 参数区：
     *
     * @param string $filename
     *
     * @throws Exception
     */
    public function checkFilename(string $filename): void
    {
        // 简单的过滤一下文件名是否合格
        if (preg_match('/[\x{4e00}-\x{9fa5}:：,，。…、~`＠＃￥％＆×＋｜｛｝＝－＊＾＄～｀!@#$%^&*()\+=（）！￥{}【】\[\]\|\"\'’‘“”；;《》<>\?\？\·]/u', $filename, $matches)) {
            if (!CLI) {
                if ($this->headerResponse){
                    header('HTTP/1.1 400 Invalid file name.');
                }else{
                    $this->headers['HTTP/1.1 400 Invalid file name.'] = 'Invalid file name.';
                }
            }
            throw new Exception(__('无效文件名。'));
        }

        // 验证扩展名
        if (!in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), $this->ext)) {
            if (!CLI) {
                if ($this->headerResponse){
                    header('HTTP/1.1 400 Invalid extension.');
                }else{
                    $this->headers['HTTP/1.1 400 Invalid extension.'] = 'Invalid extension.';
                }
            }
            throw new Exception(__('无效拓展名。'));
        }
    }

    /**
     * @DESC          # 处理文件
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/10/12 13:10
     * 参数区：
     *
     * @param string $module_name 需要设置上传的目录位置
     * @param string $base_dir
     * @param string $module_dir
     *
     * @return array
     * @throws Exception
     */
    public function saveFiles(string $module_name = '', string $module_dir = '', string $base_dir = ''): array|string
    {
        if (!$module_name) {
            $module_name = $this->getRequest()->getModuleName();
        }
        if (!$module_dir) {
            $module_dir = str_replace('/', DS, $this->getRequest()->getRouterData('class/controller_name'));
        }
        $this->setModuleDir($module_dir);
        if ($base_dir) {
            $this->setBaseUploaderDir($base_dir);
        }
        if ($module_name) {
            $this->setModuleName(str_replace('_', DS, $module_name));
        }
        $result = [];
        if (1 === count($_FILES)) {
            $file     = array_pop($_FILES);
            $filename = $file['name'];
            if ($filename) {
                $result = $this->saveFile($file['tmp_name'], $filename);
            }
        } else {
            foreach ($_FILES as $FILE) {
                $filename = $FILE['name'];
                if ($filename) {
                    $result[] = $this->saveFile($FILE['tmp_name'], $filename);
                }
            }
        }
        return $result??false;
    }

    public function getUploadFilename(string $filename): string
    {
        $this->checkFilename($filename);
        if (!str_starts_with($filename, BP)) {
            $filename = $this->getBaseUploaderDir() . $filename;
        }
        return $filename;
    }

    /**
     * @DESC          # 保存文件
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/10/12 13:10
     * 参数区：
     *
     * @param string $tmp_file
     * @param string $filename 存储位置
     *
     * @return string
     * @throws Exception
     */
    public function saveFile(string $tmp_file, string $filename): string
    {
        $filename = $this->getUploadFilename($filename);
        $dir      = dirname($filename);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
            chmod($dir, 0777);
        }
        if (move_uploaded_file($tmp_file, $filename)) {
            $filename = str_replace(BP, '', $filename);
            return '/' . str_replace('\\', '/', $filename);
        } else {
            throw new Exception(__('文件上传失败:%1 ', $filename));
        }
    }

    /**
     * @return string
     */
    #[Pure] public function getBaseUploaderDir(): string
    {
        return $this->media_dir . rtrim($this->uploader_dir, DS) . DS . ($this->getModuleName() ? $this->getModuleName() . DS : '') . ($this->getModuleDir() ? $this->getModuleDir() . DS : '');
    }

    /**
     * @param string $uploader_dir
     *
     * @return Uploader
     */
    public function setBaseUploaderDir(string $uploader_dir): Uploader
    {
        $this->uploader_dir = rtrim($uploader_dir, DS) . DS;
        return $this;
    }

    /**
     * @return string
     */
    public function getModuleName(): string
    {
        return $this->module_name ?: str_replace('_', DS, $this->getRequest()->getModuleName());
    }

    private function getRequest(): Request
    {
        return ObjectManager::getInstance(Request::class);
    }

    /**
     * @param string $module_name 模块名
     */
    public function setModuleName(string $module_name): Uploader
    {
        $this->module_name = $module_name;
        return $this;
    }

    /**
     * @return string
     */
    public function getModuleDir(): string
    {
        return $this->module_dir;
    }

    /**
     * @param string $module_dir
     */
    public function setModuleDir(string $module_dir): void
    {
        $this->module_dir = $module_dir;
    }

    public function delete(string $filepath): bool
    {
        $filename = $this->getUploadFilename($filepath);
        if (is_file($filename)) {
            unlink($filename);
        }
        return true;
    }
}
