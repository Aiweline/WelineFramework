<?php
/**
 * DataTable 数据导入API控制器
 * 处理数据导入相关API请求
 */

namespace Weline\DataTable\Api\Rest\V1;

use Weline\Framework\App\Controller\BackendRestController;
use Weline\DataTable\Exception\DataTableException;
use Weline\DataTable\Helper\ErrorHandler;
use Weline\DataTable\Helper\ImportManager;

class Import extends BackendRestController
{
    /**
     * 上传并解析导入文件
     * 路由: datatable/rest/v1/import/parse
     * 方法: POST
     */
    public function postParse()
    {
        try {
            // 检查文件上传
            if (empty($_FILES) || !isset($_FILES['file'])) {
                throw DataTableException::uploadFailed('没有上传文件');
            }

            $file = $_FILES['file'];
            
            // 验证文件上传错误
            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw DataTableException::uploadFailed($this->getUploadErrorMessage($file['error']));
            }

            // 获取文件信息
            $fileName = $file['name'];
            $fileTmpPath = $file['tmp_name'];
            $fileSize = $file['size'];
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            // 验证文件格式
            $allowedFormats = ['xlsx', 'xls', 'csv', 'json'];
            if (!in_array($fileExtension, $allowedFormats)) {
                throw DataTableException::uploadFailed("不支持的文件格式，只支持: " . implode(', ', $allowedFormats));
            }

            // 验证文件大小（最大10MB）
            $maxSize = 10 * 1024 * 1024; // 10MB
            if ($fileSize > $maxSize) {
                throw DataTableException::uploadFailed("文件大小超过限制（最大10MB）");
            }

            // 确定文件格式
            $format = ImportManager::FORMAT_CSV;
            if (in_array($fileExtension, ['xlsx', 'xls'])) {
                $format = ImportManager::FORMAT_EXCEL;
            } elseif ($fileExtension === 'json') {
                $format = ImportManager::FORMAT_JSON;
            }

            // 解析文件
            $parsedData = ImportManager::parseFile($fileTmpPath, $format);

            return $this->success('文件解析成功', [
                'file_name' => $fileName,
                'file_size' => $fileSize,
                'format' => $format,
                'headers' => $parsedData['headers'],
                'total_rows' => $parsedData['total_rows'],
                'preview_data' => array_slice($parsedData['data'], 0, 10), // 预览前10行
                'sample_data' => $parsedData['data'][0] ?? [] // 第一行数据作为示例
            ]);

        } catch (DataTableException $e) {
            $e->log('Import::postParse');
            $errorResponse = ErrorHandler::handleException($e, 'Import::postParse');
            return $this->error($errorResponse['msg'], '', $errorResponse['code']);
        } catch (\Exception $e) {
            $errorResponse = ErrorHandler::handleException($e, 'Import::postParse');
            return $this->error($errorResponse['msg'], '', $errorResponse['code']);
        }
    }

    /**
     * 验证导入数据
     * 路由: datatable/rest/v1/import/validate
     * 方法: POST
     */
    public function postValidate()
    {
        try {
            $model = $this->request->getParam('model');
            $data = $this->request->getParam('data', []);
            $fieldMapping = $this->request->getParam('field_mapping', []);
            $rules = $this->request->getParam('rules', []);

            // 验证必需参数
            ErrorHandler::validateRequiredParams(
                ['model' => $model, 'data' => $data],
                ['model', 'data']
            );

            // 验证模型类
            ErrorHandler::validateModel($model);

            $modelInstance = w_obj($model);

            // 字段映射
            if (!empty($fieldMapping)) {
                $data = ImportManager::mapFields($data, $fieldMapping);
            }

            // 如果没有提供验证规则，生成默认规则
            if (empty($rules)) {
                $rules = $this->generateDefaultRules($modelInstance);
            }

            // 验证数据
            $validationResult = ImportManager::validateData($data, $rules, $modelInstance);

            return $this->success('数据验证完成', $validationResult);

        } catch (DataTableException $e) {
            $e->log('Import::postValidate');
            $errorResponse = ErrorHandler::handleException($e, 'Import::postValidate');
            return $this->error($errorResponse['msg'], '', $errorResponse['code']);
        } catch (\Exception $e) {
            $errorResponse = ErrorHandler::handleException($e, 'Import::postValidate');
            return $this->error($errorResponse['msg'], '', $errorResponse['code']);
        }
    }

    /**
     * 执行数据导入
     * 路由: datatable/rest/v1/import/execute
     * 方法: POST
     */
    public function postExecute()
    {
        try {
            $model = $this->request->getParam('model');
            $data = $this->request->getParam('data', []);
            $fieldMapping = $this->request->getParam('field_mapping', []);
            $batchSize = max(1, intval($this->request->getParam('batch_size', 100)));

            // 验证必需参数
            ErrorHandler::validateRequiredParams(
                ['model' => $model, 'data' => $data],
                ['model', 'data']
            );

            // 验证模型类
            ErrorHandler::validateModel($model);

            $modelInstance = w_obj($model);

            // 字段映射
            if (!empty($fieldMapping)) {
                $data = ImportManager::mapFields($data, $fieldMapping);
            }

            // 执行批量导入
            $result = ImportManager::batchImport($modelInstance, $data, $batchSize);

            return $this->success('数据导入完成', $result);

        } catch (DataTableException $e) {
            $e->log('Import::postExecute');
            $errorResponse = ErrorHandler::handleException($e, 'Import::postExecute');
            return $this->error($errorResponse['msg'], '', $errorResponse['code']);
        } catch (\Exception $e) {
            $errorResponse = ErrorHandler::handleException($e, 'Import::postExecute');
            return $this->error($errorResponse['msg'], '', $errorResponse['code']);
        }
    }

    /**
     * 生成默认验证规则
     *
     * @param object $modelInstance 模型实例
     * @return array
     */
    private function generateDefaultRules($modelInstance): array
    {
        $rules = [];
        
        try {
            $columns = $modelInstance->columns();
            
            foreach ($columns as $column) {
                $fieldName = is_array($column) ? ($column['Field'] ?? $column['field'] ?? '') : $column;
                if (empty($fieldName)) {
                    continue;
                }

                $rule = [];
                
                // 必填验证
                if (is_array($column) && isset($column['Null']) && $column['Null'] === 'NO') {
                    $rule['required'] = true;
                }

                // 类型验证
                if (is_array($column) && isset($column['Type'])) {
                    $type = strtolower($column['Type']);
                    if (strpos($type, 'int') !== false) {
                        $rule['type'] = 'int';
                    } elseif (strpos($type, 'decimal') !== false || strpos($type, 'float') !== false) {
                        $rule['type'] = 'float';
                    } elseif (strpos($type, 'date') !== false || strpos($type, 'time') !== false) {
                        $rule['type'] = 'date';
                    }
                }

                // 唯一性验证（主键或唯一索引）
                if (is_array($column) && isset($column['Key'])) {
                    if ($column['Key'] === 'PRI' || $column['Key'] === 'UNI') {
                        $rule['unique'] = true;
                    }
                }

                if (!empty($rule)) {
                    $rules[$fieldName] = $rule;
                }
            }
        } catch (\Exception $e) {
            w_log_error("Generate default rules error: " . $e->getMessage());
        }

        return $rules;
    }

    /**
     * 获取上传错误消息
     *
     * @param int $errorCode 错误代码
     * @return string
     */
    private function getUploadErrorMessage(int $errorCode): string
    {
        switch ($errorCode) {
            case UPLOAD_ERR_INI_SIZE:
                return '文件大小超过php.ini设置的限制';
            case UPLOAD_ERR_FORM_SIZE:
                return '文件大小超过表单设置的限制';
            case UPLOAD_ERR_PARTIAL:
                return '文件只上传了一部分';
            case UPLOAD_ERR_NO_FILE:
                return '没有选择文件';
            case UPLOAD_ERR_NO_TMP_DIR:
                return '临时目录未设置';
            case UPLOAD_ERR_CANT_WRITE:
                return '文件写入失败';
            case UPLOAD_ERR_EXTENSION:
                return '文件上传被扩展程序阻止';
            default:
                return '未知上传错误';
        }
    }
}

