<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2024/12/19
 */

namespace Weline\I18n\Controller\Backend\Countries;

use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Controller\Backend\BaseController;
use Weline\I18n\Model\Countries;
use Weline\I18n\Model\Countries\Locale\Name;
use Weline\I18n\Model\Locale;
use Weline\I18n\Model\Locale\Name as LocaleName;
use Weline\I18n\Model\I18n;

class AsyncUpdate extends BaseController
{
    private const BATCH_SIZE = 100;
    private const PROGRESS_KEY_PREFIX = 'i18n_update_progress_';
    
    public function index()
    {
        try {
            // 生成唯一的任务ID
            $taskId = uniqid('update_', true);
            
            // 初始化进度
            $this->initProgress($taskId);
            
            // 立即开始执行更新
            $this->executeFullUpdate($taskId);
            
            // 返回任务ID给前端
            $this->request->getResponse()->setData([
                'success' => true,
                'task_id' => $taskId,
                'message' => '开始异步更新全球数据...'
            ]);
            return;
            
        } catch (\Exception $e) {
            $this->request->getResponse()->setData([
                'success' => false,
                'message' => '启动更新失败：' . $e->getMessage()
            ]);
            return;
        }
    }
    
    /**
     * 执行完整的数据更新
     */
    private function executeFullUpdate($taskId)
    {
        try {
            // 步骤1：更新国家数据
            $this->updateCountries($taskId);
            
            // 步骤2：更新区域数据
            $this->updateAllLocales($taskId);
            
            // 步骤3：更新区域名称数据
            $this->updateAllLocaleNames($taskId);
            
            // 步骤4：完成更新
            $this->finalizeUpdate($taskId);
            
        } catch (\Exception $e) {
            $this->setProgressError($taskId, $e->getMessage());
        }
    }
    
    /**
     * 执行异步更新任务
     */
    public function execute()
    {
        $taskId = $this->request->getPost('task_id');
        $step = $this->request->getPost('step', 1);
        $batch = $this->request->getPost('batch', 0);
        
        try {
            switch ($step) {
                case 1:
                    $this->updateCountries($taskId);
                    break;
                case 2:
                    $this->updateLocales($taskId, $batch);
                    break;
                case 3:
                    $this->updateLocaleNames($taskId, $batch);
                    break;
                case 4:
                    $this->finalizeUpdate($taskId);
                    break;
                default:
                    throw new \Exception('未知的更新步骤');
            }
            
            $this->request->getResponse()->setData([
                'success' => true,
                'task_id' => $taskId,
                'step' => $step,
                'batch' => $batch,
                'progress' => $this->getProgress($taskId)
            ]);
            return;
            
        } catch (\Exception $e) {
            $this->setProgressError($taskId, $e->getMessage());
            $this->request->getResponse()->setData([
                'success' => false,
                'message' => '执行更新失败：' . $e->getMessage()
            ]);
            return;
        }
    }
    
    /**
     * 获取更新进度
     */
    public function progress()
    {
        $taskId = $this->request->getGet('task_id');
        
        if (!$taskId) {
            $this->request->getResponse()->setData([
                'success' => false,
                'message' => '缺少任务ID'
            ]);
            return;
        }
        
        $progress = $this->getProgress($taskId);
        
        $this->request->getResponse()->setData([
            'success' => true,
            'progress' => $progress
        ]);
        return;
    }
    
