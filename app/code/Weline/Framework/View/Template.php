<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\View;

use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Controller\PcController;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Exception\Core;
use Weline\Framework\Hook\Hooker;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Session;
use Weline\Framework\Ui\FormKey;
use Weline\Framework\View\Cache\ViewCache;
use Weline\Framework\View\Data\DataInterface;

class Template extends DataObject
{
    use TraitTemplate;

    private string $file_ext = '.phtml';

    protected Request $request;
    private Session $session;
    private ?Taglib $taglib = null;

    /**
     * @var PcController
     */
    private PcController $controller;

    /**
     * @var string 指定模板目录
     */
    private string $template_dir = '';

    /**
     * @var string 编译后的目录
     */
    private string $compile_dir = '';

    /**
     * @var string 静态文件目录
     */
    private string $statics_dir = '';

    /**
     * @var string 静态文件目录
     */
    private string $view_dir = '';

    private array $theme;

    private EventsManager $eventsManager;

    /**
     * @var CacheInterface 缓存
     */
    private CacheInterface $viewCache;

    private static Template $instance;

    private function __clone()
    {
    }

    public static function getInstance(): Template
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
            self::$instance->init();
        }
        return self::$instance;
    }

    /**
     * @DESC          # 读取模板文件拓展
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/3/20 20:18
     * 参数区：
     * @return string
     */
    public function getFileExt(): string
    {
        return $this->file_ext;
    }

    /**
     * @DESC          # 设置模板文件拓展
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/3/20 20:17
     * 参数区：
     *
     * @param string $ext 拓展：例如.phtml则填写phtml
     *
     * @return $this
     */
    public function setFileExt(string $ext): static
    {
        $this->file_ext = '.' . $ext;
        return $this;
    }

    public function getBlock(string $class)
    {
        return ObjectManager::getInstance($class);
    }

    public function init()
    {
            $this->theme ?? $this->theme = Env::getInstance()->getConfig('theme', Env::default_theme_DATA);
            $this->eventsManager ?? $this->eventsManager = ObjectManager::getInstance(EventsManager::class);
            $this->viewCache ?? $this->viewCache = ObjectManager::getInstance(ViewCache::class)->create();
        $this->request = ObjectManager::getInstance(Request::class);

        if (!CLI) {
            if (empty($this->view_dir)) {
                $this->view_dir = $this->request->getRouterData('module_path') . DataInterface::dir . DS;
            }
                $this->getData('title') ?? $this->setData('title', $this->request->getModuleName());
            $this->request->setData('url', $this->request->getUrlBuilder()->getCurrentUrl());
                $this->getData('req') ?? $this->setData('req', array_merge(
                $this->request->getParams(), [
                'url' => $this->request->getUrlBuilder()->getCurrentUrl(),
                'query' => $this->request->getQuery(),
                'query_string' => http_build_query($this->request->getQuery()),
                'params' => $this->request->getParams()
            ]));
                $this->getData('env') ?? $this->setData('env', Env::getInstance()->getConfig());
                $this->getData('local') ?? $this->setData('local', ['code' => Cookie::getLangLocal(), 'lang' => Cookie::getLang()]);
        }

        if (empty($this->statics_dir)) {
            $this->statics_dir = $this->getViewDir(DataInterface::view_STATICS_DIR);
        }
        if (empty($this->template_dir)) {
            $this->template_dir = $this->getViewDir(DataInterface::view_TEMPLATE_DIR);
        }
        if (empty($this->compile_dir)) {
            $this->compile_dir = $this->getViewDir(DataInterface::view_TEMPLATE_COMPILE_DIR);
        }
        return $this;
    }

    public function __init()
    {
        $this->init();
    }

    /**
     * @DESC          # 获取form_key
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/10/22 22:24
     * 参数区：
     * @return string
     */
    public function getFormKey($url): string
    {
        return ObjectManager::getInstance(FormKey::class)->getHtml($url);
    }

    /**
     * @DESC         |获取视图文件
     *
     * 参数区：
     *
     * @param $filepath
     *
     * @return string
     * @throws \Exception
     */
    public function getViewFile($filepath): string
    {
        $path = $this->view_dir . $filepath;
        if (!file_exists($path) && DEV) {
            new Exception(__('文件不存在！位置：') . $path);
        }
        $this->fetch($filepath);

        return $path;
    }

    /**
     * @DESC         |模板中变量分配调用的方法
     *
     * 参数区：
     *
     * @param string|array $key 键值
     * @param null $value
     *
     * @return Template
     */
    public function assign(string|array $key, mixed $value = null): static
    {
        $this->setData($key, $value);
        return $this;
    }

    /**
     * @param string $fileName 文件名转换查找
     *
     * @throws Core
     * @throws Exception
     */
    public function convertFetchFileName(string $fileName): array
    {
        $comFileName_cache_key = $this->view_dir . $fileName . '_comFileName' . Cookie::getLangLocal();
        $tplFile_cache_key = $this->view_dir . $fileName . '_tplFile' . Cookie::getLangLocal();
        $comFileName = '';
        $tplFile = '';
        # 让非生产环境实时读取文件
        if (PROD) {
            $comFileName = $this->viewCache->get($comFileName_cache_key);
            $tplFile = $this->viewCache->get($tplFile_cache_key);
        }
        # 测试
        //        file_put_contents(__DIR__ . '/test.txt', $comFileName . PHP_EOL, FILE_APPEND);
        // 编译文件不存在的时候 重新对文件进行处理 防止每次都处理
        if (!is_file($comFileName) || !is_file($tplFile)) {
            // 解析模板路由
            if ('/' !== DS) {
                $fileName = str_replace('/', DS, $fileName);
            }
            $file_name_dir_arr = explode(DS, $fileName);
            $file_dir = '';
            $file_name = '';

            // 如果给的文件名字有路径
            if (count($file_name_dir_arr) > 1) {
                $file_name = array_pop($file_name_dir_arr);
                $file_dir = implode(DS, $file_name_dir_arr);
                if ($file_dir) {
                    $file_dir .= DS;
                }
            }
            # 检测读取别的模块的模板文件
            list($fileName, $file_dir, $view_dir, $template_dir, $compile_dir) = $this->processFileSource($fileName, $file_dir);
            // 判断文件后缀
            $file_ext = substr(strrchr($fileName, '.'), 1);
            //
            //            // 检测模板文件：如果文件名有后缀 则直接到view下面读取。没有说明是默认
            if ($file_ext) {
                $tplFile = $view_dir . $fileName;
            } else {
                $tplFile = $view_dir . $fileName . $this->getFileExt();
            }
            //            p($tplFile,1);
            $tplFile = $this->fetchFile($tplFile);
            //            p($tplFile);

            if (!file_exists($tplFile)) {
                $msg = __('获取操作：%1', $fileName) . PHP_EOL;
                $msg .= __('模板文件不存在！：%1 ', $tplFile) . PHP_EOL;
                $msg .= __('源文件：%1', $fileName);
                throw new Exception($msg);
            }

            // 检测目录是否存在,不存在则建立
            $baseComFileDir = $compile_dir . DS . Cookie::getLang() . DS . ($file_dir ?: '');
            if (!is_dir($baseComFileDir)) {
                mkdir($baseComFileDir, 0770, true);
            }

            //定义编译合成的文件 加了前缀 和路径 和后缀名.phtml
            $file_name = $file_name ?? $fileName;
            if ($file_ext) {
                $comFileName = $baseComFileDir . 'com_' . $file_name;
            } else {
                $comFileName = $baseComFileDir . 'com_' . $file_name . $this->getFileExt();
            }
            $comFileName = $this->fetchFile($comFileName);
            # 生产模式缓存: 根据管道设置缓存
            if (PROD) {
                $this->viewCache->set($comFileName_cache_key, $comFileName);
                $this->viewCache->set($tplFile_cache_key, $tplFile);
            };
        }

        # 测试
        //        file_put_contents(__DIR__ . '/test.txt', $comFileName . PHP_EOL, FILE_APPEND);
        if (is_int(strpos($comFileName, '\\'))) {
            $comFileName = str_replace('\\', DS, $comFileName);
        }
        if (is_int(strpos($comFileName, '//'))) {
            $comFileName = str_replace('//', DS, $comFileName);
        }
        return [$comFileName, $tplFile];
    }


    public function getFetchFile(string $fileName, string|null $module_name = ''): string
    {
        list($comFileName, $tplFile) = $this->convertFetchFileName($fileName);
        # 检测编译文件，如果不符合条件则重新进行文件编译
        if (DEV || !file_exists($comFileName) || (filemtime($comFileName) < filemtime($tplFile))) {
            //如果缓存文件不存在则 编译 或者文件修改了也编译
            $content = file_get_contents($tplFile);
            $repContent = $this->tmp_replace($content, $comFileName);                   //得到模板文件 并替换占位符 并得到替换后的文件
            if (DEV) {
                $tpl_pad_file_name = __('模板文件：%1 START', $tplFile);
                $tpl_str_len = strlen($tpl_pad_file_name);
                $tpl_str_pad_all = str_pad('', $tpl_str_len, '=', STR_PAD_BOTH);
                $tpl_str_pad_file = str_pad($tpl_pad_file_name, $tpl_str_len, '=', STR_PAD_BOTH);
                $com_pad_file_name = __('模板文件：%1 END', $comFileName);
                $com_str_len = strlen($com_pad_file_name);
                $com_str_pad_all = str_pad('', $com_str_len, '=', STR_PAD_BOTH);
                $com_str_pad_file = str_pad($com_pad_file_name, $com_str_len, '=', STR_PAD_BOTH);
                $repContent = "<!--" . PHP_EOL . "$tpl_str_pad_all " . PHP_EOL . $tpl_str_pad_file . PHP_EOL . $tpl_str_pad_all . PHP_EOL . ' -->'
                    . PHP_EOL . $repContent . PHP_EOL
                    . '<!--' . PHP_EOL . $com_str_pad_all . PHP_EOL . $com_str_pad_file . PHP_EOL . $com_str_pad_all . PHP_EOL . '-->';
            }
            file_put_contents($comFileName, $repContent);                               //将替换后的文件写入定义的缓存文件中
        }
        return $comFileName;
    }

    /**
     * @DESC         |调用模板显示
     *
     * 参数区：
     *
     * @param string $fileName 获取的模板名
     * @param array $dictionary 参数绑定
     *
     * @return bool|void
     * @throws \Exception
     */
    public function fetch(string $fileName, array $data = [])
    {
        /** Get output buffer. */
        return $this->fetchHtml($fileName, $data);
    }

    /**
     * @DESC         |调用模板显示
     *
     * 参数区：
     *
     * @param string $fileName 获取的模板名
     * @param array $dictionary 参数绑定
     *
     * @return bool|void
     * @throws \Exception
     */
    public function fetchHtml(string $fileName, array $dictionary = [])
    {
        $comFileName = $this->getFetchFile($fileName);
        return $this->ob_file($comFileName, $dictionary);
    }

    /**
     * @DESC         |调用模板显示
     *
     * 参数区：
     *
     * @param string $fileName 获取的模板名
     * @param array $dictionary 参数绑定
     *
     * @return bool|void
     * @throws \Exception
     */
    public function fetchTagHtml(string $tag, string $fileName, array $dictionary = [])
    {
        $comFileName = $this->fetchTagSource($tag, $fileName);
        return $this->ob_file($comFileName, $dictionary);
    }

    public function ob_file(string $filename, array $dictionary = []): string
    {
        ob_start();
        try {
            if ($dictionary) {
                $this->addData($dictionary);
            }
            # 将数组存储的变量散列到当前页内存中，使得变量可在页面中暴露出来（可直接使用）
            if ($this->getData()) {
                extract($this->getData(), EXTR_SKIP);
            }
            include $filename;
        } catch (\Exception $exception) {
            ob_end_clean();
            throw $exception;
        }
        /** Get output buffer. */
        return ob_get_clean();
    }

    /**
     * @DESC         |替换模板中的占位符
     *
     * 参数区：
     *
     * @param string $content 文本
     * @param string $fileName 模板文件
     *
     * @return string|string[]|null
     * @throws Core
     */
    public function tmp_replace(string $content, string $fileName = ''): array|string|null
    {
        # 系统自带的标签
        return $this->getTaglib()->tagReplace($this, $content, $fileName);
    }

    /*_______________URL____________*/
    private function getUrlObject(): Url
    {
        return ObjectManager::getInstance(Url::class);
    }

    public function getUrl(string $path, array $params = [], bool $merge_query = false): string
    {
        return $this->getUrlObject()->getUrl($path, $params, $merge_query);
    }

    public function getApi(string $path, array $params = [], bool $merge_query = false): string
    {
        return $this->getUrlObject()->getUrl($path, $params, $merge_query);
    }

    public function getBackendUrl(string $path, array|bool $params = [], bool $merge_query = false): string
    {
        return $this->getUrlObject()->getBackendUrl($path, $params, $merge_query);
    }

    public function getBackendApi(string $path, array|bool $params = [], bool $merge_query = false): string
    {
        return $this->getUrlObject()->getBackendApiUrl($path, $params, $merge_query);
    }
    /*_______________URL____________*/
    /**
     * @throws \ReflectionException
     * @throws Exception
     * @throws Core
     */
    public function getHook(string $name): string
    {
        /**@var Hooker $hooker */
        $hooker = ObjectManager::getInstance(Hooker::class);
        $hookers = $hooker->getHook($name);
        $hooker_content = '';
        foreach ($hookers as $module => $hooker_file) {
            if (DEV) {
                $content = "<!-- 来自模组 $module 的钩子实现{$name}代码 起-->" . $this->fetchTagHtml('hooks', $hooker_file) . "<!-- 来自模组 $module 的钩子实现{$name}代码 止-->";;
            } else {
                $content = $this->fetchTagHtml('hooks', $hooker_file);
            }
            $hooker_content .= $content;
        }
        return $hooker_content;
    }

    public function getRequest(): Request
    {
        return ObjectManager::getInstance(Request::class);
    }

    public function getTaglib()
    {
        if (isset($this->taglib)) {
            return $this->taglib;
        }
        $this->taglib = ObjectManager::getInstance(Taglib::class);
        return $this->taglib;
    }
}
