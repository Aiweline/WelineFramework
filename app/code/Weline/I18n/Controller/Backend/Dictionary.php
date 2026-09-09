<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/1/2 14:14:05
 */

namespace Weline\I18n\Controller\Backend;

use Weline\Framework\App\Debug;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Phrase\DictionaryCompiler;
use Weline\Framework\Phrase\DictionaryEvents;
use Weline\Framework\Phrase\Parser as PhraseParser;
use Weline\I18n\Model\I18n;
use Weline\I18n\Model\Locale;
use Weline\Framework\Manager\Message;
use Weline\I18n\Model\Locale\Dictionary as LocaleDictionary;
use Weline\I18n\Model\Dictionary as WordDictionary;
use Weline\I18n\Parser as I18nParser;
use Weline\I18n\Service\AiTranslationQueueService;
use Weline\I18n\Service\DictionaryCollectService;
use Weline\I18n\Service\DictionaryModuleCatalogService;
use Weline\I18n\Service\RuntimeCacheBroadcaster;
use Weline\Framework\Http\Sse\SseWriter;
use Weline\Framework\Security\Token;

class Dictionary extends BaseController
{
    private \Weline\I18n\Model\Dictionary $dictionary;
    private LocaleDictionary $localeDictionary;
    private AiTranslationQueueService $aiTranslationQueueService;
    private DictionaryCollectService $dictionaryCollectService;
    private DictionaryModuleCatalogService $moduleCatalogService;

    function __construct(
        Locale $locale,
        I18n $i18n,
        \Weline\I18n\Model\Dictionary $dictionary,
        LocaleDictionary $localeDictionary,
        AiTranslationQueueService $aiTranslationQueueService,
        DictionaryCollectService $dictionaryCollectService,
        DictionaryModuleCatalogService $moduleCatalogService,
    )
    {
        parent::__construct($locale, $i18n);
        $this->dictionary = $dictionary;
        $this->localeDictionary = $localeDictionary;
        $this->aiTranslationQueueService = $aiTranslationQueueService;
        $this->dictionaryCollectService = $dictionaryCollectService;
        $this->moduleCatalogService = $moduleCatalogService;
    }

    function get()
    {
        // 获取参数
        $localeCode = $this->request->getParam('locale_code', \Weline\Framework\Http\Cookie::getLangLocal());
        $search = $this->request->getParam('search', '');
        $moduleFilter = trim((string)$this->request->getParam('module', ''));
        $activeTab = trim((string)$this->request->getParam('tab', 'dictionary'));
        $moduleSearch = trim((string)$this->request->getParam('module_search', ''));
        $page = (int)$this->request->getParam('page', 1);
        $pageSize = (int)$this->request->getParam('page_size', 20);
        
        // 检查是否有真实数据收集的请求
        $collect = $this->request->getParam('collect', false);
        if ($collect) {
            $this->collectRealTranslationData($localeCode);
        }
        
        // 使用简单查询，避免 COALESCE 函数导致的 PostgreSQL 解析问题
        // 先查询词典表，再单独获取翻译数据
        $query = $this->dictionary->reset();
        
        // 搜索功能
        if ($search) {
            $search = addslashes($search);
            $query->where($this->dictionary::schema_fields_WORD, '%' . $search . '%', 'like');
        }
        if ($moduleFilter !== '') {
            $query->where($this->dictionary::schema_fields_MODULE, $moduleFilter);
        }
        
        // 使用框架分页功能
        $query->pagination($page, $pageSize);
        
        // 执行查询获取词典数据
        $dictionaryResult = $query->select()->fetch();
        
        // 获取所有词汇
        $words = [];
        foreach ($dictionaryResult->getItems() as $item) {
            $words[] = $item->getData($this->dictionary::schema_fields_WORD);
        }
        
        // 查询对应的翻译数据
        $translations = [];
        if (!empty($words)) {
            $translationData = $this->localeDictionary->reset()
                ->where($this->localeDictionary::schema_fields_LOCALE_CODE, $localeCode)
                ->where($this->localeDictionary::schema_fields_WORD, $words, 'in')
                ->select()
                ->fetchArray();
            
            foreach ($translationData as $t) {
                $translations[$t[$this->localeDictionary::schema_fields_WORD]] = $t;
            }
        }
        
        // 组合数据
        $allTranslations = $dictionaryResult;
        $combinedItems = [];
        foreach ($dictionaryResult->getItems() as $item) {
            $word = $item->getData($this->dictionary::schema_fields_WORD);
            $t = $translations[$word] ?? null;
            $combinedItems[] = [
                'word' => $word,
                'module' => (string)$item->getData($this->dictionary::schema_fields_MODULE),
                'translate' => $t[$this->localeDictionary::schema_fields_TRANSLATE] ?? $word,
                'locale_code' => $t[$this->localeDictionary::schema_fields_LOCALE_CODE] ?? $localeCode,
                'md5' => $t[$this->localeDictionary::schema_fields_MD5] ?? null,
                'update_time' => $t['update_time'] ?? 0
            ];
        }
        
        // 获取分页信息
        $pagination = $allTranslations->getPagination();
        
        // 计算翻译进度
        $progressStats = $this->getTranslationProgress($localeCode, $moduleFilter);
        
        // 获取可用的区域列表
        $availableLocales = $this->getAvailableLocales();
        $availableModules = $this->getAvailableModules();
        
        // 格式化时间显示
        $formattedItems = [];
        foreach ($combinedItems as $item) {
            $item['formatted_update_time'] = $this->formatUpdateTime($item['update_time'] ?? 0);
            $formattedItems[] = $item;
        }
        
        // 分配数据
        $this->assign('translations', $formattedItems);
        $this->assign('pagination', $pagination);
        $this->assign('locale_code', $localeCode);
        $this->assign('search', $search);
        $this->assign('module_filter', $moduleFilter);
        $this->assign('available_modules', $availableModules);
        $this->assign('available_locales', $availableLocales);
        $this->assign('progress_stats', $progressStats);
        $this->assign('active_tab', $activeTab === 'modules' ? 'modules' : 'dictionary');
        $this->assign('module_search', $moduleSearch);
        $this->assign('module_catalog', $this->moduleCatalogService->listModules($localeCode, $moduleSearch));
        $this->assign('module_export_dev', $this->moduleCatalogService->isDevEnvironment());
        
        // 显示提示信息
        // 如果没有数据，显示提示信息（通过模板变量传递，不直接输出Message）
        if (empty($formattedItems)) {
            $this->assign('showNoDataWarning', true);
        }
        
        return $this->fetch();
    }
    
    /**
     * 收集真实翻译数据
     */
    private function collectRealTranslationData($localeCode)
    {
        try {
            // 调用I18n模型收集框架中的真实词汇
            $this->i18n->convertToLanguageFile(false);
            
            // 从i18n_dictionary表获取收集到的词汇
            $words = $this->i18n->getLocalsWords(false);
            $collectedCount = 0;
            $insertData = [];
            
            foreach ($words as $local_code => $local_words) {
                foreach ($local_words as $word => $translate) {
                    $md5 = $this->localeDictionary->getMd5($word, $localeCode);
                    $insertData[] = [
                        $this->localeDictionary::schema_fields_MD5 => $md5,
                        $this->localeDictionary::schema_fields_WORD => $word,
                        $this->localeDictionary::schema_fields_LOCALE_CODE => $localeCode,
                        $this->localeDictionary::schema_fields_TRANSLATE => $translate
                    ];
                }
                # 落库
                $this->localeDictionary->beginTransaction();
                try {
                    $this->localeDictionary->insert($insertData, $this->localeDictionary::schema_fields_MD5)->fetch();
                    $this->localeDictionary->commit();
                } catch (\Exception $e) {
                    $this->localeDictionary->rollBack();
                    throw $e;
                }
            }
            
        } catch (\Exception $e) {
            // 静默处理错误，不输出Message
        }
    }
    
