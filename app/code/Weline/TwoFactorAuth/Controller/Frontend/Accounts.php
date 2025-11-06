<?php

declare(strict_types=1);

namespace Weline\TwoFactorAuth\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\TwoFactorAuth\Helper\TwoFactorAuthHelper;
use Weline\TwoFactorAuth\Model\TotpAccount;

/**
 * TOTP账户管理控制器
 * 
 * @package Weline\TwoFactorAuth\Controller\Frontend
 */
class Accounts extends FrontendController
{
    private TotpAccount $totpAccount;

    public function __construct(
        TotpAccount $totpAccount
    ) {
        $this->totpAccount = $totpAccount;
    }

    /**
     * 显示账户列表
     */
    public function index()
    {
        /**@var \Weline\Frontend\Session\FrontendSession $session */
        $session = \Weline\Framework\App\Env::getInstance(\Weline\Frontend\Session\FrontendSession::class);
        $userId = $session->getLoginUserID() ?? 1;

        $accounts = $this->totpAccount->getUserAccounts($userId);

        $this->assign('accounts', $accounts);
        $this->assign('user_id', $userId);

        return $this->fetch();
    }

    /**
     * 添加新账户（显示表单）
     */
    public function add()
    {
        return $this->fetch();
    }

    /**
     * 处理添加账户请求
     */
    public function save()
    {
        if (!$this->request->isPost()) {
            return $this->fetchJson(['success' => false, 'message' => '无效的请求方法']);
        }

        /**@var \Weline\Frontend\Session\FrontendSession $session */
        $session = \Weline\Framework\App\Env::getInstance(\Weline\Frontend\Session\FrontendSession::class);
        $userId = $session->getLoginUserID() ?? 1;

        $name = $this->request->getPost('name');
        $secret = $this->request->getPost('secret');
        $issuer = $this->request->getPost('issuer');
        $algorithm = $this->request->getPost('algorithm', 'SHA1');
        $digits = (int)$this->request->getPost('digits', 6);
        $period = (int)$this->request->getPost('period', 30);

        if (!$name || !$secret) {
            return $this->fetchJson(['success' => false, 'message' => '账户名称和密钥不能为空']);
        }

        // 验证密钥格式
        if (!TwoFactorAuthHelper::isValidBase32($secret)) {
            return $this->fetchJson(['success' => false, 'message' => '密钥格式不正确']);
        }

        $account = $this->totpAccount->addAccount($userId, $name, $secret, $issuer, $algorithm, $digits, $period);

        if ($account->getData('account_id')) {
            return $this->fetchJson([
                'success' => true,
                'message' => '账户添加成功',
                'account' => $account->getData()
            ]);
        } else {
            return $this->fetchJson(['success' => false, 'message' => '账户添加失败']);
        }
    }

    /**
     * 删除账户
     */
    public function delete()
    {
        if (!$this->request->isPost()) {
            return $this->fetchJson(['success' => false, 'message' => '无效的请求方法']);
        }

        /**@var \Weline\Frontend\Session\FrontendSession $session */
        $session = \Weline\Framework\App\Env::getInstance(\Weline\Frontend\Session\FrontendSession::class);
        $userId = $session->getLoginUserID() ?? 1;

        $accountId = (int)$this->request->getPost('account_id');

        if (!$accountId) {
            return $this->fetchJson(['success' => false, 'message' => '账户ID不能为空']);
        }

        $success = $this->totpAccount->deleteAccount($accountId, $userId);

        return $this->fetchJson([
            'success' => $success,
            'message' => $success ? '账户删除成功' : '账户删除失败'
        ]);
    }

