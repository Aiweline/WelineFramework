<?php
declare(strict_types=1);

namespace Weline\Ai\Console\Ai;

use Weline\Ai\Model\AiModel;
use Weline\Ai\Model\Provider\Account;
use Weline\Ai\Service\Provider\AccountService;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

/**
 * AI 调试诊断命令
 */
class Debug implements CommandInterface
{
    private Printing $printing;
    
    public function __construct(Printing $printing)
    {
        $this->printing = $printing;
    }
    
    public function execute(array $args = [], array $data = []): string
    {
        $this->printing->setup('', '');
        $this->printing->note('===== AI 配置诊断 =====');
        
        // 1. 检查供应商账户
        $this->printing->note('');
        $this->printing->note('1. 供应商账户列表：');
        $this->printing->note('--------------------------------------');
        
        /** @var Account $accountModel */
        $accountModel = ObjectManager::getInstance(Account::class);
        $accounts = $accountModel->reset()->select()->fetch();
        
        if ($accounts->count() === 0) {
            $this->printing->error('  ❌ 没有找到任何供应商账户！请先添加供应商账户。');
        } else {
            foreach ($accounts->getItems() as $acc) {
                $apiKey = $acc->getData(Account::schema_fields_API_KEY);
                $apiKeyPreview = $apiKey ? '...' . substr($apiKey, -4) : '(空)';
                $status = $acc->getData(Account::schema_fields_CONNECTION_STATUS);
                $balance = $acc->getData(Account::schema_fields_BALANCE);
                $isActive = $acc->getData(Account::schema_fields_IS_ACTIVE);
                $isDefault = $acc->getData(Account::schema_fields_IS_DEFAULT);
                
                $statusIcon = match($status) {
                    'success' => '✅',
                    'failed' => '❌',
                    default => '⏳',
                };
                
                $activeIcon = $isActive ? '✓' : '✗';
                $defaultIcon = $isDefault ? '★' : ' ';
                
                $this->printing->note(sprintf(
                    '  [%s] ID=%d | %s | %s | key=%s | balance=%.2f | active=%s | default=%s',
                    $statusIcon,
                    $acc->getId(),
                    $acc->getData(Account::schema_fields_PROVIDER_CODE),
                    $acc->getData(Account::schema_fields_ACCOUNT_NAME) ?: '(无名称)',
                    $apiKeyPreview,
                    (float)$balance,
                    $activeIcon,
                    $defaultIcon
                ));
                
                // 诊断问题
                $issues = [];
                if (empty($apiKey)) {
                    $issues[] = 'API密钥为空';
                }
                if ($status !== 'success') {
                    $issues[] = '连接状态非success';
                }
                if ((float)$balance <= 0) {
                    $issues[] = '余额<=0';
                }
                if (!$isActive) {
                    $issues[] = '未激活';
                }
                
                if (!empty($issues)) {
                    $this->printing->warning('    ⚠️  问题: ' . implode(', ', $issues));
                }
            }
        }
        
        // 2. 检查模型配置
        $this->printing->note('');
        $this->printing->note('2. AI 模型列表：');
        $this->printing->note('--------------------------------------');
        
        /** @var AiModel $modelModel */
        $modelModel = ObjectManager::getInstance(AiModel::class);
        $models = $modelModel->reset()
            ->where(AiModel::schema_fields_IS_ACTIVE, 1)
            ->order(AiModel::schema_fields_IS_DEFAULT, 'DESC')
            ->select()
            ->fetch();
        
        if ($models->count() === 0) {
            $this->printing->error('  ❌ 没有找到已激活的模型！');
        } else {
            foreach ($models->getItems() as $mdl) {
                $providerConfig = $mdl->getProviderConfig();
                $config = $mdl->getConfig();
                $hasProviderKey = !empty($providerConfig['api_key'] ?? '');
                $hasConfigKey = !empty($config['api_key'] ?? '');
                $isDefault = $mdl->getData(AiModel::schema_fields_IS_DEFAULT);
                
                $defaultIcon = $isDefault ? '★' : ' ';
                $keyIcon = ($hasProviderKey || $hasConfigKey) ? '🔑' : '  ';
                
                $this->printing->note(sprintf(
                    '  %s%s %s | %s | supplier=%s',
                    $defaultIcon,
                    $keyIcon,
                    $mdl->getData(AiModel::schema_fields_MODEL_CODE),
                    $mdl->getName(),
                    $mdl->getData(AiModel::schema_fields_SUPPLIER) ?: '(未设置)'
                ));
            }
        }
        
        // 3. 测试可用账户获取
        $this->printing->note('');
        $this->printing->note('3. 可用账户测试：');
        $this->printing->note('--------------------------------------');
        
        $suppliers = ['openai', 'deepseek', 'anthropic', 'google'];
        /** @var AccountService $accountService */
        $accountService = ObjectManager::getInstance(AccountService::class);
        
        foreach ($suppliers as $supplier) {
            $account = $accountService->getAvailableAccount($supplier);
            if ($account) {
                $apiKey = $account->getDecryptedApiKey();
                $keyPreview = $apiKey ? '...' . substr($apiKey, -4) : '(空!)';
                $this->printing->success(sprintf(
                    '  ✅ %s: 找到可用账户 ID=%d, key=%s, balance=%.2f',
                    $supplier,
                    $account->getId(),
                    $keyPreview,
                    (float)$account->getData(Account::schema_fields_BALANCE)
                ));
            } else {
                $this->printing->warning(sprintf('  ⚠️  %s: 无可用账户', $supplier));
            }
        }
        
        // 4. 尝试一次 AI 调用测试
        $this->printing->note('');
        $this->printing->note('4. AI 调用测试：');
        $this->printing->note('--------------------------------------');
        
        try {
            /** @var \Weline\Ai\Service\AiService $aiService */
            $aiService = ObjectManager::getInstance(\Weline\Ai\Service\AiService::class);
            
            $testPrompt = 'Say "OK" to confirm connection.';
            $this->printing->note('  正在测试 AI 调用 (prompt: "' . $testPrompt . '")...');
            
            $response = $aiService->generate(
                $testPrompt,
                'deepseek-v3', // 指定模型
                null,
                'en_US',
                ['test_mode' => true],
                null,      // userId
                true       // isBackend = true (后台调用)
            );
            
            $this->printing->success('  ✅ AI 调用成功！响应: ' . substr($response, 0, 100) . (strlen($response) > 100 ? '...' : ''));
        } catch (\Exception $e) {
            $this->printing->error('  ❌ AI 调用失败: ' . $e->getMessage());
            
            // 额外诊断信息
            $this->printing->warning('  调试信息：');
            $trace = $e->getTrace();
            if (!empty($trace[0])) {
                $this->printing->warning('    文件: ' . ($trace[0]['file'] ?? 'unknown'));
                $this->printing->warning('    行号: ' . ($trace[0]['line'] ?? 'unknown'));
                $this->printing->warning('    方法: ' . ($trace[0]['class'] ?? '') . '::' . ($trace[0]['function'] ?? ''));
            }
        }
        
        $this->printing->note('');
        $this->printing->note('===== 诊断完成 =====');
        $this->printing->note('');
        $this->printing->note('解决方案：');
        $this->printing->note('  1. 确保供应商账户已填写 API 密钥');
        $this->printing->note('  2. 确保账户连接测试成功（connection_status = success）');
        $this->printing->note('  3. 确保账户余额 > 0（balance > 0）');
        $this->printing->note('  4. 确保账户已激活（is_active = 1）');
        $this->printing->note('');
        
        return '';
    }
    
    public function tip(): string
    {
        return 'AI 配置诊断工具，检查供应商账户和模型配置';
    }
    
    public function help(): array|string
    {
        return <<<HELP
AI 配置诊断工具

用法: php bin/w ai:debug

此命令会检查：
1. 供应商账户配置（API密钥、连接状态、余额、激活状态）
2. AI模型列表（已激活的模型及其供应商）
3. 可用账户测试（各供应商是否有可用账户）

常见问题解决：
- API密钥未配置：请在后台 AI > 供应商账户 中添加账户并填写 API 密钥
- 连接状态非success：请测试连接
- 余额<=0：需要设置账户余额 > 0
- 未激活：需要激活账户
HELP;
    }
}
