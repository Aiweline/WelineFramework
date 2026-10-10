<?php

declare(strict_types=1);

namespace Weline\Order\Api\Data;

/**
 * 后台生命周期阶段投影（与订单类型徽章分离）。
 */
final class LifecycleStageView
{
    /**
     * @param list<array{code: string, label: string, state: string, tone: string}> $steps
     * @param list<array{code: string, label: string, tone: string, path: string, params: array<string, string>, testid?: string}> $actions
     */
    public function __construct(
        public readonly string $title,
        public readonly string $currentCode,
        public readonly string $currentLabel,
        public readonly string $tone,
        public readonly array $steps = [],
        public readonly array $actions = [],
    ) {
    }

    /**
     * @return array{
     *   title: string,
     *   current_code: string,
     *   current_label: string,
     *   tone: string,
     *   steps: list<array{code: string, label: string, state: string, tone: string}>,
     *   actions: list<array{code: string, label: string, tone: string, path: string, params: array<string, string>, testid?: string}>
     * }
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'current_code' => $this->currentCode,
            'current_label' => $this->currentLabel,
            'tone' => $this->tone,
            'steps' => $this->steps,
            'actions' => $this->actions,
        ];
    }
}
