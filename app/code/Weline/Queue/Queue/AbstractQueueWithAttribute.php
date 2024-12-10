<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Administrator
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：25/7/2023 16:55:13
 */

namespace Weline\Queue\Queue;

use PhpOffice\PhpSpreadsheet\Exception;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Weline\Framework\Database\Exception\ModelException;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Manager\ObjectManager;
use Weline\Queue\Cache\QueueCache;
use Weline\Queue\Model\Queue;
use Weline\Queue\QueueInterface;

abstract class AbstractQueueWithAttribute extends DataObject implements QueueInterface
{
    protected array $titles;
    public array $error_data = [];
    public array $success_data = [];
    public array $additional_error_data = [];
    public array $sites;
    public int $total = 0;
    public string $taskClass = '';
    public string $item_id = 'spu';
    public string $task_msg_field = 'queue_msg';
    public int $validate_total = 0;
    public float $percent = 0.0000;
    public ?Queue $queue;
    public ?object $task = null;
    protected array $current_item = [];
    protected int $current_index = 1;
    protected \Weline\Framework\Cache\CacheInterface $cache;
    protected array $fatal_errors = [];
    protected array $normal_errors = [];

    public function __construct(private QueueCache $queue_cache)
    {
        $this->cache = $queue_cache->create();
        $this->cache->setStatus(true);
    }

    function setTitles(array $titles): self
    {
        $this->titles = $titles;
        return $this;
    }

    function initQueue(Queue &$queue, string $taskClass, string $item_id = 'spu', string $task_msg_field = 'queue_msg'): self
    {
        $this->queue = &$queue;
        $this->queue->init();
        $this->taskClass = $taskClass;
        $this->setData($queue->getData());
        $this->task           = $this->getTask();
        $this->item_id        = $item_id;
        $this->task_msg_field = $task_msg_field;
        $this->task->setData($task_msg_field, '')
            ->setStatus(1)
            ->setProcess('正在处理...')
            ->setResult('运行中...')
            ->save();
        return $this;
    }

    protected function setTotal(int $total = 0): self
    {
        $this->total          = $total;
        $this->validate_total = $total;
        return $this;
    }

    protected function setValidateTotal(int $total = 0): self
    {
        $this->validate_total = $total;
        return $this;
    }

    protected function setSuccess(string $msg = ''): self
    {
        $this->current_item['msg'] = $msg;
        $this->success_data[$this->current_item[$this->item_id]]      = $this->current_item;
        return $this;
    }

    protected function appendError(string $msg = '', bool $fatalError = false): self
    {
        return $this->setError($msg, $fatalError, true);
    }

