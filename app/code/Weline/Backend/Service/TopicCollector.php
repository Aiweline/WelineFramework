<?php

declare(strict_types=1);

namespace Weline\Backend\Service;

use Weline\Backend\Api\NotificationTopicProviderInterface;
use Weline\Backend\Model\NotificationTopic;
use Weline\Framework\Manager\ObjectManager;

class TopicCollector
{
    private NotificationTopic $topicModel;

    public function __construct(
        NotificationTopic $topicModel
    ) {
        $this->topicModel = $topicModel;
    }

    /**
     * 收集所有主题并同步到数据库
     */
    public function collect(): void
    {
        $providers = $this->getProviders();
        $existingTopics = $this->getExistingTopicCodes();
        $processedCodes = [];

        foreach ($providers as $provider) {
            if (!$provider instanceof NotificationTopicProviderInterface) {
                continue;
            }
            $moduleName = $this->getModuleNameFromProvider($provider);
            $topics = $provider->getTopics();

            foreach ($topics as $topicData) {
                $code = $topicData['code'] ?? '';
                if (empty($code)) {
                    continue;
                }

                $processedCodes[] = $code;
                $this->saveOrUpdateTopic($topicData, $moduleName, $existingTopics);
            }
        }

        $this->disableOrphanedTopics($existingTopics, $processedCodes);
    }

    /**
     * 获取所有主题提供者
     *
     * 从 extends 注册表读取实现类并实例化，与 ChannelAdapterCollector 一致；
     * 不使用 ObjectManager::getInstances()，因其无参时返回所有已缓存实例，会误含非提供者类型。
     *
     * @return NotificationTopicProviderInterface[]
     */
    private function getProviders(): array
    {
        $implClasses = $this->getProviderClassesFromExtends();
        $providers = [];
        foreach ($implClasses as $implClass) {
            if (!is_string($implClass) || !class_exists($implClass)) {
                continue;
            }
            $instance = ObjectManager::getInstance($implClass);
            if ($instance instanceof NotificationTopicProviderInterface) {
                $providers[] = $instance;
            }
        }
        return $providers;
    }

    /**
     * 从各模块 extends 规约合并出 NotificationTopicProviderInterface 实现类列表
     *
     * @return string[]
     */
    private function getProviderClassesFromExtends(): array
    {
        $interface = NotificationTopicProviderInterface::class;
        $merged = [];
        $moduleList = \Weline\Framework\App\Env::getInstance()->getModuleList();
        foreach ($moduleList as $moduleName => $module) {
            $basePath = $module['base_path'] ?? '';
            if ($basePath === '' || !($module['status'] ?? false)) {
                continue;
            }
            $extendsFile = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'extends.php';
            if (!is_file($extendsFile)) {
                continue;
            }
            $config = include $extendsFile;
            if (!is_array($config) || !isset($config[$interface]) || !is_array($config[$interface])) {
                continue;
            }
            foreach ($config[$interface] as $implClass) {
                if (is_string($implClass)) {
                    $merged[] = $implClass;
                }
            }
        }
        return array_values(array_unique($merged));
    }

    /**
     * 获取已存在的主题代码列表
     */
    private function getExistingTopicCodes(): array
    {
        $topics = $this->topicModel->clearQuery()
            ->fields('topic_code')
            ->select()
            ->fetchArray();

        $codes = [];
        foreach ($topics as $topic) {
            $codes[$topic['topic_code']] = true;
        }
        return $codes;
    }

    /**
     * 保存或更新主题
     */
    private function saveOrUpdateTopic(array $topicData, string $moduleName, array $existingTopics): void
    {
        $code = $topicData['code'];
        $isNew = !isset($existingTopics[$code]);

        $model = clone $this->topicModel;
        $model->clearQuery();

        if (!$isNew) {
            $model->where(NotificationTopic::schema_fields_topic_code, $code)
                ->find()
                ->fetch();
        }

        $model->setTopicCode($code)
            ->setTopicName($topicData['name'] ?? $code)
            ->setTopicGroup($topicData['group'] ?? '')
            ->setTopicGroupName($topicData['group_name'] ?? '')
            ->setDescription($topicData['description'] ?? '')
            ->setModule($moduleName)
            ->setIcon($topicData['icon'] ?? 'ri-notification-line')
            ->setColor($topicData['color'] ?? '#50a5f1')
            ->setDefaultChannels($topicData['default_channels'] ?? ['backend'])
            ->setIsEnabled(true)
            ->setSortOrder($topicData['sort_order'] ?? 0);

        $model->save();
    }

    /**
     * 禁用孤立的主题（不再被任何提供者注册）
     */
    private function disableOrphanedTopics(array $existingTopics, array $processedCodes): void
    {
        foreach (array_keys($existingTopics) as $code) {
            if (!in_array($code, $processedCodes, true)) {
                $model = clone $this->topicModel;
                $model->clearQuery()
                    ->where(NotificationTopic::schema_fields_topic_code, $code)
                    ->find()
                    ->fetch();

                if ($model->getId()) {
                    $model->setIsEnabled(false)->save();
                }
            }
        }
    }

    /**
     * 从提供者类名获取模块名
     */
    private function getModuleNameFromProvider(NotificationTopicProviderInterface $provider): string
    {
        $className = get_class($provider);
        if (preg_match('/^([A-Za-z0-9_]+\\\\[A-Za-z0-9_]+)\\\\/', $className, $matches)) {
            return str_replace('\\', '_', $matches[1]);
        }
        return '';
    }

    /**
     * 获取所有已启用的主题
     *
     * @return array
     */
    public function getEnabledTopics(): array
    {
        return $this->topicModel->clearQuery()
            ->where(NotificationTopic::schema_fields_is_enabled, 1)
            ->order(NotificationTopic::schema_fields_topic_group)
            ->order(NotificationTopic::schema_fields_sort_order)
            ->select()
            ->fetchArray();
    }

    /**
     * 按分组获取主题
     *
     * @return array 格式：[group => [topics...], ...]
     */
    public function getTopicsGrouped(): array
    {
        $topics = $this->getEnabledTopics();
        $grouped = [];

        foreach ($topics as $topic) {
            $group = $topic['topic_group'] ?: 'default';
            if (!isset($grouped[$group])) {
                $grouped[$group] = [
                    'group_name' => $topic['topic_group_name'] ?: __('其他'),
                    'topics' => [],
                ];
            }
            $grouped[$group]['topics'][] = $topic;
        }

        return $grouped;
    }

    /**
     * 根据代码获取主题
     */
    public function getTopicByCode(string $code): ?array
    {
        $topic = $this->topicModel->clearQuery()
            ->where(NotificationTopic::schema_fields_topic_code, $code)
            ->select()
            ->fetch();

        if ($topic && $topic->getId()) {
            return $topic->getData();
        }

        return null;
    }
}
