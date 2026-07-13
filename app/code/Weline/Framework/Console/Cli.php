<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Console;

use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;

class Cli extends CliAbstract
{
    public const core_FRAMEWORK_NAMESPACE = Env::framework_name . '\\Framework';

    /**
     * @DESC         |方法描述
     *
     * 参数区：
     *
     * @return int Process exit status returned by the selected command.
     * @throws ConsoleException
     * @throws Exception
     */
    public function run(): int
    {
        ConsoleEncoding::initForCli();
        $args = $this->parseArgs($this->argv);
        // 检查是否是查找命令 (find 或 -f)
        if ($this->isFindCommand($args)) {
            $this->handleFindCommand($args);
            return 0;
        }
        
        // 有命令时，需要接受用户检查 和 参数检查 但是在检测到env环境为空时，允许执行，方便安装
        $env_user = Env::get('user');
        if (isset($args['command']) && $env_user) {
            # 检测用户
            \Weline\Framework\App\Env::check_user();
            // 没有任何参数
            if (!isset($this->argv[0])) {
                $this->execute();
                return 0;
            }
        }
        $command_class = $this->checkCommand($args);
        // 如果返回的是推荐列表，显示推荐列表
        if (is_array($command_class) && !isset($command_class['class'])) {
            $this->showRecommendations($command_class, $args);
            return 0;
        }
        
        // 检查是否需要显示help
        if (isset($args['h']) || isset($args['help']) || isset($args['-h']) || isset($args['--help'])) {
            $this->showHelp($command_class);
            return 0;
        }
        
        // 执行命令
        $command = $command_class['command'] ?? '';
        $data = $command_class['data'];
        $commandResult = ObjectManager::getInstance($command_class['class'])->execute($args, $data);
        
        // 触发命令执行完成事件（传规范命令名，便于观察者按前缀匹配，如 setup: 而非别名 s:up）
        $canonicalCommand = $this->getCanonicalCommandName($command, $command_class['class']);
        $this->dispatchCommandExecutedEvent($canonicalCommand, $args);
        $skipFooter = ($canonicalCommand === 'cron:test'
                && (\in_array('--list', $this->argv, true) || \in_array('-l', $this->argv, true)))
            || $this->isJsonOutputCommand($args);
        if (!$skipFooter) {
            $this->printer->printing("\n");
            $this->printer->note(__('执行命令：') . $command_class['command'] . ' ' . ($this->argv[1] ?? '')/*,$this->printer->colorize('CLI-System','red')*/);
        }

        // Commands may return an integer process status. Keep legacy null/bool/
        // object results successful so existing commands do not change meaning;
        // the top-level bin entrypoint is the sole owner of process termination.
        return $this->normalizeCommandExitCode($commandResult);
    }

    protected function normalizeCommandExitCode(mixed $commandResult): int
    {
        return \is_int($commandResult) ? \max(0, \min(255, $commandResult)) : 0;
    }

    private function isJsonOutputCommand(array $args): bool
    {
        if (isset($args['json']) || isset($args['j'])) {
            return true;
        }

        foreach (['format', 'f'] as $key) {
            $value = $args[$key] ?? null;
            if (\is_string($value) && \strtolower($value) === 'json') {
                return true;
            }
        }

        return false;
    }
    
    /**
     * 触发命令执行完成事件
     * 
     * @param string $command 命令名称（规范名，非别名）
     * @param array $args 命令参数
     */
    private function dispatchCommandExecutedEvent(string $command, array $args): void
    {
        try {
            /** @var \Weline\Framework\Event\EventsManager $eventsManager */
            $eventsManager = ObjectManager::getInstance(\Weline\Framework\Event\EventsManager::class);
            $eventData = ['command' => $command, 'args' => $args];
            $eventsManager->dispatch('Weline_Framework::cli::command_executed', $eventData);
        } catch (\Throwable $e) {
            // 事件触发失败不影响命令执行
        }
    }

