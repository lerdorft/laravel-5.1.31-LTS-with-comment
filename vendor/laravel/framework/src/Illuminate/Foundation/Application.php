<?php

namespace Illuminate\Foundation;

use Closure;
use RuntimeException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Routing\RoutingServiceProvider;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Illuminate\Contracts\Foundation\Application as ApplicationContract;

class Application extends Container implements ApplicationContract, HttpKernelInterface
{

    /**
     * The Laravel framework version.
     *
     * @var string
     */
    const VERSION = '5.1.31 (LTS)';

    /**
     * The base path for the Laravel installation.<br>
     * 当前项目在操作系统中的绝对路径
     *
     * @var string
     */
    protected $basePath;

    /**
     * Indicates if the application has been bootstrapped before.
     *
     * @var bool
     */
    protected $hasBeenBootstrapped = false;

    /**
     * Indicates if the application has "booted".
     *
     * @var bool
     */
    protected $booted = false;

    /**
     * The array of booting callbacks.
     *
     * @var array
     */
    protected $bootingCallbacks = [];

    /**
     * The array of booted callbacks.
     *
     * @var array
     */
    protected $bootedCallbacks = [];

    /**
     * The array of terminating callbacks.
     *
     * @var array
     */
    protected $terminatingCallbacks = [];

    /**
     * All of the registered service providers.
     *
     * @var array
     */
    protected $serviceProviders = [];

    /**
     * The names of the loaded service providers.
     *
     * @var array
     */
    protected $loadedProviders = [];

    /**
     * The deferred services and their providers.
     *
     * @var array
     */
    protected $deferredServices = [];

    /**
     * A custom callback used to configure Monolog.
     *
     * @var callable|null
     */
    protected $monologConfigurator;

    /**
     * The custom database path defined by the developer.
     *
     * @var string
     */
    protected $databasePath;

    /**
     * The custom storage path defined by the developer.
     *
     * @var string
     */
    protected $storagePath;

    /**
     * The custom environment path defined by the developer.
     *
     * @var string
     */
    protected $environmentPath;

    /**
     * The environment file to load during bootstrapping.
     *
     * @var string
     */
    protected $environmentFile = '.env';

    /**
     * The application namespace.
     *
     * @var string
     */
    protected $namespace = null;

    /**
     * Create a new Illuminate application instance.
     *
     * @param  string|null  $basePath
     * @return void
     */
    public function __construct($basePath = null)
    {
        $this->registerBaseBindings();

        $this->registerBaseServiceProviders();

        $this->registerCoreContainerAliases();

        if ($basePath) {
            $this->setBasePath($basePath);
        }
    }

    /**
     * Get the version number of the application.
     *
     * @return string
     */
    public function version()
    {
        return static::VERSION;
    }

    /**
     * Register the basic bindings into the container.
     *
     * @return void
     */
    protected function registerBaseBindings()
    {
        // setInstance() 继承自 Container, 延迟静态绑定
        // 实际执行 static::$instance = $this
        static::setInstance($this);
        // instance() 继承自 Container
        // 实际执行 $this->instances['app'] = $this
        $this->instance('app', $this);
        // instance() 继承自 Container
        // 实际执行 $this->instances['Illuminate\Container\Container'] = $this
        $this->instance('Illuminate\Container\Container', $this);
    }

    /**
     * Register all of the base service providers.
     *
     * @return void
     */
    protected function registerBaseServiceProviders()
    {
        // 类 EventServiceProvider 和 RoutingServiceProvider，
        // 继承 Illuminate\Support\ServiceProvider
        // 在构造函数（继承自Illuminate\Support\ServiceProvider）中 $this->app 保存传入的 $this
        // 类 EventServiceProvider 中的 register 将使用到 $this->app 保存的容器对象，也就是这里传入构造函数的 $this; 

        $this->register(new EventServiceProvider($this));

        $this->register(new RoutingServiceProvider($this));
    }

