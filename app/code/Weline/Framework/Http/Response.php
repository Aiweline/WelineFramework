<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Http;


use JetBrains\PhpStorm\NoReturn;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\Message;
use Weline\Framework\Manager\ObjectManager;

class Response implements ResponseInterface
{
    function getEvenManager(): EventsManager
    {
        return ObjectManager::getInstance(EventsManager::class);
    }

    private Response $instance;

    private array $headers = [];

    public function setHeader(string $header_key, string $header_value): static
    {
        $this->headers[$header_key] = $header_value;
        header("{$header_key}:{$header_value}");

        return $this;
    }

    public function setHeaders(array $headers): static
    {
        $this->headers = array_merge($this->headers, $headers);
        foreach ($this->headers as $header_key => $header_value) {
            header("{$header_key}:{$header_value}");
        }

        return $this;
    }

    public function getRequest(): Request
    {
        return ObjectManager::getInstance(Request::class);
    }

    public function setData(mixed $data): static
    {
        /**@var DataObject $dataObject */
        $dataObject = ObjectManager::getInstance(DataObject::class);
        $dataObject->setData($data);
        if (is_int(strpos($this->getRequest()->getContentType(), 'application/json'))) {
            header('Content-type: application/json');
            echo $dataObject->toJson();
        }
        if (is_int(strpos($this->getRequest()->getContentType(), 'text/xml'))) {
            header('Content-type: text/xml');
            echo $dataObject->toXml();
        } else {
            echo $dataObject->toString();
        }
        return $this;
    }

    /**
     * @DESC          # 无路由
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/7 23:06
     * 参数区：
     */
    public function noRouter(int|string $code = 404, string $msg = ''): void
    {
        if (empty($msg)) {
            switch ($code) {
                case 403:
                    $msg = 'Forbidden';
                    break;
                case 404:
                    $msg = 'Not Found';
                    break;
                case 500:
                    $msg = 'Internal Server Error';
                    break;
                default:
                    $msg = 'Unknown Error';
            }
        }
        $this->getEvenManager()->dispatch('Weline_Framework_http_response_no_router_before');
        http_response_code($code);
        @header('http/2.0 ' . $code . ' ' . $msg);
        @header('status: ' . $code . ' ' . $msg);
        if (is_file(BP . 'pub/errors/' . $code . '.php')) {
            exit(include BP . 'pub/errors/' . $code . '.php');
        }
        exit();
    }

    public function responseHttpCode(int $code = 200): void
    {
        http_response_code($code);
        exit();
    }

    #[NoReturn]
    public function redirect(string $url, $code = 302): void
    {
        $data = new DataObject(['url' => $url, 'code' => $code]);
        $this->getEvenManager()->dispatch('Weline_Framework_Http::response_redirect_before', ['data' => $data]);
        $url = $data->getData('url');
        $code = $data->getData('code');
        http_response_code($code);
        Header("Location:$url");
        exit(0);
    }

    public function renderJson(array $data): bool|string
    {
        Header('Content-Type:application/json; charset=utf-8');
        return json_encode($data);
    }

    /*下载*/
    public function download(string $file, string $name = '', bool $is_delete = false, bool $exit = true): void
    {
        if (empty($name)) {
            $name = basename($file);
        }
        if (is_file($file)) {
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename=' . $name);
            header('Content-Transfer-Encoding: binary');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($file));
            readfile($file);
            if ($is_delete) {
                unlink($file);
            }
        } else {
            Message::error(__('文件不存在！'));
        }
        if ($exit) {
            exit();
        }
    }
}