    /**
     * 根据已匹配的命令（可能为别名）和命令类，解析出规范命令名
     * 规范名为同一命令类在 getCommands() 中键名最长的那条（主命令先注册，别名后注册，主命令名更长）
     *
     * @param string $matchedCommand 当前匹配到的命令名（可能是别名，如 s:up）
     * @param string $commandClass 命令类全限定名
     * @return string 规范命令名（如 setup:upgrade）
     */
    private function getCanonicalCommandName(string $matchedCommand, string $commandClass): string
    {
        $commands = Env::getCommands();
        $canonical = $matchedCommand;
        foreach ($commands as $group_commands) {
            if (!\is_array($group_commands)) {
                continue;
            }
            foreach ($group_commands as $name => $command_data) {
                if (!\is_array($command_data) || ($command_data['class'] ?? '') !== $commandClass) {
                    continue;
                }
                if (\strlen($name) > \strlen($canonical)) {
                    $canonical = $name;
                }
            }
        }
        return $canonical;
    }
    
    /**
     * @DESC         |检查是否是查找命令
     *
     * @param array $args 参数数组
     * @return bool
     */
    private function isFindCommand(array $args): bool
    {
        // 检查 find 命令
        if (isset($args['command']) && strtolower($args['command']) === 'find') {
            return true;
        }
        
        // 检查 -f 参数：只有当没有其他命令时，-f 才作为查找命令的触发器
        // 如果已经有具体命令（如 cron:task:run），-f 应该作为该命令的参数
        $command = $args['command'] ?? '';
        if (empty($command) && (isset($args['f']) || isset($args['-f']))) {
            return true;
        }
        
        return false;
    }
    
    /**
     * @DESC         |处理查找命令
     *
     * @param array $args 参数数组
     * @return void
     */
    private function handleFindCommand(array $args): void
    {
        // 获取搜索关键词
        $keyword = $this->getFindKeyword($args);
        
        if (empty($keyword)) {
            $this->showFindHelp();
            return;
        }
        
        // 搜索命令
        $results = $this->searchCommands($keyword);
        
        // 显示搜索结果
        $this->showSearchResults($results, $keyword);
    }
    
    /**
     * @DESC         |获取查找关键词
     *
     * @param array $args 参数数组
     * @return string
     */
    private function getFindKeyword(array $args): string
    {
        // 如果使用 find 命令，关键词是第二个参数
        if (isset($args['command']) && strtolower($args['command']) === 'find') {
            // 查找数字索引的参数（非命令本身）
            foreach ($this->argv as $index => $arg) {
                if ($index > 0 && !str_starts_with($arg, '-')) {
                    return trim($arg);
                }
            }
        }
        
        // 如果使用 -f 参数
        if (isset($args['f']) && is_string($args['f']) && $args['f'] !== true) {
            return trim($args['f']);
        }
        
        return '';
    }
    
    /**
     * @DESC         |显示查找命令帮助
     *
     * @return void
     */
    private function showFindHelp(): void
    {
        $this->printer->note('🔍 ' . __('命令查找功能'));
        $this->printer->separator('─', 0, 'NOTE');
        $this->printer->printing(__('用法') . ':');
        $this->printer->success('  php bin/w <关键词>');
        $this->printer->printing('');
        $this->printer->printing(__('说明') . ':');
        $this->printer->note('  ' . __('直接输入关键词即可在命令名称、描述中搜索'));
        $this->printer->printing('');
        $this->printer->printing(__('示例') . ':');
        $this->printer->success('  php bin/w cache      # ' . __('搜索包含 "cache" 的命令'));
        $this->printer->success('  php bin/w module     # ' . __('搜索包含 "module" 的命令'));
        $this->printer->success('  php bin/w 清理       # ' . __('搜索描述中包含 "清理" 的命令'));
    }
    