    /**
     * 实例化 bootstrappers 数组中的启动器<br>
     * 调用实例对象的 bootstrap() 方法<br>
     * 并触发每个启动器的 bootstrapping 和 bootstrapped 事件
     * 
     * @param  array  $bootstrappers
     * @return void
     */
    public function bootstrapWith(array $bootstrappers)
    {
        $this->hasBeenBootstrapped = true;

        foreach ($bootstrappers as $bootstrapper) {
            $this['events']->fire('bootstrapping: ' . $bootstrapper, [$this]);

            $this->make($bootstrapper)->bootstrap($this);

            $this['events']->fire('bootstrapped: ' . $bootstrapper, [$this]);
        }
    }

    /**
     * Register a callback to run after loading the environment.
     *
     * @param  \Closure  $callback
     * @return void
     */
    public function afterLoadingEnvironment(Closure $callback)
    {
        return $this->afterBootstrapping(
                        'Illuminate\Foundation\Bootstrap\DetectEnvironment', $callback
        );
    }

    /**
     * Register a callback to run before a bootstrapper.
     *
     * @param  string  $bootstrapper
     * @param  Closure  $callback
     * @return void
     */
    public function beforeBootstrapping($bootstrapper, Closure $callback)
    {
        $this['events']->listen('bootstrapping: ' . $bootstrapper, $callback);
    }

    /**
     * Register a callback to run after a bootstrapper.
     *
     * @param  string  $bootstrapper
     * @param  Closure  $callback
     * @return void
     */
    public function afterBootstrapping($bootstrapper, Closure $callback)
    {
        $this['events']->listen('bootstrapped: ' . $bootstrapper, $callback);
    }

    /**
     * Determine if the application has been bootstrapped before.
     *
     * @return bool
     */
    public function hasBeenBootstrapped()
    {
        return $this->hasBeenBootstrapped;
    }

    /**
     * Set the base path for the application.<br>
     * 设置传入的 $basePath 参数为系统根目录<br>
     * 同时绑定一些系统根目录下的目录至容器
     *
     * @param  string  $basePath
     * @return $this
     */
    public function setBasePath($basePath)
    {
        $this->basePath = rtrim($basePath, '\/');

        $this->bindPathsInContainer();

        return $this;
    }

    /**
     * Bind all of the application paths in the container.<br>
     * 设置 app, config, database, lang, public, storage 目录
     * 
     * @return void
     */
    protected function bindPathsInContainer()
    {
        // $this->instances['path'] = $this->path();
        $this->instance('path', $this->path());
        // $this->instances['path.base'] = $this->basePath();
        // $this->instances['path.config'] = $this->configPath();
        // $this->instances['path.database'] = $this->databasePath();
        // $this->instances['path.lang'] = $this->langPath();
        // $this->instances['path.public'] = $this->publicPath();
        // $this->instances['path.storage'] = $this->storagePath();
        foreach (['base', 'config', 'database', 'lang', 'public', 'storage'] as $path) {
            $this->instance('path.' . $path, $this->{$path . 'Path'}());
        }
    }

    /**
     * Get the path to the application "app" directory.<br>
     * 返回系统根目录的 app 目录
     * 
     * @return string
     */
    public function path()
    {
        return $this->basePath . DIRECTORY_SEPARATOR . 'app';
    }

    /**
     * Get the base path of the Laravel installation.<br>
     * 返回项目在操作系统中的绝对路径
     * 
     * @return string
     */
    public function basePath()
    {
        return $this->basePath;
    }

    /**
     * Get the path to the application configuration files.<br>
     * 返回系统根目录的 config 目录
     * 
     * @return string
     */
    public function configPath()
    {
        return $this->basePath . DIRECTORY_SEPARATOR . 'config';
    }

    /**
     * Get the path to the database directory.<br>
     * 如果设置了 $this->databasePath 则返回此目录<br>
     * 否则返回系统根目录的 database 目录
     * 
     * @return string
     */
    public function databasePath()
    {
        return $this->databasePath ? : $this->basePath . DIRECTORY_SEPARATOR . 'database';
    }

    /**
     * Set the database directory.
     *
     * @param  string  $path
     * @return $this
     */
    public function useDatabasePath($path)
    {
        $this->databasePath = $path;

        $this->instance('path.database', $path);

        return $this;
    }

    /**
     * Get the path to the language files.<br>
     * 返回系统根目录的 resources/lang 目录
     * 
     * @return string
     */
    public function langPath()
    {
        return $this->basePath . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'lang';
    }

