<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Weline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\ThemeFancy\Controller\Template;

use Weline\Framework\App\Controller\FrontendController;

class Fancy extends FrontendController
{
    private const TEMPLATE_DIR = 'templates/Template/Fancy/';

    public function index(): string
    {
        $method = (string)$this->request->getParam('method', 'index.html');
        $templateFile = $this->resolveTemplateFile($method);

        if ($templateFile === '') {
            $this->request->getResponse()->noRouter();
            return '';
        }

        return (string)$this->fetchTemplateWithEvents($templateFile);
    }

    private function resolveTemplateFile(string $method): string
    {
        $method = trim($method, '/');
        if ($method === '') {
            $method = 'index.html';
        }

        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9-]*\.html$/', $method)) {
            return '';
        }

        return self::TEMPLATE_DIR . $method;
    }
}
