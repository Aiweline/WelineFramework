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

use Weline\Acl\Model\Acl as AclModel;
use Weline\Framework\Acl\Acl as AclAttribute;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Ui\FormKey;
use Weline\I18n\Controller\Backend\BaseController;
use Weline\I18n\Model\Countries;
use Weline\I18n\Model\Countries\Locale\Name;
use Weline\I18n\Model\I18n;
use Weline\I18n\Model\Locale;
use Weline\I18n\Model\Locale\Name as LocaleName;

class AsyncUpdate extends BaseController
{
    private const BATCH_SIZE = 100;
    private const PROGRESS_KEY_PREFIX = 'i18n_update_progress_';
    private const SESSION_KEY_PREFIX = 'weline_i18n_async_update.';
    private const MAX_BATCH_INDEX = 100000;

    protected function csrf(): string
    {
        return FormKey::key_name;
    }
    
    #[AclAttribute('Weline_I18n::i18n_countries_async_start', '启动国家数据异步更新', 'mdi mdi-refresh', '启动国家地区异步更新', 'Weline_I18n::i18n_countries', accessMode: AclModel::ACCESS_MODE_EDIT)]
    public function index()
    {
        try {
            $this->assertPostRequest();

            // 生成唯一的任务ID
            $taskId = 'update_' . bin2hex(random_bytes(16));
            
            // 初始化进度
            $this->initProgress($taskId);
            
            // 立即开始执行更新
            $this->executeFullUpdate($taskId);
            
            // 返回任务ID给前端
            return $this->jsonResponse([
                'success' => true,
                'task_id' => $taskId,
                'message' => (string)__('开始异步更新全球数据...')
            ]);
            
        } catch (\Throwable $e) {
            w_log_error('I18n async country update start failed: ' . $e->getMessage(), [], 'i18n');
            return $this->jsonResponse([
                'success' => false,
                'message' => (string)__('启动更新失败：%{1}', $e->getMessage())
            ], $this->statusCodeForException($e));
        }
    }
    