    /**
     * 更新国家数据
     */
    private function updateCountries($taskId)
    {
        $this->setProgress($taskId, '正在更新国家数据...', 10);
        
        /** @var I18n $i18n */
        $i18n = ObjectManager::getInstance(I18n::class);
        /** @var Countries $countries */
        $countries = ObjectManager::getInstance(Countries::class);
        /** @var Name $localeNames */
        $localeNames = ObjectManager::getInstance(Name::class);
        
        // 获取所有可用的国家信息
        $availableCountries = $i18n->getCountries('en');
        
        $insert_countries = [];
        $insert_countries_display = [];
        $update_countries = [];
        $update_countries_display = [];
        
        // 获取现有国家
        $existingCountries = $countries->clearQuery()->select()->fetch()->getItems();
        $existingCodes = [];
        foreach ($existingCountries as $country) {
            $existingCodes[] = $country->getData(Countries::fields_CODE);
        }
        
        $processed = 0;
        $total = count($availableCountries);
        
        foreach ($availableCountries as $code => $country) {
            $countryData = [
                Countries::fields_CODE => $code,
                Countries::fields_FLAG => (string)$i18n->getCountryFlag($code),
                Countries::fields_IS_ACTIVE => ($code === 'CN') ? 1 : 0,
                Countries::fields_IS_INSTALL => ($code === 'CN') ? 1 : 0,
            ];
            
            $displayData = [
                Name::fields_COUNTRY_CODE => $code,
                Name::fields_DISPLAY_LOCALE_CODE => 'en',
                Name::fields_DISPLAY_NAME => $country,
            ];
            
            if (in_array($code, $existingCodes)) {
                $update_countries[] = $countryData;
                $update_countries_display[] = $displayData;
            } else {
                $insert_countries[] = $countryData;
                $insert_countries_display[] = $displayData;
            }
            
            $processed++;
            $this->setProgress($taskId, "正在处理国家数据... ({$processed}/{$total})", 10 + ($processed / $total) * 20);
        }
        
        // 批量插入新国家
        if (!empty($insert_countries)) {
            $countries->clearQuery()->insert($insert_countries, Countries::fields_CODE)->fetch();
            $localeNames->clearQuery()->insert($insert_countries_display, $localeNames::fields_COUNTRY_CODE)->fetch();
        }
        
        // 批量更新现有国家
        if (!empty($update_countries)) {
            foreach ($update_countries as $updateData) {
                $countries->clearQuery()
                    ->where(Countries::fields_CODE, $updateData[Countries::fields_CODE])
                    ->update($updateData)->fetch();
            }
        }
        
        $this->setProgress($taskId, '国家数据更新完成', 30);
    }
    
    /**
     * 更新所有区域数据（完整版本）
     */
    private function updateAllLocales($taskId)
    {
        $this->setProgress($taskId, '正在更新区域数据...', 30);
        
        /** @var I18n $i18n */
        $i18n = ObjectManager::getInstance(I18n::class);
        /** @var Locale $locale */
        $locale = ObjectManager::getInstance(Locale::class);
        /** @var Countries $countries */
        $countries = ObjectManager::getInstance(Countries::class);
        
        // 获取所有国家
        $allCountries = $countries->clearQuery()->select()->fetch()->getItems();
        
        $allLocales = [];
        $processed = 0;
        $total = count($allCountries);
        
        foreach ($allCountries as $country) {
            $countryCode = $country->getData(Countries::fields_CODE);
            
            try {
                $countryLocales = $i18n->getCountry($countryCode)->getLocales();
                
                foreach ($countryLocales as $localeCode) {
                    $allLocales[] = [
                        Locale::fields_CODE => $localeCode,
                        Locale::fields_COUNTRY_CODE => $countryCode,
                        Locale::fields_IS_ACTIVE => 0,
                        Locale::fields_IS_INSTALL => 0,
                        Locale::fields_FLAG => ''
                    ];
                }
            } catch (\Exception $e) {
                // 跳过无法获取区域的国家
                error_log("无法获取国家 {$countryCode} 的区域信息: " . $e->getMessage());
            }
            
            $processed++;
            $this->setProgress($taskId, "正在收集区域数据... ({$processed}/{$total})", 30 + ($processed / $total) * 20);
        }
        
        // 批量插入Locale数据
        if (!empty($allLocales)) {
            $batches = array_chunk($allLocales, self::BATCH_SIZE);
            $totalBatches = count($batches);
            
            foreach ($batches as $index => $batch) {
                try {
                    $locale->clearQuery()->insert($batch, Locale::fields_CODE)->fetch();
                } catch (\Exception $e) {
                    // 忽略重复插入错误
                    if (!str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
                        throw $e;
                    }
                }
                
                $progress = 30 + 20 + (($index + 1) / $totalBatches) * 20;
                $this->setProgress($taskId, "正在插入区域数据... (批次 " . ($index + 1) . "/{$totalBatches})", $progress);
            }
        }
        
        $this->setProgress($taskId, '区域数据更新完成', 70);
    }
    
