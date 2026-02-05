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
    public static function initRedis()
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
     * 在渲染前检查缓存是否存在
     *
     * @param Widget_Archive $archive
     * @return void
     */
    public static function beforeRender($archive)
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

        // 获取当前请求的唯一标识
        $requestUri = $_SERVER["REQUEST_URI"];
        $cacheKey = self::$prefix . "page:" . md5($requestUri);

        // 尝试从缓存获取内容
        $cachedContent = $redis->get($cacheKey);

        if ($cachedContent !== false) {
            // 缓存命中，输出内容并结束执行
            $options = Helper::options();
            $config = $options->plugin("RedisCache");

            if (isset($config->debug) && $config->debug == "1") {
                $logFile = __DIR__ . "/logs/cache-" . date("Y-m-d") . ".log";
                $logMessage = date("[Y-m-d H:i:s]") . " HIT: " . $requestUri . " (Key: " . $cacheKey . ")";
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
    public static function afterRender()
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

        // 获取当前请求的唯一标识
        $requestUri = $_SERVER["REQUEST_URI"];
        $cacheKey = self::$prefix . "page:" . md5($requestUri);

        // 将内容写入缓存
        $redis->setex($cacheKey, self::$expire, $content);

        $options = Helper::options();
        $config = $options->plugin("RedisCache");

        if (isset($config->debug) && $config->debug == "1") {
            $logFile = __DIR__ . "/logs/cache-" . date("Y-m-d") . ".log";
            $logMessage = date("[Y-m-d H:i:s]") . " MISS: " . " KEY: (" . $cacheKey . ") URI: (" . $requestUri . ")";
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
