<?php

namespace WelineTools\FontSubLetter\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use WelineTools\FontSubLetter\Model\FontRecord;

class Admin extends BackendController
{
    /**
     * 后台管理主页面
     */
    public function index()
    {
        $record = ObjectManager::getInstance(FontRecord::class);
        
        // 获取统计数据
        $stats = $this->getStatistics();
        
        // 获取最近的记录
        $recentRecords = $record->order('created_at', 'DESC')->limit(10)->select();
        
        // 获取分页参数
        $page = (int)$this->request->getParam('page', 1);
        $limit = (int)$this->request->getParam('limit', 20);
        
        // 获取搜索参数
        $search = $this->request->getParam('search', '');
        $status = $this->request->getParam('status', '');
        $format = $this->request->getParam('format', '');
        $dateRange = $this->request->getParam('date_range', '');
        
        // 构建查询条件
        if ($search) {
            $record->where('original_filename', 'like', '%' . $search . '%');
        }
        
        if ($status) {
            $record->where('status', $status);
        }
        
        if ($format) {
            $record->where('font_format', $format);
        }
        
        if ($dateRange) {
            $dates = explode(' - ', $dateRange);
            if (count($dates) == 2) {
                $startDate = strtotime($dates[0]);
                $endDate = strtotime($dates[1]) + 86399; // 包含当天结束时间
                $record->where('created_at', '>=', $startDate)
                       ->where('created_at', '<=', $endDate);
            }
        }
        
        // 按创建时间倒序排列
        $record->order('created_at', 'DESC');
        
        // 获取分页数据
        $records = $record->pagination($page, $limit);
        
        $this->assign('stats', $stats);
        $this->assign('recentRecords', $recentRecords);
        $this->assign('records', $records);
        $this->assign('search', $search);
        $this->assign('status', $status);
        $this->assign('format', $format);
        $this->assign('dateRange', $dateRange);
        $this->assign('statusOptions', $record->getStatusOptions());
        $this->assign('formatOptions', $this->getFormatOptions());
        
        return $this->fetch();
    }
    
    /**
     * 获取统计数据
     */
    private function getStatistics(): array
    {
        $record = ObjectManager::getInstance(FontRecord::class);
        
        // 总记录数
        $totalRecords = $record->count();
        
        // 各状态统计
        $statusStats = [];
        foreach ($record->getStatusOptions() as $status => $label) {
            $statusStats[$status] = $record->where('status', $status)->count();
        }
        
        // 各格式统计
        $formatStats = [];
        $formats = $record->select('font_format')->group('font_format')->fetchAll();
        foreach ($formats as $format) {
            $formatName = $format['font_format'];
            $formatStats[$formatName] = $record->where('font_format', $formatName)->count();
        }
        
        // 今日统计
        $today = strtotime(date('Y-m-d'));
        $todayRecords = $record->where('created_at', '>=', $today)->count();
        
        // 本周统计
        $weekStart = strtotime('monday this week');
        $weekRecords = $record->where('created_at', '>=', $weekStart)->count();
        
        // 本月统计
        $monthStart = strtotime(date('Y-m-01'));
        $monthRecords = $record->where('created_at', '>=', $monthStart)->count();
        
        // 总文件大小
        $totalSize = $record->select('SUM(file_size) as total_size')->fetch();
        $totalFileSize = $totalSize['total_size'] ?? 0;
        
        // 平均文件大小
        $avgSize = $totalRecords > 0 ? $totalFileSize / $totalRecords : 0;
        
        return [
            'total_records' => $totalRecords,
            'status_stats' => $statusStats,
            'format_stats' => $formatStats,
            'today_records' => $todayRecords,
            'week_records' => $weekRecords,
            'month_records' => $monthRecords,
            'total_file_size' => $totalFileSize,
            'avg_file_size' => $avgSize
        ];
    }
    
    /**
     * 获取格式选项
     */
    private function getFormatOptions(): array
    {
        return [
            'ttf' => 'TrueType Font (TTF)',
            'otf' => 'OpenType Font (OTF)',
            'woff' => 'Web Open Font Format (WOFF)',
            'woff2' => 'Web Open Font Format 2.0 (WOFF2)'
        ];
    }
    
