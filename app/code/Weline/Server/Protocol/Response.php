<?php
declare(strict_types=1);

/**
 * Weline Server - HTTP Response
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Protocol;

/**
 * Response - HTTP 响应对象
 */
class Response
{
    /**
     * HTTP 状态码
     */
    protected int $statusCode = 200;
    
    /**
     * 响应头
     */
    protected array $headers = [];
    
    /**
     * 响应体
     */
    protected string $body = '';
    
    /**
     * HTTP 状态码对应的描述
     */
    protected static array $statusPhrases = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        207 => 'Multi-Status',
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Payload Too Large',
        414 => 'URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Range Not Satisfiable',
        417 => 'Expectation Failed',
        418 => 'I\'m a teapot',
        421 => 'Misdirected Request',
        422 => 'Unprocessable Entity',
        423 => 'Locked',
        424 => 'Failed Dependency',
        425 => 'Too Early',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        451 => 'Unavailable For Legal Reasons',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
        506 => 'Variant Also Negotiates',
        507 => 'Insufficient Storage',
        508 => 'Loop Detected',
        510 => 'Not Extended',
        511 => 'Network Authentication Required',
    ];
    
    /**
     * 构造函数
     * 
     * @param int $statusCode 状态码
     * @param array $headers 响应头
     * @param string $body 响应体
     */
    public function __construct(int $statusCode = 200, array $headers = [], string $body = '')
    {
        $this->statusCode = $statusCode;
        $this->headers = $headers;
        $this->body = $body;
    }
    
    /**
     * 设置状态码
     */
    public function withStatus(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }
    
    /**
     * 设置响应头
     */
    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }
    
    /**
     * 添加响应头（不覆盖）
     */
    public function withAddedHeader(string $name, string $value): self
    {
        if (isset($this->headers[$name])) {
            if (is_array($this->headers[$name])) {
                $this->headers[$name][] = $value;
            } else {
                $this->headers[$name] = [$this->headers[$name], $value];
            }
        } else {
            $this->headers[$name] = $value;
        }
        
        return $this;
    }
    
    /**
     * 批量设置响应头
     */
    public function withHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->headers[$name] = $value;
        }
        
        return $this;
    }
    
    /**
     * 设置响应体
     */
    public function withBody(string $body): self
    {
        $this->body = $body;
        return $this;
    }
    
    /**
     * 获取状态码
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
    
    /**
     * 获取响应头
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }
    
    /**
     * 获取响应体
     */
    public function getBody(): string
    {
        return $this->body;
    }
    
    /**
     * 设置 Cookie
     */
    public function withCookie(
        string $name,
        string $value = '',
        int $maxAge = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httpOnly = true,
        string $sameSite = 'Lax'
    ): self {
        $cookie = urlencode($name) . '=' . urlencode($value);
        
        if ($maxAge > 0) {
            $cookie .= '; Max-Age=' . $maxAge;
            $cookie .= '; Expires=' . gmdate('D, d M Y H:i:s', time() + $maxAge) . ' GMT';
        }
        
        if ($path) {
            $cookie .= '; Path=' . $path;
        }
        
        if ($domain) {
            $cookie .= '; Domain=' . $domain;
        }
        
        if ($secure) {
            $cookie .= '; Secure';
        }
        
        if ($httpOnly) {
            $cookie .= '; HttpOnly';
        }
        
        if ($sameSite) {
            $cookie .= '; SameSite=' . $sameSite;
        }
        
        return $this->withAddedHeader('Set-Cookie', $cookie);
    }
    
    /**
     * 重定向
     */
    public static function redirect(string $url, int $code = 302): self
    {
        return new self($code, ['Location' => $url], '');
    }
    
    /**
     * JSON 响应
     */
    public static function json(mixed $data, int $code = 200): self
    {
        return new self(
            $code,
            ['Content-Type' => 'application/json; charset=utf-8'],
            json_encode($data, JSON_UNESCAPED_UNICODE)
        );
    }
    
    /**
     * HTML 响应
     */
    public static function html(string $html, int $code = 200): self
    {
        return new self(
            $code,
            ['Content-Type' => 'text/html; charset=utf-8'],
            $html
        );
    }
    
    /**
     * 文本响应
     */
    public static function text(string $text, int $code = 200): self
    {
        return new self(
            $code,
            ['Content-Type' => 'text/plain; charset=utf-8'],
            $text
        );
    }
    
    /**
     * 文件下载响应
     */
    public static function file(string $filePath, ?string $filename = null): self
    {
        if (!file_exists($filePath)) {
            return new self(404, [], 'File not found');
        }
        
        $filename = $filename ?: basename($filePath);
        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';
        $content = file_get_contents($filePath);
        
        return new self(
            200,
            [
                'Content-Type' => $mimeType,
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Content-Length' => (string) strlen($content),
            ],
            $content
        );
    }
    
    /**
     * 转换为字符串
     */
    public function __toString(): string
    {
        // 状态行
        $statusPhrase = self::$statusPhrases[$this->statusCode] ?? 'Unknown';
        $response = "HTTP/1.1 {$this->statusCode} {$statusPhrase}\r\n";
        
        // 默认头
        $headers = $this->headers;
        
        if (!isset($headers['Content-Type'])) {
            $headers['Content-Type'] = 'text/html; charset=utf-8';
        }
        
        if (!isset($headers['Content-Length'])) {
            $headers['Content-Length'] = strlen($this->body);
        }
        
        if (!isset($headers['Connection'])) {
            $headers['Connection'] = 'keep-alive';
        }
        
        if (!isset($headers['Server'])) {
            $headers['Server'] = 'Weline Server';
        }
        
        if (!isset($headers['Date'])) {
            $headers['Date'] = gmdate('D, d M Y H:i:s') . ' GMT';
        }
        
        // 响应头
        foreach ($headers as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $v) {
                    $response .= "{$name}: {$v}\r\n";
                }
            } else {
                $response .= "{$name}: {$value}\r\n";
            }
        }
        
        // 空行和响应体
        $response .= "\r\n" . $this->body;
        
        return $response;
    }
}