    /**
     * @DESC         |搜索命令
     *
     * @param string $keyword 搜索关键词
     * @return array 搜索结果
     */
    private function searchCommands(string $keyword): array
    {
        $commands = Env::getCommands();
        $results = [];
        $keywordLower = strtolower($keyword);
        
        foreach ($commands as $group => $group_commands) {
            foreach ($group_commands as $cmd => $data) {
                $cmdLower = strtolower($cmd);
                $tipLower = isset($data['tip']) ? strtolower($data['tip']) : '';
                
                // 在命令名称或描述中搜索关键词
                if (str_contains($cmdLower, $keywordLower) || str_contains($tipLower, $keywordLower)) {
                    if (!isset($results[$group])) {
                        $results[$group] = [];
                    }
                    $results[$group][$cmd] = $data;
                }
            }
        }
        
        return $results;
    }
    
    /**
     * @DESC         |显示搜索结果
     *
     * @param array $results 搜索结果
     * @param string $keyword 搜索关键词
     * @return void
     */
    private function showSearchResults(array $results, string $keyword): void
    {
        if (empty($results)) {
            $this->printer->warning('🔍 ' . __('没有找到包含 "%{1}" 的命令', [$keyword]));
            $this->printer->note(__('💡 提示：尝试使用更短的关键词，或使用命令的一部分进行搜索'));
            return;
        }
        
        // 计算匹配的命令总数
        $totalCount = 0;
        foreach ($results as $group_commands) {
            $totalCount += count($group_commands);
        }
        
        $this->printer->note('🔍 ' . __('搜索 "%{1}" 找到 %{2} 个命令', [$keyword, $totalCount]));
        $this->printer->separator('─', 0, 'NOTE');
        
        // 使用分组树形显示
        $this->printer->groupedTreeList($results, 'NOTE');
        
        // 添加底部提示
        $this->printer->separator('═', 0, 'SUCCESS');
        $this->printer->note('💡 ' . __('提示：使用 "php bin/w <命令> -h" 查看命令详情'));
    }

    function parseArgs(array $args): array
    {
        $command = '';
        $argName = null;
        
        foreach ($args as $k => $arg) {
            if ($k == 0) {
                // 第一个参数，检查是否是命令还是参数
                if (str_starts_with($arg, '-')) {
                    // 如果第一个参数以 - 开头，说明没有命令，只有参数
                    $args['command'] = '';
                    // 处理这个参数
                    $argName = trim($arg, '-');
                    $next = $args[$k + 1] ?? null;
                    if (empty($next) || str_starts_with($next, '-')) {
                        $args[$argName] = true;
                        $args[$arg] = true;
                    }
                } else {
                    // 第一个参数不是以 - 开头，说明是命令
                    $args['command'] = $arg;
                    $command = $arg;
                }
                continue;
            }
            
            if (is_string($k)) {
                continue;
            }
            
            if (str_contains($arg, '=')) {
                // 只分割第一个等号，保留后面的值完整
                $eqPos = strpos($arg, '=');
                $key = trim(substr($arg, 0, $eqPos), '-');
                $value = substr($arg, $eqPos + 1);
                $args[$key] = $value !== false ? $value : true;
                continue;
            }
            
            # 参数名
            if (str_starts_with($arg, '-')) {
                $argName = trim($arg, '-');
                $next = $args[$k + 1] ?? null;
                if (empty($next)) {
                    $args[$argName] = true;
                    $args[$arg] = true;
                    continue;
                }
                if (str_starts_with($next, '-')) {
                    $args[$arg] = true;
                    $args[$argName] = true;
                    $argName = null;
                }
            } elseif (!empty($argName)) {
                if (!isset($args[$argName])) {
                    $args[$argName] = $arg;
                } else {
                    if (is_array($args[$argName])) {
                        $args[$argName][] = $arg;
                    } else {
                        $args[$argName] = [$args[$argName], $arg];
                    }
                }
            }
        }
        return $args;
    }

