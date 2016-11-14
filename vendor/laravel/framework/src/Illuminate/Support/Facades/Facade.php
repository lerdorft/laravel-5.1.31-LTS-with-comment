<?php

namespace Illuminate\Support\Facades;

use Mockery;
use RuntimeException;
use Mockery\MockInterface;

abstract class Facade
{
    /**
     * The application instance being facaded.
     *
     * @var \Illuminate\Contracts\Foundation\Application
     */
    protected static $app;

    /**
     * The resolved object instances.
     *
     * @var array
     */
    protected static $resolvedInstance;

    /**
     * Hotswap the underlying instance behind the facade.
     *
     * @param  mixed  $instance
     * @return void
     */
    public static function swap($instance)
    {
        static::$resolvedInstance[static::getFacadeAccessor()] = $instance;

        static::$app->instance(static::getFacadeAccessor(), $instance);
    }

    /**
     * Initiate a mock expectation on the facade.
     *
     * @param  mixed
     * @return \Mockery\Expectation
     */
    public static function shouldReceive()
    {
        $name = static::getFacadeAccessor();

        if (static::isMock()) {
            $mock = static::$resolvedInstance[$name];
        } else {
            $mock = static::createFreshMockInstance($name);
        }

        return call_user_func_array([$mock, 'shouldReceive'], func_get_args());
    }

    /**
     * Create a fresh mock instance for the given class.
     *
     * @param  string  $name
     * @return \Mockery\Expectation
     */
    protected static function createFreshMockInstance($name)
    {
        static::$resolvedInstance[$name] = $mock = static::createMockByName($name);

        $mock->shouldAllowMockingProtectedMethods();

        if (isset(static::$app)) {
            static::$app->instance($name, $mock);
        }

        return $mock;
    }

    /**
     * Create a fresh mock instance for the given class.
     *
     * @param  string  $name
     * @return \Mockery\Expectation
     */
    protected static function createMockByName($name)
    {
        $class = static::getMockableClass($name);

        return $class ? Mockery::mock($class) : Mockery::mock();
    }

    /**
     * Determines whether a mock is set as the instance of the facade.
     *
     * @return bool
     */
    protected static function isMock()
    {
        $name = static::getFacadeAccessor();

        return isset(static::$resolvedInstance[$name]) && static::$resolvedInstance[$name] instanceof MockInterface;
    }

    /**
     * 获取伪装者的所属类的名称
     *
     * @return string|null
     */
    protected static function getMockableClass()
    {
        if ($root = static::getFacadeRoot()) {
            return get_class($root);
        }
    }

    /**
     * 撕开 Facade 伪装，获取背后的伪装者的类名，类别名，甚至是实例对象<br>
     * 然后尝试去解析（通过容器的 make()）
     *
     * @return mixed
     */
    public static function getFacadeRoot()
    {
        
        // 调用子类的 getFacadeAccessor() 静态方法
        // 得到的是伪装者的类名，类别名，或者是实例对象
        
        return static::resolveFacadeInstance(static::getFacadeAccessor());
    }

    /**
     * 子类必须重写此方法，因为在父类中，该方法是直接抛错的<br>
     * 所以不重写的话子类也将直接抛错
     *
     * @return string
     *
     * @throws \RuntimeException
     */
    protected static function getFacadeAccessor()
    {
        throw new RuntimeException('Facade does not implement getFacadeAccessor method.');
    }

    /**
     * 解析对象
     *
     * @param  string|object  $name
     * @return mixed
     */
    protected static function resolveFacadeInstance($name)
    {
        // 是对象就直接返回了，不用继续处理
        // 意味着 static::getFacadeAccessor() 可以返回类的实例对象，也可以是类名/别名
        
        if (is_object($name)) {
            return $name;
        }
        
        // 如果已经解析过则不再解析，直接返回
        
        if (isset(static::$resolvedInstance[$name])) {
            return static::$resolvedInstance[$name];
        }
        
        // 如果 static::getFacadeAccessor() 返回的是类名或别名
        // 则通过容器实例对象去创建类的实例对象（通过容器实例对象的 make() 方法）
        // 得到的实例对象保存起来，下次就不用解析了
        
        return static::$resolvedInstance[$name] = static::$app[$name];
    }

    /**
     * 从 static::$resolvedInstance 中删除某个已解析对象的记录
     *
     * @param  string  $name
     * @return void
     */
    public static function clearResolvedInstance($name)
    {
        unset(static::$resolvedInstance[$name]);
    }

    /**
     * 清空所有已解析对象的记录
     *
     * @return void
     */
    public static function clearResolvedInstances()
    {
        static::$resolvedInstance = [];
    }

    /**
     * 返回容器实例对象
     *
     * @return \Illuminate\Contracts\Foundation\Application
     */
    public static function getFacadeApplication()
    {
        return static::$app;
    }

    /**
     * 保存容器实例对象至延迟静态绑定的成员
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return void
     */
    public static function setFacadeApplication($app)
    {
        static::$app = $app;
    }

    /**
     * 当通过 Class::method 这种方式调用类的方法时<br>
     * 先根据别名自动载入 Class 类<br>
     * 然后如果无法直接调用类的静态方法 method 则会调用到这里的 __callStatic()<br>
     * 也就实现了这样的 Facade 用法： Cache::get($name)
     *
     * @param  string  $method
     * @param  array   $args
     * @return mixed
     */
    public static function __callStatic($method, $args)
    {
        $instance = static::getFacadeRoot();

        if (! $instance) {
            throw new RuntimeException('A facade root has not been set.');
        }

        switch (count($args)) {
            case 0:
                return $instance->$method();

            case 1:
                return $instance->$method($args[0]);

            case 2:
                return $instance->$method($args[0], $args[1]);

            case 3:
                return $instance->$method($args[0], $args[1], $args[2]);

            case 4:
                return $instance->$method($args[0], $args[1], $args[2], $args[3]);

            default:
                return call_user_func_array([$instance, $method], $args);
        }
    }
}