    protected function setError(string $msg = '', bool $fatalError = false, bool $append = true): self
    {
        if ($fatalError) {
            $this->fatal_errors[$msg] = $msg;
        } else {
            $this->normal_errors[$msg] = $msg;
        }
        if ($this->current_item) {
            if (isset($this->current_item['error'][$this->current_item[$this->item_id]])) {
                $this->current_item['error'][$this->current_item[$this->item_id]] = $this->current_item['error'][$this->current_item[$this->item_id]] . PHP_EOL . $msg;
            } else {
                $this->current_item['error'][$this->current_item[$this->item_id]] = $msg;
            }
            $this->error_data[$this->current_item[$this->item_id]] = $this->current_item;
        }
        $msg = '';
        if ($this->fatal_errors) {
            $msg             .= __('致命错误：') . PHP_EOL;
            $fatalErrorIndex = 1;
            foreach ($this->fatal_errors as $fatalError) {
                $msg             .= $fatalErrorIndex . '、' . $fatalError . PHP_EOL;
                $fatalErrorIndex += 1;
            }
        }
        if ($this->normal_errors) {
            $msg              .= __('错误：') . PHP_EOL;
            $normalErrorIndex = 1;
            foreach ($this->normal_errors as $normalError) {
                $msg              .= $normalErrorIndex . '、' . $normalError . PHP_EOL;
                $normalErrorIndex += 1;
            }
        }
        if ($append) {
            $msg = $this->queue->getResult() . PHP_EOL . $msg;
        }
        if($this->current_item){
            if (empty($this->current_item[$this->item_id])) {
                $msg                  .= 'current_item[' . $this->item_id . '] is null';
                $this->fatal_errors[] = $msg;
            } else {
                $this->setProcessMsg('id', $this->current_item[$this->item_id]);
            }
        }

        $this->queue->setResult($msg)->save();
        try {
            /** @var Task $task */
            $task = $this->getTask();
            if ($append) {
                $taskMsg = $task->getData($this->task_msg_field) . PHP_EOL . $msg;
            } else {
                $taskMsg = $msg;
            }
            $task->setData($this->task_msg_field, $taskMsg)
                ->setProcess(__('总计: %s 条,成功: %s 条,失败: %s 条 当前：%s', [$this->total, count($this->success_data), count($this->error_data), $this->current_item['id'] ?? '']))
                ->save();
        } catch (ModelException $e) {
            d($e->getMessage());
        }
        $this->processed(false,$msg);
        return $this;
    }
    protected function error(string $msg = '', bool $fatalError = false, bool $append = true): self
    {
        if ($fatalError) {
            $this->fatal_errors[$msg] = $msg;
        } else {
            $this->normal_errors[$msg] = $msg;
        }
        if ($this->current_item) {
            if (isset($this->current_item['error'][$this->current_item[$this->item_id]])) {
                $this->current_item['error'][$this->current_item[$this->item_id]] = $this->current_item['error'][$this->current_item[$this->item_id]] . PHP_EOL . $msg;
            } else {
                $this->current_item['error'][$this->current_item[$this->item_id]] = $msg;
            }
            $this->error_data[$this->current_item[$this->item_id]] = $this->current_item;
        }
        $msg = '';
        if ($this->fatal_errors) {
            $msg             .= __('致命错误：') . PHP_EOL;
            $fatalErrorIndex = 1;
            foreach ($this->fatal_errors as $fatalError) {
                $msg             .= $fatalErrorIndex . '、' . $fatalError . PHP_EOL;
                $fatalErrorIndex += 1;
            }
        }
        if ($this->normal_errors) {
            $msg              .= __('错误：') . PHP_EOL;
            $normalErrorIndex = 1;
            foreach ($this->normal_errors as $normalError) {
                $msg              .= $normalErrorIndex . '、' . $normalError . PHP_EOL;
                $normalErrorIndex += 1;
            }
        }
        if ($append) {
            $msg = $this->queue->getResult() . PHP_EOL . $msg;
        }
        if (empty($this->current_item[$this->item_id])) {
            $msg                  .= 'current_item[' . $this->item_id . '] is null';
            $this->fatal_errors[] = $msg;
        } else {
            $this->setProcessMsg('id', $this->current_item[$this->item_id]);
        }
        $this->queue->setResult($msg)->save();
        try {
            /** @var Task $task */
            $task = $this->getTask();
            if ($append) {
                $taskMsg = $task->getData($this->task_msg_field) . PHP_EOL . $msg;
            } else {
                $taskMsg = $msg;
            }
            $task->setData($this->task_msg_field, $taskMsg)
                ->setProcess(__('总计: %s 条,成功: %s 条,失败: %s 条 当前：%s', [$this->total, count($this->success_data), count($this->error_data), $this->current_item['id'] ?? '']))
                ->save();
        } catch (ModelException $e) {
            d($e->getMessage());
        }
        $this->processed(false,$msg);
        return $this;
    }

    /**
     * @DESC          # 设置致命错误程序会终止
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/8/2 下午1:31
     * 参数区：
     * @return $this
     * @throws \Exception
     */
    public function setFatalError(string $msg = ''): self
    {
        $this->setError($msg, true);
        $this->queue->setStatus($this->queue::status_error)->save();
        $this->getTask()->setStatus(3)->save();
        return $this;
    }
    public function fatal_error(string $msg = ''): self
    {
        $this->setError($msg, true);
        $this->queue->setStatus($this->queue::status_error)->save();
        $this->getTask()->setStatus(3)->save();
        return $this;
    }

