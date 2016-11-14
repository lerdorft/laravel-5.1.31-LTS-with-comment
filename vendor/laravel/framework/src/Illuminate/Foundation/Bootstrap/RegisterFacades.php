<?php

namespace Illuminate\Foundation\Bootstrap;

use Illuminate\Support\Facades\Facade;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Contracts\Foundation\Application;

class RegisterFacades
{
    /**
     * Bootstrap the given application.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return void
     */
    public function bootstrap(Application $app)
    {
        // 清空已解析的实例对象
        
        Facade::clearResolvedInstances();
        
        // 将容器实例对象保存在类的延迟静态绑定成员
        // 这样子类也可以用了（除非子类又重写了该成员）

        Facade::setFacadeApplication($app);
        
        // 获取 config/app.php 中的 aliases 配置
        // 然后注册一个 SPL 自动加载函数
        
        AliasLoader::getInstance($app->make('config')->get('app.aliases'))->register();
    }
}
