<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Http;

interface UrlInterface
{
    /**
     * @DESC          # 创建后端API URL
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/11/6 21:15
     * 参数区：
     *
     * @param string $path
     * @param array  $params
     * @param bool   $merge_params
     *
     * @return string
     */
    public function getBackendApiUrl(string $path = '', array $params = [], bool $merge_params = true): string;

    /**
     * @DESC          # 获取URL
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/9/21 21:36
     * 参数区：
     *
     * @param string $path
     * @param array  $params
     * @param bool   $merge_params
     *
     * @return string
     */
    public function getUrl(string $path = '', array $params = [], bool $merge_params = false): string;

    /**
     * @DESC          # 获取后端URL
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/9/21 21:36
     * 参数区：
     *
     * @param string $path
     * @param array  $params
     * @param bool   $merge_params
     *
     * @return string
     */
    public function getBackendUrl(string $path = '', array $params = [], bool $merge_params = true): string;

    /**
     * @DESC          # 获取URI
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/9/22 22:30
     * 参数区：
     *
     * @param string $path
     *
     * @return string
     */
    public function getUri(string $path = ''): string;
}
