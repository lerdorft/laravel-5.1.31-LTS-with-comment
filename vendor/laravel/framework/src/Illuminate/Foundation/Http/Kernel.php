<?php

namespace Illuminate\Foundation\Http;

use Exception;
use Throwable;
use Illuminate\Routing\Router;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Facade;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as KernelContract;
use Symfony\Component\Debug\Exception\FatalThrowableError;

class Kernel implements KernelContract
{
    /**
     * The application implementation.
     *
     * @var \Illuminate\Contracts\Foundation\Application
     */
    protected $app;

    /**
     * The router instance.
     *
     * @var \Illuminate\Routing\Router
     */
    protected $router;

    /**
     * The bootstrap classes for the application.
     *
     * @var array
     */
    protected $bootstrappers = [
        'Illuminate\Foundation\Bootstrap\DetectEnvironment', // 系统运行环境监测
        'Illuminate\Foundation\Bootstrap\LoadConfiguration', // 加载配置
        'Illuminate\Foundation\Bootstrap\ConfigureLogging',  // 日志设置
        'Illuminate\Foundation\Bootstrap\HandleExceptions',  // 错误处理
        'Illuminate\Foundation\Bootstrap\RegisterFacades',   // Facade 门面设置
        'Illuminate\Foundation\Bootstrap\RegisterProviders', // 注册服务
        'Illuminate\Foundation\Bootstrap\BootProviders',     // 启动系统
    ];

    /**
     * The application's middleware stack.
     *
     * @var array
     */
    protected $middleware = [];

    /**
     * The application's route middleware.
     *
     * @var array
     */
    protected $routeMiddleware = [];

    /**
     * Create a new HTTP kernel instance.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @param  \Illuminate\Routing\Router  $router
     * @return void
     */
    public function __construct(Application $app, Router $router)
    {
        $this->app = $app;
        $this->router = $router;
        
        // $router->middleware[$key] = $middleware;
        
        foreach ($this->routeMiddleware as $key => $middleware) {
            $router->middleware($key, $middleware);
        }
    }

    /**
     * 处理 HTTP 请求
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function handle($request)
    {
        try {
            $request->enableHttpMethodParameterOverride();

            $response = $this->sendRequestThroughRouter($request);
        } catch (Exception $e) {
            $this->reportException($e);

            $response = $this->renderException($request, $e);
        } catch (Throwable $e) {
            $e = new FatalThrowableError($e);

            $this->reportException($e);

            $response = $this->renderException($request, $e);
        }

        $this->app['events']->fire('kernel.handled', [$request, $response]);

        return $response;
    }

    /**
     * Send the given request through the middleware / router.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    protected function sendRequestThroughRouter($request)
    {
        $this->app->instance('request', $request);

        Facade::clearResolvedInstance('request');
        
        // 启动一些启动器，诸如异常处理，配置，日志，Facade，运行环境监测等
        
        $this->bootstrap();
        
        // 管道方式执行中间件，然后路由分发
        
        return (new Pipeline($this->app))
                    ->send($request)
                    ->through($this->app->shouldSkipMiddleware() ? [] : $this->middleware)
                    ->then($this->dispatchToRouter());
    }

    /**
     * Call the terminate method on any terminable middleware.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Http\Response  $response
     * @return void
     */
    public function terminate($request, $response)
    {
        $middlewares = $this->app->shouldSkipMiddleware() ? [] : array_merge(
            $this->gatherRouteMiddlewares($request),
            $this->middleware
        );
        
        // 调用 $middlewares 中每个中间件的 terminate() 方法

        foreach ($middlewares as $middleware) {
            list($name, $parameters) = $this->parseMiddleware($middleware);

            $instance = $this->app->make($name);

            if (method_exists($instance, 'terminate')) {
                $instance->terminate($request, $response);
            }
        }
        
        // 调用容器的 terminate() 方法
        // 该方法中会调用 $this->app->terminatingCallbacks 中记录的类的方法
        
        $this->app->terminate();
    }