    /**
     * @DESC         |推荐命令函数 - 按分段匹配逻辑
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 推荐逻辑：
     * 1. 用户输入的命令分段
     * 2. 找出命令行大于等于分段数的命令
     * 3. 命令行分段数从小到大排序
     * 4. 开始匹配
     * 5. 先匹配开头，符合则标记到开头匹配，开头匹配不到则使用包含去匹配，匹配到标记放入低优先级
     * 6. 返回推荐的状态
     * 7. 根据状态做对应的推荐操作
     *
     * 参数区：
     *
     * @param array $commands
     *
     * @return array
     */
    private function recommendCommand(array $commands, string $command): array
    {
        // 第一步：用户输入的命令分段
        $input_segments = explode(':', $command);
        
        // 第二步：使用分段递进匹配
        $matched_commands = $this->progressiveMatchCommands($input_segments, $commands);
        
        // 第三步：按分段数排序
        usort($matched_commands, function($a, $b) {
            return $a['seg_count'] <=> $b['seg_count'];
        });
        
        // 第四步：返回推荐结果
        $recommend = [];
        foreach ($matched_commands as $item) {
            $recommend[$item['group']][] = [$item['key'] => $item['data']];
        }
        
        return $recommend;
    }
    
    /**
     * @DESC         |匹配命令分段
     *
     * @param array $input_segments 用户输入的分段
     * @param array $command_segments 命令的分段
     * @return array 匹配结果
     */
    private function matchCommandSegments(array $input_segments, array $command_segments): array
    {
        $start_match_count = 0;
        $contain_positions = [];
        
        // 对每个分段进行匹配：开头匹配优先，一旦开头匹配成功就不再考虑包含匹配
        for ($i = 0; $i < count($input_segments); $i++) {
            $input_seg = $input_segments[$i];
            $command_seg = $command_segments[$i];
            
            // 先尝试开头匹配
            if (str_starts_with($command_seg, $input_seg)) {
                $start_match_count++;
                // 开头匹配成功，该分段不再考虑包含匹配
            } else {
                // 开头匹配失败，该分段才考虑包含匹配
                if (($pos = strpos($command_seg, $input_seg)) !== false) {
                    $contain_positions[] = $pos;
                } else {
                    // 包含匹配也失败，完全不匹配
                    return [
                        'is_exact_match' => false,
                        'start_match_count' => 0,
                        'contain_positions' => []
                    ];
                }
            }
        }
        
        // 如果所有输入分段都开头匹配，且命令分段数等于输入分段数，才是完全匹配
        $is_exact_match = ($start_match_count === count($input_segments)) && (count($command_segments) === count($input_segments));
        
        return [
            'is_exact_match' => $is_exact_match,
            'start_match_count' => $start_match_count,
            'contain_positions' => $contain_positions
        ];
    }
    
    /**
     * @DESC         |分段递进匹配命令
     *
     * @param array $input_segments 用户输入的分段
     * @param array $commands 所有命令
     * @return array 匹配结果
     */
    private function progressiveMatchCommands(array $input_segments, array $commands): array
    {
        $matched_commands = [];
        
        foreach ($commands as $group => $group_commands) {
            foreach ($group_commands as $key => $command_data) {
                $key_lower = strtolower($key);
                $key_segments = explode(':', $key_lower);
                
                // 检查命令分段数是否大于等于输入分段数
                if (count($key_segments) < count($input_segments)) {
                    continue;
                }
                
                // 逐段检查开头匹配
                $all_segments_match = true;
                for ($i = 0; $i < count($input_segments); $i++) {
                    if (!str_starts_with($key_segments[$i], $input_segments[$i])) {
                        $all_segments_match = false;
                        break;
                    }
                }
                
                if ($all_segments_match) {
                    $matched_commands[] = [
                        'group' => $group,
                        'key' => $key,
                        'data' => $command_data,
                        'segments' => $key_segments,
                        'seg_count' => count($key_segments)
                    ];
                }
            }
        }
        
        return $matched_commands;
    }