    /**
     * Get the path to the public / web directory.<br>
     * 返回系统根目录的 public 目录
     *
     * @return string
     */
    public function publicPath()
    {
        return $this->basePath . DIRECTORY_SEPARATOR . 'public';
    }

    /**
     * Get the path to the storage directory.<br>
     * 如果设置了 $this->storagePath 则返回此目录<br>
     * 否则返回系统根目录的 storage 目录
     *
     * @return string
     */
    public function storagePath()
    {
        return $this->storagePath ? : $this->basePath . DIRECTORY_SEPARATOR . 'storage';
    }

    /**
     * Set the storage directory.
     *
     * @param  string  $path
     * @return $this
     */
    public function useStoragePath($path)
    {
        $this->storagePath = $path;

        $this->instance('path.storage', $path);

        return $this;
    }

    /**
     * Get the path to the environment file directory.<br>
     * 返回配置文件的目录，如果没设置则返回项目在操作系统中的绝对路径
     * 
     * @return string
     */
    public function environmentPath()
    {
        return $this->environmentPath ? : $this->basePath;
    }

    /**
     * Set the directory for the environment file.<br>
     * 设置环境配置文件的目录 $this->environmentPath 为 $path
     * 
     * @param  string  $path
     * @return $this
     */
    public function useEnvironmentPath($path)
    {
        $this->environmentPath = $path;

        return $this;
    }

    /**
     * Set the environment file to be loaded during bootstrapping.<br>
     * 设置环境配置文件 $this->environmentFile 的名称为 $file，默认为 .env
     *
     * @param  string  $file
     * @return $this
     */
    public function loadEnvironmentFrom($file)
    {
        $this->environmentFile = $file;

        return $this;
    }

    /**
     * Get the environment file the application is using.<br>
     * 获取环境配置文件 $this->environmentFile 的名称<br>
     * 如果设置了 $this->environmentFile 则返回该变量保存的文件名<br>
     * 否则返回默认值 .env
     *
     * @return string
     */
    public function environmentFile()
    {
        return $this->environmentFile ? : '.env';
    }

    /**
     * 获取或者确认系统当前运行环境
     *
     * @param  mixed
     * @return string
     */
    public function environment()
    {
        if (func_num_args() > 0) {
            $patterns = is_array(func_get_arg(0)) ? func_get_arg(0) : func_get_args();

            foreach ($patterns as $pattern) {
                if (Str::is($pattern, $this['env'])) {
                    return true;
                }
            }

            return false;
        }

        return $this['env'];
    }

    /**
     * 判断系统是否运行在 local 环境中
     *
     * @return bool
     */
    public function isLocal()
    {
        return $this['env'] == 'local';
    }

    /**
     * 检测当前系统运行环境<br>
     * 并绑定到 $this->bindings['env']
     *
     * @param  \Closure  $callback
     * @return string
     */
    public function detectEnvironment(Closure $callback)
    {
        $args = isset($_SERVER['argv']) ? $_SERVER['argv'] : null;

        return $this['env'] = (new EnvironmentDetector())->detect($callback, $args);
    }

    /**
     * 检测系统是否运行在命令行模式
     * 
     * @return bool
     */
    public function runningInConsole()
    {
        return php_sapi_name() == 'cli';
    }

    /**
     * 检测系统是否运行在单元测试模式
     * 
     * @return bool
     */
    public function runningUnitTests()
    {
        return $this['env'] == 'testing';
    }

    /**
     * Register all of the configured providers.
     *
     * @return void
     */
    public function registerConfiguredProviders()
    {
        $manifestPath = $this->getCachedServicesPath();
     
        (new ProviderRepository($this, new Filesystem, $manifestPath))
                ->load($this->config['app.providers']);
    }