    /**
     * 更新所有区域名称数据（完整版本）
     */
    private function updateAllLocaleNames($taskId)
    {
        $this->setProgress($taskId, '正在更新区域名称数据...', 70);
        
        // 支持的语言列表
        $displayLanguages = ['en', 'zh_Hans_CN', 'zh_TW', 'ja', 'ko', 'fr', 'de', 'es', 'ru', 'ar'];
        
        /** @var Locale $locale */
        $locale = ObjectManager::getInstance(Locale::class);
        /** @var LocaleName $localeName */
        $localeName = ObjectManager::getInstance(LocaleName::class);
        /** @var I18n $i18n */
        $i18n = ObjectManager::getInstance(I18n::class);
        
        // 获取所有Locale
        $allLocales = $locale->clearQuery()->select()->fetch()->getItems();
        $localeNames = [];
        
        $processed = 0;
        $total = count($allLocales) * count($displayLanguages);
        
        foreach ($allLocales as $localeItem) {
            $localeCode = $localeItem->getData(Locale::fields_CODE);
            
            foreach ($displayLanguages as $displayLang) {
                $name = $this->getLocaleNameWithFallback($localeCode, $displayLang, $i18n);
                
                $localeNames[] = [
                    LocaleName::fields_LOCALE_CODE => $localeCode,
                    LocaleName::fields_DISPLAY_LOCALE_CODE => $displayLang,
                    LocaleName::fields_DISPLAY_NAME => $name
                ];
                
                $processed++;
                if ($processed % 100 === 0) {
                    $this->setProgress($taskId, "正在生成区域名称... ({$processed}/{$total})", 70 + ($processed / $total) * 20);
                }
            }
        }
        
        // 批量插入Locale名称数据
        if (!empty($localeNames)) {
            $batches = array_chunk($localeNames, self::BATCH_SIZE);
            $totalBatches = count($batches);
            
            foreach ($batches as $index => $batch) {
                try {
                    $localeName->clearQuery()->insert($batch, LocaleName::fields_LOCALE_CODE)->fetch();
                } catch (\Exception $e) {
                    // 忽略重复插入错误
                    if (!str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
                        throw $e;
                    }
                }
                
                $progress = 70 + 20 + (($index + 1) / $totalBatches) * 10;
                $this->setProgress($taskId, "正在插入区域名称数据... (批次 " . ($index + 1) . "/{$totalBatches})", $progress);
            }
        }
        
        $this->setProgress($taskId, '区域名称数据更新完成', 90);
    }
    
    /**
     * 更新Locale数据（分批版本）
     */
    private function updateLocales($taskId, $batch = 0)
    {
        $this->setProgress($taskId, '正在更新区域数据...', 30);
        
        /** @var I18n $i18n */
        $i18n = ObjectManager::getInstance(I18n::class);
        /** @var Locale $locale */
        $locale = ObjectManager::getInstance(Locale::class);
        
        // 获取所有国家
        $countries = ObjectManager::getInstance(Countries::class);
        $allCountries = $countries->clearQuery()->select()->fetch()->getItems();
        
        // 如果是第一批，先收集所有数据
        if ($batch === 0) {
            $allLocales = [];
            foreach ($allCountries as $country) {
                $countryCode = $country->getData(Countries::fields_CODE);
                $countryLocales = $i18n->getCountry($countryCode)->getLocales();
                
                foreach ($countryLocales as $localeCode) {
                    $allLocales[] = [
                        Locale::fields_CODE => $localeCode,
                        Locale::fields_COUNTRY_CODE => $countryCode,
                        Locale::fields_IS_ACTIVE => 0,
                        Locale::fields_IS_INSTALL => 0,
                        Locale::fields_FLAG => ''
                    ];
                }
            }
            
            // 保存到会话中
            $this->setSessionData('async_locales_' . $taskId, $allLocales);
            $this->setSessionData('async_locales_total_' . $taskId, count($allLocales));
        }
        
        // 获取当前批次的数据
        $allLocales = $this->getSessionData('async_locales_' . $taskId, []);
        $total = $this->getSessionData('async_locales_total_' . $taskId, 0);
        $batchData = array_slice($allLocales, $batch * self::BATCH_SIZE, self::BATCH_SIZE);
        
        if (!empty($batchData)) {
            // 插入当前批次
            $locale->clearQuery()->insert($batchData, Locale::fields_CODE)->fetch();
            
            $processed = ($batch + 1) * self::BATCH_SIZE;
            if ($processed > $total) $processed = $total;
            
            $progress = 30 + ($processed / $total) * 20;
            $this->setProgress($taskId, "正在插入区域数据... ({$processed}/{$total})", $progress);
            
            // 如果还有更多批次，返回继续处理
            if ($processed < $total) {
                return;
            }
        }
        
        // 清理会话数据
        $this->unsetSessionData('async_locales_' . $taskId);
        $this->unsetSessionData('async_locales_total_' . $taskId);
        
        $this->setProgress($taskId, '区域数据更新完成', 50);
    }
    
