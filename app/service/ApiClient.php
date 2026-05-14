<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Cache;
use think\facade\Log;

/**
 * 后端 BeautsGO API 客户端
 *
 * 设计目标：
 *   - 单接口请求带可选缓存
 *   - multiGet 并发批量请求 —— Controller 数据聚合的核心入口
 *   - 失败时记录日志但不抛异常，由调用方决定降级策略
 *
 * 注意：所有页面级 Controller 必须用 multiGet 一次拉齐所有依赖接口，
 *      禁止在循环中、或顺序串行调用单个 GET。
 */
class ApiClient
{
    /** @var string */
    private $baseUrl;
    /** @var int */
    private $timeout;
    /** @var int */
    private $connectTimeout;
    /** @var array */
    private $defaultHeaders;

    public function __construct()
    {
        $this->baseUrl        = rtrim((string) config('api.base_url'), '/');
        $this->timeout        = (int) config('api.timeout', 5);
        $this->connectTimeout = (int) config('api.connect_timeout', 2);
        $this->defaultHeaders = [
            'Accept: application/json',
            'X-Client: beautsgo-ssr',
            'User-Agent: BeautsGO-SSR/1.0',
            // 后端 BaseApi 用 source-type header 判断登录源(utils/base.js fromSource() 浏览器返回 'web')
            'source-type: web',
            'platform: web',
        ];
    }

    /* ============================================================
     *  Public API
     * ============================================================ */

    /**
     * GET 单接口（带缓存）
     *
     * @param string $path     接口路径，例: '/Hospital/detail/123'
     * @param array  $query    URL query 参数
     * @param int    $cacheTtl 缓存秒数，0 表示不缓存
     * @return array           原样返回后端 ['code'=>0,'msg'=>'...','data'=>{...}]
     */
    public function get(string $path, array $query = [], int $cacheTtl = 60): array
    {
        $cacheKey = $this->cacheKey('GET', $path, $query);
        if ($cacheTtl > 0 && ($cached = Cache::get($cacheKey)) !== null) {
            return $cached;
        }

        $url = $this->buildUrl($path, $query);
        [$body, $code, $err] = $this->execute($this->buildHandle($url, 'GET'));

        return $this->finalize($body, $code, $err, $url, $cacheKey, $cacheTtl);
    }

    /**
     * POST 单接口（默认 form-urlencoded，可切换 JSON）
     */
    public function post(string $path, array $body = [], bool $asJson = false, int $cacheTtl = 0): array
    {
        $cacheKey = $this->cacheKey('POST', $path, $body);
        if ($cacheTtl > 0 && ($cached = Cache::get($cacheKey)) !== null) {
            return $cached;
        }

        $url = $this->buildUrl($path);
        [$res, $code, $err] = $this->execute($this->buildHandle($url, 'POST', $body, $asJson));
        return $this->finalize($res, $code, $err, $url, $cacheKey, $cacheTtl);
    }

    /**
     * 并发批量 GET —— 数据聚合主力接口
     *
     * @param array $requests
     *   ['key' => [path, query?, cacheTtlOverride?], ...]
     *   例：[
     *     'hospital' => ['/Hospital/detail/123'],
     *     'cases'    => ['/Compared/caseList/1/123'],
     *     'doctors'  => ['/Hospital/123/doctors', ['limit' => 10], 1200],
     *   ]
     * @param int $cacheTtl 默认 TTL，单条可通过元素第三位覆盖
     * @return array        ['key' => apiResponse, ...]
     */
    public function multiGet(array $requests, int $cacheTtl = 60): array
    {
        $mh      = curl_multi_init();
        $handles = [];
        $results = [];

        foreach ($requests as $key => $req) {
            $path     = (string) ($req[0] ?? '');
            $query    = (array)  ($req[1] ?? []);
            $thisTtl  = (int)    ($req[2] ?? $cacheTtl);
            if ($path === '') continue;

            $cacheKey = $this->cacheKey('GET', $path, $query);
            if ($thisTtl > 0 && ($cached = Cache::get($cacheKey)) !== null) {
                $results[$key] = $cached;
                continue;
            }

            $url = $this->buildUrl($path, $query);
            $ch  = $this->buildHandle($url, 'GET');
            curl_multi_add_handle($mh, $ch);
            $handles[$key] = [
                'handle' => $ch,
                'cache'  => $cacheKey,
                'url'    => $url,
                'ttl'    => $thisTtl,
            ];
        }

        if (!empty($handles)) {
            $running = null;
            do {
                $status = curl_multi_exec($mh, $running);
                if ($running) {
                    curl_multi_select($mh, 0.05);
                }
            } while ($running > 0 && $status === CURLM_OK);

            foreach ($handles as $key => $info) {
                $body = curl_multi_getcontent($info['handle']);
                $code = (int) curl_getinfo($info['handle'], CURLINFO_HTTP_CODE);
                $err  = curl_error($info['handle']);
                $results[$key] = $this->finalize(
                    $body, $code, $err, $info['url'], $info['cache'], $info['ttl']
                );
                curl_multi_remove_handle($mh, $info['handle']);
                curl_close($info['handle']);
            }
        }

        curl_multi_close($mh);
        return $results;
    }

    /* ============================================================
     *  Internal
     * ============================================================ */

    private function buildUrl(string $path, array $query = []): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            $url = $path;
        } else {
            $url = $this->baseUrl . '/' . ltrim($path, '/');
        }
        if (!empty($query)) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }
        return $url;
    }

    private function buildHandle(string $url, string $method = 'GET', array $body = [], bool $asJson = false)
    {
        $ch = curl_init($url);
        $headers = $this->defaultHeaders;

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_ENCODING       => 'gzip',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
        ];

        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            if ($asJson) {
                $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
                $headers[] = 'Content-Type: application/json';
            } else {
                $opts[CURLOPT_POSTFIELDS] = http_build_query($body);
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            }
        }

        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        return $ch;
    }

    private function execute($ch): array
    {
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = (string) curl_error($ch);
        curl_close($ch);
        return [$body, $code, $err];
    }

    private function finalize($body, int $code, string $err, string $url, string $cacheKey, int $cacheTtl): array
    {
        if ($err !== '' || $code !== 200 || $body === false) {
            Log::error("API failed url={$url} code={$code} err={$err}");
            return ['code' => -1, 'msg' => $err !== '' ? $err : 'http_' . $code, 'data' => null];
        }

        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            Log::error("API non-json url={$url}");
            return ['code' => -1, 'msg' => 'invalid_json', 'data' => null];
        }

        if ($cacheTtl > 0 && (int) ($data['code'] ?? -1) === 0) {
            Cache::set($cacheKey, $data, $cacheTtl);
        }
        return $data;
    }

    private function cacheKey(string $method, string $path, array $params): string
    {
        return 'api:' . $method . ':' . md5($path . serialize($params));
    }
}
