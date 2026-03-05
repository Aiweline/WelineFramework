<?php

declare(strict_types=1);

namespace Agent\WeeklyReport\Service;

use Agent\WeeklyReport\Model\WeeklyReport;
use Agent\WeeklyReport\Model\WeeklyTask;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use Weline\Framework\Manager\ObjectManager;

/**
 * Excel 导出服务
 * 
 * 职责：将周报数据导出为 Excel 文件
 */
class ExcelExporter
{
    private ?WeeklyReportService $reportService = null;

    private function getReportService(): WeeklyReportService
    {
        if ($this->reportService === null) {
            $this->reportService = ObjectManager::getInstance(WeeklyReportService::class);
        }
        return $this->reportService;
    }

    /**
     * 导出单周周报
     */
    public function exportWeekReport(int $weekNumber, int $year = 2026): string
    {
        $report = $this->getReportService()->getWeekReport($weekNumber, $year);

        if (!$report) {
            throw new \Exception("周报不存在: 第 {$weekNumber} 周");
        }

        $tasks = $this->getReportService()->getWeekTasks((int) $report->getId());

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle("第{$weekNumber}周");

        $this->writeWeekSheet($sheet, $report, $tasks, $weekNumber);

        return $this->saveSpreadsheet($spreadsheet, "周报_第{$weekNumber}周_{$year}");
    }

    /**
     * 导出全部周报
     */
    public function exportAllReports(int $year = 2026): string
    {
        $reports = $this->getReportService()->getAllReports($year);

        if (empty($reports)) {
            throw new \Exception("没有找到 {$year} 年的周报数据");
        }

        $spreadsheet = new Spreadsheet();
        $firstSheet = true;

        foreach ($reports as $report) {
            $weekNumber = $report->getData(WeeklyReport::schema_fields_WEEK_NUMBER);
            $tasks = $this->getReportService()->getWeekTasks((int) $report->getId());

            if ($firstSheet) {
                $sheet = $spreadsheet->getActiveSheet();
                $firstSheet = false;
            } else {
                $sheet = $spreadsheet->createSheet();
            }

            $holidayName = $report->getData(WeeklyReport::schema_fields_HOLIDAY_NAME);
            $sheetTitle = $holidayName ? "第{$weekNumber}周【{$holidayName}】" : "第{$weekNumber}周";
            $sheet->setTitle(mb_substr($sheetTitle, 0, 31));

            $this->writeWeekSheet($sheet, $report, $tasks, $weekNumber);
        }

        return $this->saveSpreadsheet($spreadsheet, "周报_全部_{$year}");
    }

    /**
     * 写入周报 Sheet
     */
    private function writeWeekSheet($sheet, WeeklyReport $report, array $tasks, int $weekNumber): void
    {
        $headers = [
            '第' . $weekNumber . '周',
            '子任务',
            '相关文档',
            '开始时间',
            '截止时间',
            '状态',
            '本周进展',
            '风险&解决方案',
            '下周计划',
        ];

        $holidayName = $report->getData(WeeklyReport::schema_fields_HOLIDAY_NAME);
        if ($holidayName) {
            $headers[0] = "第{$weekNumber}周【{$holidayName}】";
        }

        foreach ($headers as $col => $header) {
            $cell = chr(65 + $col) . '1';
            $sheet->setCellValue($cell, $header);
        }

        $this->applyHeaderStyle($sheet, count($headers));

        $row = 2;
        foreach ($tasks as $task) {
            $taskName = $task->getData(WeeklyTask::schema_fields_CATEGORY) ?: $task->getData(WeeklyTask::schema_fields_TASK_NAME);
            $sheet->setCellValue('A' . $row, $taskName);
            $sheet->setCellValue('B' . $row, $task->getData(WeeklyTask::schema_fields_SUB_TASK));
            $sheet->setCellValue('C' . $row, $task->getData(WeeklyTask::schema_fields_RELATED_DOC));
            $sheet->setCellValue('D' . $row, $task->getData(WeeklyTask::schema_fields_START_DATE));
            $sheet->setCellValue('E' . $row, $task->getData(WeeklyTask::schema_fields_END_DATE));
            $sheet->setCellValue('F' . $row, $task->getData(WeeklyTask::schema_fields_STATUS));
            $sheet->setCellValue('G' . $row, $task->getData(WeeklyTask::schema_fields_PROGRESS));
            $sheet->setCellValue('H' . $row, $task->getData(WeeklyTask::schema_fields_RISKS));
            $sheet->setCellValue('I' . $row, $task->getData(WeeklyTask::schema_fields_NEXT_WEEK_PLAN));

            $row++;
        }

        if (empty($tasks)) {
            if ($holidayName) {
                $sheet->setCellValue('A2', $holidayName);
                $sheet->setCellValue('G2', '节假日休息');
            }
        }

        foreach (range('A', 'I') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $this->applyDataStyle($sheet, $row - 1, count($headers));
    }

    /**
     * 应用表头样式
     */
    private function applyHeaderStyle($sheet, int $colCount): void
    {
        $lastCol = chr(64 + $colCount);

        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
        ]);

        $sheet->getRowDimension(1)->setRowHeight(25);
    }

    /**
     * 应用数据样式
     */
    private function applyDataStyle($sheet, int $lastRow, int $colCount): void
    {
        if ($lastRow < 2) {
            $lastRow = 2;
        }

        $lastCol = chr(64 + $colCount);

        $sheet->getStyle("A2:{$lastCol}{$lastRow}")->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ]);
    }

    /**
     * 保存 Spreadsheet 到文件
     */
    private function saveSpreadsheet(Spreadsheet $spreadsheet, string $filename): string
    {
        $exportDir = BP . 'var' . DIRECTORY_SEPARATOR . 'export' . DIRECTORY_SEPARATOR . 'weekly_report';

        if (!is_dir($exportDir)) {
            mkdir($exportDir, 0755, true);
        }

        $filePath = $exportDir . DIRECTORY_SEPARATOR . $filename . '_' . date('Ymd_His') . '.xlsx';

        $writer = new Xlsx($spreadsheet);
        $writer->save($filePath);

        return $filePath;
    }
}