    public function setProcessMsg(string $key, string $msg): self
    {
        if (!$this->queue) {
            throw new \Exception('queue is null');
        }
        $process = $this->queue->getProcess();
        if ($process) {
            $process = json_decode($process, true);
        } else {
            $process = [];
        }
        $process[$key][] = $msg;
        $process         = json_encode($process);
        $this->queue->setProcess($process)
            ->save();
        return $this;
    }

    public function unsetProcessMsg(string $key): self
    {
        if (!$this->queue) {
            throw new \Exception('queue is null');
        }
        $process = $this->queue->getProcess();
        if ($process) {
            $process = json_decode($process, true);
        } else {
            $process = [];
        }
        unset($process[$key]);
        $process = json_encode($process);
        $this->queue->setProcess($process)
            ->save();
        return $this;
    }

    /**
     * @DESC          # 致命错误处理
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/8/2 下午1:31
     * 参数区：
     * @return $this
     * @throws \Exception
     */
    public function processFatalError(): self
    {
        if ($this->fatal_errors) {
            throw new \Exception($this->queue->getResult());
        }
        return $this;
    }


    protected function setAdditionalError(array $additional_error_data = [], string $msg = ''): self
    {
        $additional_error_data['msg'] = $msg;
        $this->additional_error_data  = $additional_error_data;
        return $this;
    }

    public function getItem(string|array $current_item, array $merge = []): array
    {
        if (is_string($current_item)) {
            $item = [$this->item_id => $current_item, 'ok' => 0];
            $this->current_item = &$item;
        } elseif (is_array($current_item)) {
            if (!isset($current_item[$this->item_id])) {
                throw new \Exception('$current_item 必须包含 ' . $this->item_id);
            }
            if (!isset($current_item['ok'])) {
                $current_item['ok'] = 0;
            }
            $this->current_item = &$current_item;
        }
        if ($merge) {
            $current_item = array_merge($this->current_item, $merge);
            $this->current_item = &$current_item;
        }
        $this->titles = array_keys($this->current_item);
        return $this->current_item;
    }

    function processing(array|string &$current_item, int $current_index, string $msg = ''): self
    {
        $current_item = $this->getItem($current_item);
        return $this->process($current_item, $current_index, $msg, '正在处理');
    }

    function processed(bool $success = true, string $msg = ''): self
    {
        if(!$this->current_item){
            return $this;
        }
        if ($this->current_item['ok'] || $success) {
            $this->current_item['ok'] = 1;
            $this->success_data[$this->current_item[$this->item_id]] = $this->current_item;
        } else {
            $this->error_data[$this->current_item[$this->item_id]] = $this->current_item;
        }
        return $this->process($this->current_item, $this->current_index, $msg);
    }

    function process(array &$current_item, int $current_index, string $msg = '', $processMsg = '已处理'): self
    {
        $this->processFatalError();
        $current_item['msg'] = $msg;
        $this->current_index = &$current_index;
        $this->current_item = &$current_item;
        $this->percent       = $this->validate_total ? (float)(number_format($this->current_index / $this->validate_total, 4)) * 100 : 0;
        $output              = '[[' . __('成功:%1 ,失败：%2 ,' . $processMsg . ':%3 ',
                [count($this->success_data), count($this->error_data), json_encode($current_item)]) . ']]';
        $task                = $this->getTask();
        $task
            ->setProcess(__('总计:%1 , 有效:%2, 成功:%3 ,失败：%4 , 进度:%5%', [$this->total, $this->validate_total, count($this->success_data), count($this->error_data), (string)$this->percent]))
            ->save();
        $result = $this->queue->getResult();
        # 删除[[]]中的内容
        $result = preg_replace('/(\[\[.*?\]\])/', '', $result);
        $this->queue->setResult($result . $output)->save();
        return $this;
    }

    function finished(array $status = []): void
    {
        $this->processFatalError();
        if (isset($status['errors'])) {
            $this->error_data = $status['errors'];
        }
        if (isset($status['success'])) {
            $this->success_data[] = $status['success'];
        }
        $this->queue->setFinished(true)
            ->setStatus($this->queue::status_done)
            ->save();
        if ($status) {
            $this->process($this->current_item, $this->current_index, __('已完成'), __('已完成'));
        }
        $this->task->setStatus($this->task::status_SUCCESS)
            ->setData($this->task_msg_field, $this->task->getData($this->task_msg_field) . '<br>' . __('执行完成！'))
            ->setData('result', $this->task->getData('result') . '<br>' . __('执行完成！'))
            ->save();
        $this->output();
    }

