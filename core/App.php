<?php
/**
 * 应用容器 — 取代 $_SERVER 注册表模式
 * 提供类型安全的依赖访问，不破坏现有 $_SERVER 兼容
 *
 * API 使用状态（实测）：
 *   活跃：set（注册表写入 10 处）/ get（1 处）/ migrateFromServer（index.php 引导）
 *   预留（0 调用，勿删）：
 *     - bind / singleton / make —— DI 能力，make 为 get() 内部依赖路径
 *     - fire / listen —— 插件事件系统挂载点（插件无需改核心即可介入请求生命周期）
 *     - flush —— 测试辅助（清理容器状态）
 *     - has —— 容器完整性 API
 */

/**
 * 容器循环依赖异常（基础设施审计定案：make() 反射自动装配必须有解析栈，
 * 否则 A↔B 互依构造参数会无限递归 fatal；消息携带完整依赖链便于定位）
 */
class CircularDependencyException extends \RuntimeException {}

class App
{
    /** @var array 实例注册表 */
    private static $registry = [];

    /** @var array 接口→实现绑定 */
    private static $bindings = [];

    /** @var array 单例工厂 */
    private static $singletons = [];

    /** @var array 单例已解析实例 */
    private static $singletonCache = [];

    /** @var array 事件监听器 */
    private static $listeners = [];

    /** @var string[] make() 解析栈（检测循环依赖；解析完成/异常后出栈） */
    private static $resolving = [];

    /**
     * 写透镜像白名单（容器方案 P1）：App::set 仅接受这些注册键，
     * 并写透同步到 $_SERVER 同名键——$_SERVER 退化为兼容镜像视图，双源恒等；
     * 白名单外键一律拒绝，防止容器覆盖 PHP 原生 $_SERVER 键（REQUEST_URI/HTTP_* 等）。
     */
    const MIRROR_KEYS = ['db', 'cache', 'conf', 'time', 'longip', 'method', 'json', 'useragent', 'ip', 'lang'];

    /**
     * 注册服务实例（写透镜像：同步 $_SERVER 同名键）
     */
    public static function set(string $id, $instance): void
    {
        if (!in_array($id, self::MIRROR_KEYS, true)) {
            throw new \InvalidArgumentException('App 容器仅接受注册键: ' . $id);
        }
        self::$registry[$id] = $instance;
        $_SERVER[$id] = $instance;
    }

    /**
     * 获取服务实例
     * @return mixed
     */
    public static function get(string $id)
    {
        if (isset(self::$bindings[$id])) {
            $target = self::$bindings[$id];
            return isset(self::$registry[$target]) ? self::$registry[$target] : self::make($target);
        }
        return self::$registry[$id] ?? null;
    }

    /**
     * 检查是否已注册（容器方案 P3 语义收敛：仅查 registry/bindings/singletons，
     * 不再把「类存在」视作已注册——类可用性请用 class_exists）
     */
    public static function has(string $id): bool
    {
        return isset(self::$registry[$id]) || isset(self::$bindings[$id]) || isset(self::$singletons[$id]);
    }

    /**
     * 接口→实现绑定
     */
    public static function bind(string $interface, string $implementation): void
    {
        self::$bindings[$interface] = $implementation;
    }

    /**
     * 从 $_SERVER 注册表迁移到容器（一次性调用）
     * 契约（容器方案 P0 固化）：仅迁移容器中「未设置或为 null」的键——
     * 容器已有非 null 值时不得覆盖（first-write-wins）；写入口以 App::set（写透镜像）为准，
     * 本方法只兜底装载引导早期（App 尚未加载时）的 $_SERVER 直写。
     */
    public static function migrateFromServer(): void
    {
        $keys = ['db', 'cache', 'conf', 'time', 'longip', 'method', 'json', 'useragent', 'lang'];
        foreach ($keys as $k) {
            if (isset($_SERVER[$k]) && !isset(self::$registry[$k])) {
                self::$registry[$k] = $_SERVER[$k];
            }
        }
    }

    /**
     * 注册单例工厂
     */
    public static function singleton(string $id, ?callable $factory = null): void
    {
        if (null === $factory) {
            $factory = function () use ($id) {
                return self::make($id);
            };
        }
        self::$singletons[$id] = $factory;
        unset(self::$singletonCache[$id]);
    }

    /**
     * 解析实例：单例命中则复用；否则反射构造
     * 构造参数：类类型参数递归 make()；标量参数从 $params 按名/序取
     * 循环依赖（基础设施审计）：解析栈内已存在的类再次进入解析即抛
     * CircularDependencyException（消息携带完整依赖链）；解析完成/异常均出栈
     * @return object|null
     */
    public static function make(string $class, array $params = array())
    {
        // 接口绑定解析（既有语义：单跳；绑定链成环不会递归，最终落 class_exists 判定）
        if (isset(self::$bindings[$class])) {
            $class = self::$bindings[$class];
        }
        if (isset(self::$singletons[$class])) {
            if (!array_key_exists($class, self::$singletonCache)) {
                self::assertNoCycle($class);
                self::$resolving[] = $class;
                try {
                    self::$singletonCache[$class] = call_user_func(self::$singletons[$class]);
                } finally {
                    array_pop(self::$resolving);
                }
            }
            return self::$singletonCache[$class];
        }
        if (isset(self::$registry[$class])) {
            return self::$registry[$class];
        }
        if (!class_exists($class)) {
            return null;
        }
        self::assertNoCycle($class);
        self::$resolving[] = $class;
        try {
            $ref = new \ReflectionClass($class);
            $ctor = $ref->getConstructor();
            if (null === $ctor) {
                return $ref->newInstance();
            }
            $args = array();
            foreach ($ctor->getParameters() as $param) {
                $type = $param->getType();
                if ($type && !$type->isBuiltin()) {
                    $args[] = self::make($type->getName());
                    continue;
                }
                $name = $param->getName();
                if (array_key_exists($name, $params)) {
                    $args[] = $params[$name];
                } elseif ($param->isDefaultValueAvailable()) {
                    $args[] = $param->getDefaultValue();
                } else {
                    $args[] = null;
                }
            }
            return $ref->newInstanceArgs($args);
        } finally {
            array_pop(self::$resolving);
        }
    }

    /**
     * 循环依赖断言：解析栈中已存在同类即抛 CircularDependencyException（携带完整依赖链）
     */
    private static function assertNoCycle(string $class): void
    {
        if (in_array($class, self::$resolving, true)) {
            throw new \CircularDependencyException(
                '容器循环依赖: ' . implode(' -> ', array_merge(self::$resolving, array($class)))
            );
        }
    }

    /**
     * 触发事件
     * @param string $event 事件名
     * @param mixed $data 事件数据
     * @return mixed 最后一个监听器的返回值
     */
    public static function fire(string $event, $data = null)
    {
        if (!isset(self::$listeners[$event])) return $data;
        foreach (self::$listeners[$event] as $listener) {
            $result = $listener($data);
            if (null !== $result) $data = $result;
        }
        return $data;
    }

    /**
     * 注册事件监听器
     */
    public static function listen(string $event, callable $listener): void
    {
        self::$listeners[$event][] = $listener;
    }

    /**
     * 清空容器（测试用）
     */
    public static function flush(): void
    {
        self::$registry = [];
        self::$bindings = [];
        self::$singletons = [];
        self::$singletonCache = [];
        self::$listeners = [];
    }
}
