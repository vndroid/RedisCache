<?php

namespace TypechoPlugin\RedisCache;

use Redis;
use Throwable;
use Typecho\Plugin\Exception;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Password;
use Typecho\Widget\Helper\Form\Element\Radio;
use Utils\Helper;
use Widget\Archive;
use Widget\User;
use Widget\Contents\Post\Edit as PostEdit;
use Widget\Contents\Page\Edit as PageEdit;
use Widget\Feedback;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Redis 缓存提速插件 for Typecho
 *
 * @package RedisCache
 * @author Vex
 * @version 0.1.0
 * @link https://github.com/vndroid/RedisCache
 */
class Plugin implements PluginInterface
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
     *
     * @return string
     * @throws Exception
     */
    public static function activate(): string
    {
        // 检查 PHP 扩展
        if (!extension_loaded('redis')) {
            throw new Exception('需要 PHP redis 扩展');
        }

        // 在内容渲染前尝试从缓存获取
        Archive::pluginHandle()->beforeRender = [self::class, 'beforeRender'];

        // 在内容渲染后缓存内容
        Archive::pluginHandle()->afterRender = [self::class, 'afterRender'];

        // 当文章更新时清除缓存
        PostEdit::pluginHandle()->finishPublish = [self::class, 'clearCacheOnPublish'];

        // 当页面更新时清除缓存
        PageEdit::pluginHandle()->finishPublish = [self::class, 'clearCacheOnPublish'];

        // 当评论提交时清除缓存
        Feedback::pluginHandle()->finishComment = [self::class, 'clearCacheOnComment'];

        // 在后台页面底部注入配置页联动 JS
        \Typecho\Plugin::factory('admin/footer.php')->begin = [self::class, 'injectFooterJs'];

        return _t('缓存插件已启用，请正确配置缓存连接方式');
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     * @throws Exception
     */
    public static function deactivate(): void
    {
        // 获取配置，检查禁用时是否需要清理缓存
        $config = Helper::options()->plugin('RedisCache');
        $shouldCleanCache = !isset($config->cleanCacheOnDeactivate) || $config->cleanCacheOnDeactivate == '1';

        if ($shouldCleanCache) {
            try {
                $redis = self::initRedis();
                if ($redis) {
                    $keys = $redis->keys(self::$prefix . '*');
                    if (!empty($keys)) {
                        $redis->del($keys);
                    }
                }
            } catch (Throwable $e) {
                // 清缓存失败不影响禁用流程，静默处理
            }
        }
    }

    /**
     * 获取插件配置面板
     *
     * @param Form $form 配置面板
     */
    public static function config(Form $form): void
    {
        $enableCache = new Radio(
            'enableCache',
            ['1' => _t('启用'), '0' => _t('禁用')],
            '1',
            _t('启用缓存'),
            _t('是否启用 Redis 缓存功能')
        );
        $form->addInput($enableCache);

        $host = new Text(
            'host',
            null,
            'redis',
            _t('Redis 服务地址'),
            _t('输入 Redis 服务主机地址，默认为 127.0.0.1')
        );
        $form->addInput($host);

        $port = new Text(
            'port',
            null,
            '6379',
            _t('Redis 服务端口'),
            _t('输入 Redis 服务端口，默认为 6379')
        );
        $form->addInput($port);

        $enableAuth = new Radio(
            'enableAuth',
            ['1' => _t('启用'), '0' => _t('禁用')],
            '0',
            _t('启用认证'),
            _t('是否启用 Redis 认证')
        );
        $form->addInput($enableAuth);

        $password = new Password(
            'password',
            null,
            '',
            _t('Redis 服务密码'),
            _t('如果 Redis 服务启用了密码，请输入密码，否则留空')
        );
        $form->addInput($password);

        $expire = new Text(
            'expire',
            null,
            '3600',
            _t('过期时间（秒）'),
            _t('缓存过期时间，默认为一小时（3600秒）')
        );
        $form->addInput($expire);

        $prefix = new Text(
            'prefix',
            null,
            'typecho_cache:',
            _t('缓存前缀'),
            _t('缓存键名的前缀，用于区分不同应用的缓存')
        );
        $form->addInput($prefix);

        $uriPrefix = new Text(
            'uriPrefix',
            null,
            '/',
            _t('路径前缀'),
            _t('按路径前缀进行缓存，防止缓存不需要的页面，多个前缀请用英文逗号分隔')
        );
        $form->addInput($uriPrefix);

        $debug = new Radio(
            'debug',
            ['1' => _t('启用'), '0' => _t('禁用')],
            '0',
            _t('调试模式'),
            _t('启用后会记录更详细的日志信息')
        );
        $form->addInput($debug);

        $cleanCacheOnDeactivate = new Radio(
            'cleanCacheOnDeactivate',
            ['1' => _t('清理'), '0' => _t('保留')],
            '1',
            _t('禁用时清理缓存'),
            _t('禁用插件时是否清理 Redis 中的所有缓存数据，默认清理')
        );
        $form->addInput($cleanCacheOnDeactivate);
    }

    /**
     * 个人用户的配置面板
     *
     * @param Form $form
     */
    public static function personalConfig(Form $form): void
    {
    }

    /**
     * 在后台页脚注入 JS（jQuery 已加载），仅在插件配置页生效
     * 实现 enableAuth 切换时联动显示/隐藏 password 行
     */
    public static function injectFooterJs(): void
    {
        // 仅在本插件配置页注入：先确认是插件配置页，再确认是 RedisCache
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        if (!str_contains($requestUri, 'options-plugin.php') || ($_GET['config'] ?? '') !== 'RedisCache') {
            return;
        }

        $jsFile = __DIR__ . '/assets/admin-config.js';
        $jsContent = file_get_contents($jsFile);
        if ($jsContent !== false) {
            echo '<script>' . $jsContent . '</script>';
        }
    }

    /**
     * 初始化Redis连接
     *
     * @return Redis|null
     * @throws Exception
     */
    public static function initRedis(): ?Redis
    {
        if (self::$redis !== null) {
            return self::$redis;
        }

        $config = Helper::options()->plugin('RedisCache');

        // 如果禁用缓存，直接返回
        if (isset($config->enableCache) && $config->enableCache == '0') {
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
        $logDir = __DIR__ . '/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $logFile = $logDir . '/redis-' . date('Y-m-d') . '.log';

        try {
            // 检查Redis扩展是否加载
            if (!extension_loaded('redis')) {
                throw new \Exception('PHP Redis 扩展未安装');
            }

            // 尝试连接Redis
            $redis = new Redis();
            $connected = $redis->connect($config->host, intval($config->port), 3);

            if (!$connected) {
                throw new \Exception('无法连接到 Redis 服务');
            }

            // 如果设置了密码，进行验证
            if (!empty($config->password)) {
                $authResult = $redis->auth($config->password);
                if (!$authResult) {
                    throw new \Exception('Redis 服务认证失败');
                }
            }

            // 检查连接
            $pong = $redis->ping();
            if ($pong !== '+PONG' && $pong !== true) {
                throw new \Exception('Redis 服务 PING 失败');
            }

            $logMessage = date('[Y-m-d H:i:s]') . ' redis connect successful: ' . $config->host . ':' . $config->port;

            // 写入测试数据
            $testKey = self::$prefix . 'test';
            $testValue = 'Hello Typecho! ' . date('Y-m-d H:i:s');
            $redis->set($testKey, $testValue);
            $retrievedValue = $redis->get($testKey);

            if ($retrievedValue !== $testValue) {
                throw new \Exception('缓存测试数据写入失败');
            }

            $logMessage .= "\n" . date('[Y-m-d H:i:s]') . ' redis writable-test successful: ' . $retrievedValue;

            // 删除测试数据
            $redis->del($testKey);

            // 探测 RedisJSON 支持情况并写入日志（不影响主流程）
            try {
                $json = self::detectRedisJsonSupport($redis);
                $logMessage .= "\n" . date('[Y-m-d H:i:s]') . ' redis json support: ' .
                    ($json['supported'] ? 'YES' : 'NO') .
                    ' via=' . ($json['via'] ?? '-') .
                    (empty($json['module']) ? '' : ' module=' . $json['module']) .
                    (empty($json['version']) ? '' : ' ver=' . $json['version']) .
                    (empty($json['reason']) ? '' : ' reason=' . $json['reason']);
            } catch (Throwable $e) {
                $logMessage .= "\n" . date('[Y-m-d H:i:s]') . ' redis json support: UNKNOWN reason=' . $e->getMessage();
            }

            file_put_contents($logFile, $logMessage . "\n", FILE_APPEND);

            self::$redis = $redis;
            return $redis;
        } catch (\Exception $e) {
            $errorMessage = date('[Y-m-d H:i:s]') . ' redis connect failed: ' . $e->getMessage();
            file_put_contents($logFile, $errorMessage . "\n", FILE_APPEND);
            return null;
        }
    }

    /**
     * 探测当前 Redis 实例是否支持 RedisJSON（或老版本 ReJSON）
     * 仅支持 Redis 8.0+
     *
     * 返回结构：
     * - supported: bool 是否支持 JSON 命令
     * - via: string 使用的探测方式（module_list/command_info/error）
     * - module: ?string 命中的模块名（RedisJSON/ReJSON），若有
     * - version: ?string 模块版本
     * - reason: ?string 不支持/失败原因
     *
     * @param Redis $redis
     * @return array{supported: bool, via: string, module: ?string, version: ?string, reason: ?string}
     */
    private static function detectRedisJsonSupport(Redis $redis): array
    {
        $result = [
            'supported' => false,
            'via'       => null,
            'module'    => null,
            'version'   => null,
            'reason'    => null,
        ];

        // 1) 主推荐：MODULE LIST（Redis 8.0+ 标准方式）
        try {
            if (!method_exists($redis, 'rawCommand')) {
                $result['via']    = 'module_list';
                $result['reason'] = 'rawCommand_not_available';
            } else {
                $modules = $redis->rawCommand('MODULE', 'LIST');

                if (!is_array($modules)) {
                    $result['via']    = 'module_list';
                    $result['reason'] = 'unexpected_reply';
                } else {
                    foreach ($modules as $moduleInfo) {
                        if (!is_array($moduleInfo)) {
                            continue;
                        }

                        // Redis 8.0+ 返回关联数组格式 ['name' => '...', 'ver' => '...', ...]
                        $name = (string) ($moduleInfo['name'] ?? '');
                        $version = (string) ($moduleInfo['ver'] ?? '');

                        // 检查是否为 JSON 模块
                        if (!empty($name) && (strtolower($name) === 'rejson' || strtolower($name) === 'redisjson')) {
                            return [
                                'supported' => true,
                                'via'       => 'module_list',
                                'module'    => $name,
                                'version'   => $version ?: null,
                                'reason'    => null,
                            ];
                        }
                    }

                    $result['via']    = 'module_list';
                    $result['reason'] = 'module_not_loaded';
                }
            }
        } catch (Throwable $e) {
            $result['via']    = 'module_list';
            $result['reason'] = 'module_list_error: ' . $e->getMessage();
        }

        // 2) 备选：COMMAND INFO JSON.GET（快速备选方案）
        try {
            if (!method_exists($redis, 'rawCommand')) {
                // 如果 rawCommand 不可用，直接返回失败
                $result['via']    ??= 'error';
                $result['reason'] ??= 'rawCommand_unavailable';
                return $result;
            }

            $info = $redis->rawCommand('COMMAND', 'INFO', 'JSON.GET');
            if (is_array($info) && count($info) > 0 && ($info[0] ?? null) !== null && $info !== [false]) {
                return [
                    'supported' => true,
                    'via'       => 'command_info',
                    'module'    => 'RedisJSON',
                    'version'   => null,
                    'reason'    => null,
                ];
            }

            // COMMAND INFO 失败，保留之前的失败原因
            $result['via']    ??= 'command_info';
            $result['reason'] ??= 'command_not_found';
        } catch (Throwable $e) {
            $result['via']    ??= 'command_info';
            $result['reason'] ??= 'command_info_error: ' . $e->getMessage();
        }

        $result['via']    ??= 'error';
        $result['reason'] ??= 'unknown';
        return $result;
    }

    /**
     * 记录是否已开启输出缓冲
     */
    private static bool $obStarted = false;

    /**
     * 在渲染前检查缓存是否存在
     *
     * @param Archive $archive
     * @return void
     * @throws \Typecho\Db\Exception
     * @throws Exception
     */
    public static function beforeRender(Archive $archive): void
    {
        // 管理员登录时不使用缓存
        if (User::alloc()->hasLogin()) {
            return;
        }

        $redis = self::initRedis();
        if (!$redis) {
            return;
        }

        $requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $cacheKey   = self::$prefix . 'page:' . md5($requestUri);

        $cachedContent = $redis->get($cacheKey);

        if ($cachedContent !== false) {
            $config = Helper::options()->plugin('RedisCache');

            if (isset($config->debug) && $config->debug == '1') {
                $logFile    = __DIR__ . '/logs/cache-' . date('Y-m-d') . '.log';
                $logMessage = date('[Y-m-d H:i:s]') . ' CACHE: (HIT)  KEY: (' . $cacheKey . ') URI: (' . $requestUri . ')';
                file_put_contents($logFile, $logMessage . "\n", FILE_APPEND);
            }

            $cachedContent .= "\n<!-- Powered by Redis, TIME: " .
                date('Y-m-d H:i:s', time() - $redis->ttl($cacheKey)) .
                ', TTL: ' . $redis->ttl($cacheKey) . 's -->';

            echo $cachedContent;
            exit();
        }

        // 缓存未命中，开始输出缓冲
        ob_start();
        self::$obStarted = true;
    }

    /**
     * 在渲染后保存缓存
     *
     * @param Archive $archive
     * @return void
     * @throws \Typecho\Db\Exception
     * @throws Exception
     */
    public static function afterRender(Archive $archive): void
    {
        // 如果 beforeRender 未开启缓冲（跳过了缓存逻辑），直接返回
        if (!self::$obStarted) {
            return;
        }
        self::$obStarted = false;

        // 管理员登录时不缓存
        if (User::alloc()->hasLogin()) {
            ob_end_flush();
            return;
        }

        $redis = self::initRedis();
        if (!$redis) {
            ob_end_flush();
            return;
        }

        $requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $config     = Helper::options()->plugin('RedisCache');

        // URI 筛选：读取配置中的路径前缀，只缓存匹配的页面
        $rawPrefixes = isset($config->uriPrefix) ? trim($config->uriPrefix) : '/';
        $uriPrefixes = array_filter(array_map('trim', explode(',', $rawPrefixes)));

        $matched = false;
        foreach ($uriPrefixes as $p) {
            // 前缀为 "/" 表示缓存所有路径
            if ($p === '/' || str_starts_with($requestUri, $p)) {
                $matched = true;
                break;
            }
        }

        if (!$matched) {
            ob_end_flush();
            return;
        }

        // 检查路径中是否存在较深嵌套（多于两个斜杠），如果存在则跳过缓存
        if (substr_count($requestUri, '/') > 2) {
            if (isset($config->debug) && $config->debug == '1') {
                $logFile    = __DIR__ . '/logs/cache-' . date('Y-m-d') . '.log';
                $logMessage = date('[Y-m-d H:i:s]') . ' CACHE: (PASS) URI: (' . $requestUri . ') REASON: (multiple slashes detected)';
                file_put_contents($logFile, $logMessage . "\n", FILE_APPEND);
            }
            ob_end_flush();
            return;
        }

        $content  = ob_get_clean();
        if ($content === false) {
            return;
        }
        $cacheKey = self::$prefix . 'page:' . md5($requestUri);

        $redis->setex($cacheKey, self::$expire, $content);
        echo $content;

        if (isset($config->debug) && $config->debug == '1') {
            $logFile    = __DIR__ . '/logs/cache-' . date('Y-m-d') . '.log';
            $logMessage = date('[Y-m-d H:i:s]') . ' CACHE: (MISS) KEY: (' . $cacheKey . ') URI: (' . $requestUri . ')';
            file_put_contents($logFile, $logMessage . "\n", FILE_APPEND);
        }
    }

    /**
     * 文章/页面发布时清除缓存（finishPublish 钩子传入 $contents, $widget）
     *
     * @param array $contents 内容数组
     * @param PostEdit|PageEdit $widget 编辑组件
     * @return void
     */
    public static function clearCacheOnPublish(array $contents, PostEdit|PageEdit $widget): void
    {
        self::flushPageCache();
    }

    /**
     * 评论提交时清除缓存（finishComment 钩子仅传入 $this）
     *
     * @param Feedback $widget 评论组件
     * @return void
     */
    public static function clearCacheOnComment(Feedback $widget): void
    {
        self::flushPageCache();
    }

    /**
     * 清除所有页面缓存
     *
     * @return void
     * @throws Exception
     */
    private static function flushPageCache(): void
    {
        $redis = self::initRedis();
        if (!$redis) {
            return;
        }

        $pattern = self::$prefix . 'page:*';
        $keys    = $redis->keys($pattern);

        if (!empty($keys)) {
            $redis->del($keys);

            $config = Helper::options()->plugin('RedisCache');

            if (isset($config->debug) && $config->debug == '1') {
                $logFile    = __DIR__ . '/logs/cache-' . date('Y-m-d') . '.log';
                $logMessage = date('[Y-m-d H:i:s]') . ' Cache Cleanup: ' . count($keys) . ' pages';
                file_put_contents($logFile, $logMessage . "\n", FILE_APPEND);
            }
        }
    }
}
