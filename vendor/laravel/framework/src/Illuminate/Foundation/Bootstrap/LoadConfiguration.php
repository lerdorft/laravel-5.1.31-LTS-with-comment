<?php

namespace Illuminate\Foundation\Bootstrap;

use Illuminate\Config\Repository;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Config\Repository as RepositoryContract;

class LoadConfiguration
{
    /**
     * Bootstrap the given application.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return void
     */
    public function bootstrap(Application $app)
    {
        $items = [];

        // First we will see if we have a cache configuration file. If we do, we'll load
        // the configuration items from that file so that it is very quick. Otherwise
        // we will need to spin through every configuration file and load them all.
        
        // 检查是否缓存了配置文件，是的话就直接加载进来并设置配置为缓存文件内容
        // 这样速度会快些
        // 默认缓存文件地址：bootstrap/cache/config.php
        
        if (file_exists($cached = $app->getCachedConfigPath())) {
            $items = require $cached;

            $loadedFromCache = true;
        }
        
        // 将 $config 保存到容器的 $this->instances 中
        // 这么做以后就可以用这样的方式访问到 $config 了：
        // $app->config
        // 因为这种访问方式会先触发 $app 的魔术方法 __get()，方法里面是这样的：
        // return $this[$key];
        // 这也触发了容器实现的 ArrayAccess 方法 offsetGet()，方法里面是这样的：
        // return $this->make($key);
        // make() 方法会检查 $this->instances 中是否存在 $key
        // 有就直接返回，就是我们想要的 $config
        // $config 对象也实现了 ArrayAccess 的相关方法
        // 具体看下面设置默认时区的方式
        
        $app->instance('config', $config = new Repository($items));

        // Next we will spin through all of the configuration files in the configuration
        // directory and load each one into the repository. This will make all of the
        // options available to the developer for use in various parts of this app.
        // 如果无法从缓存文件中获取配置，那就一个个来吧
        // 这里注意一下 $config 是实例对象，是引用类型
        // 此处修改 $config 也将影响 $this->instances['config']
        
        if (! isset($loadedFromCache)) {
            $this->loadConfigurationFiles($app, $config);
        }
        
        // 实现了 ArrayAccess 的相关方法后才能这样读取配置：
        // $config['app.timezone']
        
        date_default_timezone_set($config['app.timezone']);

        mb_internal_encoding('UTF-8');
    }

    /**
     * Load the configuration items from all of the files.<br>
     * 获取配置后设置配置
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @param  \Illuminate\Contracts\Config\Repository  $repository
     * @return void
     */
    protected function loadConfigurationFiles(Application $app, RepositoryContract $repository)
    {
        foreach ($this->getConfigurationFiles($app) as $key => $path) {
            // Illuminate\Contracts\Config\Repository 类实现了 ArrayAccess 接口
            // 所以也可以这么设置配置：
            // $repository[$key] = require $path;
            $repository->set($key, require $path);
        }
    }

    /**
     * Get all of the configuration files for the application.<br>
     * 从 $app->configPath() 返回的文件夹路径中获取配置文件信息
     * 返回的数据结构：<br>
     * Array(<br>
     *     'app.test' => C:\laravel-5.1.31-LTS-with-comment\config\app\test.php<br>
     *     'app' => C:\laravel-5.1.31-LTS-with-comment\config\app.php<br>
     * )
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return array
     */
    protected function getConfigurationFiles(Application $app)
    {
        $files = [];
        
        // $app['path.config'] 的结果等同于 $app->configPath()
        // 详见 lluminate\Foundation\Application 的 bindPathsInContainer() 方法
        
        $configPath = realpath($app->configPath());
        
        // Symfony\Component\Finder\Finder 类实现了 IteratorAggregate 和 Countable 接口
        // 因此这里可以循环处理 Finder 实例对象
      
        foreach (Finder::create()->files()->name('*.php')->in($configPath) as $file) {
            $nesting = $this->getConfigurationNesting($file, $configPath);
            
            $files[$nesting.basename($file->getRealPath(), '.php')] = $file->getRealPath();
        }
        
        return $files;
    }

    /**
     * Get the configuration file nesting path.<br>
     * 根据配置文件的目录层级（相对于$app->configPath()），例如：a/b/c.php 返回字符串 a.b
     *
     * @param  \Symfony\Component\Finder\SplFileInfo  $file
     * @param  string  $configPath
     * @return string
     */
    protected function getConfigurationNesting(SplFileInfo $file, $configPath)
    {
        $directory = dirname($file->getRealPath());

        if ($tree = trim(str_replace($configPath, '', $directory), DIRECTORY_SEPARATOR)) {
            $tree = str_replace(DIRECTORY_SEPARATOR, '.', $tree).'.';
        }

        return $tree;
    }
}