    /**
     * 获取当前验证码的中间计算结果（不包含最终密码）
     * 返回HMAC结果和偏移量，前端完成最后一步计算
     */
    public function getCode()
    {
        /**@var \Weline\Frontend\Session\FrontendSession $session */
        $session = \Weline\Framework\App\Env::getInstance(\Weline\Frontend\Session\FrontendSession::class);
        $userId = $session->getLoginUserID() ?? 1;

        $accountId = (int)$this->request->getParam('account_id');

        if (!$accountId) {
            return $this->fetchJson(['success' => false, 'message' => '账户ID不能为空']);
        }

        $account = $this->totpAccount->where('account_id', $accountId)
            ->where('user_id', $userId)
            ->find()
            ->fetch();

        if (!$account) {
            return $this->fetchJson(['success' => false, 'message' => '账户不存在']);
        }

        $secret = $account->getData('secret');
        $algorithm = $account->getData('algorithm') ?: 'SHA1';
        $digits = (int)($account->getData('digits') ?: 6);
        $period = (int)($account->getData('period') ?: 30);
        $timestamp = time();
        
        // 后端计算HMAC和偏移量
        $key = $this->base32Decode($secret);
        $timeStep = (int)floor($timestamp / $period);
        $timeBytes = pack('N*', 0, $timeStep);
        $hash = hash_hmac(strtolower($algorithm), $timeBytes, $key, true);
        
        // 获取偏移量和截断后的hash
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $truncatedHash = substr($hash, $offset, 4);
        
        // 转换为整数
        $value = unpack('N', $truncatedHash)[1];
        $value = $value & 0x7FFFFFFF;
        
        // 只返回中间结果，不计算最终密码
        $remaining = $period - ($timestamp % $period);

        return $this->fetchJson([
            'success' => true,
            'hash_value' => $value,
            'digits' => $digits,
            'offset' => $offset,
            'remaining' => $remaining,
            'period' => $period
        ]);
    }
    
    /**
     * Base32解码（从Helper复制）
     */
    private function base32Decode(string $data): string
    {
        if (empty($data)) {
            return '';
        }
        
        $BASE32_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $data = strtoupper(str_replace([' ', '-', '='], '', $data));
        $binary = '';
        
        for ($i = 0; $i < strlen($data); $i++) {
            $char = $data[$i];
            $pos = strpos($BASE32_CHARS, $char);
            if ($pos === false) {
                continue;
            }
            $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        
        $chunks = str_split($binary, 8);
        $decoded = '';
        
        foreach ($chunks as $chunk) {
            if (strlen($chunk) < 8) {
                break;
            }
            $decoded .= chr(bindec($chunk));
        }
        
        return $decoded;
    }

    /**
     * 导入账户（从URI）
     */
    public function import()
    {
        if (!$this->request->isPost()) {
            return $this->fetchJson(['success' => false, 'message' => '无效的请求方法']);
        }

        $uri = $this->request->getPost('uri');
        
        if (!$uri) {
            return $this->fetchJson(['success' => false, 'message' => 'URI不能为空']);
        }

        // 解析otpauth:// URI
        $parsed = $this->parseOtpAuthUri($uri);

        if (!$parsed) {
            return $this->fetchJson(['success' => false, 'message' => 'URI格式不正确']);
        }

        /**@var \Weline\Frontend\Session\FrontendSession $session */
        $session = \Weline\Framework\App\Env::getInstance(\Weline\Frontend\Session\FrontendSession::class);
        $userId = $session->getLoginUserID() ?? 1;

        // 验证密钥格式
        if (!TwoFactorAuthHelper::isValidBase32($parsed['secret'])) {
            return $this->fetchJson(['success' => false, 'message' => '密钥格式不正确']);
        }

        $account = $this->totpAccount->addAccount(
            $userId,
            $parsed['name'],
            $parsed['secret'],
            $parsed['issuer'] ?? null,
            $parsed['algorithm'] ?? 'SHA1',
            $parsed['digits'] ?? 6,
            $parsed['period'] ?? 30
        );

        if ($account->getData('account_id')) {
            return $this->fetchJson([
                'success' => true,
                'message' => '账户导入成功',
                'account' => $account->getData()
            ]);
        } else {
            return $this->fetchJson(['success' => false, 'message' => '账户导入失败']);
        }
    }

    /**
     * 解析otpauth:// URI
     * 
     * @param string $uri
     * @return array|null
     */
    private function parseOtpAuthUri(string $uri): ?array
    {
        if (!str_starts_with($uri, 'otpauth://')) {
            return null;
        }

        $parsed = parse_url($uri);

        if (!isset($parsed['scheme']) || !isset($parsed['host'])) {
            return null;
        }

        $label = urldecode(ltrim($parsed['path'], '/'));
        $query = [];
        
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $query);
        }

