<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Http;

interface RequestInterface
{
    public const GET = 'GET';

    public const POST = 'POST';

    public const PUT = 'PUT';

    public const DELETE = 'DELETE';

    public const UPDATE = 'UPDATE';

    public const METHODS = [
        self::GET,
        self::POST,
        self::PUT,
        self::DELETE,
        self::UPDATE,
    ];

    public const CONTENT_TYPE = [
        'json' => 'application/json',
        'xml' => 'application/xml',
    ];

    public const auth_TYPE_BEARER = 'bearer';

    public const auth_TYPE_BASIC_AUTH = 'basic';

    /**
     * @DESC         |获取服务server
     *
     * 参数区：
     *
     * @return array
     */
    public function getServer();

    /**
     * @DESC         |读取uri
     *
     * 参数区：
     *
     * @return string
     */
    public function getUri(): string;

    /**
     * @DESC         |获取内容类型
     *
     * 参数区：
     *
     * @return string
     */
    public function getContentType();

    /**
     * @DESC         |获取请求头
     *
     * 参数区：
     *
     * @param string $key
     *
     * @return array|null
     */
    public function getHeader(string $key = '');

    /**
     * @DESC         |获取Auth验证,默认方式 Bearer
     *
     * 参数区：
     *
     * @param string $auth_type
     *
     * @return array|string|null
     */
    public function getAuth(string $auth_type = self::auth_TYPE_BEARER);

    /**
     * @DESC         |API_KEY验证
     *
     * 参数区：
     *
     * @param string $key
     *
     * @return string
     */
    public function getApiKey(string $key): string;

    /**
     * @DESC         |获取传统键值对参数
     *
     * 参数区：
     *
     * @param string $key
     *
     * @return mixed
     */
    public function getParam(string $key);

    /**
     * @DESC         |获取全部参数
     *
     * 参数区：
     *
     * @return mixed
     */
    public function getParams();

    /**
     * @DESC         |获取body体json对应key参数
     *
     * 参数区：
     *
     * @param $key
     *
     * @return mixed
     */
    public function getBodyParam($key);

    /**
     * @DESC         |获取body参数,json自动转化为数组
     *
     * 参数区：
     *
     * @return mixed
     */
    public function getBodyParams();

    /**
     * @DESC         |是否post方法
     *
     * 参数区：
     *
     * @return bool
     */
    public function isPost(): bool;

    /**
     * @DESC         |是否GET请求
     *
     * 参数区：
     *
     * @return bool
     */
    public function isGet(): bool;

    /**
     * @DESC         |是否PUT请求
     *
     * 参数区：
     *
     * @return bool
     */
    public function isPut(): bool;

    /**
     * @DESC         |是否DELETE请求
     *
     * 参数区：
     *
     * @return bool
     */
    public function isDelete(): bool;

    /**
     * @DESC         |获取请求方法
     *
     * 参数区：
     *
     * @return string
     */
    public function getMethod(): string;

    /**
     * @DESC         |获取路径
     *
     * 参数区：
     *
     * @return string
     */
    public function getBaseUri(): string;

    /**
     * @DESC         |获取基础URL
     *
     * 参数区：
     *
     * @return string
     */
    public function getBaseHost(): string;

    /**
     * @DESC          # 获取模组名字
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/22 19:46
     * 参数区：
     * @return string
     */
    public function getModuleName(): string;
}