    /**
     * Gather the route middleware for the given request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    protected function gatherRouteMiddlewares($request)
    {
        if ($route = $request->route()) {
            return $this->router->gatherRouteMiddlewares($route);
        }

        return [];
    }

    /**
     * 解析一个中间件字符串，从而获得中间件名称和中间件参数<br>
     * 返回的数据格式：["name", ["param1", "param2"]]
     * 
     * @param  string  $middleware
     * @return array
     */
    protected function parseMiddleware($middleware)
    {
        // 用:分割 $middleware，最多分割成2段
        // 不足2段则用空数组（$parameters 也就为空数组了）补齐，
        // 换句话说这里用这种方式设置了 $parameters 的默认值为空数组 
        
        list($name, $parameters) = array_pad(explode(':', $middleware, 2), 2, []);
        
        // 当 $middleware 无法分割成2段时 $parameters 就是空数组
        // 反之则是字符串，这里再将字符串 $parameters 分割成数组
        
        if (is_string($parameters)) {
            $parameters = explode(',', $parameters);
        }
        
        // 返回的数据格式：["name", ["param1", "param2"]]
        
        return [$name, $parameters];
    }

    /**
     * 向 $this->middleware 数组开头添加一个中间件字符串（如果不存在）<br>
     * 字符串格式： A\B\MiddleWare:param1,param2<br>
     * 格式中:右侧为参数，可选（目前为止没发现有什么卵用）
     *
     * @param  string  $middleware
     * @return $this
     */
    public function prependMiddleware($middleware)
    {
        if (array_search($middleware, $this->middleware) === false) {
            array_unshift($this->middleware, $middleware);
        }

        return $this;
    }

    /**
     * 向 $this->middleware 数组末尾添加一个中间件名称（如果不存在）<br>
     * 字符串格式： A\B\MiddleWare:param1,param2<br>
     * 格式中:右侧为参数，可选（目前为止没发现有什么卵用）
     *
     * @param  string  $middleware
     * @return $this
     */
    public function pushMiddleware($middleware)
    {
        if (array_search($middleware, $this->middleware) === false) {
            $this->middleware[] = $middleware;
        }

        return $this;
    }

    /**
     * 调用 $this->app->bootstrapWith() 运行 $this->bootstrappers 中记录的启动程序
     *
     * @return void
     */
    public function bootstrap()
    {
        if (! $this->app->hasBeenBootstrapped()) {
            $this->app->bootstrapWith($this->bootstrappers());
        }
    }

    /**
     * Get the route dispatcher callback.<br>
     * 当请求经过管道功能执行中间件过后，下一步就是进入路由<br>
     * 该函数就是路由入口
     *
     * @return \Closure
     */
    protected function dispatchToRouter()
    {
        return function ($request) {
            $this->app->instance('request', $request);

            return $this->router->dispatch($request);
        };
    }

    /**
     * 判断 $this->middleware 数组中是否有某个中间件名称
     *
     * @param  string  $middleware
     * @return bool
     */
    public function hasMiddleware($middleware)
    {
        return in_array($middleware, $this->middleware);
    }

    /**
     * 返回 $this->bootstrappers 中记录的启动器名称
     *
     * @return array
     */
    protected function bootstrappers()
    {
        return $this->bootstrappers;
    }

    /**
     * Report the exception to the exception handler.
     *
     * @param  \Exception  $e
     * @return void
     */
    protected function reportException(Exception $e)
    {
        $this->app['Illuminate\Contracts\Debug\ExceptionHandler']->report($e);
    }

    /**
     * Render the exception to a response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Exception  $e
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function renderException($request, Exception $e)
    {
        return $this->app['Illuminate\Contracts\Debug\ExceptionHandler']->render($request, $e);
    }

    /**
     * 获取容器实例对象
     *
     * @return \Illuminate\Contracts\Foundation\Application
     */
    public function getApplication()
    {
        return $this->app;
    }
}