    /**
     * 更新Locale名称数据
     */
    private function updateLocaleNames($taskId, $batch = 0)
    {
        $this->setProgress($taskId, '正在更新区域名称数据...', 50);
        
        // 支持的语言列表
        $displayLanguages = ['en', 'zh_Hans_CN', 'zh_TW', 'ja', 'ko', 'fr', 'de', 'es', 'ru', 'ar'];
        
        /** @var Locale $locale */
        $locale = ObjectManager::getInstance(Locale::class);
        /** @var LocaleName $localeName */
        $localeName = ObjectManager::getInstance(LocaleName::class);
        /** @var I18n $i18n */
        $i18n = ObjectManager::getInstance(I18n::class);
        
        // 如果是第一批，先收集所有数据
        if ($batch === 0) {
            $allLocales = $locale->clearQuery()->select()->fetch()->getItems();
            $localeNames = [];
            
            foreach ($allLocales as $localeItem) {
                $localeCode = $localeItem->getData(Locale::fields_CODE);
                
                foreach ($displayLanguages as $displayLang) {
                    $localeName = $this->getLocaleNameWithFallback($localeCode, $displayLang, $i18n);
                    
                    $localeNames[] = [
                        LocaleName::fields_LOCALE_CODE => $localeCode,
                        LocaleName::fields_DISPLAY_LOCALE_CODE => $displayLang,
                        LocaleName::fields_DISPLAY_NAME => $localeName
                    ];
                }
            }
            
            // 保存到会话中
            $this->setSessionData('async_locale_names_' . $taskId, $localeNames);
            $this->setSessionData('async_locale_names_total_' . $taskId, count($localeNames));
        }
        
        // 获取当前批次的数据
        $allLocaleNames = $this->getSessionData('async_locale_names_' . $taskId, []);
        $total = $this->getSessionData('async_locale_names_total_' . $taskId, 0);
        $batchData = array_slice($allLocaleNames, $batch * self::BATCH_SIZE, self::BATCH_SIZE);
        
        if (!empty($batchData)) {
            // 插入当前批次
            $localeName->clearQuery()->insert($batchData, LocaleName::fields_LOCALE_CODE)->fetch();
            
            $processed = ($batch + 1) * self::BATCH_SIZE;
            if ($processed > $total) $processed = $total;
            
            $progress = 50 + ($processed / $total) * 40;
            $this->setProgress($taskId, "正在插入区域名称数据... ({$processed}/{$total})", $progress);
            
            // 如果还有更多批次，返回继续处理
            if ($processed < $total) {
                return;
            }
        }
        
        // 清理会话数据
        $this->unsetSessionData('async_locale_names_' . $taskId);
        $this->unsetSessionData('async_locale_names_total_' . $taskId);
        
        $this->setProgress($taskId, '区域名称数据更新完成', 90);
    }
    
    /**
     * 完成更新
     */
    private function finalizeUpdate($taskId)
    {
        $this->setProgress($taskId, '全球数据更新完成！', 100);
        
        // 设置完成状态
        $progressData = $this->getSessionData(self::PROGRESS_KEY_PREFIX . $taskId, []);
        $progressData['status'] = 'completed';
        $progressData['message'] = '全球数据更新完成！';
        $progressData['progress'] = 100;
        $progressData['last_update'] = time();
        
        $this->setSessionData(self::PROGRESS_KEY_PREFIX . $taskId, $progressData);
    }
    
    /**
     * 分批插入Locale数据
     */
    private function batchInsertLocales($taskId, $allLocales)
    {
        /** @var Locale $locale */
        $locale = ObjectManager::getInstance(Locale::class);
        
        $batches = array_chunk($allLocales, self::BATCH_SIZE);
        $totalBatches = count($batches);
        
        foreach ($batches as $index => $batch) {
            $locale->clearQuery()->insert($batch, Locale::fields_CODE)->fetch();
            
            $progress = 30 + (($index + 1) / $totalBatches) * 20;
            $this->setProgress($taskId, "正在插入区域数据... (批次 " . ($index + 1) . "/{$totalBatches})", $progress);
        }
    }
    