    /**
     * 导出翻译包
     */
    public function getExportTranslations()
    {
        $localeCode = $this->request->getParam('locale_code');
        
        if (!$localeCode) {
            Message::error(__('请指定要导出的语言代码'));
            $this->redirect('*/backend/dictionary');
            return;
        }
        
        try {
            // 获取该语言的所有翻译数据
            $translations = $this->localeDictionary->reset()
                ->where($this->localeDictionary::schema_fields_LOCALE_CODE, $localeCode)
                ->where($this->localeDictionary::schema_fields_TRANSLATE, '', '!=') // 只导出已翻译的
                ->select()
                ->fetch()
                ->getItems();
            
            if (empty($translations)) {
                Message::warning(__('没有找到可导出的翻译数据'));
                $this->redirect('*/backend/dictionary?locale_code=' . $localeCode);
                return;
            }
            
            // 构建翻译数组
            $translationArray = [];
            foreach ($translations as $translation) {
                $word = $translation->getData($this->localeDictionary::schema_fields_WORD);
                $translate = $translation->getData($this->localeDictionary::schema_fields_TRANSLATE);
                $translationArray[$word] = $translate;
            }
            
            // 生成PHP翻译文件内容
            $content = "<?php\n\n";
            $content .= "/**\n";
            $content .= " * Language Pack for {$localeCode}\n";
            $content .= " * Generated on: " . date('Y-m-d H:i:s') . "\n";
            $content .= " * Total translations: " . count($translationArray) . "\n";
            $content .= " */\n\n";
            $content .= "return " . var_export($translationArray, true) . ";\n";
            
            // 设置下载头
            $localeName = $this->getLocaleName($localeCode);
            $filename = "translation_{$localeCode}_{$localeName}_" . date('YmdHis') . ".php";
            
            $this->request->getResponse()->setHeader('Content-Type', 'application/octet-stream');
            $this->request->getResponse()->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
            $this->request->getResponse()->setHeader('Content-Length', strlen($content));
            
            echo $content;
            exit;
            
        } catch (\Exception $e) {
            Message::error(__('导出翻译包失败: %{1}', $e->getMessage()));
            $this->redirect('*/backend/dictionary?locale_code=' . $localeCode);
        }
    }

    /**
     * 获取示例翻译数据 (已废弃，改为真实数据收集)
     */
    private function getSampleTranslations($localeCode)
    {
        $translations = [
            'zh_Hans_CN' => [
                'Hello' => '你好',
                'World' => '世界',
                'Welcome' => '欢迎',
                'Thank you' => '谢谢',
                'Good morning' => '早上好',
                'Good afternoon' => '下午好',
                'Good evening' => '晚上好',
                'Goodbye' => '再见',
                'Please' => '请',
                'Sorry' => '对不起',
                'Yes' => '是',
                'No' => '否',
                'Home' => '首页',
                'Settings' => '设置',
                'Save' => '保存'
            ],
            'en_US' => [
                'Hello' => 'Hello',
                'World' => 'World',
                'Welcome' => 'Welcome',
                'Thank you' => 'Thank you',
                'Good morning' => 'Good morning',
                'Good afternoon' => 'Good afternoon',
                'Good evening' => 'Good evening',
                'Goodbye' => 'Goodbye',
                'Please' => 'Please',
                'Sorry' => 'Sorry',
                'Yes' => 'Yes',
                'No' => 'No',
                'Home' => 'Home',
                'Settings' => 'Settings',
                'Save' => 'Save'
            ],
            'ja_JP' => [
                'Hello' => 'こんにちは',
                'World' => '世界',
                'Welcome' => 'いらっしゃいませ',
                'Thank you' => 'ありがとうございます',
                'Good morning' => 'おはようございます',
                'Good afternoon' => 'こんにちは',
                'Good evening' => 'こんばんは',
                'Goodbye' => 'さようなら',
                'Please' => 'お願いします',
                'Sorry' => 'すみません',
                'Yes' => 'はい',
                'No' => 'いいえ',
                'Home' => 'ホーム',
                'Settings' => '設定',
                'Save' => '保存'
            ]
        ];
        
        return $translations[$localeCode] ?? $translations['en_US'];
    }
    