    /**
     * @DESC         |检查命令
     *
     * 参数区：
     *
     * @param array $args
     * @return array
     */
    private function checkCommand(array $args): array
    {
        $command = strtolower(trim($args['command'] ?? ''));
        
        // 空命令：显示所有命令列表
        if ($command === '') {
            $commands = Env::getCommands();
            if (!empty($commands)) {
                return $commands; // 返回所有命令，让 showRecommendations 显示
            }
        }
        
        if ($command === 'command:upgrade') {
            try {
                ObjectManager::getInstance(\Weline\Framework\Console\Console\Command\Upgrade::class)->execute();
            } catch (Exception $exception) {
                $this->printer->error($exception->getMessage());
                exit(1);
            }
            exit(0);
        }
        $commands = Env::getCommands();
        if ($command !== 'command:upgrade' && empty($commands)) {
            try {
                ObjectManager::getInstance(\Weline\Framework\Console\Console\Command\Upgrade::class)->execute();
            } catch (Exception $exception) {
                $this->printer->error($exception->getMessage());
                exit(1);
            }
            $commands = Env::getCommands();
            if (empty($commands)) {
                $this->printer->error('Command registry update failed; please run: php bin/w command:upgrade');
                exit(1);
            }
//            exit($this->printer->error('命令系统异常！请完整执行（不能简写）更新模块命令后重试：php bin/w command:upgrade'));
        }

        // 检查完整命令
        foreach ($commands as $group => $group_commands) {
            if (isset($group_commands[$command]) && $command_data = $group_commands[$command]) {
                $command_class = $command_data['class'];
                return ['class' => $command_class, 'command' => $command, 'data' => $command_data];
            }
        }
        $recommendCommands = $this->recommendCommand($commands, $command);
        $commands = [];
        foreach ($recommendCommands as $recommendCommand) {
            $commands = array_merge($commands, $recommendCommand);
        }
        
        // 收集所有唯一命令（按命令名分组，同名命令按优先级排序）
        $commandsByName = [];
        foreach ($commands as $cmdItem) {
            if (!is_array($cmdItem)) {
                continue;
            }
            foreach ($cmdItem as $c => $data) {
                if (is_array($data) && isset($data['class'])) {
                    $commandsByName[$c][] = ['class' => $data['class'], 'command' => $c, 'data' => $data];
                }
            }
        }
        
        // 对同名命令按优先级排序：app/code 下的命令优先于 vendor 下的命令
        $allUniqueCommands = [];
        foreach ($commandsByName as $cmdName => $cmdList) {
            if (count($cmdList) > 1) {
                // 同名命令多个，按优先级排序
                usort($cmdList, function($a, $b) {
                    $aIsVendor = $this->isVendorCommand($a['class']);
                    $bIsVendor = $this->isVendorCommand($b['class']);
                    // app/code 优先于 vendor（vendor 排后面）
                    if ($aIsVendor && !$bIsVendor) {
                        return 1;
                    }
                    if (!$aIsVendor && $bIsVendor) {
                        return -1;
                    }
                    return 0;
                });
            }
            // 取优先级最高的
            $allUniqueCommands[$cmdList[0]['class']] = $cmdList[0];
        }
        
        // 如果只有一个唯一命令类，直接执行
        if (count($allUniqueCommands) === 1) {
            return reset($allUniqueCommands);
        }
        
        if (count($commands) === 1 && $singleCmd = $commands[0]) {
            foreach ($singleCmd as $c => $data) {
                if (is_array($data) && isset($data['class'])) {
                    return ['class' => $data['class'], 'command' => $c, 'data' => $data];
                }
            }
        }
        
        // 当有多个匹配时，检查是否有且仅有一个命令的段数与用户输入段数相同（精确段数匹配）
        $inputSegCount = count(explode(':', $command));
        $exactSegMatches = [];
        foreach ($commands as $cmdItem) {
            if (!is_array($cmdItem)) {
                continue;
            }
            foreach ($cmdItem as $c => $data) {
                if (is_array($data) && isset($data['class'])) {
                    $cmdSegCount = count(explode(':', $c));
                    if ($cmdSegCount === $inputSegCount) {
                        $exactSegMatches[] = ['class' => $data['class'], 'command' => $c, 'data' => $data];
                    }
                }
            }
        }
        if (count($exactSegMatches) === 1) {
            return $exactSegMatches[0];
        }
        
        // 多个匹配但都指向同一命令类（主命令+别名）时，直接执行
        $uniqueClasses = [];
        $classToCommand = [];
        foreach ($commands as $cmdItem) {
            if (!is_array($cmdItem)) {
                continue;
            }
            foreach ($cmdItem as $c => $data) {
                if (is_array($data) && isset($data['class'])) {
                    $cls = $data['class'];
                    $uniqueClasses[$cls] = true;
                    if (!isset($classToCommand[$cls]) || strlen($c) > strlen($classToCommand[$cls]['command'])) {
                        $classToCommand[$cls] = ['class' => $cls, 'command' => $c, 'data' => $data];
                    }
                }
            }
        }
        if (count($uniqueClasses) === 1 && !empty($classToCommand)) {
            $single = reset($classToCommand);
            return $single;
        }
        
        // 如果没有找到唯一匹配，返回推荐列表
        if (empty($commands)) {
            return $recommendCommands;
        }
        
        // 重新组织推荐结果，保持排序
        $sortedRecommendCommands = [];
        foreach ($recommendCommands as $key => $command) {
            foreach ($command as $k => $item) {
                $keys = array_keys($item);
                $command_key = array_shift($keys);
                $command_data = array_pop($item);
                // 确保数据结构正确
                if (is_array($command_data) && isset($command_data['class'])) {
                    $sortedRecommendCommands[$key][$command_key] = $command_data;
                }
            }
        }
        
        // 计算每个group的最高分数
        $groupScores = [];
        foreach ($sortedRecommendCommands as $group => $groupCommands) {
            foreach ($groupCommands as $commandKey => $commandData) {
                if (isset($commandData['group']) && isset($commandData['score'])) {
                    if (!isset($groupScores[$commandData['group']]) || $commandData['score'] > $groupScores[$commandData['group']]) {
                        $groupScores[$commandData['group']] = $commandData['score'];
                    }
                }
            }
        }

        // To make it simple, let's move the group sorting to checkCommand before printing

        // Since the problem is in display order, let's move the sorting to checkCommand

        // For now, let's assume the loop order is sufficient, but to force, let's sort the groups

        $groupList = array_keys($sortedRecommendCommands);
        usort($groupList, function($a, $b) use ($groupScores) {
            return ($groupScores[$b] ?? 0) <=> ($groupScores[$a] ?? 0);
        });

        $finalList = [];
        foreach ($groupList as $group) {
            $finalList[$group] = $sortedRecommendCommands[$group];
        }

        return $finalList;
    }
    
