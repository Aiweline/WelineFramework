<?php
declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Console\Component;

use GuoLaiRen\PageBuilder\Service\ComponentService;
use GuoLaiRen\PageBuilder\Service\ComponentValidator;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

/**
 * 组件规约验证命令
 * 
 * 用法：
 *   php bin/m pagebuilder:component:validate [style_code]
 * 
 * 示例：
 *   php bin/m pagebuilder:component:validate tpmst
 *   php bin/m pagebuilder:component:validate --all
 */
class Validate implements \Weline\Framework\Console\CommandInterface
{
    private Printing $printing;
    private ComponentValidator $validator;
    private ComponentService $componentService;
    
    public function __construct()
    {
        $this->printing = ObjectManager::getInstance(Printing::class);
        $this->validator = ObjectManager::getInstance(ComponentValidator::class);
        $this->componentService = ObjectManager::getInstance(ComponentService::class);
    }
    
    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        // 移除命令名，获取位置参数
        // $args 格式: ['component:validate', 'tpmst', ...] 或 ['component:validate', '--all']
        $positionalArgs = [];
        foreach ($args as $key => $arg) {
            if (is_int($key) && !str_starts_with((string)$arg, '-')) {
                $positionalArgs[] = $arg;
            }
        }
        // 第一个位置参数是命令名，移除它
        array_shift($positionalArgs);
        $styleCode = $positionalArgs[0] ?? null;
        
        $validateAll = isset($args['all']) || isset($args['a']) || isset($data['all']) || isset($data['a']);
        
        if (!$styleCode && !$validateAll) {
            $this->printing->error('请指定模板代码或使用 --all 验证所有模板');
            $this->printing->note('用法: php bin/m component:validate <style_code>');
            $this->printing->note('      php bin/m component:validate --all');
            return;
        }
        
        if ($validateAll) {
            $this->validateAll();
            return;
        }
        
        $this->validateOne($styleCode);
    }
    
    /**
     * 验证单个模板
     */
    private function validateOne(string $styleCode): void
    {
        $this->printing->printing("验证模板组件: {$styleCode}", '');
        $this->printing->printing(str_repeat('=', 50), '');
        
        $result = $this->validator->validateTemplate($styleCode, false);
        
        $this->printResult($styleCode, $result);
    }
    
    /**
     * 验证所有模板
     */
    private function validateAll(): void
    {
        $styleDir = BP . 'app/code/GuoLaiRen/PageBuilder/view/templates/style/';
        
        if (!is_dir($styleDir)) {
            $this->printing->error('模板目录不存在');
            return;
        }
        
        $dirs = scandir($styleDir);
        $totalErrors = 0;
        $totalWarnings = 0;
        $processedTemplates = 0;
        
        $this->printing->printing('验证所有模板组件配置', '');
        $this->printing->printing(str_repeat('=', 60), '');
        
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..' || $dir === '_layouts' || $dir === '_shared') {
                continue;
            }
            
            $fullPath = $styleDir . $dir;
            if (!is_dir($fullPath)) {
                continue;
            }
            
            // 检查是否有 component.json
            $componentJsonPath = "{$fullPath}/components/component.json";
            if (!file_exists($componentJsonPath)) {
                continue;
            }
            
            $processedTemplates++;
            $result = $this->validator->validateTemplate($dir, false);
            
            $totalErrors += count($result['errors']);
            $totalWarnings += count($result['warnings']);
            
            $this->printResult($dir, $result);
            $this->printing->printing('', '');
        }
        
        // 总结
        $this->printing->printing(str_repeat('=', 60), '');
        $this->printing->printing("总结: 检查了 {$processedTemplates} 个模板", '');
        
        if ($totalErrors > 0) {
            $this->printing->error("总错误数: {$totalErrors}");
        } else {
            $this->printing->success("无错误");
        }
        
        if ($totalWarnings > 0) {
            $this->printing->warning("总警告数: {$totalWarnings}");
        }
    }
    
    /**
     * 打印验证结果
     */
    private function printResult(string $styleCode, array $result): void
    {
        if ($result['valid']) {
            $this->printing->success("[{$styleCode}] ✅ 验证通过");
        } else {
            $this->printing->error("[{$styleCode}] ❌ 验证失败");
        }
        
        // 打印错误
        foreach ($result['errors'] as $error) {
            $this->printing->error("  ❌ {$error}");
        }
        
        // 打印警告
        foreach ($result['warnings'] as $warning) {
            $this->printing->warning("  ⚠️ {$warning}");
        }
    }
    
    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '验证PageBuilder组件配置规约。用法: pagebuilder:component:validate <style_code> 或 --all';
    }
    
    /**
     * @inheritDoc
     */
    public function help(): array|string
    {
        return <<<HELP
用法:
  php bin/m pagebuilder:component:validate <style_code>
  php bin/m pagebuilder:component:validate --all

参数:
  <style_code>    模板代码，例如 tpmst

选项:
  --all, -a       验证所有模板

示例:
  php bin/m pagebuilder:component:validate tpmst      验证 tpmst 模板
  php bin/m pagebuilder:component:validate --all     验证所有模板

说明:
  此命令用于验证 PageBuilder 组件配置的完整性和正确性，包括：
  - 检查 component.json 中定义的组件文件是否存在
  - 验证组件代码命名规范（小写字母和连字符）
  - 检查必需字段（name, file, region, category）
  - 验证区域配置的默认组件是否有效
HELP;
    }
}