    /**
     * 获取词典中已登记的模块列表（用于筛选与按模块批量翻译）。
     *
     * @return list<string>
     */
    private function getAvailableModules(): array
    {
        try {
            $rows = $this->dictionary->reset()
                ->fields($this->dictionary::schema_fields_MODULE)
                ->where($this->dictionary::schema_fields_MODULE, '', '!=')
                ->group($this->dictionary::schema_fields_MODULE)
                ->select()
                ->fetchArray();

            $modules = [];
            foreach ($rows as $row) {
                $module = trim((string)($row[$this->dictionary::schema_fields_MODULE] ?? ''));
                if ($module !== '') {
                    $modules[] = $module;
                }
            }

            sort($modules, SORT_STRING);

            return $modules;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * 获取可用的区域列表
     */
    private function getAvailableLocales()
    {
        try {
            $locales = $this->locale->reset()
                ->where($this->locale::schema_fields_IS_INSTALL, 1)
                ->select()
                ->fetch()
                ->getItems();
            
            $availableLocales = [];
            foreach ($locales as $locale) {
                $code = $locale[$this->locale::schema_fields_CODE];
                $name = $this->getLocaleName($code);
                $availableLocales[$code] = [
                    'name' => $name,
                    'code' => $code,
                    'display' => $name . ' (' . $code . ')'
                ];
            }
            
            return $availableLocales;
        } catch (\Exception $e) {
            return [];
        }
    }
    
    /**
     * 获取区域名称
     */
    private function getLocaleName($localeCode)
    {
        try {
            // 使用输出缓冲确保不会产生任何输出
            ob_start();
            $result = $this->i18n->getLocaleName($localeCode, \Weline\Framework\Http\Cookie::getLangLocal());
            ob_end_clean();
            return $result;
        } catch (\Exception $e) {
            return $localeCode;
        }
    }

    function getDelete()
    {
        $isAsyncRequest = $this->isAsyncRequest();
        $md5 = $this->request->getGet('md5');
        if (!$md5) {
            if ($isAsyncRequest) {
                return $this->asyncJsonResponse(false, (string)__('缺少必要的参数'));
            }
            Message::error(__('缺少必要的参数'));
            $this->redirect($this->request->getReferer());
            return;
        }
        
        $this->localeDictionary->beginTransaction();
        try {
            $this->localeDictionary->load($md5)->delete();
            $this->localeDictionary->commit();
            if ($isAsyncRequest) {
                return $this->asyncJsonResponse(true, (string)__('翻译删除成功！'), ['md5' => $md5]);
            }
            Message::success(__('翻译删除成功！'));
        } catch (\Throwable $exception) {
            $this->localeDictionary->rollBack();
            if ($isAsyncRequest) {
                return $this->asyncJsonResponse(false, (string)__('删除失败: %{1}', $exception->getMessage()));
            }
            Message::error(__('删除失败: %{1}', $exception->getMessage()));
        }
        $this->redirect($this->request->getReferer());
    }

    public function postClearLocale()
    {
        $localeCode = trim((string)$this->request->getParam('locale_code', ''));
        if ($localeCode === '') {
            return $this->fetchJson([
                'success' => false,
                'message' => __('缺少目标语言参数')
            ]);
        }

        try {
            $count = (int)$this->localeDictionary->reset()
                ->where($this->localeDictionary::schema_fields_LOCALE_CODE, $localeCode)
                ->count();

            if ($count > 0) {
                $this->localeDictionary->beginTransaction();
                try {
                    $this->localeDictionary->reset()
                        ->where($this->localeDictionary::schema_fields_LOCALE_CODE, $localeCode)
                        ->delete()
                        ->fetch();
                    $this->localeDictionary->commit();
                } catch (\Throwable $throwable) {
                    $this->localeDictionary->rollBack();
                    throw $throwable;
                }
            }

            $this->clearRuntimeTranslationCaches();

            return $this->fetchJson([
                'success' => true,
                'count' => $count,
                'message' => __('已清理目标语言 %{1} 的译文：%{2} 条', [$localeCode, $count])
            ]);
        } catch (\Throwable $throwable) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('清理目标语言失败: %{1}', $throwable->getMessage())
            ]);
        }
    }

    public function postClearAll()
    {
        try {
            $localeCount = (int)$this->localeDictionary->reset()->count();
            $wordCount = (int)$this->dictionary->reset()->count();

            if ($localeCount > 0 || $wordCount > 0) {
                $this->dictionary->beginTransaction();
                try {
                    if ($localeCount > 0) {
                        $this->localeDictionary->reset()->delete()->fetch();
                    }
                    if ($wordCount > 0) {
                        $this->dictionary->reset()->delete()->fetch();
                    }
                    $this->dictionary->commit();
                } catch (\Throwable $throwable) {
                    $this->dictionary->rollBack();
                    throw $throwable;
                }
            }

            $this->clearRuntimeTranslationCaches();

            return $this->fetchJson([
                'success' => true,
                'word_count' => $wordCount,
                'locale_count' => $localeCount,
                'message' => __('已清理全部词典：词条 %{1} 条，译文 %{2} 条', [$wordCount, $localeCount])
            ]);
        } catch (\Throwable $throwable) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('清理全部词典失败: %{1}', $throwable->getMessage())
            ]);
        }
    }
    
    /**
     * 添加翻译
     */
    function postAdd()
    {
        $isAsyncRequest = $this->isAsyncRequest();
        $word = $this->request->getPost('word');
        $localeCode = $this->request->getPost('locale_code');
        $translate = $this->request->getPost('translate');
        
        if (!$word || !$localeCode || !$translate) {
            if ($isAsyncRequest) {
                return $this->asyncJsonResponse(false, (string)__('请填写完整的翻译信息'));
            }
            Message::error(__('请填写完整的翻译信息'));
            $this->redirect($this->request->getReferer());
            return;
        }
        
        try {
            $md5 = $this->localeDictionary->getMd5($word, $localeCode);
            $this->localeDictionary->setData([
                $this->localeDictionary::schema_fields_MD5 => $md5,
                $this->localeDictionary::schema_fields_WORD => $word,
                $this->localeDictionary::schema_fields_LOCALE_CODE => $localeCode,
                $this->localeDictionary::schema_fields_TRANSLATE => $translate
            ])->save();
            
            if ($isAsyncRequest) {
                return $this->asyncJsonResponse(true, (string)__('翻译添加成功！'), [
                    'locale_code' => $localeCode,
                    'word' => $word,
                ]);
            }
            Message::success(__('翻译添加成功！'));
        } catch (\Throwable $exception) {
            if ($isAsyncRequest) {
                return $this->asyncJsonResponse(false, (string)__('添加失败: %{1}', $exception->getMessage()));
            }
            Message::error(__('添加失败: %{1}', $exception->getMessage()));
        }
        
        $this->redirect($this->request->getReferer());
    }
    
    /**
     * 编辑翻译
     */
    function postEdit()
    {
        $isAsyncRequest = $this->isAsyncRequest();
        $md5 = $this->request->getPost('md5');
        $translate = $this->request->getPost('translate');
        
        if (!$md5 || !$translate) {
            if ($isAsyncRequest) {
                return $this->asyncJsonResponse(false, (string)__('请填写完整的翻译信息'));
            }
            Message::error(__('请填写完整的翻译信息'));
            $this->redirect($this->request->getReferer());
            return;
        }
        
        try {
            $this->localeDictionary->load($md5);
            if (!$this->localeDictionary->getId()) {
                if ($isAsyncRequest) {
                    return $this->asyncJsonResponse(false, (string)__('翻译不存在'));
                }
                Message::error(__('翻译不存在'));
                $this->redirect($this->request->getReferer());
                return;
            }
            
            $this->localeDictionary->setData($this->localeDictionary::schema_fields_TRANSLATE, $translate)->save();
            if ($isAsyncRequest) {
                return $this->asyncJsonResponse(true, (string)__('翻译更新成功！'), [
                    'md5' => $md5,
                    'translate' => $translate,
                ]);
            }
            Message::success(__('翻译更新成功！'));
        } catch (\Throwable $exception) {
            if ($isAsyncRequest) {
                return $this->asyncJsonResponse(false, (string)__('更新失败: %{1}', $exception->getMessage()));
            }
            Message::error(__('更新失败: %{1}', $exception->getMessage()));
        }
        
        $this->redirect($this->request->getReferer());
    }
    
    /**
     * 创建分页HTML
     */
    private function createPaginationHtml($translations)
    {
        $currentPage = $translations->currentPage;
        $totalPages = $translations->totalPages;
        $total = $translations->total;
        
        if ($totalPages <= 1) {
            return '';
        }
        
        $localeCode = $this->request->getParam('locale_code', '');
        $search = $this->request->getParam('search', '');
        
        $html = '<nav aria-label="Page navigation"><ul class="w-pagination" data-justify="center">';
        
        // 上一页
        if ($currentPage > 1) {
            $prevPage = $currentPage - 1;
            $url = "@backend-url('*/backend/dictionary')?page={$prevPage}&locale_code={$localeCode}&search={$search}";
            $html .= '<li class="w-pagination__item"><a class="w-pagination__link" href="' . $url . '">上一页</a></li>';
        }
        
        // 页码
        $start = max(1, $currentPage - 2);
        $end = min($totalPages, $currentPage + 2);
        
        for ($i = $start; $i <= $end; $i++) {
            if ($i == $currentPage) {
                $html .= '<li class="w-pagination__item" data-state="active"><span class="w-pagination__link">' . $i . '</span></li>';
            } else {
                $url = "@backend-url('*/backend/dictionary')?page={$i}&locale_code={$localeCode}&search={$search}";
                $html .= '<li class="w-pagination__item"><a class="w-pagination__link" href="' . $url . '">' . $i . '</a></li>';
            }
        }
        
        // 下一页
        if ($currentPage < $totalPages) {
            $nextPage = $currentPage + 1;
            $url = "@backend-url('*/backend/dictionary')?page={$nextPage}&locale_code={$localeCode}&search={$search}";
            $html .= '<li class="w-pagination__item"><a class="w-pagination__link" href="' . $url . '">下一页</a></li>';
        }
        
        $html .= '</ul></nav>';
        
        return $html;
    }
    
    /**
     * 导出CSV文件
     */
    public function exportCsv()
    {
        $localeCode = $this->request->getParam('locale_code');
        
        if (!$localeCode) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('请选择要导出的语言')
            ]);
        }
        
        try {
            // 获取该语言的所有翻译数据
            $query = $this->localeDictionary->reset();
            $query->where($this->localeDictionary::schema_fields_LOCALE_CODE, $localeCode);
            $translations = $query->select()->fetch();
            
            if (empty($translations->getItems())) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('该语言没有翻译数据')
                ]);
            }
            
            // 设置响应头
            $this->request->getResponse()->setHeader('Content-Type', 'text/csv; charset=utf-8');
            $this->request->getResponse()->setHeader('Content-Disposition', 'attachment; filename="' . $localeCode . '.csv"');
            
            // 输出CSV内容
            echo "\xEF\xBB\xBF"; // UTF-8 BOM
            echo "word,translate,locale_code,update_time\n";
            
            foreach ($translations->getItems() as $item) {
                $word = str_replace('"', '""', $item['word'] ?? '');
                $translate = str_replace('"', '""', $item['translate'] ?? '');
                $localeCode = $item['locale_code'] ?? '';
                $updateTime = $item['update_time'] ?? '';
                
                echo "\"$word\",\"$translate\",\"$localeCode\",\"$updateTime\"\n";
            }
            
            return;
            
        } catch (\Exception $exception) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('导出失败: %{1}', $exception->getMessage())
            ]);
        }
    }
    
    /**
     * 导入CSV文件
     */
    public function importCsv()
    {
        try {
            $uploadedFile = $this->request->getFiles('csv_file');
            
            if (!$uploadedFile || !isset($uploadedFile['tmp_name']) || !is_uploaded_file($uploadedFile['tmp_name'])) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('请选择要导入的CSV文件')
                ]);
            }
            
            // 验证文件名是否为有效的locale_code
            $fileName = pathinfo($uploadedFile['name'], PATHINFO_FILENAME);
            $localeCode = $fileName;
            
            // 验证locale_code是否有效
            if (!$this->isValidLocaleCode($localeCode)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('文件名必须是有效的locale_code，如：zh_Hans_CN、en_US等')
                ]);
            }
            
            // 读取CSV文件
            $csvData = [];
            if (($handle = fopen($uploadedFile['tmp_name'], 'r')) !== FALSE) {
                // 跳过BOM
                $firstLine = fgets($handle);
                if (substr($firstLine, 0, 3) === "\xEF\xBB\xBF") {
                    $firstLine = substr($firstLine, 3);
                }
                
                // 处理标题行
                $headers = str_getcsv($firstLine);
                
                // 读取数据行
                while (($data = fgetcsv($handle)) !== FALSE) {
                    if (count($data) >= 2) { // 至少需要word和translate列
                        $csvData[] = [
                            'word' => $data[0] ?? '',
                            'translate' => $data[1] ?? '',
                            'locale_code' => $localeCode,
                            'update_time' => time()
                        ];
                    }
                }
                fclose($handle);
            }
            
            if (empty($csvData)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('CSV文件为空或格式不正确')
                ]);
            }
            
            // 批量插入或更新翻译数据
            $successCount = 0;
            $updateCount = 0;
            
            foreach ($csvData as $item) {
                if (empty($item['word']) || empty($item['translate'])) {
                    continue;
                }
                
                // 检查是否已存在
                $existing = $this->localeDictionary->reset()
                    ->where($this->localeDictionary::schema_fields_WORD, $item['word'])
                    ->where($this->localeDictionary::schema_fields_LOCALE_CODE, $localeCode)
                    ->select()
                    ->fetch();
                
                if ($existing->getItems()) {
                    // 更新现有记录
                    $existing->getItems()[0]->setData($this->localeDictionary::schema_fields_TRANSLATE, $item['translate'])
                        ->setData($this->localeDictionary::schema_fields_UPDATE_TIME, $item['update_time'])
                        ->save();
                    $updateCount++;
                } else {
                    // 插入新记录
                    $this->localeDictionary->reset()
                        ->setData($this->localeDictionary::schema_fields_MD5, $this->localeDictionary->getMd5($item['word'], $localeCode))
                        ->setData($this->localeDictionary::schema_fields_WORD, $item['word'])
                        ->setData($this->localeDictionary::schema_fields_TRANSLATE, $item['translate'])
                        ->setData($this->localeDictionary::schema_fields_LOCALE_CODE, $localeCode)
                        ->setData($this->localeDictionary::schema_fields_UPDATE_TIME, $item['update_time'])
                        ->save();
                    $successCount++;
                }
            }
            
            return $this->fetchJson([
                'success' => true,
                'message' => __('导入成功！新增 %{1} 条，更新 %{2} 条', [$successCount, $updateCount]),
                'data' => [
                    'new_count' => $successCount,
                    'update_count' => $updateCount,
                    'total_count' => $successCount + $updateCount,
                    'queue_count' => count($queued)
                ]
            ]);
            
        } catch (\Exception $exception) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('导入失败: %{1}', $exception->getMessage())
            ]);
        }
    }

    /**
     * 通过 bin-query 接收文本化 CSV，避免文件上传动作回退到页面提交。
     * 文件下载仍使用原生下载响应；CSV 导入属于写操作，必须等待 bin-query
     * 成功后再更新页面提示和进度。
     */
    public function postImportCsvContent(): string
    {
        $fileName = trim((string)$this->request->getPost('file_name', ''));
        $content = (string)$this->request->getPost('csv_content', '');
        if ($fileName === '' || $content === '') {
            return $this->fetchJson([
                'success' => false,
                'message' => __('请选择要导入的CSV文件')
            ]);
        }

        $localeCode = pathinfo($fileName, PATHINFO_FILENAME);
        if (!$this->isValidLocaleCode($localeCode)) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('文件名必须是有效的locale_code，如：zh_Hans_CN、en_US等')
            ]);
        }

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('无法读取CSV文件')
            ]);
        }

        fwrite($handle, $content);
        rewind($handle);
        $firstLine = fgets($handle);
        if ($firstLine !== false && substr($firstLine, 0, 3) === "\xEF\xBB\xBF") {
            $firstLine = substr($firstLine, 3);
        }
        if ($firstLine === false) {
            fclose($handle);
            return $this->fetchJson([
                'success' => false,
                'message' => __('CSV文件为空或格式不正确')
            ]);
        }

        // 消费标题行；后续数据列仍按标准 CSV 规则读取。
        str_getcsv($firstLine);
        $csvData = [];
        while (($data = fgetcsv($handle)) !== false) {
            if (count($data) >= 2) {
                $csvData[] = [
                    'word' => $data[0] ?? '',
                    'translate' => $data[1] ?? '',
                    'locale_code' => $localeCode,
                    'update_time' => time()
                ];
            }
        }
        fclose($handle);

        if (empty($csvData)) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('CSV文件为空或格式不正确')
            ]);
        }

        $successCount = 0;
        $updateCount = 0;
        foreach ($csvData as $item) {
            if (empty($item['word']) || empty($item['translate'])) {
                continue;
            }

            $existing = $this->localeDictionary->reset()
                ->where($this->localeDictionary::schema_fields_WORD, $item['word'])
                ->where($this->localeDictionary::schema_fields_LOCALE_CODE, $localeCode)
                ->select()
                ->fetch();

            if ($existing->getItems()) {
                $existing->getItems()[0]
                    ->setData($this->localeDictionary::schema_fields_TRANSLATE, $item['translate'])
                    ->setData($this->localeDictionary::schema_fields_UPDATE_TIME, $item['update_time'])
                    ->save();
                $updateCount++;
                continue;
            }

            $this->localeDictionary->reset()
                ->setData($this->localeDictionary::schema_fields_MD5, $this->localeDictionary->getMd5($item['word'], $localeCode))
                ->setData($this->localeDictionary::schema_fields_WORD, $item['word'])
                ->setData($this->localeDictionary::schema_fields_TRANSLATE, $item['translate'])
                ->setData($this->localeDictionary::schema_fields_LOCALE_CODE, $localeCode)
                ->setData($this->localeDictionary::schema_fields_UPDATE_TIME, $item['update_time'])
                ->save();
            $successCount++;
        }

        return $this->fetchJson([
            'success' => true,
            'message' => __('导入成功！新增 %{1} 条，更新 %{2} 条', [$successCount, $updateCount]),
            'data' => [
                'new_count' => $successCount,
                'update_count' => $updateCount,
                'total_count' => $successCount + $updateCount,
            ]
        ]);
    }
    
    /**
     * 验证locale_code是否有效
     */
    private function isValidLocaleCode($localeCode)
    {
        // 获取所有可用的locale_code
        $availableLocales = $this->getAvailableLocales();
        return in_array($localeCode, array_column($availableLocales, 'code'), true);
    }

    /**
     * 格式化更新时间显示
     */
    private function formatUpdateTime($timestamp)
    {
        // 确保 $timestamp 是有效的数字
        if (!$timestamp || !is_numeric($timestamp) || $timestamp == 0) {
            return '从未更新';
        }
        
        // 转换为整数
        $timestamp = (int)$timestamp;
        
        $now = time();
        $diff = $now - $timestamp;
        
        // 小于1分钟
        if ($diff < 60) {
            return '刚刚';
        }
        
        // 小于1小时
        if ($diff < 3600) {
            $minutes = floor($diff / 60);
            return $minutes . '分钟前';
        }
        
        // 小于1天
        if ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . '小时前';
        }
        
        // 小于1周
        if ($diff < 604800) {
            $days = floor($diff / 86400);
            return $days . '天前';
        }
        
        // 小于1个月
        if ($diff < 2592000) {
            $weeks = floor($diff / 604800);
            return $weeks . '周前';
        }
        
        // 小于1年
        if ($diff < 31536000) {
            $months = floor($diff / 2592000);
            return $months . '个月前';
        }
        
        // 超过1年
        $years = floor($diff / 31536000);
        return $years . '年前';
    }

    /**
     * 获取翻译进度统计
     */
    private function getTranslationProgress(string $localeCode, string $moduleFilter = ''): array
    {
        if ($localeCode === '') {
            return [
                'total' => 0,
                'translated' => 0,
                'untranslated' => 0,
                'progress_percent' => 0,
            ];
        }

        try {
            $moduleFilter = trim($moduleFilter);

            $baseQuery = $this->dictionary->reset();
            if ($moduleFilter !== '') {
                $baseQuery->where($this->dictionary::schema_fields_MODULE, $moduleFilter);
            }
            $baseTotal = (int)$baseQuery->count();

            $localeTotal = $this->countLocaleDictionaryEntries($localeCode, $moduleFilter);
            $total = max($baseTotal, $localeTotal);

            $translated = $this->countLocaleTranslatedEntries($localeCode, $moduleFilter);
            $untranslated = max(0, $total - $translated);
            $progressPercent = $total > 0 ? round(($translated / $total) * 100, 1) : 0;

            return [
                'total' => $total,
                'translated' => $translated,
                'untranslated' => $untranslated,
                'progress_percent' => $progressPercent,
            ];
        } catch (\Throwable $e) {
            w_log_error('I18n dictionary progress stats failed: ' . $e->getMessage(), [
                'locale_code' => $localeCode,
                'module_filter' => $moduleFilter,
            ], 'i18n');
            return [
                'total' => 0,
                'translated' => 0,
                'untranslated' => 0,
                'progress_percent' => 0,
            ];
        }
    }

    /**
     * 统计目标语言下的 locale 词典条目数。
     */
    private function countLocaleDictionaryEntries(string $localeCode, string $moduleFilter = ''): int
    {
        $query = $this->localeDictionary->reset()
            ->where($this->localeDictionary::schema_fields_LOCALE_CODE, $localeCode);
        $this->applyModuleScopeToLocaleQuery($query, $moduleFilter);

        return (int)$query->count();
    }

    /**
     * 统计目标语言下已真正翻译的条目数（译文非空且不等于原文）。
     */
    private function countLocaleTranslatedEntries(string $localeCode, string $moduleFilter = ''): int
    {
        $table = $this->localeDictionary->getTable();
        $localeField = $this->localeDictionary::schema_fields_LOCALE_CODE;
        $translateField = $this->localeDictionary::schema_fields_TRANSLATE;
        $wordField = $this->localeDictionary::schema_fields_WORD;
        $localeCodeSql = str_replace("'", "''", $localeCode);

        $conditions = [
            "{$localeField} = '{$localeCodeSql}'",
            "{$translateField} IS NOT NULL",
            "{$translateField} <> ''",
            "{$translateField} <> {$wordField}",
        ];

        $moduleCondition = $this->buildModuleScopeSqlCondition($moduleFilter);
        if ($moduleCondition !== '') {
            $conditions[] = $moduleCondition;
        }

        $sql = 'SELECT COUNT(*) AS translated_count FROM ' . $table . ' WHERE ' . implode(' AND ', $conditions);
        $rows = $this->localeDictionary->reset()->query($sql)->fetchArray();
        $row = is_array($rows[0] ?? null) ? $rows[0] : (is_array($rows) ? $rows : []);

        return (int)($row['translated_count'] ?? 0);
    }

    /**
     * 为 locale 词典查询附加模块范围。
     */
    private function applyModuleScopeToLocaleQuery(\Weline\I18n\Model\Locale\Dictionary $query, string $moduleFilter): void
    {
        $moduleFilter = trim($moduleFilter);
        if ($moduleFilter === '') {
            return;
        }

        $moduleWords = $this->getModuleWords($moduleFilter);
        if ($moduleWords !== []) {
            $query->where($this->localeDictionary::schema_fields_WORD, $moduleWords, 'in');
            return;
        }

        $query->where($this->localeDictionary::schema_fields_SOURCE_MODULE, $moduleFilter);
    }

    /**
     * 构建模块范围 SQL 条件（供聚合统计使用）。
     */
    private function buildModuleScopeSqlCondition(string $moduleFilter): string
    {
        $moduleFilter = trim($moduleFilter);
        if ($moduleFilter === '') {
            return '';
        }

        $moduleWords = $this->getModuleWords($moduleFilter);
        if ($moduleWords !== []) {
            $quotedWords = array_map(
                static fn (string $word): string => "'" . str_replace("'", "''", $word) . "'",
                $moduleWords
            );

            return $this->localeDictionary::schema_fields_WORD . ' IN (' . implode(',', $quotedWords) . ')';
        }

        $moduleSql = str_replace("'", "''", $moduleFilter);

        return $this->localeDictionary::schema_fields_SOURCE_MODULE . " = '{$moduleSql}'";
    }

    /**
     * @return list<string>
     */
    private function getModuleWords(string $moduleFilter): array
    {
        $rows = $this->dictionary->reset()
            ->fields($this->dictionary::schema_fields_WORD)
            ->where($this->dictionary::schema_fields_MODULE, $moduleFilter)
            ->select()
            ->fetchArray();

        $words = [];
        foreach ($rows as $row) {
            $word = trim((string)($row[$this->dictionary::schema_fields_WORD] ?? ''));
            if ($word !== '') {
                $words[] = $word;
            }
        }

        return $words;
    }

    /**
     * 异步保存翻译
     */
    public function postQuickSave()
    {
        $md5 = $this->request->getPost('md5');
        $translate = $this->request->getPost('translate');
        $word = $this->request->getPost('word');
        $localeCode = $this->request->getPost('locale_code');
        
        if ($word === null || !$localeCode || $translate === null) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('参数不完整')
            ]);
        }
        try {
            $word = WordDictionary::assertWord($word);
            if ($md5) {
                // 有 md5，使用 md5 查找现有记录
                $this->localeDictionary->load($md5);
                if ($this->localeDictionary->getId()) {
                    // 记录存在，更新翻译
                    $this->localeDictionary->setData($this->localeDictionary::schema_fields_TRANSLATE, $translate)->save();
                } else {
                    // md5 不存在，创建新记录
                    $this->localeDictionary->reset()->setData([
                        $this->localeDictionary::schema_fields_MD5 => $md5,
                        $this->localeDictionary::schema_fields_WORD => $word,
                        $this->localeDictionary::schema_fields_LOCALE_CODE => $localeCode,
                        $this->localeDictionary::schema_fields_TRANSLATE => $translate
                    ])->save();
                }
            } else {
                // 没有 md5，新增记录，使用模型生成 md5
                $md5 = $this->localeDictionary->getMd5($word, $localeCode);
                $this->localeDictionary->reset()->setData([
                    $this->localeDictionary::schema_fields_MD5 => $md5,
                    $this->localeDictionary::schema_fields_WORD => $word,
                    $this->localeDictionary::schema_fields_LOCALE_CODE => $localeCode,
                    $this->localeDictionary::schema_fields_TRANSLATE => $translate
                ])->save();
            }
            
            // 更新基础词典表中对应记录的视图数据
            $this->updateBaseDictionaryViewData($word, $translate, $localeCode);
            
            // 获取更新后的进度
            $currentLocaleCode = $this->localeDictionary->getData($this->localeDictionary::schema_fields_LOCALE_CODE);
            $progressStats = $this->getTranslationProgress($currentLocaleCode);
            
            return $this->fetchJson([
                'success' => true,
                'message' => __('保存成功'),
                'progress' => $progressStats
            ]);
        } catch (\Exception $exception) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('保存失败: %{1}', $exception->getMessage())
            ]);
        }
    }
    
    /**
     * 获取快速翻译数据
     */
    public function getQuickTranslationData()
    {
        // 清理任何可能的输出缓冲
        while (ob_get_level()) {
            ob_end_clean();
        }
        ob_start();
        
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');
        
        $localeCode = $this->request->getParam('locale_code');
        $search = $this->request->getParam('search', '');
        $targetMd5 = $this->request->getParam('target_md5', ''); // 目标记录的MD5，用于定位
        $page = (int)$this->request->getParam('page', 1);
        $pageSize = (int)$this->request->getParam('page_size', 10);
        
        try {
            // 使用简单查询，避免 COALESCE 函数导致的 PostgreSQL 解析问题
            $query = $this->dictionary->reset();
            
            // 应用搜索条件
            if (!empty($search)) {
                $search = addslashes($search);
                $query->where($this->dictionary::schema_fields_WORD, '%' . $search . '%', 'like');
            }
            
            // 获取所有词典数据
            $dictionaryItems = $query->select()->fetchArray();
            
            // 获取所有词汇
            $words = array_column($dictionaryItems, $this->dictionary::schema_fields_WORD);
            
            // 查询对应的翻译数据
            $translations = [];
            if (!empty($words)) {
                $translationData = $this->localeDictionary->reset()
                    ->where($this->localeDictionary::schema_fields_LOCALE_CODE, $localeCode)
                    ->where($this->localeDictionary::schema_fields_WORD, $words, 'in')
                    ->select()
                    ->fetchArray();
                
                foreach ($translationData as $t) {
                    $translations[$t[$this->localeDictionary::schema_fields_WORD]] = $t;
                }
            }
            
            // 组合数据
            $translationItems = [];
            foreach ($dictionaryItems as $item) {
                $word = $item[$this->dictionary::schema_fields_WORD];
                $t = $translations[$word] ?? null;
                $translationItems[] = [
                    'word' => $word,
                    'translate' => $t[$this->localeDictionary::schema_fields_TRANSLATE] ?? $word,
                    'locale_code' => $t[$this->localeDictionary::schema_fields_LOCALE_CODE] ?? $localeCode,
                    'md5' => $t[$this->localeDictionary::schema_fields_MD5] ?? null,
                    'update_time' => $t['update_time'] ?? 0
                ];
            }
            
            // 在应用层进行排序：未翻译优先
            if (!empty($translationItems)) {
                usort($translationItems, function($a, $b) {
                    // 判断是否已翻译
                    $aTranslated = !empty($a['translate']) && $a['translate'] !== $a['word'];
                    $bTranslated = !empty($b['translate']) && $b['translate'] !== $b['word'];
                    
                    // 未翻译的排在前面
                    if ($aTranslated !== $bTranslated) {
                        return $aTranslated ? 1 : -1;
                    }
                    
                    // 相同翻译状态按更新时间倒序
                    $bTime = $b['update_time'] ?? 0;
                    $aTime = $a['update_time'] ?? 0;
                    return $bTime <=> $aTime;
                });
            }
            
            // 如果指定了目标MD5，找到目标记录的位置
            $targetIndex = -1;
            if (!empty($targetMd5)) {
                foreach ($translationItems as $index => $item) {
                    if ($item['md5'] === $targetMd5) {
                        $targetIndex = $index;
                        break;
                    }
                }
            }
            
            // 手动分页
            $total = count($translationItems);
            $totalPages = ceil($total / $pageSize);
            
            // 如果找到目标记录，调整分页以显示目标记录及其上下文
            if ($targetIndex >= 0) {
                // 计算目标记录应该在的页码
                $targetPage = (int)(floor($targetIndex / $pageSize) + 1);
                $page = $targetPage;
                
                // 获取目标记录前后的上下文（共5条记录，目标记录居中）
                $contextSize = 5;
                $contextStart = (int)max(0, $targetIndex - floor($contextSize / 2));
                $contextEnd = (int)min($total, $contextStart + $contextSize);
                
                // 如果末尾不够，调整开始位置
                if ($contextEnd - $contextStart < $contextSize && $contextStart > 0) {
                    $contextStart = (int)max(0, $contextEnd - $contextSize);
                }
                
                $paginatedItems = array_slice($translationItems, $contextStart, $contextSize);
                
                // 计算目标记录在当前页面中的索引
                $targetIndexInPage = $targetIndex - $contextStart;
                
            } else {
                // 正常分页
                $offset = (int)(($page - 1) * $pageSize);
                $paginatedItems = array_slice($translationItems, $offset, $pageSize);
                $targetIndexInPage = -1;
            }
            
            // 格式化时间显示并添加翻译状态标志
            $formattedPaginatedItems = [];
            foreach ($paginatedItems as $item) {
                $item['formatted_update_time'] = $this->formatUpdateTime($item['update_time'] ?? 0);
                // 添加翻译状态标志
                $item['is_translated'] = !empty($item['translate']) && $item['translate'] !== $item['word'];
                $formattedPaginatedItems[] = $item;
            }
            
            $progressStats = $this->getTranslationProgress($localeCode);

            return $this->fetchJson([
                'success' => true,
                'data' => $formattedPaginatedItems,
                'pagination' => [
                    'current_page' => $page,
                    'total_pages' => $totalPages,
                    'total' => $total
                ],
                'target_index' => $targetIndexInPage, // 目标记录在当前页面中的索引
                'search' => $search,
                'progress' => $progressStats
            ]);
            
        } catch (\Exception $exception) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('获取数据失败: %{1}', $exception->getMessage())
            ]); 
        }
    }

    /**
     * 收集真实翻译词汇（JSON，兼容 bin-query）
     */
    public function postCollectWords()
    {
        try {
            $defaultLanguageCode = Env::default_LANGUAGE_CODE;
            $localeCode = trim((string)$this->request->getPost('locale_code', $defaultLanguageCode));
            if ($localeCode === '') {
                $localeCode = $defaultLanguageCode;
            }

            $result = $this->dictionaryCollectService->collect($localeCode);
            if (!$result['success']) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => $result['message'] ?? __('收集失败'),
                ]);
            }

            return $this->fetchJson([
                'success' => true,
                'count' => $result['count'],
                'default_locale_count' => $result['default_locale_count'],
                'queue_count' => $result['queue_count'],
                'message' => $result['message'],
                'progress' => $this->getTranslationProgress($localeCode),
            ]);
        } catch (\Exception $e) {
            w_log_error('I18n dictionary collect failed: ' . $e->getMessage(), [
                'trace' => substr($e->getTraceAsString(), 0, 1200),
            ], 'i18n');
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * 收集词典 SSE 流（POST）
     */
    public function postCollectWordsStream(): void
    {
        $this->streamCollectWords();
    }

    /**
     * 收集词典 SSE 流（GET 兼容）
     */
    public function getCollectWordsStream(): void
    {
        $this->streamCollectWords();
    }

    private function streamCollectWords(): void
    {
        $this->layoutType = null;

        $csrfPost = (string) $this->request->getPost('csrf', (string) $this->request->getGet('csrf', ''));
        $csrfValid = Token::get('csrf');
        if ($csrfValid === null || !\hash_equals($csrfValid, $csrfPost)) {
            $sse = new SseWriter();
            $sse->start();
            $sse->sendError((string) __('CSRF 验证失败'));
            $sse->complete(['success' => false]);

            return;
        }

        $defaultLanguageCode = Env::default_LANGUAGE_CODE;
        $localeCode = trim((string) $this->request->getPost('locale_code', (string) $this->request->getGet('locale_code', $defaultLanguageCode)));
        if ($localeCode === '') {
            $localeCode = $defaultLanguageCode;
        }

        $this->dictionaryCollectService->stream(
            new SseWriter(),
            $localeCode,
            fn (string $code): array => $this->getTranslationProgress($code),
        );
    }

    private function normalizeCollectedWords(array $words): array
    {
        $normalized = [];

        foreach ($words as $word => $translate) {
            if (!is_string($word) && !is_int($word)) {
                continue;
            }

            if (!is_scalar($translate) && $translate !== null) {
                continue;
            }

            $word = trim((string)$word);
            if ($word === '') {
                continue;
            }

            $translate = $translate === null ? '' : (string)$translate;
            $normalized[$word] = trim($translate) === '' ? $word : $translate;
        }

        return $normalized;
    }

    private function clearRuntimeTranslationCaches(): void
    {
        ObjectManager::getInstance(RuntimeCacheBroadcaster::class)->broadcast();
    }

    /**
     * 启用自动词汇注册
     */
    public function postEnableAutoRegister()
    {
        
        try {
            // 设置自动注册模式
            $this->setAutoRegisterMode(true);
            
            return $this->fetchJson([
                'success' => true,
                'message' => __('自动词汇注册已启用')
            ]);
            
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * 禁用自动词汇注册
     */
    public function postDisableAutoRegister()
    {
        
        try {
            // 关闭自动注册模式
            $this->setAutoRegisterMode(false);
            
            return $this->fetchJson([
                'success' => true,
                'message' => __('自动词汇注册已禁用')
            ]);
            
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * 设置自动注册模式
     */
    private function setAutoRegisterMode($enabled)
    {
        // 使用Env类设置配置到env.php文件
        \Weline\Framework\App\Env::set('translation.auto_register', $enabled);
    }

    /**
     * 检查是否启用自动注册
     */
    public function isAutoRegisterEnabled()
    {
        try {
            // 从Env类读取配置
            $value = \Weline\Framework\App\Env::get('translation.auto_register');
            return $value !== null ? (bool)$value : false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 检查自动注册状态（AJAX接口）
     */
    public function getCheckAutoRegister()
    {
        $enabled = $this->isAutoRegisterEnabled();
        
        return $this->fetchJson([
            'enabled' => $enabled,
            'status' => $enabled ? '已启用' : '已禁用'
        ]);
    }

    /**
     * 设置翻译模式到env.php
     */
    public function postSetTranslationMode()
    {
        // 清理任何可能的输出缓冲
        while (ob_get_level()) {
            ob_end_clean();
        }
        ob_start();
        
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');
        
        try {
            $mode = $this->request->getParam('mode'); // 'online' 或 'cache'
            
            if (!in_array($mode, ['online', 'cache'])) {
                throw new \Exception(__('无效的翻译模式'));
            }
            
            // 更新env.php配置
            $this->updateEnvTranslationMode($mode);
            
            // 更新缓存中的翻译模式
            $this->setTranslationModeCache($mode);
            
            // 清理输出缓冲
            ob_end_clean();
            
            return $this->fetchJson([
                'success' => true,
                'mode' => $mode,
                'message' => __('翻译模式设置成功')
            ]);
            
        } catch (\Exception $e) {
            // 清理输出缓冲
            ob_end_clean();
            return $this->fetchJson($this->error($e->getMessage()));
        }
    }

    /**
     * 更新env.php中的翻译模式配置
     */
    private function updateEnvTranslationMode($mode)
    {
        $envFile = 'app/etc/env.php';
        
        if (!file_exists($envFile)) {
            throw new \Exception(__('配置文件不存在'));
        }
        
        // 使用输出缓冲确保不会产生任何输出
        ob_start();
        
        try {
            // 读取当前配置
            $config = include $envFile;
            
            // 添加或更新翻译模式配置
            $config['translation'] = [
                'mode' => $mode,
                'online' => [
                    'enabled' => $mode === 'online',
                    'api_key' => '', // 可以后续添加API密钥配置
                    'provider' => 'google', // 默认使用Google翻译
                ],
                'cache' => [
                    'enabled' => $mode === 'cache',
                    'ttl' => 3600, // 缓存1小时
                ],
                'auto_register' => $this->isAutoRegisterEnabled(),
            ];
            
            // 生成新的配置文件内容
            ob_start();
            var_export($config);
            $configString = ob_get_clean();
            $content = "<?php return " . $configString . ";";
            
            // 写入文件
            if (file_put_contents($envFile, $content) === false) {
                throw new \Exception(__('写入配置文件失败'));
            }
            
        } finally {
            // 清理所有输出缓冲
            while (ob_get_level()) {
                ob_end_clean();
            }
        }
    }

    /**
     * 设置翻译模式缓存
     */
    private function setTranslationModeCache($mode)
    {
        $cacheKey = 'i18n_translation_mode';
        $cache = $this->i18n->i18nCache;
        $cache->set($cacheKey, $mode, 0); // 永久缓存
    }

    /**
     * 获取当前翻译模式
     */
    public function getCurrentTranslationMode()
    {
        
        try {
            // 先从缓存获取
            $cacheKey = 'i18n_translation_mode';
            $cache = $this->i18n->i18nCache;
            $mode = $cache->get($cacheKey);
            
            // 如果缓存中没有，从env.php读取
            if (!$mode) {
                $envFile = 'app/etc/env.php';
                if (file_exists($envFile)) {
                    $config = include $envFile;
                    $mode = $config['translation']['mode'] ?? 'cache';
                } else {
                    $mode = 'cache'; // 默认缓存模式
                }
            }
            
            return $this->fetchJson([
                'mode' => $mode,
                'mode_name' => $mode === 'online' ? '在线翻译模式' : '缓存模式',
                'auto_register' => $this->isAutoRegisterEnabled()
            ]);
            
        } catch (\Exception $e) {
            return $this->fetchJson([
                'mode' => 'cache',
                'mode_name' => '缓存模式',
                'auto_register' => false,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * 更新基础词典表中对应记录的视图数据
     */
    private function updateBaseDictionaryViewData($word, $translate, $localeCode)
    {
        $word = WordDictionary::assertWord($word);
        try {
            // 查找基础词典表中的记录
            $baseRecord = $this->dictionary->reset()
                ->where($this->dictionary::schema_fields_WORD, $word)
                ->find()
                ->fetch();
            
            if ($baseRecord->getId()) {
                // 更新基础词典表的视图相关字段
                $updateData = [
                    // 更新翻译状态
                    'translation_status' => !empty($translate) && $translate !== $word ? 'translated' : 'untranslated',
                    // 更新最后翻译的语言
                    'last_translated_locale' => $localeCode,
                    // 更新最后翻译时间
                    'last_translation_time' => time(),
                    // 更新翻译内容（如果需要存储最新翻译）
                    'latest_translation' => $translate
                ];
                
                $baseRecord->setData($updateData)->save();
            } else {
                // 如果基础词典表中没有记录，创建新记录
                $this->dictionary->reset()->setData([
                    $this->dictionary::schema_fields_WORD => $word,
                    $this->dictionary::schema_fields_IS_BACKEND => 0,
                    $this->dictionary::schema_fields_MODULE => '',
                    'translation_status' => !empty($translate) && $translate !== $word ? 'translated' : 'untranslated',
                    'last_translated_locale' => $localeCode,
                    'last_translation_time' => time(),
                    'latest_translation' => $translate
                ])->save();
            }
        } catch (\Exception $e) {
            // 记录错误但不影响主流程
            Debug::log('updateBaseDictionaryViewData', '更新基础词典表视图数据失败: ' . $e->getMessage());
        }
    }

    /**
     * 模块 Tab：模块列表 JSON（切换语言/搜索时刷新）。
     */
    public function getModuleCatalog()
    {
        $localeCode = trim((string)$this->request->getGet('locale_code', ''));
        $search = trim((string)$this->request->getGet('module_search', ''));

        return $this->fetchJson([
            'success' => true,
            'items' => $this->moduleCatalogService->listModules($localeCode, $search),
            'dev_environment' => $this->moduleCatalogService->isDevEnvironment(),
        ]);
    }

    /**
     * 模块 Tab：对单个模块执行 AI 翻译（仅该模块词条）。
     */
    public function postModuleTranslate()
    {
        $localeCode = trim((string)$this->request->getPost('locale_code', ''));
        $module = trim((string)$this->request->getPost('module', ''));

        if ($localeCode === '' || $module === '') {
            return $this->fetchJson([
                'success' => false,
                'message' => __('缺少目标语言或模块'),
            ]);
        }

        $result = $this->moduleCatalogService->translateModule($module, $localeCode);
        if ($result['success'] && !isset($result['message'])) {
            $result['message'] = (string)__(
                '模块 %{1} 本批翻译 %{2} 条，剩余 %{3} 条',
                [$module, (string)($result['translated'] ?? 0), (string)($result['remaining'] ?? 0)],
            );
        }
        $result['module_row'] = $this->findModuleCatalogRow($localeCode, $module);

        return $this->fetchJson($result);
    }

    /**
     * 模块 Tab：写回模块 i18n CSV（开发环境）。
     */
    public function postModuleExportCsv()
    {
        $localeCode = trim((string)$this->request->getPost('locale_code', ''));
        $module = trim((string)$this->request->getPost('module', ''));

        if ($localeCode === '' || $module === '') {
            return $this->fetchJson([
                'success' => false,
                'message' => __('缺少目标语言或模块'),
            ]);
        }

        $result = $this->moduleCatalogService->exportModuleToCsv($module, $localeCode);
        $result['module_row'] = $this->findModuleCatalogRow($localeCode, $module);

        return $this->fetchJson($result);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findModuleCatalogRow(string $localeCode, string $module): ?array
    {
        foreach ($this->moduleCatalogService->listModules($localeCode) as $row) {
            if (($row['module'] ?? '') === $module) {
                return $row;
            }
        }

        return null;
    }

    /**
     * 按模块批量 AI 翻译（dispatch dictionary_translate）。
     * @deprecated 请使用模块 Tab 的 postModuleTranslate
     */
    public function postBatchTranslate()
    {
        $localeCode = trim((string)$this->request->getPost('locale_code', ''));
        $module = trim((string)$this->request->getPost('module', ''));
        if ($localeCode === '') {
            return $this->fetchJson([
                'success' => false,
                'message' => __('缺少目标语言'),
            ]);
        }

        $options = [
            'owner' => 'Weline_I18n:dictionary_admin',
            'publish' => true,
        ];
        if ($module !== '') {
            $options['owner'] = $module;
        }

        $result = DictionaryEvents::translate($module !== '' ? $module : 'Weline_I18n', $localeCode, $options);

        return $this->fetchJson([
            'success' => !empty($result['success']),
            'message' => (string)($result['message'] ?? __('批量翻译任务已提交')),
            'data' => $result,
            'progress' => $this->getTranslationProgress($localeCode, $module),
        ]);
    }
    
}
