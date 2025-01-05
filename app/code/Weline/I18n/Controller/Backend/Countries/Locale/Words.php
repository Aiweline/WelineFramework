<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/12/21 21:40:22
 */

namespace Weline\I18n\Controller\Backend\Countries\Locale;

use Weline\Framework\App\Env;
use Weline\Framework\App\System;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Phrase\Cache\PhraseCache;
use Weline\I18n\Cache\I18nCache;
use Weline\I18n\Controller\Backend\BaseController;
use Weline\I18n\Model\Countries;
use Weline\I18n\Model\Countries\Locale\Name;
use Weline\I18n\Model\Dictionary;
use Weline\I18n\Model\I18n;
use Weline\I18n\Model\Locale;

#[\Weline\Framework\Acl\Acl('Weline_I18n::i18n', '国际化I18n管理', 'ri-translate', '国际化I18n管理')]
class Words extends BaseController
{
    /**
     * @var \Weline\I18n\Model\Dictionary
     */
    private Dictionary $dictionary;
    /**
     * @var \Weline\I18n\Model\Locale\Dictionary
     */
    private Locale\Dictionary $localeDictionary;

    public function __construct(
        Locale            $locale,
        I18n              $i18n,
        Dictionary        $dictionary,
        Locale\Dictionary $localeDictionary
    )
    {
        parent::__construct($locale, $i18n);
        $this->dictionary = $dictionary;
        $this->localeDictionary = $localeDictionary;
    }

    function __init()
    {
        parent::__init();
        // 抽取词典
        $words = $this->i18n->getCollectedWords();
        foreach ($words as $key => $word) {
            unset($words[$key]);
            if ($key) {
                $words[] = ['word' => $key];
            }
        }
        if ($words) {
            $this->dictionary->beginTransaction();
            try {
                $this->dictionary->insert($words, $this->dictionary::fields_ID)->fetch();
                $this->dictionary->commit();
            } catch (\Exception $exception) {
                $this->dictionary->rollBack();
                $this->getMessageManager()->addException($exception);
                $this->redirect('*/backend/countries/locales', $this->request->getParams());
            }
        }
    }

    /**
     * @DESC          # 方法描述
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/1/4 23:14
     * 参数区：
     * @return mixed
     * @throws \ReflectionException
     * @throws \Weline\Framework\App\Exception
     */
    #[\Weline\Framework\Acl\Acl('Weline_I18n::i18n_words', '1i8n词典管理', 'mdi mdi-bookshelf', '1i8n词典管理')]
    public function index(): mixed
    {
        $locale_code = $this->request->getGet('code');
        // 查询已安装并且已经激活的地方代码
        $locale = $this->locale->clear()->where(
            [
                $this->locale::fields_CODE => $locale_code,
                $this->locale::fields_IS_ACTIVE => 1,
                $this->locale::fields_IS_INSTALL => 1
            ]
        )->where('n.display_locale_code', Cookie::getLangLocal())
            ->where('ln.display_locale_code', Cookie::getLangLocal())
            ->joinModel(Name::class, 'n', 'main_table.country_code=n.country_code')
            ->joinModel(Locale\Name::class, 'ln', 'main_table.code=ln.locale_code')
            ->find()
            ->fetch();
        if (!$locale->getId()) {
            $this->getMessageManager()->addWarning(__('该区域未激活或者未安装！'));
        }
        $this->assign('locale', $locale);
        // 如果存在搜索
        if ($search = $this->request->getGet('search')) {
            $this->localeDictionary->where($this->localeDictionary::fields_WORD, "%$search%", 'like', 'or')
                ->where($this->localeDictionary::fields_TRANSLATE, "%$search%", 'like');
        }
        // 获取当前操作的词典
        $this->localeDictionary->where(Locale\Dictionary::fields_LOCALE_CODE, $locale->getId())
            ->order('create_time', 'desc')
            ->order('update_time', 'asc')
            ->pagination()
            ->select()
            ->fetch();
        $this->assign('words', $this->localeDictionary->getItems());
        $this->assign('pagination', $this->localeDictionary->getPagination());
        $this->assign('total', $this->localeDictionary->pagination['totalSize']);
        $this->assign('translate_mode', __(Env::getInstance()->getConfig('translate_mode') ?? ''));
        return $this->fetch();
    }

