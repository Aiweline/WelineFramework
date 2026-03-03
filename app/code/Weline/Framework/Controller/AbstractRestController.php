<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Controller;

use Weline\Framework\Event\EventsManager;

abstract class AbstractRestController extends Core
{
    public const fetch_JSON = 'json';

    public const fetch_XML = 'xml';

    public const fetch_STRING = 'string';

    public function __construct()
    {
        # 设置前置事件
        $event = w_obj(EventsManager::class);
        $event->dispatch('Weline_Framework_RestController::init_before', $this);
        # 初始化父类（Core类没有构造函数，使用__init方法）
        $this->__init();
        # 设置后置事件
        $event->dispatch('Weline_Framework_RestController::init_after', $this);
    }

    /**
     * @DESC         |方法描述
     *
     * 参数区：
     *
     * @param        $data
     * @param string $type
     *
     * @return false|string
     */
    protected function fetch($data, string $type = self::fetch_JSON)
    {
        $result = null;
        $response = $this->request->getResponse();
        
        switch ($type) {
            case self::fetch_STRING:
                foreach ($data as $key => $datum) {
                    $result .= $key . ':' . $datum . ',';
                }
                $result = trim($data, ',');

                break;
            case self::fetch_XML:
                $response->setHeader('Content-Type', 'text/xml; charset=UTF-8');
                $result = $this->setXml($data);

                break;
            case self::fetch_JSON:
            default:
                $response->setHeader('Content-Type', 'application/json; charset=utf-8');
                // 将所有值转换为字符串
                $data = $this->convertAllToString($data);
                $result = json_encode($data, JSON_UNESCAPED_UNICODE);

                break;
        }

        return $result;
    }

    /**
     * 递归将所有值转换为字符串
     *
     * @param mixed $data
     * @return mixed
     */
    private function convertAllToString($data)
    {
        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                $result[$key] = $this->convertAllToString($value);
            }
            return $result;
        } elseif (is_object($data)) {
            // 如果是对象，转换为数组再处理
            $array = (array)$data;
            $result = [];
            foreach ($array as $key => $value) {
                $result[$key] = $this->convertAllToString($value);
            }
            return $result;
        } elseif (is_null($data)) {
            return '';
        } else {
            // 将所有其他类型（int, float, bool等）转换为字符串
            return (string)$data;
        }
    }

    private function setXml(array $data)
    {
        $xml = '<xml>';
        foreach ($data as $key => $val) {
            if (is_numeric($val)) {
                $xml .= "<$key>$val</$key>";
            } elseif (is_array($val)) {
                $xml_ = str_replace('<xml>', '', $this->setXml($val));
                $xml_ = str_replace('</xml>', '', $xml_);
                $xml  .= "<$key>{$xml_}</$key>";
            } else {
                $xml .= "<$key><![CDATA[$val]]></$key>";
            }
        }
        $xml .= '</xml>';

        return $xml;
    }

    /**
     * 返回成功响应
     * 
     * @param string $msg 成功消息
     * @param mixed $data 响应数据
     * @param int $code HTTP 状态码
     */
    protected function success(string $msg = '请求成功！', mixed $data = '', int $code = 200): array|string
    {
        $response = [
            'success' => true,
            'error' => false,
            'code' => $code,
            'msg' => __($msg),
            'message' => __($msg),
            'data' => $data,
        ];
        $result = $this->fetch($response);
        return $result ?: '';
    }

    /**
     * 返回错误响应（支持多语言和前端友好提示）
     * 
     * @param string $msg 错误消息
     * @param mixed $data 额外数据
     * @param int $code HTTP 状态码
     * @param string|null $title 错误标题（可选，默认根据状态码生成）
     */
    protected function error(string $msg = '请求失败！', mixed $data = '', int $code = 400, ?string $title = null): array|string
    {
        $response = [
            'success' => false,
            'error' => true,
            'code' => $code,
            'title' => $title ?? \Weline\Framework\Exception\ErrorResponse::getTitle($code),
            'msg' => __($msg),
            'message' => __($msg),
            'icon' => \Weline\Framework\Exception\ErrorResponse::getIcon($code),
            'data' => $data,
        ];
        $result = $this->fetch($response);
        return $result ?: '';
    }

    /**
     * 返回异常响应（支持多语言和前端友好提示）
     * 
     * @param \Throwable $exception 异常对象
     * @param string $msg 自定义错误消息（可选）
     * @param mixed $data 额外数据
     * @param int|null $code HTTP 状态码（可选，默认从异常获取）
     */
    protected function exception(\Throwable $exception, string $msg = '', mixed $data = '', ?int $code = null): array|string
    {
        $statusCode = $code ?? \Weline\Framework\Exception\ErrorResponse::getStatusCode($exception);
        $message = $msg ?: $exception->getMessage();
        
        $response = [
            'success' => false,
            'error' => true,
            'code' => $statusCode,
            'title' => \Weline\Framework\Exception\ErrorResponse::getTitle($statusCode),
            'msg' => __($message),
            'message' => __($message),
            'icon' => \Weline\Framework\Exception\ErrorResponse::getIcon($statusCode),
            'data' => $data,
        ];
        
        // DEV 模式添加调试信息
        if (\defined('DEV') && DEV) {
            $response['debug'] = [
                'exception' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ];
        }
        
        $result = $this->fetch($response);
        return $result ?: '';
    }
}
