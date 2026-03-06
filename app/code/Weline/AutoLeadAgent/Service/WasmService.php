<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\AutoLeadAgent\Service;

use Weline\AutoLeadAgent\Model\WasmHash;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\App\Exception;

/**
 * WASM服务类
 * 
 * 负责WASM文件的哈希计算和管理
 */
class WasmService
{
    /**
     * WASM文件路径
     */
    private string $wasmPath;

    public function __construct()
    {
        $this->wasmPath = BP . '/app/code/Weline/AutoLeadAgent/view/statics/wasm/agent-core.wasm';
    }

    /**
     * 计算WASM文件的SHA-256哈希值
     * 
     * @param string $wasmPath WASM文件路径（可选，默认使用类属性）
     * @return string SHA-256哈希值（64字符十六进制字符串）
     * @throws Exception
     */
    public function calculateHash(string $wasmPath = ''): string
    {
        try {
            $path = $wasmPath ?: $this->wasmPath;

            if (!file_exists($path)) {
                throw new Exception(__('WASM文件不存在：%{1}', [$path]));
            }

            $hash = hash_file('sha256', $path);
            
            if ($hash === false) {
                throw new Exception(__('计算WASM文件哈希失败'));
            }

            return $hash;

        } catch (\Exception $e) {
            throw new Exception(__('计算WASM哈希失败：%{1}', [$e->getMessage()]));
        }
    }

    /**
     * 注册WASM哈希值
     * 
     * @param string $wasmPath WASM文件路径
     * @param string $version 版本号
     * @return string 哈希值
     * @throws Exception
     */
    public function registerHash(string $wasmPath = '', string $version = '1.0.0'): string
    {
        try {
            $path = $wasmPath ?: $this->wasmPath;
            $hash = $this->calculateHash($path);

            /** @var WasmHash $wasmHashModel */
            $wasmHashModel = ObjectManager::getInstance(WasmHash::class);
            
            // 检查是否已存在相同哈希
            $existing = $wasmHashModel->clear()
                ->where(WasmHash::schema_fields_HASH_VALUE, $hash)
                ->find()
                ->fetch();

            if ($existing->getId()) {
                return $hash;
            }

            // 保存新哈希记录
            $now = date('Y-m-d H:i:s');
            $wasmHashModel->clear()
                ->setData(WasmHash::schema_fields_WASM_PATH, $path)
                ->setData(WasmHash::schema_fields_HASH_VALUE, $hash)
                ->setData(WasmHash::schema_fields_VERSION, $version)
                ->setData(WasmHash::schema_fields_CREATED_AT, $now)
                ->setData(WasmHash::schema_fields_UPDATED_AT, $now)
                ->save();

            return $hash;

        } catch (\Exception $e) {
            throw new Exception(__('注册WASM哈希失败：%{1}', [$e->getMessage()]));
        }
    }

    /**
     * 获取最新的WASM哈希值
     * 
     * @return string 最新哈希值
     * @throws Exception
     */
    public function getLatestHash(): string
    {
        try {
            /** @var WasmHash $wasmHashModel */
            $wasmHashModel = ObjectManager::getInstance(WasmHash::class);
            
            $latest = $wasmHashModel->clear()
                ->order('hash_id', 'DESC')
                ->find()
                ->fetch();

            if ($latest->getId()) {
                return $latest->getData(WasmHash::schema_fields_HASH_VALUE);
            }

            // 如果没有记录，尝试计算当前文件的哈希
            if (file_exists($this->wasmPath)) {
                return $this->registerHash($this->wasmPath);
            }

            throw new Exception(__('未找到WASM哈希记录，且WASM文件不存在'));

        } catch (\Exception $e) {
            throw new Exception(__('获取最新WASM哈希失败：%{1}', [$e->getMessage()]));
        }
    }

    /**
     * 验证WASM文件哈希
     * 
     * @param string $hash 期望的哈希值
     * @param string $wasmPath WASM文件路径（可选）
     * @return bool 是否匹配
     */
    public function verifyHash(string $hash, string $wasmPath = ''): bool
    {
        try {
            $calculatedHash = $this->calculateHash($wasmPath);
            return hash_equals($hash, $calculatedHash);
        } catch (\Exception $e) {
            w_log_error('WasmService verifyHash error: ' . $e->getMessage());
            return false;
        }
    }
}