    public function collect()
    {
        $locale_code = $this->request->getParam('code');
        // 检测地方码是否存在
        if (!$this->locale->load($locale_code)->getId()) {
            $this->getMessageManager()->addWarning(__('地方码不存在！'));
            $this->redirect('*/backend/countries/locale/words', $this->request->getParams());
        }
        // 从词典获取
        $words = $this->dictionary->select()->fetchArray();
        foreach ($words as $key => $word) {
            unset($words[$key]);
            $words[$word[$this->dictionary::fields_WORD]] = $word[$this->dictionary::fields_WORD];
        }
        // 获取收集到的词
        $collected_words = array_merge($words, $this->i18n->getCollectedWords());
        foreach ($collected_words as $key => $collected_word) {
            unset($collected_words[$key]);
            if ($key and $key = trim((string)$key)) {
                $collected_words[] = [
                    $this->localeDictionary::fields_WORD => $key,
                    $this->localeDictionary::fields_LOCALE_CODE => $locale_code,
                    $this->localeDictionary::fields_MD5 => md5($locale_code . $key),
                    $this->localeDictionary::fields_TRANSLATE => $key,
                ];
            }
        }
        if ($collected_words) {
            $this->localeDictionary->beginTransaction();
            try {
                $this->localeDictionary->insert($collected_words, $this->localeDictionary::fields_MD5)->fetch()->commit();
                $this->getMessageManager()->addSuccess(__('词典收集成功！一共更新 %1 个词。', count($collected_words)));
            } catch (\Exception $exception) {
                $this->localeDictionary->rollBack();
                $this->getMessageManager()->addException($exception);
            }
        }
        $this->redirect('*/backend/countries/locale/words', $this->request->getParams());
    }

    public function translate(): string
    {
        $data = $this->request->getPost();
        if (!isset($data['code']) || !$this->locale->load($data['code'])->getId()) {
            return $this->fetchJson($this->error(__('地区码不存在！')));
        }
        /**@var \Weline\I18n\Model\Countries $countriesModel */
        $countriesModel = ObjectManager::getInstance(Countries::class);
        if (!isset($data['country_code']) || !$countriesModel->load($data['country_code'])->getId()) {
            return $this->fetchJson($this->error(__('国家码不存在！')));
        }
        unset($data['country_code']);
        $data['locale_code'] = $data['code'];
        $data['translate'] = htmlentities(trim($data['translate']));
        unset($data['code']);
        // 更新翻译
        $this->localeDictionary->beginTransaction();
        try {
            $this->localeDictionary->insert($data, $this->localeDictionary::fields_MD5, $this->localeDictionary::fields_TRANSLATE)->fetch();
            $this->localeDictionary->commit();
            return $this->fetchJson($this->success(__('成功保存！')));
        } catch (\Exception $exception) {
            $this->localeDictionary->rollBack();
            return $this->fetchJson($this->error($exception->getMessage()));
        }
    }

    public function postRestore()
    {
        $data = $this->request->getPost();
        if (!isset($data['md5']) || !$this->localeDictionary->load($this->localeDictionary::fields_MD5, $data['md5'])->getId()) {
            return $this->fetchJson($this->error(__('地区码不存在！')));
        }
        if (!isset($data['code']) || !$this->locale->load($data['code'])->getId()) {
            return $this->fetchJson($this->error(__('地区码不存在！')));
        }
        /**@var \Weline\I18n\Model\Countries $countriesModel */
        $countriesModel = ObjectManager::getInstance(Countries::class);
        if (!isset($data['country_code']) || !$countriesModel->load($data['country_code'])->getId()) {
            return $this->fetchJson($this->error(__('国家码不存在！')));
        }
        // 恢复原词
        $this->localeDictionary->setData($this->localeDictionary::fields_TRANSLATE, $this->localeDictionary->getData
        ($this->localeDictionary::fields_WORD))->save(true);
        return $this->fetchJson($this->success(__('恢复成功！'), $this->localeDictionary->getData($this->localeDictionary::fields_WORD)));
    }

