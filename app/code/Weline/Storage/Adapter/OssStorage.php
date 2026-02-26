<?php

declare(strict_types=1);

namespace Weline\Storage\Adapter;

use Weline\Storage\Api\StorageInterface;

/**
 * @DESC | 阿里云 OSS 存储适配器
 */
class OssStorage implements StorageInterface
{
    private string $bucket;
    private string $endpoint;
    private string $prefix;
    private ?object $client = null;
    private array $config;
    
    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->bucket = $config['bucket'] ?? '';
        $this->endpoint = $config['endpoint'] ?? 'oss-cn-hangzhou.aliyuncs.com';
        $this->prefix = \trim($config['prefix'] ?? '', '/');
    }
    
    public function getDriver(): string
    {
        return 'oss';
    }
    
    private function getClient(): ?object
    {
        if ($this->client !== null) {
            return $this->client;
        }
        
        if (!\class_exists(\OSS\OssClient::class)) {
            return null;
        }
        
        try {
            $this->client = new \OSS\OssClient(
                $this->config['access_key_id'] ?? '',
                $this->config['access_key_secret'] ?? '',
                $this->endpoint
            );
        } catch (\Throwable $e) {
            return null;
        }
        
        return $this->client;
    }
    
    private function prefixPath(string $path): string
    {
        $path = \ltrim($path, '/');
        return $this->prefix ? $this->prefix . '/' . $path : $path;
    }
    
    public function put(string $path, $contents, array $options = []): bool
    {
        $client = $this->getClient();
        if ($client === null) {
            return false;
        }
        
        try {
            $ossOptions = [];
            
            if (isset($options['content_type'])) {
                $ossOptions[\OSS\OssClient::OSS_CONTENT_TYPE] = $options['content_type'];
            }
            
            if (\is_resource($contents)) {
                $contents = \stream_get_contents($contents);
            }
            
            $client->putObject($this->bucket, $this->prefixPath($path), $contents, $ossOptions);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
    
    public function putStream(string $path, $resource, array $options = []): bool
    {
        $contents = \stream_get_contents($resource);
        if ($contents === false) {
            return false;
        }
        return $this->put($path, $contents, $options);
    }
    
    public function get(string $path): ?string
    {
        $client = $this->getClient();
        if ($client === null) {
            return null;
        }
        
        try {
            return $client->getObject($this->bucket, $this->prefixPath($path));
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    public function getStream(string $path)
    {
        $contents = $this->get($path);
        if ($contents === null) {
            return null;
        }
        
        $stream = \fopen('php://temp', 'r+b');
        \fwrite($stream, $contents);
        \rewind($stream);
        return $stream;
    }
    
    public function delete(string $path): bool
    {
        $client = $this->getClient();
        if ($client === null) {
            return false;
        }
        
        try {
            $client->deleteObject($this->bucket, $this->prefixPath($path));
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
    
    public function deleteMultiple(array $paths): bool
    {
        $client = $this->getClient();
        if ($client === null) {
            return false;
        }
        
        try {
            $objects = [];
            foreach ($paths as $path) {
                $objects[] = $this->prefixPath($path);
            }
            
            $client->deleteObjects($this->bucket, $objects);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
    
    public function exists(string $path): bool
    {
        $client = $this->getClient();
        if ($client === null) {
            return false;
        }
        
        try {
            return $client->doesObjectExist($this->bucket, $this->prefixPath($path));
        } catch (\Throwable $e) {
            return false;
        }
    }
    
    public function url(string $path): ?string
    {
        $protocol = ($this->config['use_ssl'] ?? true) ? 'https' : 'http';
        return "{$protocol}://{$this->bucket}.{$this->endpoint}/" . $this->prefixPath($path);
    }
    
    public function temporaryUrl(string $path, int $expiration = 3600): ?string
    {
        $client = $this->getClient();
        if ($client === null) {
            return null;
        }
        
        try {
            return $client->signUrl($this->bucket, $this->prefixPath($path), $expiration);
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    public function size(string $path): ?int
    {
        $client = $this->getClient();
        if ($client === null) {
            return null;
        }
        
        try {
            $meta = $client->getObjectMeta($this->bucket, $this->prefixPath($path));
            return isset($meta['content-length']) ? (int) $meta['content-length'] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    public function lastModified(string $path): ?int
    {
        $client = $this->getClient();
        if ($client === null) {
            return null;
        }
        
        try {
            $meta = $client->getObjectMeta($this->bucket, $this->prefixPath($path));
            if (isset($meta['last-modified'])) {
                return \strtotime($meta['last-modified']);
            }
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    public function mimeType(string $path): ?string
    {
        $client = $this->getClient();
        if ($client === null) {
            return null;
        }
        
        try {
            $meta = $client->getObjectMeta($this->bucket, $this->prefixPath($path));
            return $meta['content-type'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    public function copy(string $from, string $to): bool
    {
        $client = $this->getClient();
        if ($client === null) {
            return false;
        }
        
        try {
            $client->copyObject(
                $this->bucket,
                $this->prefixPath($from),
                $this->bucket,
                $this->prefixPath($to)
            );
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
    
    public function move(string $from, string $to): bool
    {
        if (!$this->copy($from, $to)) {
            return false;
        }
        return $this->delete($from);
    }
    
    public function list(string $directory = '', bool $recursive = false): array
    {
        $client = $this->getClient();
        if ($client === null) {
            return [];
        }
        
        try {
            $prefix = $this->prefixPath($directory);
            if ($prefix && !\str_ends_with($prefix, '/')) {
                $prefix .= '/';
            }
            
            $options = [
                'prefix' => $prefix,
                'max-keys' => 1000,
            ];
            
            if (!$recursive) {
                $options['delimiter'] = '/';
            }
            
            $results = [];
            $marker = '';
            
            do {
                if ($marker) {
                    $options['marker'] = $marker;
                }
                
                $listResult = $client->listObjects($this->bucket, $options);
                
                if (!$recursive) {
                    foreach ($listResult->getPrefixList() ?? [] as $prefixInfo) {
                        $path = $prefixInfo->getPrefix();
                        if ($this->prefix && \str_starts_with($path, $this->prefix . '/')) {
                            $path = \substr($path, \strlen($this->prefix) + 1);
                        }
                        $results[] = [
                            'path' => \rtrim($path, '/'),
                            'name' => \basename(\rtrim($path, '/')),
                            'type' => 'directory',
                            'size' => 0,
                            'last_modified' => null,
                        ];
                    }
                }
                
                foreach ($listResult->getObjectList() ?? [] as $object) {
                    $path = $object->getKey();
                    if ($this->prefix && \str_starts_with($path, $this->prefix . '/')) {
                        $path = \substr($path, \strlen($this->prefix) + 1);
                    }
                    
                    if ($path === '' || \str_ends_with($path, '/')) {
                        continue;
                    }
                    
                    $results[] = [
                        'path' => $path,
                        'name' => \basename($path),
                        'type' => 'file',
                        'size' => (int) $object->getSize(),
                        'last_modified' => \strtotime($object->getLastModified()),
                    ];
                }
                
                $marker = $listResult->getNextMarker();
            } while ($listResult->getIsTruncated());
            
            return $results;
        } catch (\Throwable $e) {
            return [];
        }
    }
    
    public function makeDirectory(string $path): bool
    {
        return true;
    }
    
    public function deleteDirectory(string $path): bool
    {
        $items = $this->list($path, true);
        if (empty($items)) {
            return true;
        }
        
        $paths = [];
        foreach ($items as $item) {
            if ($item['type'] === 'file') {
                $paths[] = $item['path'];
            }
        }
        
        return $this->deleteMultiple($paths);
    }
    
    public function testConnection(): bool
    {
        $client = $this->getClient();
        if ($client === null) {
            return false;
        }
        
        try {
            $client->getBucketInfo($this->bucket);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
    
    public function getInfo(): array
    {
        return [
            'driver' => 'oss',
            'bucket' => $this->bucket,
            'endpoint' => $this->endpoint,
            'prefix' => $this->prefix,
            'sdk_available' => \class_exists(\OSS\OssClient::class),
        ];
    }
}
