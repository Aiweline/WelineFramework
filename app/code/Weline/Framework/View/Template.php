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
use Weline\Framework\App\State;
use Weline\Framework\Context;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Controller\PcController;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Exception\Core;
use Weline\Framework\Hook\Hooker;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\FiberOutputBuffer;
use Weline\Framework\Ui\FormKey;
use Weline\Framework\View\Data\DataInterface;

class Template extends DataObject
{
    use TraitTemplate;

    private string $file_ext = '.phtml';

    protected Request $request;
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
    private CachePoolInterface $viewCache;

    private static ?Template $instance = null;
    private static ?\WeakMap $fiberInstances = null;

    private function __clone()
    {
    }

    public static function getInstance(): Template
    {
        $fiber = self::currentFiber();
        if ($fiber !== null) {
            self::$fiberInstances ??= new \WeakMap();
            if (!isset(self::$fiberInstances[$fiber])) {
                $instance = new self();
                $instance->init();
                self::$fiberInstances[$fiber] = $instance;
            }

            return self::$fiberInstances[$fiber];
        }

        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->init();
        }
        return self::$instance;
    }

    /**
     * WLS 状态重置：销毁单例实例，强制下次 getInstance() 重新创建
     * 
     * WLS 常驻内存模式下，Template 单例的 _data 数组会残留上个请求的
     * title、req、env、local、view_dir 等请求级数据，导致页面标题、
     * 请求参数、模板目录等状态泄漏到下一个请求。
     */
    public static function resetInstance(): void
    {
        $fiber = self::currentFiber();
        if ($fiber !== null) {
            if (self::$fiberInstances !== null && isset(self::$fiberInstances[$fiber])) {
                unset(self::$fiberInstances[$fiber]);
            }
            return;
        }

        self::$instance = null;
        self::$fiberInstances = null;
    }

    private static function currentFiber(): ?\Fiber
    {
        if (!class_exists(\Weline\Framework\Runtime\Runtime::class)) {
            return null;
        }

        if (!\Weline\Framework\Runtime\Runtime::isPersistent()) {
            return null;
        }

        return \Fiber::getCurrent();
    }

    private function isRequestRuntime(): bool
    {
        if (!\defined('CLI') || !CLI) {
            return true;
        }

        $context = Context::getCurrent();
        return $context !== null && $context->get('meta.type') === 'request';
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
        // 语言初始化
        $this->initLanguage();
        $this->theme ??= Env::getInstance()->getConfig('theme', Env::default_theme_DATA);
        $this->eventsManager ??= ObjectManager::getInstance(EventsManager::class);
        $this->viewCache ??= w_cache('view');
        $this->request = ObjectManager::getInstance(Request::class);

        if ($this->isRequestRuntime()) {
            // 请求级数据必须每次请求都重新绑定，避免单例状态泄漏。
            $this->view_dir = $this->request->getRouterData('module_path') . DataInterface::dir . DS;
            $this->setData('title', $this->request->getModuleName());
            $this->request->setData('url', $this->request->getUrlBuilder()->getCurrentUrl());
            $this->setData('req', new TemplateRequestView());
            $this->setData('env', new TemplateEnvView());
            $this->setData('local', ['code' => Cookie::getLangLocal(), 'lang' => Cookie::getLang()]);
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

    private function initLanguage(): void
    {
        $lang = State::getLang();
        $this->setData('lang', $lang);
        // lang变量用于HTML lang属性，必须符合BCP 47规范（将下划线替换为连字符）
        $htmlLang = str_replace('_', '-', $lang);
        $this->setData('lang_local', State::getLangLocal());
        // htmlLang变量与lang相同，保持向后兼容
        $this->setData('htmlLang', $htmlLang);
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
     * @DESC          # 获取当前请求的 form_key 值（用于 JS 等）
     * @return string
     */
    public function getFormKeyValue(): string
    {
        $this->init();
        $path = $this->request->getUrlBuilder()->getCurrentUrl();
        return ObjectManager::getInstance(FormKey::class)->getKey($path, '');
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
                $msg = __('获取操作：%{1}', $fileName) . PHP_EOL;
                $msg .= __('模板文件不存在！：%{1} ', $tplFile) . PHP_EOL;
                $msg .= __('源文件：%{1}', $fileName);
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
        
        // 检测编译文件，如果不符合条件则重新进行文件编译
        if (self::shouldRecompileCompiledTemplate(
            $comFileName,
            $tplFile,
            DEV,
            Env::getInstance()->getConfig('template.force_recompile_in_dev', false)
        )) {
            // 如果缓存文件不存在则编译，或者文件修改了也编译
            $content = file_get_contents($tplFile);
            $repContent = $this->tmp_replace($content, $comFileName);  // 得到模板文件并替换占位符，得到替换后的文件
            
            // 检查是否显示模板位置注释（默认不显示，可通过配置 template.show_comments 控制）
            $showTemplateComments = Env::getInstance()->getConfig('template.show_comments', false);
            if ($showTemplateComments === true || $showTemplateComments === '1' || $showTemplateComments === 1) {
                $tpl_pad_file_name = __('模板文件：%{1} START', $tplFile);
                $tpl_str_len = strlen($tpl_pad_file_name);
                $tpl_str_pad_all = str_pad('', $tpl_str_len, '=', STR_PAD_BOTH);
                $tpl_str_pad_file = str_pad($tpl_pad_file_name, $tpl_str_len, '=', STR_PAD_BOTH);
                $com_pad_file_name = __('模板文件：%{1} END', $comFileName);
                $com_str_len = strlen($com_pad_file_name);
                $com_str_pad_all = str_pad('', $com_str_len, '=', STR_PAD_BOTH);
                $com_str_pad_file = str_pad($com_pad_file_name, $com_str_len, '=', STR_PAD_BOTH);
                $repContent = "<!--" . PHP_EOL . "$tpl_str_pad_all " . PHP_EOL . $tpl_str_pad_file . PHP_EOL . $tpl_str_pad_all . PHP_EOL . ' -->'
                    . PHP_EOL . $repContent . PHP_EOL
                    . '<!--' . PHP_EOL . $com_str_pad_all . PHP_EOL . $com_str_pad_file . PHP_EOL . $com_str_pad_all . PHP_EOL . '-->';
            } else {
                // 当 template.show_comments 为 false 时，移除所有 HTML 注释
                $repContent = preg_replace('/\<!--([\s\S]*?)-->/', '', $repContent);
            }
            
            // 触发模板编译后事件，允许 Observer 处理内容（如提取 JS 模块声明和翻译词）
            // 在所有编译处理完成后、写入文件之前触发，这样观察者可以处理最终的内容
            /**@var EventsManager $eventsManager */
            $eventsManager = ObjectManager::getInstance(EventsManager::class);
            $eventData = new DataObject([
                'content' => $repContent,
                'comFileName' => $comFileName,
                'tplFile' => $tplFile,
                'template' => $this,
            ]);
            $eventsManager->dispatch('Weline_Framework_Template::after_compile', $eventData);
            $repContent = $eventData->getData('content');

            // Embed content hash in compiled file for cross-platform reliable cache detection
            $contentHash = md5_file($tplFile) . '-' . filesize($tplFile);
            $hashHeader = "<?php /* hash:{$contentHash} */ ?>\n";
            $compiledContent = $hashHeader . $repContent;

            // Ensure compiled template directory exists before writing file.
            $compiledDir = dirname($comFileName);
            if (!is_dir($compiledDir)) {
                if (!mkdir($compiledDir, 0770, true) && !is_dir($compiledDir)) {
                    throw new Exception(__('无法创建模板编译目录：%{1}', $compiledDir));
                }
            }

            // Write compiled file with hash header
            file_put_contents($comFileName, $compiledContent);

            // Also update TemplateCacheManager for enhanced caching
            try {
                $cacheManager = TemplateCacheManager::getInstance();
                $cacheManager->writeCache($tplFile, $repContent);
            } catch (\Throwable) {
                // Non-critical - continue without enhanced cache
            }
        }

        return $comFileName;
    }

    /**
     * Determine if compiled template needs recompilation
     *
     * Uses multi-factor validation for maximum accuracy:
     * 1. Content hash (MD5 + size) - primary check, cross-platform reliable
     * 2. Mtime fallback - for rapid dev iteration
     * 3. Force flag - for explicit recompile requests
     *
     * @param string $compiledFile Path to compiled template
     * @param string $templateFile Path to source template
     * @param bool $isDev Whether in development mode
     * @param mixed $forceRecompileInDev Force recompile in dev (true/1/'1'/[hash])
     * @return bool True if needs recompilation
     */
    public static function shouldRecompileCompiledTemplate(
        string $compiledFile,
        string $templateFile,
        bool $isDev = false,
        mixed $forceRecompileInDev = false
    ): bool {
        // Factor 1: Compiled file doesn't exist
        if (!file_exists($compiledFile)) {
            return true;
        }

        // Factor 2: Try TemplateCacheManager for content-based validation (fastest path)
        try {
            $cacheManager = TemplateCacheManager::getInstance();
            $cachedFile = $cacheManager->getCachedFile($templateFile, $isDev);
            if ($cachedFile !== null && is_file($cachedFile)) {
                // Cache hit - no recompile needed
                return false;
            }
        } catch (\Throwable) {
            // CacheManager unavailable, fall through to mtime check
        }

        // Factor 3: Content hash validation (precise, cross-platform)
        $currentHash = md5_file($templateFile) . '-' . filesize($templateFile);

        // Check for embedded hash in compiled file (our new format)
        $compiledContent = @file_get_contents($compiledFile);
        if ($compiledContent !== false && str_starts_with($compiledContent, "<?php /* hash:")) {
            // Extract hash from compiled file header
            if (preg_match('/^\<\?php \/\* hash:([a-f0-9]+-\d+) \*\//', $compiledContent, $matches)) {
                $embeddedHash = $matches[1];
                if ($embeddedHash === $currentHash) {
                    // Content hash matches - cache is valid
                    return false;
                }
                // Content changed - needs recompile
                return true;
            }
        }

        // Factor 4: Mtime fallback (for backward compatibility and dev rapid iteration)
        // In dev mode, mtime check allows quick turnaround for file changes
        if (filemtime($compiledFile) < filemtime($templateFile)) {
            return true;
        }

        // Factor 5: Production mode - content hash is authoritative
        if (!$isDev) {
            // Production: mtime passed but content might differ
            // Trust mtime only if we don't have content hash mismatch above
            return false;
        }

        // Factor 6: Explicit force recompile in dev mode
        if ($forceRecompileInDev !== false) {
            // Can be: true, 1, '1', or specific hash string to match
            if ($forceRecompileInDev === true || $forceRecompileInDev === 1 || $forceRecompileInDev === '1') {
                return true;
            }
            // If it's a hash string, only recompile if hash differs
            if (is_string($forceRecompileInDev) && $forceRecompileInDev !== $currentHash) {
                return true;
            }
        }

        return false;
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
        $result = $this->ob_file($comFileName, $dictionary);
        return $result;
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
        // 每次渲染都重新执行 init，确保请求级状态始终绑定当前请求。
        $this->init();
        // WLS swaps the request instance per incoming request. Refresh the legacy
        // `$this->request` reference so older templates using that property stay correct.
        $this->request = ObjectManager::getInstance(Request::class);
        FiberOutputBuffer::beginCapture();
        try {
            if ($dictionary) {
                $this->addData($dictionary);
            }
            // 框架级保障：模板内 $block 永远指向当前 Template 实例。
            // 兼容历史模板（含 view/tpl 编译产物）中的 $block->setTitle()/getBackendUrl() 调用。
            $block = $this;
            $this->setData('block', $this);
            # 将数组存储的变量散列到当前页内存中，使得变量可在页面中暴露出来（可直接使用）
            if ($this->getData()) {
                extract($this->getData(), EXTR_SKIP);
            }
            include $filename;
        } catch (\Exception $exception) {
            FiberOutputBuffer::discardCapture();
            throw $exception;
        }
        /** Get output buffer. */
        $result = FiberOutputBuffer::endCapture();
        return $result;
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

    public function getFrontendUrl(string $path, array $params = [], bool $merge_query = false): string
    {
        return $this->getUrlObject()->getFrontendUrl($path, $params, $merge_query);
    }

    public function getApi(string $path, array $params = [], bool $merge_query = false): string
    {
        return $this->getUrlObject()->getUrl($path, $params, $merge_query);
    }

    public function getBackendUrl(string $path, array|bool $params = [], bool $merge_query = false): string
    {
        return $this->getUrlObject()->getBackendUrl($path, $params, $merge_query);
    }

    /**
     * 后台 URL 的路径部分（不含 scheme/host/port），表单 action / 站内链接应优先使用，避免代理端口与直连端口不一致导致 POST 丢失。
     */
    public function getBackendUrlPath(string $path = '', array $params = [], bool $merge_query = false): string
    {
        return $this->getUrlObject()->getBackendUrlPath($path, $params, $merge_query);
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
        $hooker_content = '';
        
        // 获取hook文件列表（已按顺序排序）
        $hookFiles = [];
        $hookFilesWithMeta = [];
        try {
            /** @var \Weline\Framework\Hook\Config\HookReader $hookReader */
            $hookReader = ObjectManager::make(\Weline\Framework\Hook\Config\HookReader::class);
            $hookReader->setPath($name);
            $hookFiles = $hookReader->getFileList(); // 已按顺序排序
            
            // 获取完整的hook信息（包含solo等元数据）
            $hookFilesWithMeta = $hookReader->getFileListWithMeta();
        } catch (\Throwable $e) {
            // 如果获取失败，尝试使用Hooker（向后兼容）
            /**@var Hooker $hooker */
            $hooker = ObjectManager::getInstance(Hooker::class);
            $hookFiles = $hooker->getHook($name);
        }
        
        // 检查是否有solo（独享）的hook
        $soloHook = null;
        $affectedHooks = [];
        foreach ($hookFilesWithMeta as $module => $meta) {
            if (!empty($meta['solo'])) {
                $soloHook = $module;
                // 收集所有被影响的hook（除了solo的hook本身）
                foreach ($hookFilesWithMeta as $otherModule => $otherMeta) {
                    if ($otherModule !== $module) {
                        $affectedHooks[] = $otherModule;
                    }
                }
                break; // 只允许一个solo hook
            }
        }
        
        // 如果存在solo hook，只执行solo hook
        if ($soloHook !== null) {
            $hookFiles = [$soloHook => $hookFiles[$soloHook]];
        }
        
        // 按顺序遍历hook文件（HookReader已按优先级和排序顺序排序）
        foreach ($hookFiles as $module => $hooker_file) {
            // 获取hook文件路径（用于属性备注）
            $hookFilePath = $hookFiles[$module] ?? $hooker_file;
            // 提取相对路径
            if (strpos($hookFilePath, BP) === 0) {
                $relativePath = str_replace(BP, '', $hookFilePath);
                $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
            } else {
                // 如果已经是相对路径格式（Module::path），直接使用
                $relativePath = str_replace($module . '::', '', $hookFilePath);
            }
            
            // HookReader 已经返回正确格式：ModuleName::hooks/path/to/file.phtml
            // 直接使用，不需要再次构建路径
            $hookHtml = $this->fetchTagHtml('hooks', $hooker_file);
            
            // 检查是否是solo hook
            $isSolo = ($soloHook === $module);
            $hookMeta = $hookFilesWithMeta[$module] ?? [];
            
            if (DEV) {
                // 开发环境：添加注释和data-hook-source属性
                // 如果hook内容不是空字符串，用span包裹并添加属性
                if (trim($hookHtml) !== '') {
                    // 构建data属性
                    $dataAttrs = 'data-hook-source="' . htmlspecialchars($module, ENT_QUOTES, 'UTF-8') . '"';
                    $dataAttrs .= ' data-hook-file="' . htmlspecialchars($relativePath, ENT_QUOTES, 'UTF-8') . '"';
                    
                    // 如果是solo hook，添加solo相关属性
                    if ($isSolo) {
                        $dataAttrs .= ' data-hook-solo="true"';
                        if (!empty($affectedHooks)) {
                            $dataAttrs .= ' data-hook-affected="' . htmlspecialchars(implode(',', $affectedHooks), ENT_QUOTES, 'UTF-8') . '"';
                        }
                    }
                    
                    // 检查hook内容是否已经是完整的HTML元素（有开始和结束标签）
                    // 如果是，尝试在第一个元素上添加属性
                    // 注意：如果Hook内容包含script或style标签，不能用span包裹，否则script/style会被当作文本
                    $hasScriptOrStyle = preg_match('/<(script|style)[^>]*>/i', $hookHtml);
                    if (preg_match('/^<([a-zA-Z][a-zA-Z0-9]*)[^>]*>/', $hookHtml, $matches)) {
                        // 找到第一个标签，添加data属性
                        $tagName = $matches[1];
                        $hookHtml = preg_replace(
                            '/^(<' . preg_quote($tagName, '/') . '[^>]*)(>)/',
                            '$1 ' . $dataAttrs . '$2',
                            $hookHtml,
                            1
                        );
                    } elseif ($hasScriptOrStyle) {
                        // 如果包含script或style标签，即使没有找到第一个标签，也不要用span包裹
                        // 直接在内容前添加一个隐藏的span来标记Hook来源
                        $hookHtml = '<!-- Hook source: ' . htmlspecialchars($module, ENT_QUOTES, 'UTF-8') . ' -->' . $hookHtml;
                    } else {
                        // 如果没有找到标签，用span包裹
                        $hookHtml = '<span ' . $dataAttrs . '>' . $hookHtml . '</span>';
                    }
                }
                
                // 添加注释
                $soloInfo = $isSolo ? ' | 独享模式（已禁用其他' . count($affectedHooks) . '个hook）' : '';
                $content = "<!-- Hook: {$name} | 模块: {$module} | 文件: {$relativePath}{$soloInfo} -->\n" . 
                          $hookHtml . 
                          "\n<!-- /Hook: {$name} | 模块: {$module} -->";
            } else {
                // 生产环境：只添加data-hook-source属性（不添加注释）
                if (trim($hookHtml) !== '') {
                    // 构建data属性
                    $dataAttrs = 'data-hook-source="' . htmlspecialchars($module, ENT_QUOTES, 'UTF-8') . '"';
                    
                    // 如果是solo hook，添加solo相关属性
                    if ($isSolo) {
                        $dataAttrs .= ' data-hook-solo="true"';
                        if (!empty($affectedHooks)) {
                            $dataAttrs .= ' data-hook-affected="' . htmlspecialchars(implode(',', $affectedHooks), ENT_QUOTES, 'UTF-8') . '"';
                        }
                    }
                    
                    // 检查hook内容是否已经是完整的HTML元素
                    // 注意：如果Hook内容包含script或style标签，不能用span包裹，否则script/style会被当作文本
                    $hasScriptOrStyle = preg_match('/<(script|style)[^>]*>/i', $hookHtml);
                    if (preg_match('/^<([a-zA-Z][a-zA-Z0-9]*)[^>]*>/', $hookHtml, $matches)) {
                        // 找到第一个标签，添加data属性
                        $tagName = $matches[1];
                        $hookHtml = preg_replace(
                            '/^(<' . preg_quote($tagName, '/') . '[^>]*)(>)/',
                            '$1 ' . $dataAttrs . '$2',
                            $hookHtml,
                            1
                        );
                    } elseif ($hasScriptOrStyle) {
                        // 如果包含script或style标签，即使没有找到第一个标签，也不要用span包裹
                        // 直接在内容前添加一个隐藏的span来标记Hook来源
                        $hookHtml = '<!-- Hook source: ' . htmlspecialchars($module, ENT_QUOTES, 'UTF-8') . ' -->' . $hookHtml;
                    } else {
                        // 如果没有找到标签，用span包裹
                        $hookHtml = '<span ' . $dataAttrs . '>' . $hookHtml . '</span>';
                    }
                }
                $content = $hookHtml;
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
