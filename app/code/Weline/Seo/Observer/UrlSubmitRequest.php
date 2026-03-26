<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Model\SeoAccount;
use Weline\Seo\Model\SeoSubject;
use Weline\Seo\Model\SeoTask;
use Weline\Seo\Service\EventDispatcher;

/**
 * URL 提交请求观察者
 *
 * 监听 Weline_Seo::integration::url_submit_request 事件，
 * 将 URL 按 scope 分发到对应的 SEO 账户并入队任务。
 */
class UrlSubmitRequest implements ObserverInterface
{
    private ObjectManager $objectManager;

    public function __construct(ObjectManager $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    public function execute(Event &$event): void
    {
        $data = $event->getData();

        $url = trim((string)($data['url'] ?? ''));
        $scope = trim((string)($data['scope'] ?? ''));
        $subjectType = (string)($data['subject_type'] ?? SeoSubject::SUBJECT_TYPE_PAGE);
        $subjectEntityId = (int)($data['subject_entity_id'] ?? $data['subject_id'] ?? 0);

        if ($url === '' || $scope === '') {
            return;
        }

        try {
            /** @var SeoSubject $subjectModel */
            $subjectModel = $this->objectManager->getInstance(SeoSubject::class);
            $subject = $subjectModel->findOrCreate($subjectType, $subjectEntityId);
            $subject->setUrl($url)
                ->setData(SeoSubject::schema_fields_SCOPE, $scope)
                ->setStatus(SeoSubject::STATUS_ENABLED)
                ->save();

            $subjectId = $subject->getId();
            if (!$subjectId) {
                return;
            }

            /** @var SeoAccount $accountModel */
            $accountModel = $this->objectManager->getInstance(SeoAccount::class);

            // 查找匹配 scope 的启用账户
            $accounts = $accountModel->reset()
                ->where(SeoAccount::schema_fields_SCOPE, $scope)
                ->where(SeoAccount::schema_fields_IS_ACTIVE, SeoAccount::STATUS_ACTIVE)
                ->select()
                ->fetchArray();

            if (empty($accounts)) {
                return;
            }

            /** @var SeoTask $taskModel */
            $taskModel = $this->objectManager->getInstance(SeoTask::class);

            /** @var EventDispatcher $eventDispatcher */
            $eventDispatcher = $this->objectManager->getInstance(EventDispatcher::class);

            foreach ($accounts as $accountData) {
                $provider = (string)($accountData[SeoAccount::schema_fields_PROVIDER] ?? '');
                $accountId = (int)($accountData[SeoAccount::schema_fields_ACCOUNT_ID] ?? 0);

                if ($provider === '' || $accountId <= 0) {
                    continue;
                }

                $taskPayload = [
                    'urls' => [$url],
                    'provider' => $provider,
                    'account_id' => $accountId,
                    'scope' => $scope,
                ];

                $task = $taskModel->reset()
                    ->setTaskType(SeoTask::TASK_TYPE_PUSH_URLS)
                    ->setSubjectType($subjectType)
                    ->setSubjectId($subjectId)
                    ->setPayloadArray($taskPayload)
                    ->setPriority(SeoTask::PRIORITY_NORMAL)
                    ->setStatus(SeoTask::STATUS_PENDING)
                    ->setMaxAttempts(3);

                $task->setData(SeoTask::schema_fields_SCOPE, $scope)
                    ->save();

                $taskId = (int)$task->getId();
                if ($taskId) {
                    $eventDispatcher->dispatchTaskEnqueued(
                        $taskId,
                        SeoTask::TASK_TYPE_PUSH_URLS,
                        $subjectType,
                        $subjectId,
                        [
                            'scope' => $scope,
                        ]
                    );
                }
            }
        } catch (\Throwable $e) {
            // TODO: 使用框架日志记录错误
        }
    }
}