    /**
     * @DESC         |显示推荐命令列表
     *
     * @param array $recommendations 推荐命令列表
     * @return void
     */
    private function showRecommendations(array $recommendations, array $args): void
    {
        if ($args && empty($recommendations)) {
            $this->printer->error(__('没有找到匹配的命令'));
            return;
        }
        
        // 使用简洁美观的标题
        $this->printer->note(__('🎯 找到以下匹配的命令'));
        $this->printer->separator('─', 0, 'NOTE');
        
        // 使用新的分组树形显示
        $this->printer->groupedTreeList($recommendations, 'NOTE');
        
        // 添加底部装饰
        $this->printer->separator('═', 0, 'SUCCESS');
        $this->printer->note(__('💡 提示：可以使用短命令形式，如 u:r 匹配 user:reset:password'));
    }

    /**
     * 判断命令类是否来自 vendor 目录（Framework 或其他 vendor 包）
     * app/code 下的命令优先于 vendor 下的命令
     *
     * @param string $class 命令类全名
     * @return bool 是否为 vendor 命令
     */
    private function isVendorCommand(string $class): bool
    {
        // 通过类名反射获取文件路径
        try {
            $reflection = new \ReflectionClass($class);
            $filePath = $reflection->getFileName();
            if ($filePath) {
                // 规范化路径分隔符
                $filePath = str_replace('\\', '/', $filePath);
                // 检查是否在 vendor 目录下
                return str_contains($filePath, '/vendor/');
            }
        } catch (\Throwable $e) {
            // 反射失败，降级为模块名判断
        }
        
        // 降级：通过模块名判断（Weline_Framework_* 通常在 vendor）
        // 但这不够准确，因为 Framework 模块也可能在 app/code
        return false;
    }
    