    /**
     * 执行完整的数据更新
     */
    private function executeFullUpdate(string $taskId): void
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
            
        } catch (\Throwable $e) {
            $this->setProgressError($taskId, $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 执行异步更新任务
     */
    #[AclAttribute('Weline_I18n::i18n_countries_async_execute', '执行国家数据异步更新', 'mdi mdi-refresh', '执行国家地区异步更新', 'Weline_I18n::i18n_countries', accessMode: AclModel::ACCESS_MODE_EDIT)]
    public function execute()
    {
        try {
            $this->assertPostRequest();

            $taskId = $this->sanitizeTaskId($this->request->getPost('task_id'));
            $step = $this->sanitizeStep($this->request->getPost('step', 1));
            $batch = $this->sanitizeBatch($this->request->getPost('batch', 0));

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
                    throw new \InvalidArgumentException((string)__('未知的更新步骤'));
            }
            
            return $this->jsonResponse([
                'success' => true,
                'task_id' => $taskId,
                'step' => $step,
                'batch' => $batch,
                'progress' => $this->getProgress($taskId)
            ]);
            
        } catch (\Throwable $e) {
            if (isset($taskId) && $taskId !== '') {
                $this->setProgressError($taskId, $e->getMessage());
            }
            w_log_error('I18n async country update execute failed: ' . $e->getMessage(), [], 'i18n');
            return $this->jsonResponse([
                'success' => false,
                'message' => (string)__('执行更新失败：%{1}', $e->getMessage())
            ], $this->statusCodeForException($e));
        }
    }
    
    /**
     * 获取更新进度
     */
    #[AclAttribute('Weline_I18n::i18n_countries_async_progress', '查看国家数据更新进度', 'mdi mdi-progress-clock', '查看国家地区异步更新进度', 'Weline_I18n::i18n_countries', accessMode: AclModel::ACCESS_MODE_READ)]
    public function progress()
    {
        try {
            $taskId = $this->sanitizeTaskId($this->request->getGet('task_id'));
            $progress = $this->getProgress($taskId);

            return $this->jsonResponse([
                'success' => true,
                'progress' => $progress
            ]);
        } catch (\Throwable $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => (string)$e->getMessage()
            ], $this->statusCodeForException($e));
        }
    }
    
    /**
     * 更新国家数据
     */
    private function updateCountries(string $taskId): void
    {
        $this->setProgress($taskId, (string)__('正在更新国家数据...'), 10);
        
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
            $existingCodes[] = $country->getData(Countries::schema_fields_CODE);
        }
        
        $processed = 0;
        $total = count($availableCountries);
        if ($total === 0) {
            throw new \RuntimeException((string)__('没有找到可安装的国家数据！'));
        }
        
        foreach ($availableCountries as $code => $country) {
            $countryData = [
                Countries::schema_fields_CODE => $code,
                Countries::schema_fields_FLAG => (string)$i18n->getCountryFlag($code),
                Countries::schema_fields_IS_ACTIVE => ($code === 'CN') ? 1 : 0,
                Countries::schema_fields_IS_INSTALL => ($code === 'CN') ? 1 : 0,
            ];
            
            $displayData = [
                Name::schema_fields_COUNTRY_CODE => $code,
                Name::schema_fields_DISPLAY_LOCALE_CODE => 'en',
                Name::schema_fields_DISPLAY_NAME => $country,
            ];
            
            if (in_array($code, $existingCodes)) {
                $update_countries[] = $countryData;
                $update_countries_display[] = $displayData;
            } else {
                $insert_countries[] = $countryData;
                $insert_countries_display[] = $displayData;
            }
            
            $processed++;
            $this->setProgress($taskId, (string)__('正在处理国家数据... (%{processed}/%{total})', [
                'processed' => $processed,
                'total' => $total,
            ]), 10 + ($processed / $total) * 20);
        }
        
        // 批量插入新国家
        if (!empty($insert_countries)) {
            $countries->clearQuery()->insert($insert_countries, Countries::schema_fields_CODE)->fetch();
            // 使用联合唯一索引字段作为冲突检测
            $localeNames->clearQuery()->insert($insert_countries_display, $localeNames::schema_fields_COUNTRY_CODE . ',' . $localeNames::schema_fields_DISPLAY_LOCALE_CODE)->fetch();
        }
        
        // 批量更新现有国家
        if (!empty($update_countries)) {
            foreach ($update_countries as $updateData) {
                $countries->clearQuery()
                    ->where(Countries::schema_fields_CODE, $updateData[Countries::schema_fields_CODE])
                    ->update($updateData)->fetch();
            }
        }
        
        $this->setProgress($taskId, (string)__('国家数据更新完成'), 30);
    }
    
    /**
     * 更新所有区域数据（完整版本）
     */
    private function updateAllLocales(string $taskId): void
    {
        $this->setProgress($taskId, (string)__('正在更新区域数据...'), 30);
        
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
        if ($total === 0) {
            throw new \RuntimeException((string)__('没有找到已安装的国家'));
        }
        
        foreach ($allCountries as $country) {
            $countryCode = $country->getData(Countries::schema_fields_CODE);
            
            try {
                $country = $i18n->getCountry($countryCode);
                $countryLocales = (array)($country['locales'] ?? []);
                
                foreach ($countryLocales as $localeCode) {
                    $allLocales[] = [
                        Locale::schema_fields_CODE => $localeCode,
                        Locale::schema_fields_COUNTRY_CODE => $countryCode,
                        Locale::schema_fields_IS_ACTIVE => 0,
                        Locale::schema_fields_IS_INSTALL => 0,
                        Locale::schema_fields_FLAG => ''
                    ];
                }
            } catch (\Exception $e) {
                // 跳过无法获取区域的国家
                w_log_warning((string)__('无法获取国家 %{1} 的区域信息: %{2}', [$countryCode, $e->getMessage()]), [], 'i18n');
            }
            
            $processed++;
            $this->setProgress($taskId, (string)__('正在收集区域数据... (%{processed}/%{total})', [
                'processed' => $processed,
                'total' => $total,
            ]), 30 + ($processed / $total) * 20);
        }
        
        // 批量插入Locale数据
        if (!empty($allLocales)) {
            $batches = array_chunk($allLocales, self::BATCH_SIZE);
            $totalBatches = count($batches);
            
            foreach ($batches as $index => $batch) {
                try {
                    $locale->clearQuery()->insert($batch, Locale::schema_fields_CODE)->fetch();
                } catch (\Exception $e) {
                    // 忽略重复插入错误
                    if (!str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
                        throw $e;
                    }
                }
                
                $progress = 30 + 20 + (($index + 1) / $totalBatches) * 20;
                $this->setProgress($taskId, (string)__('正在插入区域数据... (批次 %{batch}/%{total})', [
                    'batch' => $index + 1,
                    'total' => $totalBatches,
                ]), $progress);
            }
        }
        
        $this->setProgress($taskId, (string)__('区域数据更新完成'), 70);
    }
    
    /**
     * 更新所有区域名称数据（完整版本）
     */
    private function updateAllLocaleNames(string $taskId): void
    {
        $this->setProgress($taskId, (string)__('正在更新区域名称数据...'), 70);
        
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
        if ($total === 0) {
            throw new \RuntimeException((string)__('没有找到可更新的区域数据'));
        }
        
        foreach ($allLocales as $localeItem) {
            $localeCode = $localeItem->getData(Locale::schema_fields_CODE);
            
            foreach ($displayLanguages as $displayLang) {
                $name = $this->getLocaleNameWithFallback($localeCode, $displayLang, $i18n);
                
                $localeNames[] = [
                    LocaleName::schema_fields_LOCALE_CODE => $localeCode,
                    LocaleName::schema_fields_DISPLAY_LOCALE_CODE => $displayLang,
                    LocaleName::schema_fields_DISPLAY_NAME => $name
                ];
                
                $processed++;
                if ($processed % 100 === 0) {
                    $this->setProgress($taskId, (string)__('正在生成区域名称... (%{processed}/%{total})', [
                        'processed' => $processed,
                        'total' => $total,
                    ]), 70 + ($processed / $total) * 20);
                }
            }
        }
        
        // 批量插入Locale名称数据
        if (!empty($localeNames)) {
            $batches = array_chunk($localeNames, self::BATCH_SIZE);
            $totalBatches = count($batches);
            
            foreach ($batches as $index => $batch) {
                try {
                    // 使用联合唯一索引字段作为冲突检测
                    $localeName->clearQuery()->insert($batch, LocaleName::schema_fields_LOCALE_CODE . ',' . LocaleName::schema_fields_DISPLAY_LOCALE_CODE)->fetch();
                } catch (\Exception $e) {
                    // 忽略重复插入错误
                    if (!str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
                        throw $e;
                    }
                }
                
                $progress = 70 + 20 + (($index + 1) / $totalBatches) * 10;
                $this->setProgress($taskId, (string)__('正在插入区域名称数据... (批次 %{batch}/%{total})', [
                    'batch' => $index + 1,
                    'total' => $totalBatches,
                ]), $progress);
            }
        }
        
        $this->setProgress($taskId, (string)__('区域名称数据更新完成'), 90);
    }
    
    /**
     * 更新Locale数据（分批版本）
     */
    private function updateLocales(string $taskId, int $batch = 0): void
    {
        $this->setProgress($taskId, (string)__('正在更新区域数据...'), 30);
        
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
                $countryCode = $country->getData(Countries::schema_fields_CODE);
                $country = $i18n->getCountry($countryCode);
                $countryLocales = (array)($country['locales'] ?? []);
                
                foreach ($countryLocales as $localeCode) {
                    $allLocales[] = [
                        Locale::schema_fields_CODE => $localeCode,
                        Locale::schema_fields_COUNTRY_CODE => $countryCode,
                        Locale::schema_fields_IS_ACTIVE => 0,
                        Locale::schema_fields_IS_INSTALL => 0,
                        Locale::schema_fields_FLAG => ''
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
        if (!\is_array($allLocales)) {
            throw new \RuntimeException((string)__('任务状态数据无效'));
        }
        $total = (int)$total;
        $batchData = array_slice($allLocales, $batch * self::BATCH_SIZE, self::BATCH_SIZE);
        
        if (!empty($batchData)) {
            // 插入当前批次
            $locale->clearQuery()->insert($batchData, Locale::schema_fields_CODE)->fetch();
            
            $processed = ($batch + 1) * self::BATCH_SIZE;
            if ($processed > $total) $processed = $total;
            
            $progress = $total > 0 ? 30 + ($processed / $total) * 20 : 50;
            $this->setProgress($taskId, (string)__('正在插入区域数据... (%{processed}/%{total})', [
                'processed' => $processed,
                'total' => $total,
            ]), $progress);
            
            // 如果还有更多批次，返回继续处理
            if ($processed < $total) {
                return;
            }
        }
        
        // 清理会话数据
        $this->unsetSessionData('async_locales_' . $taskId);
        $this->unsetSessionData('async_locales_total_' . $taskId);
        
        $this->setProgress($taskId, (string)__('区域数据更新完成'), 50);
    }
    
    /**
     * 更新Locale名称数据
     */
    private function updateLocaleNames(string $taskId, int $batch = 0): void
    {
        $this->setProgress($taskId, (string)__('正在更新区域名称数据...'), 50);
        
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
                $localeCode = $localeItem->getData(Locale::schema_fields_CODE);
                
                foreach ($displayLanguages as $displayLang) {
                    $localeName = $this->getLocaleNameWithFallback($localeCode, $displayLang, $i18n);
                    
                    $localeNames[] = [
                        LocaleName::schema_fields_LOCALE_CODE => $localeCode,
                        LocaleName::schema_fields_DISPLAY_LOCALE_CODE => $displayLang,
                        LocaleName::schema_fields_DISPLAY_NAME => $localeName
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
        if (!\is_array($allLocaleNames)) {
            throw new \RuntimeException((string)__('任务状态数据无效'));
        }
        $total = (int)$total;
        $batchData = array_slice($allLocaleNames, $batch * self::BATCH_SIZE, self::BATCH_SIZE);
        
        if (!empty($batchData)) {
            // 插入当前批次，使用联合唯一索引字段作为冲突检测
            $localeName->clearQuery()->insert($batchData, LocaleName::schema_fields_LOCALE_CODE . ',' . LocaleName::schema_fields_DISPLAY_LOCALE_CODE)->fetch();
            
            $processed = ($batch + 1) * self::BATCH_SIZE;
            if ($processed > $total) $processed = $total;
            
            $progress = $total > 0 ? 50 + ($processed / $total) * 40 : 90;
            $this->setProgress($taskId, (string)__('正在插入区域名称数据... (%{processed}/%{total})', [
                'processed' => $processed,
                'total' => $total,
            ]), $progress);
            
            // 如果还有更多批次，返回继续处理
            if ($processed < $total) {
                return;
            }
        }
        
        // 清理会话数据
        $this->unsetSessionData('async_locale_names_' . $taskId);
        $this->unsetSessionData('async_locale_names_total_' . $taskId);
        
        $this->setProgress($taskId, (string)__('区域名称数据更新完成'), 90);
    }
    
    /**
     * 完成更新
     */
    private function finalizeUpdate(string $taskId): void
    {
        $this->setProgress($taskId, (string)__('全球数据更新完成！'), 100);
        
        // 设置完成状态
        $progressData = $this->getSessionData(self::PROGRESS_KEY_PREFIX . $taskId, []);
        $progressData['status'] = 'completed';
        $progressData['message'] = (string)__('全球数据更新完成！');
        $progressData['progress'] = 100;
        $progressData['last_update'] = time();
        
        $this->setSessionData(self::PROGRESS_KEY_PREFIX . $taskId, $progressData);
    }
    
    /**
     * 分批插入Locale数据
     */
    private function batchInsertLocales(string $taskId, array $allLocales): void
    {
        /** @var Locale $locale */
        $locale = ObjectManager::getInstance(Locale::class);
        
        $batches = array_chunk($allLocales, self::BATCH_SIZE);
        $totalBatches = count($batches);
        
        foreach ($batches as $index => $batch) {
            $locale->clearQuery()->insert($batch, Locale::schema_fields_CODE)->fetch();
            
            $progress = 30 + (($index + 1) / $totalBatches) * 20;
            $this->setProgress($taskId, (string)__('正在插入区域数据... (批次 %{batch}/%{total})', [
                'batch' => $index + 1,
                'total' => $totalBatches,
            ]), $progress);
        }
    }
    
    /**
     * 分批插入Locale名称数据
     */
    private function batchInsertLocaleNames(string $taskId, array $localeNames): void
    {
        /** @var LocaleName $localeName */
        $localeName = ObjectManager::getInstance(LocaleName::class);
        
        $batches = array_chunk($localeNames, self::BATCH_SIZE);
        $totalBatches = count($batches);
        
        foreach ($batches as $index => $batch) {
            // 使用联合唯一索引字段作为冲突检测
            $localeName->clearQuery()->insert($batch, LocaleName::schema_fields_LOCALE_CODE . ',' . LocaleName::schema_fields_DISPLAY_LOCALE_CODE)->fetch();
            
            $progress = 50 + (($index + 1) / $totalBatches) * 40;
            $this->setProgress($taskId, (string)__('正在插入区域名称数据... (批次 %{batch}/%{total})', [
                'batch' => $index + 1,
                'total' => $totalBatches,
            ]), $progress);
        }
    }
    
    /**
     * 获取Locale名称（带降级处理）
     */
    private function getLocaleNameWithFallback(string $localeCode, string $displayLang, I18n $i18n): string
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
    private function initProgress(string $taskId): void
    {
        $progress = [
            'task_id' => $taskId,
            'status' => 'running',
            'message' => (string)__('准备开始更新...'),
            'progress' => 0,
            'start_time' => time(),
            'error' => null
        ];
        
        $this->setSessionData(self::PROGRESS_KEY_PREFIX . $taskId, $progress);
    }
    
    /**
     * 设置进度
     */
    private function setProgress(string $taskId, string $message, int|float $progress): void
    {
        $progressData = $this->getSessionData(self::PROGRESS_KEY_PREFIX . $taskId, []);
        $progressData['message'] = $message;
        $progressData['progress'] = max(0, min(100, (float)$progress));
        $progressData['last_update'] = time();
        
        $this->setSessionData(self::PROGRESS_KEY_PREFIX . $taskId, $progressData);
    }
    
    /**
     * 设置错误
     */
    private function setProgressError(string $taskId, string $error): void
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
    private function getProgress(string $taskId): array
    {
        $progress = $this->getSessionData(self::PROGRESS_KEY_PREFIX . $taskId, [
            'status' => 'not_found',
            'message' => (string)__('任务不存在'),
            'progress' => 0
        ]);

        return \is_array($progress) ? $progress : [
            'status' => 'invalid',
            'message' => (string)__('任务状态数据无效'),
            'progress' => 0,
        ];
    }
    
    /**
     * 清理进度
     */
    private function clearProgress(string $taskId): void
    {
        $this->unsetSessionData(self::PROGRESS_KEY_PREFIX . $taskId);
    }
    
    /**
     * 设置会话数据
     */
    private function setSessionData(string $key, mixed $value): void
    {
        $this->session->set($this->sessionKey($key), $value);
        $this->session->getSession()->save();
    }
    
    /**
     * 获取会话数据
     */
    private function getSessionData(string $key, mixed $default = null): mixed
    {
        $value = $this->session->get($this->sessionKey($key));

        return $value ?? $default;
    }
    
    /**
     * 删除会话数据
     */
    private function unsetSessionData(string $key): void
    {
        $this->session->delete($this->sessionKey($key));
        $this->session->getSession()->save();
    }

    private function sessionKey(string $key): string
    {
        $key = trim($key);
        if ($key === '' || !preg_match('/^[A-Za-z0-9_.-]+$/', $key)) {
            throw new \InvalidArgumentException((string)__('任务状态键无效'));
        }

        return self::SESSION_KEY_PREFIX . $key;
    }

    private function assertPostRequest(): void
    {
        if (!$this->request->isPost()) {
            throw new \RuntimeException((string)__('请求方法错误，请使用 POST 提交'));
        }
    }

    private function sanitizeTaskId(mixed $taskId): string
    {
        $taskId = trim((string)$taskId);
        if ($taskId === '') {
            throw new \InvalidArgumentException((string)__('缺少任务ID'));
        }
        if (!preg_match('/^update_[A-Fa-f0-9]{32}$/', $taskId)) {
            throw new \InvalidArgumentException((string)__('任务ID格式无效'));
        }

        return $taskId;
    }

    private function sanitizeStep(mixed $step): int
    {
        $step = filter_var($step, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 4],
        ]);
        if ($step === false) {
            throw new \InvalidArgumentException((string)__('未知的更新步骤'));
        }

        return (int)$step;
    }

    private function sanitizeBatch(mixed $batch): int
    {
        $batch = filter_var($batch, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => self::MAX_BATCH_INDEX],
        ]);
        if ($batch === false) {
            throw new \InvalidArgumentException((string)__('批次参数无效'));
        }

        return (int)$batch;
    }

    private function jsonResponse(array $payload, int $statusCode = 200): string
    {
        $response = $this->request->getResponse();
        $response->setHttpResponseCode($statusCode)
            ->setHeader('Content-Type', 'application/json; charset=utf-8')
            ->setBody((string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));

        return $response->getBody();
    }

    private function statusCodeForException(\Throwable $throwable): int
    {
        return $throwable instanceof \InvalidArgumentException ? 400 : 500;
    }
}