    public function push()
    {
        $code = $this->request->getParam('code');
        $locale = $this->locale->where([
            $this->locale::fields_IS_ACTIVE => 1,
            $this->locale::fields_IS_INSTALL => 1,
            $this->locale::fields_CODE => $code
        ])
            ->find()
            ->fetch();
        if (!$locale->getId()) {
            $this->getMessageManager()->addWarning(__('地区码未安装或者未激活！地区码：%1', $this->request->getParam('code')));
            $this->redirect('*/backend/countries/locale/words', $this->request->getParams());
        }
        // 语言包生成
        $i18n_dir = APP_PATH . 'i18n' . DS . 'WelineFramework' . DS;
        $pack_dir = $i18n_dir . $code . DS;
        if (!is_dir($pack_dir)) {
            mkdir($pack_dir, 0775, true);
        }
        // 语言包注册文件
        $register_file = $pack_dir . 'register.php';
        if (!is_file($register_file)) {
            touch($register_file);
        }
        $pack_document = $this->i18n->getLocaleName($locale->getId(), Cookie::getLangLocal()) . "({$this->i18n->getLocaleName($locale->getId(),$locale->getId())})";
        $register_file_content = <<<REGISTER_CONTENT
<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

use Weline\Framework\Register\Register;

Register::register(
    Register::I18N,
    __DIR__,
    '1.0.1',
    '{$pack_document}'
);

REGISTER_CONTENT;
        file_put_contents($register_file, $register_file_content);

        // 语言包文件
        $pack_file = $pack_dir . $code . '.csv';
        if (!is_file($pack_file)) {
            touch($pack_file);
        }
        $locale_dictionaries = $this->localeDictionary->where($this->localeDictionary::fields_LOCALE_CODE, $code)->select()->fetchArray();
        $pack_file_content = '';
        foreach ($locale_dictionaries as $locale_dictionary) {
            $pack_file_content .= $locale_dictionary['word'] . ',' . $locale_dictionary['translate'] . PHP_EOL;
        }
        file_put_contents($pack_file, $pack_file_content);
        // 清理i18n缓存
        /**@var \Weline\Framework\Cache\CacheInterface $i18n */
        $i18n = ObjectManager::getInstance(I18nCache::class . 'Factory');
        $i18n->clear();
        /**@var \Weline\Framework\Cache\CacheInterface $phrase */
        $phrase = ObjectManager::getInstance(PhraseCache::class . 'Factory');
        $phrase->clear();
        $this->getMessageManager()->addSuccess(__('成功清理i18n缓存！'));
        // 清理生成的模板缓存文件
        /**@var System $system */
        $system = ObjectManager::getInstance(System::class);
        $system->exec('rm -rf ' . Env::path_framework_generated_complicate);
        $this->getMessageManager()->addSuccess(__('成功清理系统模板缓存文件！'));
        $this->getMessageManager()->addSuccess(__('成功发布！'));
        $this->redirect('*/backend/countries/locale/words', $this->request->getParams());
    }

    public function enable()
    {
        try {
            Env::getInstance()->setConfig('translate_mode', 'online');
            $this->getMessageManager()->addSuccess(__('成功开启！'));
        } catch (\Exception $exception) {
            $this->getMessageManager()->addException($exception);
        }
        $this->redirect('*/backend/countries/locale/words', $this->request->getGet());
    }

    public function disable()
    {
        try {
            Env::getInstance()->setConfig('translate_mode', 'default');
            $this->getMessageManager()->addSuccess(__('成功禁用！'));
        } catch (\Exception $exception) {
            $this->getMessageManager()->addException($exception);
        }
        $this->redirect('*/backend/countries/locale/words', $this->request->getGet());
    }
}