    /**
     * 字符提取统计
     */
    public function charStats()
    {
        $record = ObjectManager::getInstance(FontRecord::class);
        
        // 获取所有提取的字符
        $allExtractedChars = $record->select('extracted_chars, custom_chars')
                                   ->where('extracted_chars', 'not like', '')
                                   ->where('extracted_chars', 'not like', 'null')
                                   ->fetchAll();
        
        // 统计字符使用频率
        $charStats = [];
        $totalExtractions = 0;
        
        foreach ($allExtractedChars as $row) {
            $extractedChars = json_decode($row['extracted_chars'], true) ?: [];
            $customChars = json_decode($row['custom_chars'], true) ?: [];
            
            $allChars = array_merge($extractedChars, $customChars);
            $totalExtractions += count($allChars);
            
            foreach ($allChars as $char) {
                if (is_numeric($char)) {
                    $char = chr($char);
                }
                $charStats[$char] = ($charStats[$char] ?? 0) + 1;
            }
        }
        
        // 按使用频率排序
        arsort($charStats);
        
        // 获取前50个最常用的字符
        $topChars = array_slice($charStats, 0, 50, true);
        
        // 按字符类型分类统计
        $charTypeStats = $this->analyzeCharTypes($charStats);
        
        $this->assign('charStats', $charStats);
        $this->assign('topChars', $topChars);
        $this->assign('charTypeStats', $charTypeStats);
        $this->assign('totalExtractions', $totalExtractions);
        $this->assign('uniqueChars', count($charStats));
        
        return $this->fetch();
    }
    
    /**
     * 分析字符类型
     */
    private function analyzeCharTypes(array $charStats): array
    {
        $types = [
            'numbers' => ['count' => 0, 'chars' => []],
            'uppercase' => ['count' => 0, 'chars' => []],
            'lowercase' => ['count' => 0, 'chars' => []],
            'chinese' => ['count' => 0, 'chars' => []],
            'punctuation' => ['count' => 0, 'chars' => []],
            'symbols' => ['count' => 0, 'chars' => []],
            'other' => ['count' => 0, 'chars' => []]
        ];
        
        foreach ($charStats as $char => $count) {
            $charCode = ord($char);
            
            if (preg_match('/[0-9]/', $char)) {
                $types['numbers']['count'] += $count;
                $types['numbers']['chars'][$char] = $count;
            } elseif (preg_match('/[A-Z]/', $char)) {
                $types['uppercase']['count'] += $count;
                $types['uppercase']['chars'][$char] = $count;
            } elseif (preg_match('/[a-z]/', $char)) {
                $types['lowercase']['count'] += $count;
                $types['lowercase']['chars'][$char] = $count;
            } elseif ($charCode > 127 && preg_match('/[\x{4e00}-\x{9fff}]/u', $char)) {
                $types['chinese']['count'] += $count;
                $types['chinese']['chars'][$char] = $count;
            } elseif (preg_match('/[.,;:!?\'"()\[\]{}<>@#$%^&*\-_+=/|\\~`©®]/', $char)) {
                $types['punctuation']['count'] += $count;
                $types['punctuation']['chars'][$char] = $count;
            } elseif (preg_match('/[^\w\s]/', $char)) {
                $types['symbols']['count'] += $count;
                $types['symbols']['chars'][$char] = $count;
            } else {
                $types['other']['count'] += $count;
                $types['other']['chars'][$char] = $count;
            }
        }
        
        return $types;
    }
    
    /**
     * 用户统计
     */
    public function userStats()
    {
        $record = ObjectManager::getInstance(FontRecord::class);
        
        // 获取用户统计
        $userStats = $record->select('user_id, COUNT(*) as upload_count, SUM(file_size) as total_size')
                           ->group('user_id')
                           ->order('upload_count', 'DESC')
                           ->fetchAll();
        
        // 获取活跃用户（最近7天）
        $weekAgo = time() - 7 * 24 * 3600;
        $activeUsers = $record->select('user_id, COUNT(*) as recent_count')
                             ->where('created_at', '>=', $weekAgo)
                             ->group('user_id')
                             ->order('recent_count', 'DESC')
                             ->limit(10)
                             ->fetchAll();
        
        // 获取用户上传趋势（最近30天）
        $monthAgo = time() - 30 * 24 * 3600;
        $dailyStats = $record->select('DATE(FROM_UNIXTIME(created_at)) as date, COUNT(*) as count')
                            ->where('created_at', '>=', $monthAgo)
                            ->group('date')
                            ->order('date', 'ASC')
                            ->fetchAll();
        
        $this->assign('userStats', $userStats);
        $this->assign('activeUsers', $activeUsers);
        $this->assign('dailyStats', $dailyStats);
        
        return $this->fetch();
    }
    
