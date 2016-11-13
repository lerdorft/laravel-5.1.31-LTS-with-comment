<?php

// 设置程序执行开始时间

define('LARAVEL_START', microtime(true));

/*
  |--------------------------------------------------------------------------
  | Register The Composer Auto Loader
  |--------------------------------------------------------------------------
  |
  | Composer provides a convenient, automatically generated class loader
  | for our application. We just need to utilize it! We'll require it
  | into the script here so that we do not have to worry about the
  | loading of any our classes "manually". Feels great to relax.
  |
 */

// 加载自动加载类文件 /vendor/autoload.php

require __DIR__ . '/../vendor/autoload.php';

/*
  |--------------------------------------------------------------------------
  | Include The Compiled Class File
  |--------------------------------------------------------------------------
  |
  | To dramatically increase your application's performance, you may use a
  | compiled class file which contains all of the classes commonly used
  | by a request. The Artisan "optimize" is used to create this file.
  |
 */

// 通过 artisan 的 optimize 命令可以生成一个包含所用到的函数，类等的单一缓存文件
// 这样可以避免文件加载带来的IO开销

$compiledPath = __DIR__ . '/cache/compiled.php';

if (file_exists($compiledPath)) {
    require $compiledPath;
}