    /**
     * Register a service provider with the application.
     *
     * @param  \Illuminate\Support\ServiceProvider|string  $provider
     * @param  array  $options
     * @param  bool   $force
     * @return \Illuminate\Support\ServiceProvider
     */
    public function register($provider, $options = [], $force = false)
    {
        // 根据 $provider（可以是类名或者是实例对象）去 $this->serviceProviders
        // 中寻找属于 $provider 一类的或者子类的 provider （使用 instanceof 判断）
        // 找到 provider 对象且 $force = false 的情况下返回找到的 provider，不再继续执行

        if (($registered = $this->getProvider($provider)) && !$force) {
            return $registered;
        }

        // 如果 $provider 是字符串就执行 $provider = new $provider($this)
        // 等同于 new EventServiceProvider($this)
        // 解析过后得到的对象就是当前 register() 的 $provider 参数期望的值

        if (is_string($provider)) {
            $provider = $this->resolveProviderClass($provider);
        }

        // 所有继承自 Illuminate\Support\ServiceProvider 类的 service provider
        // 都必须实现 register 方法（父级类中是抽象方法）
        // 通常 service provider 的 register 方法通过 Illuminate\Contracts\Container 类
        // 中的 singleton, bind 或者 ArrayAccess 接口提供的方法绑定至容器的 $this->bindings

        $provider->register();

        // Once we have registered the service we will iterate through the options
        // and set each of them on the application so they will be available on
        // the actual loading of the service objects and for developer usage.

        foreach ($options as $key => $value) {
            // 调用 ArrayAccess 提供的 offsetSet() 方法
            // 如果 $value 不是 Closure，则被包装成仅返回 $value 的一个闭包函数
            // 最后调用 $this->bind($key, $value) 绑定至 $this->bindings
            $this[$key] = $value;
        }

        // 执行 $this->make('events')->fire($class = get_class($provider), [$provider])
        // 执行 $this->serviceProviders[] = $provider
        // 加入 $this->serviceProviders 后即可防止重复注册服务
        // 执行 $this->loadedProviders[get_class($provider)] = true
        
        $this->markAsRegistered($provider);

        // If the application has already booted, we will call this boot method on
        // the provider class so it has an opportunity to do its boot logic and
        // will be ready for any usage by the developer's application logics.
        // 当系统已经启动后（启动后 $this->booted 为 true，Illuminate\Foundation\Bootstrap\BootProviders 中启动）
        // 再注册的新服务，该服务实例对象的 boot() 方法会被调用

        if ($this->booted) {
            $this->bootProvider($provider);
        }

        return $provider;
    }

    /**
     * Get the registered service provider instance if it exists.<br>
     * 根据 $provider 从 $this->serviceProviders 中获取 service provider 对象
     *
     * @param  \Illuminate\Support\ServiceProvider|string  $provider
     * @return \Illuminate\Support\ServiceProvider|null
     */
    public function getProvider($provider)
    {
        //传入的可能是类名或者实例对象，实例对象就取它的类名
        $name = is_string($provider) ? $provider : get_class($provider);
        // Arr = Illuminate\Support\Arr
        //如果 $this->serviceProviders 中存在一个值是 $name 的实例对象(可以是子类)，
        //则返回该对象（只返回第一个匹配上的）
        //无法找到则返回 NULL
        return Arr::first($this->serviceProviders, function ($key, $value) use ($name) {
                    return $value instanceof $name;
                });
    }

    /**
     * Resolve a service provider instance from the class name.<br>
     * 通过类名 $provider 得到一个实例对象，使用容器对象($this)作为构造函数唯一参数
     * 
     * @param  string  $provider
     * @return \Illuminate\Support\ServiceProvider
     */
    public function resolveProviderClass($provider)
    {
        return new $provider($this);
    }

    /**
     * Mark the given provider as registered.
     *
     * @param  \Illuminate\Support\ServiceProvider  $provider
     * @return void
     */
    protected function markAsRegistered($provider)
    {
        // 调用 ArrayAccess 的 offsetGet 方法
        // 实际执行 $this->make('events');

        $this['events']->fire($class = get_class($provider), [$provider]);

        $this->serviceProviders[] = $provider;

        $this->loadedProviders[$class] = true;
    }

    /**
     * Load and boot all of the remaining deferred providers.
     *
     * @return void
     */
    public function loadDeferredProviders()
    {
        // We will simply spin through each of the deferred providers and register each
        // one and boot them if the application has booted. This should make each of
        // the remaining services available to this application for immediate use.
        foreach ($this->deferredServices as $service => $provider) {
            $this->loadDeferredProvider($service);
        }

        $this->deferredServices = [];
    }