    /**
     * 导出数据
     */
    public function export()
    {
        $record = ObjectManager::getInstance(FontRecord::class);
        
        // 获取所有记录
        $records = $record->order('created_at', 'DESC')->fetchAll();
        
        // 设置CSV头
        $headers = [
            'ID',
            '用户ID',
            '原始文件名',
            '字体格式',
            '文件大小',
            '状态',
            '提取字符数',
            '自定义字符数',
            '创建时间',
            '更新时间'
        ];
        
        // 输出CSV
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="font_records_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // 写入BOM
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // 写入表头
        fputcsv($output, $headers);
        
        // 写入数据
        foreach ($records as $row) {
            $extractedChars = json_decode($row['extracted_chars'], true) ?: [];
            $customChars = json_decode($row['custom_chars'], true) ?: [];
            
            $data = [
                $row['id'],
                $row['user_id'],
                $row['original_filename'],
                $row['font_format'],
                $this->formatFileSize($row['file_size']),
                $row['status'],
                count($extractedChars),
                count($customChars),
                date('Y-m-d H:i:s', $row['created_at']),
                date('Y-m-d H:i:s', $row['updated_at'])
            ];
            
            fputcsv($output, $data);
        }
        
        fclose($output);
        exit;
    }
    

    
    /**
     * 获取图表数据（AJAX）
     */
    public function getChartData()
    {
        $record = ObjectManager::getInstance(FontRecord::class);
        $type = $this->request->getParam('type', 'daily');
        
        switch ($type) {
            case 'daily':
                $data = $this->getDailyChartData($record);
                break;
            case 'format':
                $data = $this->getFormatChartData($record);
                break;
            case 'status':
                $data = $this->getStatusChartData($record);
                break;
            default:
                $data = [];
        }
        
        return $this->fetchJson([
            'code' => 200,
            'data' => $data
        ]);
    }
    
    /**
     * 获取每日上传数据
     */
    private function getDailyChartData($record): array
    {
        $monthAgo = time() - 30 * 24 * 3600;
        $dailyStats = $record->select('DATE(FROM_UNIXTIME(created_at)) as date, COUNT(*) as count')
                            ->where('created_at', '>=', $monthAgo)
                            ->group('date')
                            ->order('date', 'ASC')
                            ->fetchAll();
        
        $labels = [];
        $data = [];
        
        foreach ($dailyStats as $stat) {
            $labels[] = $stat['date'];
            $data[] = (int)$stat['count'];
        }
        
        return [
            'labels' => $labels,
            'data' => $data
        ];
    }
    
    /**
     * 获取格式分布数据
     */
    private function getFormatChartData($record): array
    {
        $formatStats = $record->select('font_format, COUNT(*) as count')
                             ->group('font_format')
                             ->fetchAll();
        
        $labels = [];
        $data = [];
        
        foreach ($formatStats as $stat) {
            $labels[] = strtoupper($stat['font_format']);
            $data[] = (int)$stat['count'];
        }
        
        return [
            'labels' => $labels,
            'data' => $data
        ];
    }
    
    /**
     * 获取状态分布数据
     */
    private function getStatusChartData($record): array
    {
        $statusStats = $record->select('status, COUNT(*) as count')
                             ->group('status')
                             ->fetchAll();
        
        $labels = [];
        $data = [];
        
        foreach ($statusStats as $stat) {
            $statusOptions = $record->getStatusOptions();
            $labels[] = $statusOptions[$stat['status']] ?? $stat['status'];
            $data[] = (int)$stat['count'];
        }
        
        return [
            'labels' => $labels,
            'data' => $data
        ];
    }

    /**
     * 获取字符类型（用于模板）
     */
    public function getCharType(string $char): string
    {
        $charCode = ord($char);
        
        if (preg_match('/[0-9]/', $char)) {
            return 'numbers';
        } elseif (preg_match('/[A-Z]/', $char)) {
            return 'uppercase';
        } elseif (preg_match('/[a-z]/', $char)) {
            return 'lowercase';
        } elseif ($charCode > 127 && preg_match('/[\x{4e00}-\x{9fff}]/u', $char)) {
            return 'chinese';
        } elseif (preg_match('/[.,;:!?\'"()\[\]{}<>@#$%^&*\-_+=\/|\\~`©®]/', $char)) {
            return 'punctuation';
        } elseif (preg_match('/[^\w\s]/', $char)) {
            return 'symbols';
        } else {
            return 'other';
        }
    }

    /**
     * 格式化文件大小（用于模板）
     */
    public function formatFileSize(int $size): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unit = 0;
        
        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }
        
        return round($size, 2) . ' ' . $units[$unit];
    }

    /**
     * 获取平均上传数（用于模板）
     */
    public function getAverageUploads(array $userStats): int
    {
        if (empty($userStats)) {
            return 0;
        }
        
        $totalUploads = array_sum(array_column($userStats, 'upload_count'));
        return round($totalUploads / count($userStats));
    }

    /**
     * 获取最高上传数（用于模板）
     */
    public function getTopUser(array $userStats): int
    {
        if (empty($userStats)) {
            return 0;
        }
        
        return max(array_column($userStats, 'upload_count'));
    }

    /**
     * 获取活跃度级别（用于模板）
     */
    public function getActivityLevel(int $uploadCount): string
    {
        if ($uploadCount >= 10) {
            return 'high';
        } elseif ($uploadCount >= 3) {
            return 'medium';
        } else {
            return 'low';
        }
    }
}