    /**
     * 分批插入Locale名称数据
     */
    private function batchInsertLocaleNames($taskId, $localeNames)
    {
        /** @var LocaleName $localeName */
        $localeName = ObjectManager::getInstance(LocaleName::class);
        
        $batches = array_chunk($localeNames, self::BATCH_SIZE);
        $totalBatches = count($batches);
        
        foreach ($batches as $index => $batch) {
            $localeName->clearQuery()->insert($batch, LocaleName::fields_LOCALE_CODE)->fetch();
            
            $progress = 50 + (($index + 1) / $totalBatches) * 40;
            $this->setProgress($taskId, "正在插入区域名称数据... (批次 " . ($index + 1) . "/{$totalBatches})", $progress);
        }
    }
    
    /**
     * 获取Locale名称（带降级处理）
     */
    private function getLocaleNameWithFallback($localeCode, $displayLang, $i18n)
    {
        try {
            // 尝试使用I18n服务获取名称
            $name = $i18n->getLocaleName($localeCode, $displayLang);
            if (!empty($name) && $name !== $localeCode) {
                return $name;
            }
        } catch (\Exception $e) {
            // 忽略错误，继续尝试其他方法
        }
        
        try {
            // 尝试使用Symfony Intl
            $name = \Symfony\Component\Intl\Locales::getName($localeCode, $displayLang);
            if (!empty($name) && $name !== $localeCode) {
                return $name;
            }
        } catch (\Exception $e) {
            // 忽略错误，继续尝试其他方法
        }
        
        try {
            // 尝试使用中文作为降级
            $name = \Symfony\Component\Intl\Locales::getName($localeCode, 'zh_Hans_CN');
            if (!empty($name) && $name !== $localeCode) {
                return $name;
            }
        } catch (\Exception $e) {
            // 忽略错误
        }
        
        // 最后降级到locale代码本身
        return $localeCode;
    }
    
    /**
     * 初始化进度
     */
    private function initProgress($taskId)
    {
        $progress = [
            'task_id' => $taskId,
            'status' => 'running',
            'message' => '准备开始更新...',
            'progress' => 0,
            'start_time' => time(),
            'error' => null
        ];
        
        $this->setSessionData(self::PROGRESS_KEY_PREFIX . $taskId, $progress);
    }
    
    /**
     * 设置进度
     */
    private function setProgress($taskId, $message, $progress)
    {
        $progressData = $this->getSessionData(self::PROGRESS_KEY_PREFIX . $taskId, []);
        $progressData['message'] = $message;
        $progressData['progress'] = $progress;
        $progressData['last_update'] = time();
        
        $this->setSessionData(self::PROGRESS_KEY_PREFIX . $taskId, $progressData);
    }
    
    /**
     * 设置错误
     */
    private function setProgressError($taskId, $error)
    {
        $progressData = $this->getSessionData(self::PROGRESS_KEY_PREFIX . $taskId, []);
        $progressData['status'] = 'error';
        $progressData['error'] = $error;
        $progressData['last_update'] = time();
        
        $this->setSessionData(self::PROGRESS_KEY_PREFIX . $taskId, $progressData);
    }
    
    /**
     * 获取进度
     */
    private function getProgress($taskId)
    {
        return $this->getSessionData(self::PROGRESS_KEY_PREFIX . $taskId, [
            'status' => 'not_found',
            'message' => '任务不存在',
            'progress' => 0
        ]);
    }
    
    /**
     * 清理进度
     */
    private function clearProgress($taskId)
    {
        $this->unsetSessionData(self::PROGRESS_KEY_PREFIX . $taskId);
    }
    
    /**
     * 设置会话数据
     */
    private function setSessionData($key, $value)
    {
        $_SESSION[$key] = $value;
    }
    
    /**
     * 获取会话数据
     */
    private function getSessionData($key, $default = null)
    {
        return $_SESSION[$key] ?? $default;
    }
    
    /**
     * 删除会话数据
     */
    private function unsetSessionData($key)
    {
        unset($_SESSION[$key]);
    }
}
