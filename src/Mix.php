<?php

/**
 * Class Mix
 * @author liu,jian <coder.keda@gmail.com>
 */
class Mix
{

    /**
     * 版本号
     * @var string
     */
    public static $version = '2.0.1';

    /**
     * App实例
     * @var \Mix\Console\Application|\Mix\Http\Application|\Mix\WebSocket\Application|\Mix\Tcp\Application|\Mix\Udp\Application
     */
    public static $app;

    /**
     * 环境配置
     * @var \Mix\Core\Environment
     */
    public static $env;

    /**
     * 构建配置
     * @param array $config
     * @param bool $ref
     * @return mixed
     */
    public static function configure($config, $ref = false)
    {
        foreach ($config as $key => $value) {
            // 子类处理
            if (is_array($value)) {
                // 引用依赖
                if (isset($value['ref'])) {
                    $config[$key] = self::configure($value, true);
                }
                // 引用组件
                if (isset($value['component'])) {
                    $name         = $value['component'];
                    $config[$key] = self::$app->$name;
                }
            }
        }
        if ($ref) {
            // 实例化
            if (isset($config['ref'])) {
                $name       = $config['ref'];
                $bean       = \Mix\Core\Bean::config($name);
                $class      = $bean['class'];
                $properties = $bean['properties'] ?? [];
                return new $class($properties);
            }
        }
        return $config;
    }

    /**
     * 导入属性
     * @param $object
     * @param $config
     * @return mixed
     */
    public static function importProperties($object, $config)
    {
        foreach ($config as $name => $value) {
            // 导入
            $object->$name = $value;
            // 注释类型检测
            $class      = get_class($object);
            $reflection = new \ReflectionClass($class);
            if (!$reflection->hasProperty($name)) {
                continue;
            }
            $docComment = $reflection->getProperty($name)->getDocComment();
            $var        = self::getVarFrom($docComment);
            if (!$var) {
                continue;
            }
            if (!interface_exists($var) && !class_exists($var)) {
                throw new \Mix\Exception\DependencyInjectionException("Interface or class not found, class: {$class}, property: {$name}, @var: {$var}");
            }
            if (!($value instanceof $var)) {
                throw new \Mix\Exception\DependencyInjectionException("The type of the imported property does not match, class: {$class}, property: {$name}, @var: {$var}");
            }
        }
        return $object;
    }

    /**
     * 获取注释中var的值
     * @param $docComment
     * @return string
     */
    protected static function getVarFrom($docComment)
    {
        $var = '';
        if (!$docComment) {
            return $var;
        }
        $key   = '@var';
        $len   = 4;
        $start = strpos($docComment, $key);
        $end   = strpos($docComment, '*', $start + $len);
        if ($start !== false && $end !== false) {
            $tmp = substr($docComment, $start + $len, $end - $start - $len);
            $tmp = explode(' ', trim($tmp));
            $var = array_shift($tmp);
            $var = substr($var, 0, 1) === '\\' ? substr($var, 1) : '';
        }
        return $var;
    }

    /**
     * 创建组件
     * @param $config
     * @return mixed
     */
    public static function createComponent($config)
    {
        $name       = $config['ref'];
        $bean       = \Mix\Core\Bean::config($name);
        $class      = $bean['class'];
        $properties = $bean['properties'] ?? [];
        return new $class($properties);
    }

    /**
     * 从文件载入环境配置
     * @param $filename
     * @return bool
     */
    public static function loadEnvironmentFrom($filename)
    {
        $env = new \Mix\Core\Environment(['filename' => $filename]);
        $env->load();
        self::$env = $env;
        return true;
    }

}
