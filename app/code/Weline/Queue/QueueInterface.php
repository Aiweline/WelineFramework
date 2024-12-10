<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Administrator
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：11/7/2023 09:26:19
 */

namespace Weline\Queue;

use Weline\Queue\Model\Queue;

interface QueueInterface
{
    /**
     * @DESC          # 队列名
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 11/7/2023 下午2:01
     * 参数区：
     * @return string
     */
    public function name(): string;

    /**
     * @DESC          # 队列类型所需属性  使用：\Weline\Eav\Model\EavAttribute[]
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 23/4/2024 下午4:55
     * 参数区：
     * @return array \Weline\Eav\Model\EavAttribute[]
     */
    public function attributes(): array;

    /**
     * @DESC          # 提示
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 25/7/2023 下午3:41
     * 参数区：
     * @return string
     */
    public function tip(): string;

    /**
     * @DESC          # 队列执行方法
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 11/7/2023 下午2:01
     * 参数区：
     * @return string 执行结果
     */
    public function execute(Queue &$queue): string;

    /**
     * @DESC          # 验证数据结构是否正确 【只需要设置队列的Result验证结果即可：示例$queue->setResult('验证失败！');】
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 25/7/2023 下午3:40
     * 参数区：
     * @return string|bool
     */
    public function validate(Queue &$queue): bool;
}