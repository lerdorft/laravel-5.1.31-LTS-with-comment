<?php

namespace Illuminate\Foundation\Bootstrap;

use Dotenv;
use InvalidArgumentException;
use Illuminate\Contracts\Foundation\Application;

class DetectEnvironment
{
    /**
     * Bootstrap the given application.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return void
     */
    public function bootstrap(Application $app)
    {
        // 从 $app->environmentPath() 目录下的
        // $app->environmentFile() 配置文件中读取并设置环境变量
        
        try {
            Dotenv::load($app->environmentPath(), $app->environmentFile());
        } catch (InvalidArgumentException $e) {
            //
        }
        
<<<<<<< HEAD
        // 设置容器的 $this['env'] 值，默认为 production
=======
>>>>>>> 1749bc95df4a1a6e2960ab7b9e29a88df2a11bed
        
        $app->detectEnvironment(function () {
            return env('APP_ENV', 'production');
        });
    }
}
