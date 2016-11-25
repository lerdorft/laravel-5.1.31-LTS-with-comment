<?php

namespace Illuminate\Foundation\Support\Providers;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Routing\UrlGenerator;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The controller namespace for the application.
     *
     * @var string|null
     */
    protected $namespace;

    /**
     * Bootstrap any application services.
     *
     * @param  \Illuminate\Routing\Router  $router
     * @return void
     */
    public function boot(Router $router)
    {
        $this->setRootControllerNamespace();
        
        // 如果路由配置文件已缓存，则加载缓存文件（默认为：bootstrap/cache/routes.php）
        
        if ($this->app->routesAreCached()) {
            $this->loadCachedRoutes();
        } else {
            
            // 加载路由配置文件（默认为：app/Http/routes.php）
            
            $this->loadRoutes();
            
            // 添加（至 $this->app->bootedCallbacks）系统启动后的钩子函数
            // 如果系统已启动，则该函数会立即被执行
            
            $this->app->booted(function () use ($router) {
                $router->getRoutes()->refreshNameLookups();
            });
        }
    }

    /**
     * Set the root controller namespace for the application.
     *
     * @return void
     */
    protected function setRootControllerNamespace()
    {
        if (is_null($this->namespace)) {
            return;
        }

        $this->app[UrlGenerator::class]->setRootControllerNamespace($this->namespace);
    }

    /**
     * Load the cached routes for the application.<br>
     * 添加（至 $this->app->bootedCallbacks）系统启动后的钩子函数<br>
     * 如果系统已启动，则该函数会立即被执行<br>
     * 该函数加载路由配置的缓存文件
     *
     * @return void
     */
    protected function loadCachedRoutes()
    {
        $this->app->booted(function () {
            require $this->app->getCachedRoutesPath();
        });
    }

    /**
     * Load the application routes.
     *
     * @return void
     */
    protected function loadRoutes()
    {
        $this->app->call([$this, 'map']);
    }

    /**
     * Load the standard routes file for the application.
     *
     * @param  string  $path
     * @return mixed
     */
    protected function loadRoutesFrom($path)
    {
        $router = $this->app->make(Router::class);

        if (is_null($this->namespace)) {
            return require $path;
        }

        $router->group(['namespace' => $this->namespace], function (Router $router) use ($path) {
            require $path;
        });
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Pass dynamic methods onto the router instance.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return call_user_func_array([$this->app->make(Router::class), $method], $parameters);
    }
}
