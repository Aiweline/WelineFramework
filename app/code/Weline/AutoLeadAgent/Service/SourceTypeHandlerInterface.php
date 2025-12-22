<?php

declare(strict_types=1);

namespace Weline\AutoLeadAgent\Service;

/**
 * 自动寻客来源类型处理接口
 *
 * 每种“任务来源类型”（如：店铺、产品、文章等）都应该实现本接口，
 * 以便通过事件向自动寻客系统统一提供类型、选项和处理逻辑。
 */
interface SourceTypeHandlerInterface
{
    /**
     * 获取类型标识（如：store、product）
     */
    public function getType(): string;

    /**
     * 获取类型显示名称（用于前端展示）
     */
    public function getName(): string;

    /**
     * 获取该类型下所有可选项
     *
     * 返回结构示例：
     * [
     *     ['id' => 1, 'name' => 'XX店铺', 'description' => '描述...', 'meta' => [...]],
     *     ...
     * ]
     */
    public function getOptions(): array;

    /**
     * 获取指定ID的详细信息（用于画像分析等）
     */
    public function getDetail(int $id): array;

    /**
     * 处理寻客任务
     *
     * @param int $taskId   搜索任务ID
     * @param int $sourceId 源对象ID（如店铺ID）
     */
    public function processTask(int $taskId, int $sourceId): void;
}