        // 解析label，格式通常为 issuer:name 或 name
        $parts = explode(':', $label, 2);
        $name = $parts[1] ?? $parts[0];
        $issuer = $parts[0] !== $name ? $parts[0] : ($query['issuer'] ?? null);

        return [
            'name' => $name,
            'issuer' => $issuer,
            'secret' => $query['secret'] ?? '',
            'algorithm' => strtoupper($query['algorithm'] ?? 'SHA1'),
            'digits' => (int)($query['digits'] ?? 6),
            'period' => (int)($query['period'] ?? 30),
        ];
    }

    /**
     * 导出账户
     */
    public function export()
    {
        /**@var \Weline\Frontend\Session\FrontendSession $session */
        $session = \Weline\Framework\App\Env::getInstance(\Weline\Frontend\Session\FrontendSession::class);
        $userId = $session->getLoginUserID() ?? 1;

        $format = $this->request->getParam('format', 'json');
        
        $accounts = $this->totpAccount->getUserAccounts($userId);

        if ($format === 'json') {
            return $this->exportJson($accounts);
        } elseif ($format === 'csv') {
            return $this->exportCsv($accounts);
        } else {
            return $this->fetchJson(['success' => false, 'message' => '不支持的导出格式']);
        }
    }

    /**
     * 导出为JSON格式
     */
    private function exportJson(array $accounts)
    {
        $data = [
            'version' => 1,
            'export_time' => date('Y-m-d H:i:s'),
            'accounts' => []
        ];

        foreach ($accounts as $account) {
            $data['accounts'][] = [
                'name' => $account['name'] ?? '',
                'issuer' => $account['issuer'] ?? '',
                'secret' => $account['secret'] ?? '',
                'algorithm' => $account['algorithm'] ?? 'SHA1',
                'digits' => $account['digits'] ?? 6,
                'period' => $account['period'] ?? 30,
                'uri' => $this->buildOtpAuthUri($account)
            ];
        }

        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="totp_accounts_' . date('Y-m-d_H-i-s') . '.json"');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit();
    }

    /**
     * 导出为CSV格式
     */
    private function exportCsv(array $accounts)
    {
        $output = fopen('php://output', 'w');
        
        // BOM for Excel
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Header
        fputcsv($output, ['账户名称', '发行者', '密钥', '算法', '位数', '周期(秒)']);

        foreach ($accounts as $account) {
            fputcsv($output, [
                $account['name'] ?? '',
                $account['issuer'] ?? '',
                $account['secret'] ?? '',
                $account['algorithm'] ?? 'SHA1',
                $account['digits'] ?? 6,
                $account['period'] ?? 30,
            ]);
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="totp_accounts_' . date('Y-m-d_H-i-s') . '.csv"');
        
        rewind($output);
        fpassthru($output);
        fclose($output);
        exit();
    }

    /**
     * 构建otpauth URI
     */
    private function buildOtpAuthUri(array $account): string
    {
        $name = $account['name'] ?? '';
        $issuer = $account['issuer'] ?? '';
        $secret = $account['secret'] ?? '';
        $algorithm = $account['algorithm'] ?? 'SHA1';
        $digits = $account['digits'] ?? 6;
        $period = $account['period'] ?? 30;

        $params = [
            'secret' => $secret,
            'algorithm' => $algorithm,
            'digits' => $digits,
            'period' => $period
        ];

        if ($issuer) {
            $params['issuer'] = $issuer;
        }

        $uri = sprintf(
            'otpauth://totp/%s:%s?%s',
            rawurlencode($issuer ?: $name),
            rawurlencode($name),
            http_build_query($params)
        );

        return $uri;
    }

    /**
     * 提供Worker文件（加密混淆的TOTP计算Worker）
     */
    public function worker()
    {
        $workerPath = __DIR__ . '/../../view/statics/Frontend/Accounts/totp-worker.js';
        
        if (file_exists($workerPath)) {
            $content = file_get_contents($workerPath);
            header('Content-Type: application/javascript; charset=utf-8');
            // 设置缓存头，但允许更新
            header('Cache-Control: public, max-age=3600');
            echo $content;
            exit();
        } else {
            header('Content-Type: application/javascript; charset=utf-8');
            echo '// Worker文件不存在';
            exit();
        }
    }
}