    /**
     * Load the provider for a deferred service.
     *
     * @param  string  $service
     * @return void
     */
    public function loadDeferredProvider($service)
    {
        if (!isset($this->deferredServices[$service])) {
            return;
        }

        $provider = $this->deferredServices[$service];

        // If the service provider has not already been loaded and registered we can
        // register it with the application and remove the service from this list
        // of deferred services, since it will already be loaded on subsequent.
        if (!isset($this->loadedProviders[$provider])) {
            $this->registerDeferredProvider($provider, $service);
        }
    }

    /**
     * Register a deferred provider and service.
     *
     * @param  string  $provider
     * @param  string  $service
     * @return void
     */
    public function registerDeferredProvider($provider, $service = null)
    {
        // Once the provider that provides the deferred service has been registered we
        // will remove it from our local list of the deferred services with related
        // providers so that this container does not try to resolve it out again.
        if ($service) {
            unset($this->deferredServices[$service]);
        }

        $this->register($instance = new $provider($this));

        if (!$this->booted) {
            $this->booting(function () use ($instance) {
                $this->bootProvider($instance);
            });
        }
    }

    /**
     * Resolve the given type from the container.
     *
     * (Overriding Container::make)
     *
     * @param  string  $abstract
     * @param  array   $parameters
     * @return mixed
     */
    public function make($abstract, array $parameters = [])
    {
        $abstract = $this->getAlias($abstract);

        if (isset($this->deferredServices[$abstract])) {
            $this->loadDeferredProvider($abstract);
        }

        return parent::make($abstract, $parameters);
    }

    /**
     * Determine if the given abstract type has been bound.<br>
     * 检查在 $this->deferredServices 中是否设置了 $abstract 下标<br>
     * 也就说是否绑定了这个抽象事物（比如“狗”是描述一种四条腿动物的抽象名词）<br>
     * 如果在 $this->deferredServices 查不到则从父级的<br>
     * $this->bindings，$this->instances,$this->aliases 中查看是否已绑定
     *
     * (Overriding Container::bound)
     *
     * @param  string  $abstract
     * @return bool
     */
    public function bound($abstract)
    {
        return isset($this->deferredServices[$abstract]) || parent::bound($abstract);
    }

    /**
     * 获取系统是否已经启动的状态
     *
     * @return bool
     */
    public function isBooted()
    {
        return $this->booted;
    }

    /**
     * 启动系统，启动注册的服务<br> 
     * Illuminate\Foundation\Bootstrap\BootProviders 中会调用此方法
     * 
     * @return void
     */
    public function boot()
    {
        // 不重复启动
        
        if ($this->booted) {
            return;
        }
        
        // 调用系统启动中相关的钩子函数
        
        $this->fireAppCallbacks($this->bootingCallbacks);
        
        // 通过 $this->register() 注册的服务（服务实例对象会被放入 $this->serviceProviders）
        // 在系统启动后会在此被调用 boot() 方法启动
        
        array_walk($this->serviceProviders, function ($p) {
            $this->bootProvider($p);
        });
        
        // 标记系统为已启动，防止重复启动
        
        $this->booted = true;
        
        // 调用系统启动后相关的钩子函数
        
        $this->fireAppCallbacks($this->bootedCallbacks);
    }

    /**
     * 启动服务（运行服务对象的 boot() 方法）
     *
     * @param  \Illuminate\Support\ServiceProvider  $provider
     * @return mixed
     */
    protected function bootProvider(ServiceProvider $provider)
    {
        if (method_exists($provider, 'boot')) {
            return $this->call([$provider, 'boot']);
        }
    }

    /**
     * Register a new boot listener.<br>
     * 将用于系统启动时的钩子函数放入变量中
     * 
     * @param  mixed  $callback
     * @return void
     */
    public function booting($callback)
    {
        $this->bootingCallbacks[] = $callback;
    }