    /**
     * 根据分组名称获取对应的图标
     *
     * @param string $group 分组名称
     * @return string 图标
     */
    private function getGroupIcon(string $group): string
    {
        // 根据分组名称的关键词匹配图标
        $groupLower = strtolower($group);
        
        if (strpos($groupLower, 'user') !== false) {
            return '👤'; // 用户相关
        } elseif (strpos($groupLower, 'cache') !== false) {
            return '💾'; // 缓存相关
        } elseif (strpos($groupLower, 'module') !== false) {
            return '📦'; // 模块相关
        } elseif (strpos($groupLower, 'theme') !== false) {
            return '🎨'; // 主题相关
        } elseif (strpos($groupLower, 'queue') !== false) {
            return '⏳'; // 队列相关
        } elseif (strpos($groupLower, 'cron') !== false) {
            return '⏰'; // 定时任务相关
        } elseif (strpos($groupLower, 'server') !== false) {
            return '🖥️'; // 服务器相关
        } elseif (strpos($groupLower, 'database') !== false || strpos($groupLower, 'index') !== false) {
            return '🗄️'; // 数据库相关
        } elseif (strpos($groupLower, 'event') !== false) {
            return '⚡'; // 事件相关
        } elseif (strpos($groupLower, 'i18n') !== false || strpos($groupLower, 'translate') !== false) {
            return '🌐'; // 国际化相关
        } elseif (strpos($groupLower, 'maintenance') !== false) {
            return '🔧'; // 维护相关
        } elseif (strpos($groupLower, 'deploy') !== false) {
            return '🚀'; // 部署相关
        } elseif (strpos($groupLower, 'dev') !== false) {
            return '🛠️'; // 开发相关
        } elseif (strpos($groupLower, 'system') !== false) {
            return '⚙️'; // 系统相关
        } elseif (strpos($groupLower, 'plugin') !== false) {
            return '🔌'; // 插件相关
        } elseif (strpos($groupLower, 'setup') !== false) {
            return '🔨'; // 设置相关
        } elseif (strpos($groupLower, 'menu') !== false) {
            return '📋'; // 菜单相关
        } elseif (strpos($groupLower, 'resource') !== false) {
            return '📄'; // 资源相关
        } elseif (strpos($groupLower, 'rpc') !== false) {
            return '🔗'; // RPC相关
        } elseif (strpos($groupLower, 'command') !== false) {
            return '⌨️'; // 命令相关
        } elseif (strpos($groupLower, 'shopify') !== false) {
            return '🛒'; // Shopify相关
        } elseif (strpos($groupLower, 'phpunit') !== false) {
            return '🧪'; // 测试相关
        } elseif (strpos($groupLower, 'doc') !== false) {
            return '📚'; // 文档相关
        } else {
            return '📁'; // 默认文件夹图标
        }
    }

