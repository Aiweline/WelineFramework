<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2025/10/09
 */

namespace Weline\Ai\Service\Provider;

use Weline\Ai\Model\AiModel;

/**
 * AI提供者接口
 * 
 * 所有AI服务提供者必须实现此接口
 */
interface ProviderInterface
{
    /**
     * 生成内容
     * 
     * @param AiModel $model
     * @param string $prompt
     * @param array $params
     * @return array 返回数组包含：content, usage, model, finish_reason
     */
    public function generate(AiModel $model, string $prompt, array $params = []): array;

    /**
     * 流式生成
     * 
     * @param AiModel $model
     * @param string $prompt
     * @param callable $callback
     * @param array $params
     * @return array 返回数组包含：content, usage
     */
    public function generateStream(AiModel $model, string $prompt, callable $callback, array $params = []): array;

    /**
     * 检查是否支持该模型
     * 
     * @param string $modelCode
     * @return bool
     */
    public function supports(string $modelCode): bool;
}

