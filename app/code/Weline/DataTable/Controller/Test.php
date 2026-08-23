<?php

declare(strict_types=1);

namespace Weline\DataTable\Controller;

use Weline\Framework\App\Controller\FrontendController;

class Test extends FrontendController
{
    private const TEMPLATE_BASE = 'Weline_DataTable::templates/frontend/test/';

    public function index(): string
    {
        return $this->renderPage('index', 'DataTable Frontend Demo');
    }

    public function basic(): string
    {
        return $this->renderPage('basic', 'Basic Table Demo');
    }

    public function join(): string
    {
        return $this->renderPage('join', 'Joined Table Demo');
    }

    public function form(): string
    {
        return $this->renderPage('form', 'Standalone Form Demo');
    }

    public function upload(): string
    {
        return $this->renderPage('upload', 'Upload Field Demo');
    }

    public function transaction(): string
    {
        return $this->renderPage('transaction', 'Transaction Demo');
    }

    public function dependency(): string
    {
        return $this->renderPage('dependency', 'Dependency Demo');
    }

    public function cascade(): string
    {
        return $this->renderPage('cascade', 'Cascade Delete Demo');
    }

    public function performance(): string
    {
        return $this->renderPage('performance', 'Performance Demo');
    }

    private function renderPage(string $page, string $title): string
    {
        return $this->renderDefaultLayout(
            self::TEMPLATE_BASE . $page . '.phtml',
            $title,
            [
                'page_title' => $title,
                'page_key' => $page,
                'demo_links' => [
                    'index' => '/datatable/test',
                    'basic' => '/datatable/test/basic',
                    'join' => '/datatable/test/join',
                    'form' => '/datatable/test/form',
                    'upload' => '/datatable/test/upload',
                    'transaction' => '/datatable/test/transaction',
                    'dependency' => '/datatable/test/dependency',
                    'cascade' => '/datatable/test/cascade',
                    'performance' => '/datatable/test/performance',
                ],
            ]
        );
    }
}