    /**
     * Register a new "booted" listener.<br>
     * 将用于系统启动后的钩子函数放入变量中，并调用传入的函数
     *
     * @param  mixed  $callback
     * @return void
     */
    public function booted($callback)
    {
        $this->bootedCallbacks[] = $callback;

        if ($this->isBooted()) {
            $this->fireAppCallbacks([$callback]);
        }
    }

    /**
     * Call the booting callbacks for the application.<br>
     * 执行 $callbacks 数组中的函数，并将调用容器实例对象作为唯一参数传入函数
     *
     * @param  array  $callbacks
     * @return void
     */
    protected function fireAppCallbacks(array $callbacks)
    {
        foreach ($callbacks as $callback) {
            call_user_func($callback, $this);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function handle(SymfonyRequest $request, $type = self::MASTER_REQUEST, $catch = true)
    {
        return $this['Illuminate\Contracts\Http\Kernel']->handle(Request::createFromBase($request));
    }

    /**
     * Determine if middleware has been disabled for the application.
     *
     * @return bool
     */
    public function shouldSkipMiddleware()
    {
        return $this->bound('middleware.disable') &&
                $this->make('middleware.disable') === true;
    }

    /**
     * Determine if the application configuration is cached.<br>
     * 查看 $this->basePath().'/bootstrap/cache/config.php' 文件是否存在
     * 
     * @return bool
     */
    public function configurationIsCached()
    {
        return $this['files']->exists($this->getCachedConfigPath());
    }

    /**
     * Get the path to the configuration cache file.<br>
     * 返回系统根目录下的 bootstrap/cache/config.php 文件路径
     * 
     * @return string
     */
    public function getCachedConfigPath()
    {
        return $this->basePath() . '/bootstrap/cache/config.php';
    }

    /**
     * Determine if the application routes are cached.<br>
     * 查看 $this->basePath().'/bootstrap/cache/routes.php' 文件是否存在<br>
     * 调用 $this->getCachedRoutesPath()
     *
     * @return bool
     */
    public function routesAreCached()
    {
        return $this['files']->exists($this->getCachedRoutesPath());
    }

    /**
     * Get the path to the routes cache file.<br>
     * 返回系统根目录下的 bootstrap/cache/routes.php 文件路径
     *
     * @return string
     */
    public function getCachedRoutesPath()
    {
        return $this->basePath() . '/bootstrap/cache/routes.php';
    }

    /**
     * Get the path to the cached "compiled.php" file.<br>
     * 返回系统根目录下的 bootstrap/cache/compiled.php 文件路径
     *
     * @return string
     */
    public function getCachedCompilePath()
    {
        return $this->basePath() . '/bootstrap/cache/compiled.php';
    }

    /**
     * Get the path to the cached services.json file.<br>
     * 返回系统根目录下的 bootstrap/cache/services.json 文件路径
     *
     * @return string
     */
    public function getCachedServicesPath()
    {
        return $this->basePath() . '/bootstrap/cache/services.json';
    }

    /**
     * Determine if the application is currently down for maintenance.<br>
     * 判断项目是否处于下线维护中<br>
     * 当系统根目录 storage/framework/down 文件存在时认为系统下线维护中
     * 
     * @return bool
     */
    public function isDownForMaintenance()
    {
        return file_exists($this->storagePath() . '/framework/down');
    }

    /**
     * Throw an HttpException with the given data.
     *
     * @param  int     $code
     * @param  string  $message
     * @param  array   $headers
     * @return void
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     */
    public function abort($code, $message = '', array $headers = [])
    {
        if ($code == 404) {
            throw new NotFoundHttpException($message);
        }

        throw new HttpException($code, $message, null, $headers);
    }

    /**
     * Register a terminating callback with the application.<br>
     * 将函数 $callback 放入 $this->terminatingCallbacks 数组中
     * 
     * @param  \Closure  $callback
     * @return $this
     */
    public function terminating(Closure $callback)
    {
        $this->terminatingCallbacks[] = $callback;

        return $this;
    }

    /**
     * Terminate the application.
     *
     * @return void
     */
    public function terminate()
    {
        foreach ($this->terminatingCallbacks as $terminating) {
            $this->call($terminating);
        }
    }

    /**
     * Get the service providers that have been loaded.
     * 返回 $this->loadedProviders
     *
     * @return array
     */
    public function getLoadedProviders()
    {
        return $this->loadedProviders;
    }

    /**
     * Get the application's deferred services.<br>
     * 返回 $this->deferredServices
     * 
     * @return array
     */
    public function getDeferredServices()
    {
        return $this->deferredServices;
    }

    /**
     * Set the application's deferred services.<br>
     * 设置（覆盖） $this->deferredServices 的值为一个数组
     *
     * @param  array  $services
     * @return void
     */
    public function setDeferredServices(array $services)
    {
        $this->deferredServices = $services;
    }

    /**
     * Add an array of services to the application's deferred services.<br>
     * 传入参数 $services 与现有的 $this->deferredServices 数组合并
     * 
     * @param  array  $services
     * @return void
     */
    public function addDeferredServices(array $services)
    {
        $this->deferredServices = array_merge($this->deferredServices, $services);
    }

    /**
     * Determine if the given service is a deferred service.
     *
     * @param  string  $service
     * @return bool
     */
    public function isDeferredService($service)
    {
        return isset($this->deferredServices[$service]);
    }

    /**
     * Define a callback to be used to configure Monolog.
     *
     * @param  callable  $callback
     * @return $this
     */
    public function configureMonologUsing(callable $callback)
    {
        $this->monologConfigurator = $callback;

        return $this;
    }

    /**
     * Determine if the application has a custom Monolog configurator.
     *
     * @return bool
     */
    public function hasMonologConfigurator()
    {
        return !is_null($this->monologConfigurator);
    }

    /**
     * Get the custom Monolog configurator for the application.
     *
     * @return callable
     */
    public function getMonologConfigurator()
    {
        return $this->monologConfigurator;
    }

    /**
     * Get the current application locale.
     *
     * @return string
     */
    public function getLocale()
    {
        return $this['config']->get('app.locale');
    }

    /**
     * Set the current application locale.
     *
     * @param  string  $locale
     * @return void
     */
    public function setLocale($locale)
    {
        $this['config']->set('app.locale', $locale);

        $this['translator']->setLocale($locale);

        $this['events']->fire('locale.changed', [$locale]);
    }

    /**
     * Register the core class aliases in the container.<br>
     * 循环地将一些键值对存入 $this->aliases 中
     * 
     * @return void
     */
    public function registerCoreContainerAliases()
    {
        $aliases = [
            'app'                  => ['Illuminate\Foundation\Application', 'Illuminate\Contracts\Container\Container', 'Illuminate\Contracts\Foundation\Application'],
            'auth'                 => 'Illuminate\Auth\AuthManager',
            'auth.driver'          => ['Illuminate\Auth\Guard', 'Illuminate\Contracts\Auth\Guard'],
            'auth.password.tokens' => 'Illuminate\Auth\Passwords\TokenRepositoryInterface',
            'blade.compiler'       => 'Illuminate\View\Compilers\BladeCompiler',
            'cache'                => ['Illuminate\Cache\CacheManager', 'Illuminate\Contracts\Cache\Factory'],
            'cache.store'          => ['Illuminate\Cache\Repository', 'Illuminate\Contracts\Cache\Repository'],
            'config'               => ['Illuminate\Config\Repository', 'Illuminate\Contracts\Config\Repository'],
            'cookie'               => ['Illuminate\Cookie\CookieJar', 'Illuminate\Contracts\Cookie\Factory', 'Illuminate\Contracts\Cookie\QueueingFactory'],
            'encrypter'            => ['Illuminate\Encryption\Encrypter', 'Illuminate\Contracts\Encryption\Encrypter'],
            'db'                   => 'Illuminate\Database\DatabaseManager',
            'db.connection'        => ['Illuminate\Database\Connection', 'Illuminate\Database\ConnectionInterface'],
            'events'               => ['Illuminate\Events\Dispatcher', 'Illuminate\Contracts\Events\Dispatcher'],
            'files'                => 'Illuminate\Filesystem\Filesystem',
            'filesystem'           => ['Illuminate\Filesystem\FilesystemManager', 'Illuminate\Contracts\Filesystem\Factory'],
            'filesystem.disk'      => 'Illuminate\Contracts\Filesystem\Filesystem',
            'filesystem.cloud'     => 'Illuminate\Contracts\Filesystem\Cloud',
            'hash'                 => 'Illuminate\Contracts\Hashing\Hasher',
            'translator'           => ['Illuminate\Translation\Translator', 'Symfony\Component\Translation\TranslatorInterface'],
            'log'                  => ['Illuminate\Log\Writer', 'Illuminate\Contracts\Logging\Log', 'Psr\Log\LoggerInterface'],
            'mailer'               => ['Illuminate\Mail\Mailer', 'Illuminate\Contracts\Mail\Mailer', 'Illuminate\Contracts\Mail\MailQueue'],
            'auth.password'        => ['Illuminate\Auth\Passwords\PasswordBroker', 'Illuminate\Contracts\Auth\PasswordBroker'],
            'queue'                => ['Illuminate\Queue\QueueManager', 'Illuminate\Contracts\Queue\Factory', 'Illuminate\Contracts\Queue\Monitor'],
            'queue.connection'     => 'Illuminate\Contracts\Queue\Queue',
            'redirect'             => 'Illuminate\Routing\Redirector',
            'redis'                => ['Illuminate\Redis\Database', 'Illuminate\Contracts\Redis\Database'],
            'request'              => 'Illuminate\Http\Request',
            'router'               => ['Illuminate\Routing\Router', 'Illuminate\Contracts\Routing\Registrar'],
            'session'              => 'Illuminate\Session\SessionManager',
            'session.store'        => ['Illuminate\Session\Store', 'Symfony\Component\HttpFoundation\Session\SessionInterface'],
            'url'                  => ['Illuminate\Routing\UrlGenerator', 'Illuminate\Contracts\Routing\UrlGenerator'],
            'validator'            => ['Illuminate\Validation\Factory', 'Illuminate\Contracts\Validation\Factory'],
            'view'                 => ['Illuminate\View\Factory', 'Illuminate\Contracts\View\Factory'],
        ];

        foreach ($aliases as $key => $aliases) {
            foreach ((array) $aliases as $alias) {
                // 实际执行 $this->aliases[$alias] = $key;
                $this->alias($key, $alias);
            }
        }
    }

    /**
     * Flush the container of all bindings and resolved instances.<br>
     * 执行了以下操作<br>
     * $this->aliases = [];<br>
     * $this->resolved = [];<br>
     * $this->bindings = [];<br>
     * $this->instances = [];<br>
     * $this->loadedProviders = [];
     * 
     * @return void
     */
    public function flush()
    {
        // 父级类 Container
        // $this->aliases = [];
        // $this->resolved = [];
        // $this->bindings = [];
        // $this->instances = [];
        parent::flush();

        $this->loadedProviders = [];
    }

    /**
     * 获取当前正在使用的内核对象<br>
     * 根据 php_sapi_name() == 'cli' 返回的结果<br>
     * 使用 $this->make() 方法去实例化对应的类<br>
     * 控制台核心类 Illuminate\Contracts\Console\Kernel <br>
     * 或者HTTP核心类 Illuminate\Contracts\Http\Kernel<br>
     *
     * @return \Illuminate\Contracts\Console\Kernel|\Illuminate\Contracts\Http\Kernel
     */
    protected function getKernel()
    {
        $kernelContract = $this->runningInConsole() ? 'Illuminate\Contracts\Console\Kernel' : 'Illuminate\Contracts\Http\Kernel';

        return $this->make($kernelContract);
    }

    /**
     * Get the application namespace.
     *
     * @return string
     *
     * @throws \RuntimeException
     */
    public function getNamespace()
    {
        if (!is_null($this->namespace)) {
            return $this->namespace;
        }
        //读取系统根目录的 composer.json 并解成一个数组
        $composer = json_decode(file_get_contents(base_path('composer.json')), true);

        foreach ((array) data_get($composer, 'autoload.psr-4') as $namespace => $path) {
            foreach ((array) $path as $pathChoice) {
                if (realpath(app_path()) == realpath(base_path() . '/' . $pathChoice)) {
                    return $this->namespace = $namespace;
                }
            }
        }

        throw new RuntimeException('Unable to detect application namespace.');
    }

}