    /**
     * @DESC          # 设置结果
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 25/7/2023 下午5:08
     * 参数区：
     * @param string $taskClass
     * @return array
     * @throws Exception
     * @throws ModelException
     * @throws \PhpOffice\PhpSpreadsheet\Writer\Exception
     */
    public function output(): array
    {
        $result              = [];
        $success_spreadsheet = new Spreadsheet();
        # region 生成成功结果文件
        if ($this->success_data) {
            try {
                $success_spreadsheet->removeSheetByIndex(0);
            } catch (Exception $e) {
            }
            $success_sheet = $success_spreadsheet->createSheet();
            $success_sheet->setTitle(__('成功'));
            $success_rows = 1;
            foreach ($this->titles as $key => $title) {
                $coordinateIndex = $key + 1;
                $cellName        = $success_sheet->getCellByColumnAndRow($coordinateIndex, $success_rows)->getCoordinate();
                $success_sheet->setCellValue($cellName, $title);
            }
            foreach ($this->success_data as $success) {
                $success_rows += 1;
                $column_index = 0;
                foreach ($success as $item) {
                    $column_index += 1;
                    $cellName     = $success_sheet->getCellByColumnAndRow($column_index, $success_rows)->getCoordinate();
                    if (is_array($item)) {
                        $item = w_var_export($item, true);
                    }
                    $success_sheet->setCellValue($cellName, $item);
                }
            }
            # 写入文件名称
            $filename = 'success' . DS . date('Y-m-d-H') . DS . md5(date('Y-m-d-H-i:s')) . '.xlsx';
            $writer   = new Xlsx($success_spreadsheet);

            $file_path      = 'pub' . DS . 'media' . DS . 'export' . DS . $filename;
            $save_file_path = BP . $file_path;
            if (!is_dir(dirname($save_file_path))) {
                mkdir(dirname($save_file_path), 0775, true);
                touch($save_file_path);
            }
            $writer->save($save_file_path);
            $result['file']['success'] = $file_path;
        } else {
            $result['file']['success'] = '';
        }
        # endregion
        # region 生成失败结果文件
        $error_spreadsheet = new Spreadsheet();
        if ($this->error_data) {
            try {
                $error_spreadsheet->removeSheetByIndex(0);
            } catch (Exception $e) {
            }
            $error_sheet = $error_spreadsheet->createSheet();
            $error_sheet->setTitle(__('失败'));
            $error_rows     = 1;
            $this->titles[] = 'error';
            foreach ($this->titles as $key => $title) {
                $coordinateIndex = $key + 1;
                $cellName        = $error_sheet->getCellByColumnAndRow($coordinateIndex, $error_rows)->getCoordinate();
                $error_sheet->setCellValue($cellName, $title);
            }
            foreach ($this->error_data as $error) {
                $error_rows   += 1;
                $column_index = 0;
                foreach ($error as $item) {
                    $column_index += 1;
                    $cellName     = $error_sheet->getCellByColumnAndRow($column_index, $error_rows)->getCoordinate();
                    if (is_array($item)) {
                        $item = w_var_export($item, true);
                    }
                    $error_sheet->setCellValue($cellName, $item);
                }
            }
            # 写入文件名称
            $filename = 'error' . DS . date('Y-m-d-H') . DS . md5(date('Y-m-d-H-i:s')) . '.xlsx';
            $writer   = new Xlsx($error_spreadsheet);

            $file_error_path      = 'pub' . DS . 'media' . DS . 'export' . DS . $filename;
            $save_error_file_path = BP . $file_error_path;
            if (!is_dir(dirname($save_error_file_path))) {
                mkdir(dirname($save_error_file_path), 0775, true);
                touch($save_error_file_path);
            }
            $writer->save($save_error_file_path);

            $result['file']['error'] = $file_error_path;
        } else {
            $result['file']['error'] = '';
        }
        # endregion
        # region 生成附加错误结果文件
        $additional_error_spreadsheet = new Spreadsheet();
        if ($this->additional_error_data) {
            try {
                $additional_error_spreadsheet->removeSheetByIndex(0);
            } catch (Exception $e) {
            }
            $additional_error_sheet = $additional_error_spreadsheet->createSheet();
            $additional_error_sheet->setTitle(__('附加错误'));
            $error_rows     = 1;
            $this->titles[] = __('附加错误');
            foreach ($this->titles as $key => $title) {
                $coordinateIndex = $key + 1;
                $cellName        = $additional_error_sheet->getCellByColumnAndRow($coordinateIndex, $error_rows)->getCoordinate();
                $additional_error_sheet->setCellValue($cellName, $title);
            }
            foreach ($this->additional_error_data as $error) {
                $error_rows += 1;
                $cellName   = $additional_error_sheet->getCellByColumnAndRow(1, $error_rows)->getCoordinate();
                if (is_array($error)) {
                    $error = w_var_export($error, true);
                }
                $additional_error_sheet->setCellValue($cellName, $error);
            }
            # 写入文件名称
            $filename = 'additional_error_data' . DS . date('Y-m-d-H') . DS . md5(date('Y-m-d-H-i:s')) . '.xlsx';
            $writer   = new Xlsx($additional_error_spreadsheet);

            $file_error_path      = 'pub' . DS . 'media' . DS . 'export' . DS . $filename;
            $save_error_file_path = BP . $file_error_path;
            if (!is_dir(dirname($save_error_file_path))) {
                mkdir(dirname($save_error_file_path), 0775, true);
                touch($save_error_file_path);
            }
            $writer->save($save_error_file_path);

            $result['file']['additional_error_data'] = $file_error_path;
        } else {
            $result['file']['additional_error_data'] = '';
        }
        # endregion
        $output = '';
        foreach ($result['file'] as $id => $item) {
            if ($item) {
                $output .= '<br><a href="/' . $item . '" target="_blank">' . $id . '</a>';
            }
        }
        $task = $this->getTask();
        # 删除[[]]中的内容
        $task->setData($this->task_msg_field, $task->getData($this->task_msg_field) . PHP_EOL . $output)
            ->setData('result', $task->getResult() . PHP_EOL . $output)
            ->save();
        $this->queue->setResult($this->queue->getResult() . $output)->save();
        return $result;
    }


