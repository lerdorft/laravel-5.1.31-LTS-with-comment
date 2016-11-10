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
        
        // 检测系统运行环境，默认值 production，即生产环境
        
        $app->detectEnvironment(function () {
            return env('APP_ENV', 'production');
        });
    }
}
