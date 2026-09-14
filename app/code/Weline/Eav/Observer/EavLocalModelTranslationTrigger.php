<?php

declare(strict_types=1);

namespace Weline\Eav\Observer;

use Weline\Framework\Context;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Runtime\RequestContext;
use Weline\I18n\Service\LocalModelTranslation\LocalModelTranslationQueueService;

/**
 * Queue LocalModel translations after EAV structure/option writes.
 *
 * Translation stays asynchronous: the save request only performs the
 * idempotent queue check. A request memo prevents an option matrix import from
 * doing one queue lookup for every option row.
 */
final class EavLocalModelTranslationTrigger implements ObserverInterface
{
    private const REQUESTED_BY = 'Weline_Eav:eav_model_save_after';
    private const REQUEST_MEMO_KEY = 'i18n.local_model_translation.eav_enqueued';

    public function __construct(
        private readonly LocalModelTranslationQueueService $queueService,
    ) {
    }

    public function execute(Event &$event): void
    {
        $data = $event->getData('data');
        $model = $data instanceof \Weline\Framework\DataObject\DataObject ? $data->getData('model') : null;
        // 仅处理事件配置中登记的五类 EAV 元数据模型。
        if (!($model instanceof \Weline\Eav\Model\EavEntity)
            && !($model instanceof \Weline\Eav\Model\EavAttribute)
            && !($model instanceof \Weline\Eav\Model\EavAttribute\Set)
            && !($model instanceof \Weline\Eav\Model\EavAttribute\Group)
            && !($model instanceof \Weline\Eav\Model\EavAttribute\Option)
        ) {
            return;
        }

        if (Context::hasCurrent() && RequestContext::has(self::REQUEST_MEMO_KEY)) {
            return;
        }

        if (Context::hasCurrent()) {
            RequestContext::set(self::REQUEST_MEMO_KEY, true);
        }

        try {
            $this->queueService->enqueue(self::REQUESTED_BY);
        } catch (\Throwable $throwable) {
            // Translation is best-effort and must never make an EAV save fail.
            if (function_exists('w_log_error')) {
                w_log_error(
                    'EAV LocalModel translation enqueue failed: ' . $throwable->getMessage(),
                    [],
                    'i18n',
                );
            }
        }
    }
}
