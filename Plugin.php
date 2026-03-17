<?php

/**
 * Redis 缓存插件 - 将 Typecho 内容缓存到 Redis
 *
 * @package RedisCache
 * @author Vex
 * @version 0.1.0
 * @link https://github.com/vndroid/RedisCache
 */
class RedisCache_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 初始化实例
     */
    private static ?Redis $redis = null;

    /**
     * 统一缓存前缀
     */
    private static string $prefix = "typecho_cache:";

    /**
     * 缓存过期时间（默认秒）
     */
    private static int $expire = 3600; // 默认 1 小时

    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     */
    public static function activate()
    {
        // 初始化Redis连接
        Typecho_Plugin::factory("index.php")->begin = [
            "RedisCache_Plugin",
            "initRedis",
        ];

        // 在内容渲染前尝试从缓存获取
        Typecho_Plugin::factory("Widget_Archive")->beforeRender = [
            "RedisCache_Plugin",
            "beforeRender",
        ];

        // 在内容渲染后缓存内容
        Typecho_Plugin::factory("Widget_Archive")->afterRender = [
            "RedisCache_Plugin",
            "afterRender",
        ];

        // 当内容更新时清除缓存
        Typecho_Plugin::factory("Widget_Contents_Post_Edit")->finishPublish = [
            "RedisCache_Plugin",
            "clearCache",
        ];
        Typecho_Plugin::factory("Widget_Contents_Page_Edit")->finishPublish = [
            "RedisCache_Plugin",
            "clearCache",
        ];

        // 当评论更新时清除缓存
        Typecho_Plugin::factory("Widget_Feedback")->finishComment = [
            "RedisCache_Plugin",
            "clearCache",
        ];

        return _t("缓存插件已启用");
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     */
    public static function deactivate()
    {
        Helper::removePanel(1, "RedisCache/manage-cache.php");
        return _t("缓存插件已禁用");
    }

    /**
     * 获取插件配置面板
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $host = new Typecho_Widget_Helper_Form_Element_Text(
            "host",
            null,
            "127.0.0.1",
            _t("Redis 服务地址"),
            _t("输入 Redis 服务主机地址，默认为 127.0.0.1"),
        );
        $form->addInput($host);

        $port = new Typecho_Widget_Helper_Form_Element_Text(
            "port",
            null,
            "6379",
            _t("Redis 服务端口"),
            _t("输入 Redis 服务端口，默认为 6379"),
        );
        $form->addInput($port);

        $password = new Typecho_Widget_Helper_Form_Element_Password(
            "password",
            null,
            "",
            _t("Redis 服务密码"),
            _t("如果 Redis 服务启用了密码，请输入密码，否则留空"),
        );
        $form->addInput($password);

        $expire = new Typecho_Widget_Helper_Form_Element_Text(
            "expire",
            null,
            "3600",
            _t("过期时间（秒）"),
            _t("缓存过期时间，默认为一小时（3600秒）"),
        );
        $form->addInput($expire);

        $prefix = new Typecho_Widget_Helper_Form_Element_Text(
            "prefix",
            null,
            "typecho_cache:",
            _t("缓存前缀"),
            _t("缓存键名的前缀，用于区分不同应用的缓存"),
        );
        $form->addInput($prefix);

        $prefix = new Typecho_Widget_Helper_Form_Element_Text(
            "uriPrefix",
            null,
            "/",
            _t("路径前缀"),
            _t("按路径前缀进行缓存，防止缓存不需要的页面，多个前缀请用英文逗号分隔"),
        );

        $form->addInput($prefix);

        $enableCache = new Typecho_Widget_Helper_Form_Element_Radio(
            "enableCache",
            ["1" => _t("启用"), "0" => _t("禁用")],
            "1",
            _t("启用缓存"),
            _t("是否启用 Redis 缓存功能"),
        );
        $form->addInput($enableCache);

        $debug = new Typecho_Widget_Helper_Form_Element_Radio(
            "debug",
            ["1" => _t("启用"), "0" => _t("禁用")],
            "1",
            _t("调试模式"),
            _t("启用后会记录更详细的日志信息"),
        );
        $form->addInput($debug);
    }

    /**
     * 个人用户的配置面板
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
    }

    /**
     * 初始化Redis连接
     */
    public static function initRedis(): ?Redis
    {
        if (self::$redis !== null) {
            return self::$redis;
        }

        $options = Helper::options();
        $config = $options->plugin("RedisCache");

        // 如果禁用缓存，直接返回
        if (isset($config->enableCache) && $config->enableCache == "0") {
            return null;
        }

        // 设置缓存参数
        if (isset($config->expire)) {
            self::$expire = intval($config->expire);
        }

        if (isset($config->prefix)) {
            self::$prefix = $config->prefix;
        }

        // 创建日志目录
        $logDir = __DIR__ . "/logs";
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $logFile = $logDir . "/redis-" . date("Y-m-d") . ".log";

        try {
            // 检查Redis扩展是否加载
            if (!extension_loaded("redis")) {
                throw new Exception("PHP Redis 扩展未安装");
            }

            // 尝试连接Redis
            $redis = new Redis();
            $connected = $redis->connect($config->host, $config->port, 3);

            if (!$connected) {
                throw new Exception("无法连接到 Redis 服务");
            }

            // 如果设置了密码，进行验证
            if (!empty($config->password)) {
                $authResult = $redis->auth($config->password);
                if (!$authResult) {
                    throw new Exception("Redis 服务认证失败");
                }
            }

            // 检查连接
            $pong = $redis->ping();
            if ($pong !== "+PONG" && $pong !== true) {
                throw new Exception("Redis 服务 PING 失败");
            }

            $logMessage =
                date("[Y-m-d H:i:s]") .
                " redis connect successful: " .
                $config->host .
                ":" .
                $config->port;

            // 写入测试数据
            $testKey = self::$prefix . "test";
            $testValue = "Hello Typecho! " . date("Y-m-d H:i:s");
            $redis->set($testKey, $testValue);
            $retrievedValue = $redis->get($testKey);

            if ($retrievedValue !== $testValue) {
                throw new Exception("缓存测试数据写入失败");
            }

            $logMessage .=
                "\n" .
                date("[Y-m-d H:i:s]") .
                " redis writable-test successful: " .
                $retrievedValue;

            // 删除测试数据
            $redis->del($testKey);

            // 探测 RedisJSON 支持情况并写入日志（不影响主流程）
            try {
                $json = self::detectRedisJsonSupport($redis);
                $logMessage .=
                    "\n" .
                    date("[Y-m-d H:i:s]") .
                    " redis json support: " .
                    ($json['supported'] ? 'YES' : 'NO') .
                    " via=" .
                    ($json['via'] ?? '-') .
                    (empty($json['module']) ? '' : " module=" . $json['module']) .
                    (empty($json['version']) ? '' : " ver=" . $json['version']) .
                    (empty($json['reason']) ? '' : " reason=" . $json['reason']);
            } catch (Throwable $e) {
                $logMessage .=
                    "\n" .
                    date("[Y-m-d H:i:s]") .
                    " redis json support: UNKNOWN reason=" .
                    $e->getMessage();
            }

            // 写入日志
            file_put_contents($logFile, $logMessage . "\n", FILE_APPEND);

            self::$redis = $redis;
            return $redis;
        } catch (Exception $e) {
            // 连接失败记录日志，但不影响系统运行
            $errorMessage =
                date("[Y-m-d H:i:s]") . " redis connect failed: " . $e->getMessage();
            file_put_contents($logFile, $errorMessage . "\n", FILE_APPEND);
            return null;
        }
    }

    /**
     * 探测当前 Redis 实例是否支持 RedisJSON（或老版本 ReJSON）
     *
     * 返回结构：
     * - supported: bool 是否支持 JSON 命令
     * - via: string 使用的探测方式（module_list/command_info/info_modules/error）
     * - module: ?string 命中的模块名（ReJSON/RedisJSON），若有
     * - version: ?string 模块版本（如果可获取）
     * - reason: ?string 不支持/失败原因（如果有）
     */
    private static function detectRedisJsonSupport(Redis $redis): array
    {
        $result = [
            'supported' => false,
            'via' => null,
            'module' => null,
            'version' => null,
            'reason' => null,
        ];

        // 1) 优先尝试 MODULE LIST（需要权限，且部分代理/云服务可能禁用）
        try {
            if (method_exists($redis, 'rawCommand')) {
                $modules = $redis->rawCommand('MODULE', 'LIST');

                // phpredis 可能返回：
                // - array of arrays: [ [ 'name','ReJSON','ver',20000,...], ... ]
                // - array of associative arrays (取决于版本/redis reply)
                if (is_array($modules)) {
                    foreach ($modules as $moduleInfo) {
                        if (!is_array($moduleInfo)) {
                            continue;
                        }

                        $name = null;
                        $ver = null;

                        // 尝试按键值对解析
                        if (isset($moduleInfo['name'])) {
                            $name = (string) $moduleInfo['name'];
                        }
                        if (isset($moduleInfo['ver'])) {
                            $ver = (string) $moduleInfo['ver'];
                        }

                        // 尝试按 [key,val,key,val] 解析
                        if ($name === null) {
                            for ($i = 0; $i + 1 < count($moduleInfo); $i += 2) {
                                $k = $moduleInfo[$i] ?? null;
                                $v = $moduleInfo[$i + 1] ?? null;
                                if ($k === 'name') {
                                    $name = is_string($v) ? $v : (string) $v;
                                } elseif ($k === 'ver') {
                                    $ver = is_string($v) ? $v : (string) $v;
                                }
                            }
                        }

                        if ($name !== null) {
                            $lower = strtolower($name);
                            if ($lower === 'rejson' || $lower === 'redisjson') {
                                $result['supported'] = true;
                                $result['via'] = 'module_list';
                                $result['module'] = $name;
                                $result['version'] = $ver;
                                return $result;
                            }
                        }
                    }

                    $result['via'] = 'module_list';
                    $result['reason'] = 'module_not_loaded';
                } else {
                    $result['via'] = 'module_list';
                    $result['reason'] = 'unexpected_reply';
                }
            } else {
                $result['via'] = 'module_list';
                $result['reason'] = 'rawCommand_not_available';
            }
        } catch (Throwable $e) {
            // 常见：NOPERM this user has no permissions...
            $result['via'] = 'module_list';
            $result['reason'] = 'module_list_error: ' . $e->getMessage();
        }

        // 2) 降级：COMMAND INFO JSON.GET（只要命令存在即可判断）
        try {
            if (method_exists($redis, 'rawCommand')) {
                $info = $redis->rawCommand('COMMAND', 'INFO', 'JSON.GET');
                // COMMAND INFO 返回：不存在时为 [null] 或空数组（不同版本略有差异）
                if (is_array($info) && count($info) > 0 && $info[0] !== null && $info !== [false]) {
                    $result['supported'] = true;
                    $result['via'] = 'command_info';
                    $result['module'] = $result['module'] ?? 'RedisJSON';
                    return $result;
                }

                if ($result['via'] === null) {
                    $result['via'] = 'command_info';
                    $result['reason'] = 'command_not_found';
                }
            }
        } catch (Throwable $e) {
            if ($result['via'] === null || $result['via'] === 'module_list') {
                $result['via'] = 'command_info';
                $result['reason'] = 'command_info_error: ' . $e->getMessage();
            }
        }

        // 3) 再降级：INFO MODULES（Redis >= 6.0 通常可用，但也可能被禁用）
        try {
            // phpredis 支持 info()，参数可能是字符串 section
            $infoStr = $redis->info('modules');
            if (is_array($infoStr)) {
                // phpredis 有时会把 INFO 转成数组；modules 可能以字符串形式出现在某些键里
                $flat = json_encode($infoStr);
                if (is_string($flat)) {
                    $lower = strtolower($flat);
                    if (str_contains($lower, 'rejson') || str_contains($lower, 'redisjson')) {
                        $result['supported'] = true;
                        $result['via'] = 'info_modules';
                        $result['module'] = $result['module'] ?? 'RedisJSON';
                        return $result;
                    }
                }

                $result['via'] = $result['via'] ?? 'info_modules';
                $result['reason'] = $result['reason'] ?? 'module_not_found_in_info';
            }
        } catch (Throwable $e) {
            $result['via'] = $result['via'] ?? 'info_modules';
            $result['reason'] = $result['reason'] ?? ('info_modules_error: ' . $e->getMessage());
        }

        $result['via'] = $result['via'] ?? 'error';
        $result['reason'] = $result['reason'] ?? 'unknown';
        return $result;
    }

    /**
     * 在渲染前检查缓存是否存在
     *
     * @param Widget_Archive $archive
     * @return void
     */
    public static function beforeRender($archive): void
    {
        // 管理员登录时不使用缓存
        if (Typecho_Widget::widget("Widget_User")->hasLogin()) {
            return;
        }

        // 初始化Redis
        $redis = self::initRedis();
        if (!$redis) {
            return;
        }

        // 获取当前请求的唯一标识（去掉查询参数）
        $fullUri = $_SERVER["REQUEST_URI"];
        $requestUri = parse_url($fullUri, PHP_URL_PATH) ?: '/';
        $cacheKey = self::$prefix . "page:" . md5($requestUri);

        // 尝试从缓存获取内容
        $cachedContent = $redis->get($cacheKey);

        if ($cachedContent !== false) {
            // 缓存命中，输出内容并结束执行
            $options = Helper::options();
            $config = $options->plugin("RedisCache");

            if (isset($config->debug) && $config->debug == "1") {
                $logFile = __DIR__ . "/logs/cache-" . date("Y-m-d") . ".log";
                $logMessage = date("[Y-m-d H:i:s]") . " CACHE: (HIT)  " . " KEY: (" . $cacheKey . ") URI: (" . $requestUri . ")";
                file_put_contents($logFile, $logMessage . "\n", FILE_APPEND);
            }

            // 为页面添加缓存命中标记
            $cachedContent .=
                "\n<!-- Powered by Redis, TIME: " .
                date("Y-m-d H:i:s", time() - $redis->ttl($cacheKey)) .
                ", TTL: " .
                $redis->ttl($cacheKey) .
                "s -->";

            echo $cachedContent;
            exit();
        }

        // 缓存未命中，开始输出缓冲
        ob_start();
    }

    /**
     * 在渲染后保存缓存
     *
     * @return void
     */
    public static function afterRender(): void
    {
        // 管理员登录时不缓存
        if (Typecho_Widget::widget("Widget_User")->hasLogin()) {
            return;
        }

        // 初始化Redis
        $redis = self::initRedis();
        if (!$redis) {
            return;
        }

        // 获取输出内容
        $content = ob_get_contents();

        // 获取当前请求的唯一标识（去掉查询参数）
        $fullUri = $_SERVER["REQUEST_URI"];
        $requestUri = parse_url($fullUri, PHP_URL_PATH) ?: '/';

        // URI 筛选：只缓存符合条件的页面
        // 只缓存以 / 开头，且等于 / 或以 /archives、/page 开头的 URI
        if (!($requestUri === '/' ||
              strpos($requestUri, '/archives') === 0 ||
              strpos($requestUri, '/page') === 0)) {
            return;
        }

        $cacheKey = self::$prefix . "page:" . md5($requestUri);

        // 将内容写入缓存
        $redis->setex($cacheKey, self::$expire, $content);

        $options = Helper::options();
        $config = $options->plugin("RedisCache");

        if (isset($config->debug) && $config->debug == "1") {
            $logFile = __DIR__ . "/logs/cache-" . date("Y-m-d") . ".log";
            $logMessage = date("[Y-m-d H:i:s]") . " CACHE: (MISS) " . " KEY: (" . $cacheKey . ") URI: (" . $requestUri . ")";
            file_put_contents($logFile, $logMessage . "\n", FILE_APPEND);
        }
    }

    /**
     * 清除缓存
     *
     * @param mixed $content 内容
     * @param mixed $widget 组件
     * @return mixed
     */
    public static function clearCache($content, $widget): mixed
    {
        // 初始化Redis
        $redis = self::initRedis();
        if (!$redis) {
            return $content;
        }

        // 获取所有缓存键
        $pattern = self::$prefix . "page:*";
        $keys = $redis->keys($pattern);

        // 删除所有匹配的缓存
        if (!empty($keys)) {
            $redis->del($keys);

            $options = Helper::options();
            $config = $options->plugin("RedisCache");

            if (isset($config->debug) && $config->debug == "1") {
                $logFile =
                    __DIR__ .
                    "/logs/cache-" .
                    date("Y-m-d") .
                    ".log";
                $logMessage =
                    date("[Y-m-d H:i:s]") .
                    " Cache Cleanup: " .
                    count($keys) .
                    " pages";
                file_put_contents($logFile, $logMessage . "\n", FILE_APPEND);
            }
        }

        return $content;
    }

    /**
     * 获取缓存前缀
     *
     * @return string
     */
    public static function getPrefix(): string
    {
        return self::$prefix;
    }
}
