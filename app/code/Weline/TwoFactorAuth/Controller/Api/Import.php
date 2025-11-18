<?php

declare(strict_types=1);

namespace Weline\TwoFactorAuth\Controller\Api;

use Weline\Framework\App\Controller\FrontendRestController;
use Weline\TwoFactorAuth\Helper\BackupImporter;

/**
 * 导入API
 * 
 * @package Weline\TwoFactorAuth\Controller\Api
 */
class Import extends FrontendRestController
{
    /**
     * 解析备份文件
     * 
     * POST /api/2fa/parse
     * Body: { "content": "...", "format": "auto" }
     */
    public function parse()
    {
        if (!$this->request->isPost()) {
            return $this->jsonError('无效的请求方法', 405);
        }

        $content = $this->request->getPost('content');
        $format = $this->request->getPost('format') ?? 'auto';

        if (!$content) {
            return $this->jsonError('缺少备份内容', 400);
        }

        try {
            $accounts = BackupImporter::parse($content, $format);

            if (empty($accounts)) {
                return $this->jsonError('未找到有效的账户数据', 400);
            }

            // 验证账户
            $validAccounts = [];
            foreach ($accounts as $account) {
                if (BackupImporter::validateAccount($account)) {
                    $validAccounts[] = $account;
                }
            }

            return $this->jsonSuccess([
                'total' => count($accounts),
                'valid' => count($validAccounts),
                'accounts' => $validAccounts
            ]);

        } catch (\Exception $e) {
            return $this->jsonError('解析失败：' . $e->getMessage(), 400);
        }
    }

    /**
     * 导出账户
     * 
     * POST /api/2fa/export
     * Body: { "accounts": [...], "format": "json" }
     */
    public function export()
    {
        if (!$this->request->isPost()) {
            return $this->jsonError('无效的请求方法', 405);
        }

        $accounts = $this->request->getPost('accounts');
        $format = $this->request->getPost('format') ?? 'json';

        if (!$accounts) {
            return $this->jsonError('缺少账户数据', 400);
        }

        // 如果是字符串，解析为数组
        if (is_string($accounts)) {
            $accounts = json_decode($accounts, true);
        }

        if (!is_array($accounts)) {
            return $this->jsonError('账户数据格式错误', 400);
        }

        try {
            $exportData = match ($format) {
                'weline' => BackupImporter::exportToJson($accounts),
                'aegis' => BackupImporter::exportToAegis($accounts),
                'andotp' => BackupImporter::exportToAndOTP($accounts),
                '2fas' => BackupImporter::exportTo2FAS($accounts),
                'uri_list' => BackupImporter::exportToUriList($accounts),
                default => BackupImporter::exportToJson($accounts)
            };

            return $this->jsonSuccess([
                'format' => $format,
                'data' => $exportData,
                'count' => count($accounts)
            ]);

        } catch (\Exception $e) {
            return $this->jsonError('导出失败：' . $e->getMessage(), 500);
        }
    }

    /**
     * 获取支持的导入格式
     * 
     * GET /api/2fa/formats
     */
    public function formats()
    {
        $formats = BackupImporter::getSupportedFormats();
        return $this->jsonSuccess([
            'formats' => $formats
        ]);
    }

    /**
     * 获取支持的导出格式
     * 
     * GET /api/2fa/export-formats
     */
    public function exportFormats()
    {
        $formats = BackupImporter::getExportFormats();
        return $this->jsonSuccess([
            'formats' => $formats
        ]);
    }

    /**
     * 返回成功响应
     */
    private function jsonSuccess(array $data, int $code = 200)
    {
        return $this->fetch(array_merge([
            'success' => true,
            'code' => $code
        ], $data), self::fetch_JSON);
    }

    /**
     * 返回错误响应
     */
    private function jsonError(string $message, int $code = 400)
    {
        return $this->fetch([
            'success' => false,
            'code' => $code,
            'message' => $message
        ], self::fetch_JSON);
    }
}