    public function validate(Queue &$queue): bool
    {
        $this->queue = $queue;
        # 自带属性检测 如果有属性类型是 op_select_site 和 op_select_sites 则根据类型必填性检测值
        $options_data = [
            'entity' => $queue
        ];
        $attributes   = $queue->getAttributes($options_data);
        $data         = [];
        foreach ($attributes as $attribute) {
            $data[$attribute->getCode()] = $attribute->getValue();
        }
        foreach ($attributes as $attribute) {
            $res = $queue->validateAttribute($attribute);
            if (is_string($res)) {
                $queue->setResult($res);
                return false;
            }
        }
        return true;
    }

    static function getFileData(string $file, array|string $keys = []): array|string
    {
        if (is_string($keys)) {
            $keys = explode(',', $keys);
        }
        if (!is_file($file)) {
            $sourceFile = PUB . 'media' . DS . $file;
            if (!file_exists($sourceFile)) {
                return [];
            }
            $file = $sourceFile;
        }
        $reader = IOFactory::load($file);
        $rows   = $reader->getSheet(0)->toArray();
        $data   = [];
        if (empty($keys)) {
            $data = $rows;
        } else {
            foreach ($rows as $k => $row) {
                if ($k === 0) {
                    continue;
                }
                if (empty($row[0])) {
                    continue;
                }
                $tmpData = [];
                if ($keys) {
                    foreach ($keys as $key => $value) {
                        $tmpData[$value] = $row[$key] ?? '';
                    }
                } else {
                    $tmpData = $row;
                }
                if (empty($tmpData)) {
                    continue;
                }
                $data[] = $tmpData;
            }
        }
        return $data;
    }

    public static function getValues(Queue &$queue)
    {
        $values = [];
        foreach ($queue->getAttributes() as $attribute) {
            $values[$attribute->getCode()] = $attribute->getValue();
        }
        return $values;
    }
}