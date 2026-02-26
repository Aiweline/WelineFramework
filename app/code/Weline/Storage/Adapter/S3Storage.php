<?php

declare(strict_types=1);

namespace Weline\Storage\Adapter;

use Weline\Storage\Api\StorageInterface;

/**
 * @DESC | AWS S3 存储适配器
 */
class S3Storage implements StorageInterface
{
    private string $bucket;
    private string $region;
    private string $prefix;
    private ?object $client = null;
    private array $config;
    
    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->bucket = $config['bucket'] ?? '';
        $this->region = $config['region'] ?? 'us-east-1';
        $this->prefix = \trim($config['prefix'] ?? '', '/');
    }
    
    public function getDriver(): string
    {
        return 's3';
    }
    
    private function getClient(): ?object
    {
        if ($this->client !== null) {
            return $this->client;
        }
        
        if (!\class_exists(\Aws\S3\S3Client::class)) {
            return null;
        }
        
        try {
            $this->client = new \Aws\S3\S3Client([
                'version' => 'latest',
                'region' => $this->region,
                'credentials' => [
                    'key' => $this->config['key'] ?? '',
                    'secret' => $this->config['secret'] ?? '',
                ],
                'endpoint' => $this->config['endpoint'] ?? null,
                'use_path_style_endpoint' => $this->config['use_path_style_endpoint'] ?? false,
            ]);
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
            $params = [
                'Bucket' => $this->bucket,
                'Key' => $this->prefixPath($path),
                'Body' => $contents,
            ];
            
            if (isset($options['content_type'])) {
                $params['ContentType'] = $options['content_type'];
            }
            
            if (isset($options['visibility']) && $options['visibility'] === 'public') {
                $params['ACL'] = 'public-read';
            }
            
            $client->putObject($params);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
    
    public function putStream(string $path, $resource, array $options = []): bool
    {
        return $this->put($path, $resource, $options);
    }
    
    public function get(string $path): ?string
    {
        $client = $this->getClient();
        if ($client === null) {
            return null;
        }
        
        try {
            $result = $client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $this->prefixPath($path),
            ]);
            
            return (string) $result['Body'];
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    public function getStream(string $path)
    {
        $client = $this->getClient();
        if ($client === null) {
            return null;
        }
        
        try {
            $result = $client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $this->prefixPath($path),
            ]);
            
            $body = $result['Body'];
            if ($body instanceof \Psr\Http\Message\StreamInterface) {
                return \GuzzleHttp\Psr7\StreamWrapper::getResource($body);
            }
            
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    public function delete(string $path): bool
    {
        $client = $this->getClient();
        if ($client === null) {
            return false;
        }
        
        try {
            $client->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $this->prefixPath($path),
            ]);
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
                $objects[] = ['Key' => $this->prefixPath($path)];
            }
            
            $client->deleteObjects([
                'Bucket' => $this->bucket,
                'Delete' => ['Objects' => $objects],
            ]);
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
        $client = $this->getClient();
        if ($client === null) {
            return null;
        }
        
        try {
            return $client->getObjectUrl($this->bucket, $this->prefixPath($path));
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    public function temporaryUrl(string $path, int $expiration = 3600): ?string
    {
        $client = $this->getClient();
        if ($client === null) {
            return null;
        }
        
        try {
            $cmd = $client->getCommand('GetObject', [
                'Bucket' => $this->bucket,
                'Key' => $this->prefixPath($path),
            ]);
            
            $request = $client->createPresignedRequest($cmd, "+{$expiration} seconds");
            return (string) $request->getUri();
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
            $result = $client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $this->prefixPath($path),
            ]);
            return (int) ($result['ContentLength'] ?? 0);
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
            $result = $client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $this->prefixPath($path),
            ]);
            
            $lastModified = $result['LastModified'] ?? null;
            if ($lastModified instanceof \DateTimeInterface) {
                return $lastModified->getTimestamp();
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
            $result = $client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $this->prefixPath($path),
            ]);
            return $result['ContentType'] ?? null;
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
            $client->copyObject([
                'Bucket' => $this->bucket,
                'Key' => $this->prefixPath($to),
                'CopySource' => $this->bucket . '/' . $this->prefixPath($from),
            ]);
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
            
            $params = [
                'Bucket' => $this->bucket,
                'Prefix' => $prefix,
            ];
            
            if (!$recursive) {
                $params['Delimiter'] = '/';
            }
            
            $results = [];
            $paginator = $client->getPaginator('ListObjectsV2', $params);
            
            foreach ($paginator as $page) {
                if (!$recursive && isset($page['CommonPrefixes'])) {
                    foreach ($page['CommonPrefixes'] as $prefixInfo) {
                        $path = $prefixInfo['Prefix'];
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
                
                foreach ($page['Contents'] ?? [] as $object) {
                    $path = $object['Key'];
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
                        'size' => (int) ($object['Size'] ?? 0),
                        'last_modified' => $object['LastModified'] instanceof \DateTimeInterface 
                            ? $object['LastModified']->getTimestamp() 
                            : null,
                    ];
                }
            }
            
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
            $client->headBucket(['Bucket' => $this->bucket]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
    
    public function getInfo(): array
    {
        return [
            'driver' => 's3',
            'bucket' => $this->bucket,
            'region' => $this->region,
            'prefix' => $this->prefix,
            'sdk_available' => \class_exists(\Aws\S3\S3Client::class),
        ];
    }
}
