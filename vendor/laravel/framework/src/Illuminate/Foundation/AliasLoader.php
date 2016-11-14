<?php

namespace Illuminate\Foundation;

class AliasLoader
{
    /**
     * The array of class aliases.
     *
     * @var array
     */
    protected $aliases;

    /**
     * Indicates if a loader has been registered.
     *
     * @var bool
     */
    protected $registered = false;

    /**
     * The singleton instance of the loader.
     *
     * @var \Illuminate\Foundation\AliasLoader
     */
    protected static $instance;

    /**
     * Create a new AliasLoader instance.
     *
     * @param  array  $aliases
     */
    private function __construct($aliases)
    {
        $this->aliases = $aliases;
    }

    /**
     * Get or create the singleton alias loader instance.
     * 取得或者创建本类的实例对象
     *
     * @param  array  $aliases
     * @return \Illuminate\Foundation\AliasLoader
     */
    public static function getInstance(array $aliases = [])
    {
        if (is_null(static::$instance)) {
            return static::$instance = new static($aliases);
        }

        $aliases = array_merge(static::$instance->getAliases(), $aliases);

        static::$instance->setAliases($aliases);

        return static::$instance;
    }

    /**
     * 自动加载函数<br>
     * 如果 $this->aliases 中有这个类别名，则<br>
     * 设置一个类的别名并自动加载它
     *
     * @param  string  $alias
     * @return bool|null
     */
    public function load($alias)
    {
        if (isset($this->aliases[$alias])) {
            return class_alias($this->aliases[$alias], $alias);
        }
    }

    /**
     * 添加一个类别名
     *
     * @param  string  $class
     * @param  string  $alias
     * @return void
     */
    public function alias($class, $alias)
    {
        $this->aliases[$class] = $alias;
    }

    /**
     * 注册自动加载函数（一个实例对象只能注册一次）
     *
     * @return void
     */
    public function register()
    {
        if (! $this->registered) {
            $this->prependToLoaderStack();

            $this->registered = true;
        }
    }

    /**
     * 调用 spl_autoload_register() 注册一个自动加载函数<br>
     * 并放入自动加载函数队列开头（即最优先使用该函数去自动加载）
     *
     * @return void
     */
    protected function prependToLoaderStack()
    {
        spl_autoload_register([$this, 'load'], true, true);
    }

    /**
     * 获取实例对象保存的别名清单
     *
     * @return array
     */
    public function getAliases()
    {
        return $this->aliases;
    }

    /**
     * 设置实例对象的别名清单
     *
     * @param  array  $aliases
     * @return void
     */
    public function setAliases(array $aliases)
    {
        $this->aliases = $aliases;
    }

    /**
     * 获取实例对象是否注册过自动加载函数
     *
     * @return bool
     */
    public function isRegistered()
    {
        return $this->registered;
    }

    /**
     * 设置实例对象的 ”是否注册过自动加载函数“ 这个状态
     *
     * @param  bool  $value
     * @return void
     */
    public function setRegistered($value)
    {
        $this->registered = $value;
    }

    /**
     * 将实例对象保存至类的静态成员
     *
     * @param  \Illuminate\Foundation\AliasLoader  $loader
     * @return void
     */
    public static function setInstance($loader)
    {
        static::$instance = $loader;
    }

    /**
     * Clone method.
     *
     * @return void
     */
    private function __clone()
    {
        //
    }
}
