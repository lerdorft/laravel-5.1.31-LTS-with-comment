<?php

namespace Illuminate\Pipeline;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Pipeline\Pipeline as PipelineContract;

class Pipeline implements PipelineContract
{
    /**
     * The container implementation.
     *
     * @var \Illuminate\Contracts\Container\Container
     */
    protected $container;

    /**
     * The object being passed through the pipeline.
     *
     * @var mixed
     */
    protected $passable;

    /**
     * The array of class pipes.
     *
     * @var array
     */
    protected $pipes = [];

    /**
     * The method to call on each pipe.
     *
     * @var string
     */
    protected $method = 'handle';

    /**
     * Create a new class instance.
     *
     * @param  \Illuminate\Contracts\Container\Container  $container
     * @return void
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Set the object being sent through the pipeline.
     *
     * @param  mixed  $passable
     * @return $this
     */
    public function send($passable)
    {
        $this->passable = $passable;

        return $this;
    }

    /**
     * Set the array of pipes.<br>
     * 设置一堆需要经过的管道（中间件）
     *
     * @param  array|mixed  $pipes
     * @return $this
     */
    public function through($pipes)
    {
        $this->pipes = is_array($pipes) ? $pipes : func_get_args();

        return $this;
    }

    /**
     * Set the method to call on the pipes.<br>
     * 设置用于执行管道（中间件）的方法（默认是 handle）
     *
     * @param  string  $method
     * @return $this
     */
    public function via($method)
    {
        $this->method = $method;

        return $this;
    }

    /**
     * Run the pipeline with a final destination callback.<br>
     * 执行管道（中间件），并执行最终 $destination 闭包函数（一般是路由相关函数）
     *
     * @param  \Closure  $destination
     * @return mixed
     */
    public function then(Closure $destination)
    {
        
        // 当执行完 App\Http\Kernel::$middleware 保存的中间件后最后执行 $firstSlice 闭包函数
        // $firstSlice 即 Illuminate\Foundation\Http\Kernel::dispatchToRouter() 返回的闭包函数
        // $firstSlice 在 Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::handle() 中被调用
        // 该函数的作用是路由
        
        $firstSlice = $this->getInitialSlice($destination);
        
        // 反转 middleware 数组，利用 array_reduce() 控制 middleware 之间的栈调用的顺序
        
        $pipes = array_reverse($this->pipes);
        
        // array_reduce() 最后返回的是一个 Closure, 该闭包函数的原型如下：
        // function($passable) use ($stack, $pipe)
        // $stack： $pipes 中，当前 middleware 的上一个元素（middleware）产生的闭包函数
        // $pipe： 当前 middleware 的类名
        // 最后返回的是一个 Closure 结构如下：
        // 
        // function($passable) use (
        //     $stack: function($passable) use (
        //         $stack: function($passable) use (
        //             $stack: function($passable) use (
        //                 $stack: function($passable) use (
        //                     $stack: function($passable) use (
        //                         $stack: $firstSlice,
        //                         $pipe: '\App\Http\Middleware\VerifyCsrfToken'
        //                     ) {
        //                         $stack($passable);
        //                     },
        //                     $pipe: '\Illuminate\View\Middleware\ShareErrorsFromSession'
        //                 ) {
        //                     $stack($passable);
        //                 },
        //                 $pipe: '\Illuminate\Session\Middleware\StartSession'
        //             ) {
        //                 $stack($passable);
        //             },
        //             $pipe: '\Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse'
        //         ) {
        //             $stack($passable);
        //         },
        //         $pipe: '\App\Http\Middleware\EncryptCookies'
        //     ) {
        //         $stack($passable);
        //     },
        //     $pipe: '\Illuminate\Foundation\Http\Middleware\CheckForMaintenanceMode'
        // ) {
        //     $stack($passable);
        // }
        // 
        // 通过 middleware 的 handle() 类中的类似代码：
        // $next($request);
        // 也就是上面的 $stack($passable);
        // 实现 middleware 之间的栈调用
        
        return call_user_func(
            array_reduce($pipes, $this->getSlice(), $firstSlice), $this->passable
        );
    }

    /**
     * Get a Closure that represents a slice of the application onion.
     *
     * @return \Closure
     */
    protected function getSlice()
    {
        return function ($stack, $pipe) {
            return function ($passable) use ($stack, $pipe) {
                // If the pipe is an instance of a Closure, we will just call it directly but
                // otherwise we'll resolve the pipes out of the container and call it with
                // the appropriate method and arguments, returning the results back out.
                if ($pipe instanceof Closure) {
                    return call_user_func($pipe, $passable, $stack);
                } else {
                    list($name, $parameters) = $this->parsePipeString($pipe);

                    return call_user_func_array([$this->container->make($name), $this->method],
                                                array_merge([$passable, $stack], $parameters));
                }
            };
        };
    }

    /**
     * Get the initial slice to begin the stack call.
     *
     * @param  \Closure  $destination
     * @return \Closure
     */
    protected function getInitialSlice(Closure $destination)
    {
        return function ($passable) use ($destination) {
            return call_user_func($destination, $passable);
        };
    }

    /**
     * Parse full pipe string to get name and parameters.
     *
     * @param  string $pipe
     * @return array
     */
    protected function parsePipeString($pipe)
    {
        list($name, $parameters) = array_pad(explode(':', $pipe, 2), 2, []);

        if (is_string($parameters)) {
            $parameters = explode(',', $parameters);
        }

        return [$name, $parameters];
    }
}