    /**
     * 根据命令名称获取对应的图标
     *
     * @param string $command 命令名称
     * @return string 图标
     */
    private function getCommandIcon(string $command): string
    {
        $cmdLower = strtolower($command);
        
        // 根据命令类型匹配图标
        if (strpos($cmdLower, 'create') !== false || strpos($cmdLower, 'add') !== false) {
            return '➕'; // 创建/添加
        } elseif (strpos($cmdLower, 'delete') !== false || strpos($cmdLower, 'remove') !== false) {
            return '🗑️'; // 删除/移除
        } elseif (strpos($cmdLower, 'update') !== false || strpos($cmdLower, 'upgrade') !== false) {
            return '🔄'; // 更新/升级
        } elseif (strpos($cmdLower, 'clear') !== false || strpos($cmdLower, 'flush') !== false) {
            return '🧹'; // 清理/刷新
        } elseif (strpos($cmdLower, 'reset') !== false) {
            return '🔄'; // 重置
        } elseif (strpos($cmdLower, 'status') !== false || strpos($cmdLower, 'listing') !== false) {
            return '📊'; // 状态/列表
        } elseif (strpos($cmdLower, 'start') !== false || strpos($cmdLower, 'run') !== false) {
            return '▶️'; // 启动/运行
        } elseif (strpos($cmdLower, 'stop') !== false) {
            return '⏹️'; // 停止
        } elseif (strpos($cmdLower, 'enable') !== false || strpos($cmdLower, 'active') !== false) {
            return '✅'; // 启用/激活
        } elseif (strpos($cmdLower, 'disable') !== false) {
            return '❌'; // 禁用
        } elseif (strpos($cmdLower, 'install') !== false) {
            return '📥'; // 安装
        } elseif (strpos($cmdLower, 'uninstall') !== false) {
            return '📤'; // 卸载
        } elseif (strpos($cmdLower, 'sync') !== false) {
            return '🔄'; // 同步
        } elseif (strpos($cmdLower, 'collect') !== false) {
            return '📋'; // 收集
        } elseif (strpos($cmdLower, 'compile') !== false) {
            return '🔨'; // 编译
        } elseif (strpos($cmdLower, 'debug') !== false) {
            return '🐛'; // 调试
        } elseif (strpos($cmdLower, 'test') !== false || strpos($cmdLower, 'phpunit') !== false) {
            return '🧪'; // 测试
        } elseif (strpos($cmdLower, 'import') !== false) {
            return '📥'; // 导入
        } elseif (strpos($cmdLower, 'export') !== false) {
            return '📤'; // 导出
        } elseif (strpos($cmdLower, 'backup') !== false) {
            return '💾'; // 备份
        } elseif (strpos($cmdLower, 'restore') !== false) {
            return '🔄'; // 恢复
        } elseif (strpos($cmdLower, 'migrate') !== false) {
            return '🚀'; // 迁移
        } elseif (strpos($cmdLower, 'deploy') !== false) {
            return '🚀'; // 部署
        } elseif (strpos($cmdLower, 'build') !== false) {
            return '🏗️'; // 构建
        } elseif (strpos($cmdLower, 'generate') !== false) {
            return '⚡'; // 生成
        } elseif (strpos($cmdLower, 'validate') !== false) {
            return '✅'; // 验证
        } elseif (strpos($cmdLower, 'check') !== false) {
            return '🔍'; // 检查
        } elseif (strpos($cmdLower, 'monitor') !== false) {
            return '👁️'; // 监控
        } elseif (strpos($cmdLower, 'log') !== false) {
            return '📝'; // 日志
        } elseif (strpos($cmdLower, 'config') !== false) {
            return '⚙️'; // 配置
        } elseif (strpos($cmdLower, 'info') !== false || strpos($cmdLower, 'detail') !== false) {
            return 'ℹ️'; // 信息/详情
        } else {
            return '⚡'; // 默认命令图标
        }
    }

    /**
     * @DESC         |显示命令帮助信息
     *
     * @param array $command_info 命令信息数组
     * @return void
     */
    private function showHelp(array $command_info): void
    {
        // 直接从命令类的help()方法获取帮助信息
        try {
            $commandInstance = ObjectManager::getInstance($command_info['class']);
            $help = $commandInstance->help();
            
            // 如果help是数组，使用CommandHelper格式化
            if (is_array($help)) {
                $help = CommandHelper::parseHelpArray($help);
            }
            
            // 显示命令名称和tip
            $this->printer->note(__('命令') . ': ' . $command_info['command']);
            if (method_exists($commandInstance, 'tip')) {
                $this->printer->success(__('简述') . ': ' . $commandInstance->tip());
            }
            $this->printer->separator('═', 0, 'NOTE');
            
            // 显示help信息
            $this->printer->printing($help);
        } catch (\Exception $e) {
            $this->printer->error(__('获取命令帮助信息失败') . ': ' . $e->getMessage());
        }
    }
    
}
