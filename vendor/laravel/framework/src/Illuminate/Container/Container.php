<?php

namespace Illuminate\Container;

use Closure;
use ArrayAccess;
use ReflectionClass;
use ReflectionMethod;
use ReflectionFunction;
use ReflectionParameter;
use InvalidArgumentException;
use Illuminate\Contracts\Container\Container as ContainerContract;
use Illuminate\Contracts\Container\BindingResolutionException as BindingResolutionContractException;

class Container implements ArrayAccess, ContainerContract
{

    /**
     * The current globally available container (if any).
     *
     * @var static
     */
    protected static $instance;

    /**
     * An array of the types that have been resolved.
     *
     * @var array
     */
    protected $resolved = [];

    /**
     * The container's bindings.
     *
     * @var array
     */
    protected $bindings = [];

    /**
     * The container's shared instances.
     *
     * @var array
     */
    protected $instances = [];

    /**
     * The registered type aliases.
     *
     * @var array
     */
    protected $aliases = [];

    /**
     * The extension closures for services.
     *
     * @var array
     */
    protected $extenders = [];

    /**
     * All of the registered tags.
     *
     * @var array
     */
    protected $tags = [];

    /**
     * The stack of concretions currently being built.
     *
     * @var array
     */
    protected $buildStack = [];

    /**
     * The contextual binding map.
     *
     * @var array
     */
    public $contextual = [];

    /**
     * All of the registered rebound callbacks.
     *
     * @var array
     */
    protected $reboundCallbacks = [];

    /**
     * All of the global resolving callbacks.
     *
     * @var array
     */
    protected $globalResolvingCallbacks = [];

    /**
     * All of the global after resolving callbacks.
     *
     * @var array
     */
    protected $globalAfterResolvingCallbacks = [];

    /**
     * All of the after resolving callbacks by class type.
     *
     * @var array
     */
    protected $resolvingCallbacks = [];

    /**
     * All of the after resolving callbacks by class type.
     *
     * @var array
     */
    protected $afterResolvingCallbacks = [];

    /**
     * Define a contextual binding.
     *
     * @param  string  $concrete
     * @return \Illuminate\Contracts\Container\ContextualBindingBuilder
     */
    public function when($concrete)
    {
        return new ContextualBindingBuilder($this, $concrete);
    }

    /**
     * Determine if the given abstract type has been bound.<br>
     * 判断在 $this->bindings，$this->instances,$this->aliases 中是否已绑定<br>
     * isset($this->bindings[$abstract]) ......
     * 
     * @param  string  $abstract
     * @return bool
     */
    public function bound($abstract)
    {
        return isset($this->bindings[$abstract]) || isset($this->instances[$abstract]) || $this->isAlias($abstract);
    }

    /**
     * Determine if the given abstract type has been resolved.<br>
     * 判断一个抽象是否被解析过（是否被 $this->make() 过）<br>
     * 返回 isset($this->resolved[$abstract]) || isset($this->instances[$abstract])
     *
     * @param  string  $abstract
     * @return bool
     */
    public function resolved($abstract)
    {
        if ($this->isAlias($abstract)) {
            $abstract = $this->getAlias($abstract);
        }

        return isset($this->resolved[$abstract]) || isset($this->instances[$abstract]);
    }

    /**
     * Determine if a given string is an alias.<br>
     * 判断字符串是否在 $this->aliases 中<br>
     * isset($this->aliases[$name])
     *
     * @param  string  $name
     * @return bool
     */
    public function isAlias($name)
    {
        return isset($this->aliases[$name]);
    }

    /**
     * Register a binding with the container.
     *
     * @param  string|array  $abstract
     * @param  \Closure|string|null  $concrete
     * @param  bool  $shared
     * @return void
     */
    public function bind($abstract, $concrete = null, $shared = false)
    {
        // If the given types are actually an array, we will assume an alias is being
        // defined and will grab this "real" abstract class name and register this
        // alias with the container so that it can be used as a shortcut for it.
        // 如果 $abstract 是数组，则
        // 提取第一个键作为 $abstract 的值
        // 提取第一个值作为 $alias 的值
        // 并且设置 $this->aliases[$alias] = $abstract

        if (is_array($abstract)) {
            // $abstract = key($abstract)
            // $alias = current($abstract)
            list($abstract, $alias) = $this->extractAlias($abstract);
            // $this->aliases[$alias] = $abstract;
            $this->alias($abstract, $alias);
        }

        // If no concrete type was given, we will simply set the concrete type to the
        // abstract type. This will allow concrete type to be registered as shared
        // without being forced to state their classes in both of the parameter.
        // 执行 unset($this->instances[$abstract], $this->aliases[$abstract]);

        $this->dropStaleInstances($abstract);

        // 如果 $concrete 为 NULL 使用 $abstract 代替

        if (is_null($concrete)) {
            $concrete = $abstract;
        }

        // If the factory is not a Closure, it means it is just a class name which is
        // bound into this container to the abstract type and we will just wrap it
        // up inside its own Closure to give us more convenience when extending.
        // $concrete 不是一个闭包函数的话需要包装一下

        if (!$concrete instanceof Closure) {
            $concrete = $this->getClosure($abstract, $concrete);
        }

        $this->bindings[$abstract] = compact('concrete', 'shared');

        // If the abstract type was already resolved in this container we'll fire the
        // rebound listener so that any objects which have already gotten resolved
        // can have their copy of the object updated via the listener callbacks.
        // 如果在 $this->make() 中解析过该抽象则重新绑定一下
        // return isset($this->resolved[$abstract]) || isset($this->instances[$abstract])

        if ($this->resolved($abstract)) {
            $this->rebound($abstract);
        }
    }

    /**
     * Get the Closure to be used when building a type.
     *
     * @param  string  $abstract
     * @param  string  $concrete
     * @return \Closure
     */
    protected function getClosure($abstract, $concrete)
    {
        return function ($c, $parameters = []) use ($abstract, $concrete) {
            $method = ($abstract == $concrete) ? 'build' : 'make';

            return $c->$method($concrete, $parameters);
        };
    }

    /**
     * Add a contextual binding to the container.
     *
     * @param  string  $concrete
     * @param  string  $abstract
     * @param  \Closure|string  $implementation
     * @return void
     */
    public function addContextualBinding($concrete, $abstract, $implementation)
    {
        $this->contextual[$concrete][$abstract] = $implementation;
    }

    /**
     * Register a binding if it hasn't already been registered.
     *
     * @param  string  $abstract
     * @param  \Closure|string|null  $concrete
     * @param  bool  $shared
     * @return void
     */
    public function bindIf($abstract, $concrete = null, $shared = false)
    {
        if (!$this->bound($abstract)) {
            $this->bind($abstract, $concrete, $shared);
        }
    }

    /**
     * Register a shared binding in the container.
     *
     * @param  string|array  $abstract
     * @param  \Closure|string|null  $concrete
     * @return void
     */
    public function singleton($abstract, $concrete = null)
    {
        $this->bind($abstract, $concrete, true);
    }

    /**
     * Wrap a Closure such that it is shared.
     *
     * @param  \Closure  $closure
     * @return \Closure
     */
    public function share(Closure $closure)
    {
        return function ($container) use ($closure) {
            // We'll simply declare a static variable within the Closures and if it has
            // not been set we will execute the given Closures to resolve this value
            // and return it back to these consumers of the method as an instance.
            static $object;

            if (is_null($object)) {
                $object = $closure($container);
            }

            return $object;
        };
    }

    /**
     * Bind a shared Closure into the container.
     *
     * @param  string    $abstract
     * @param  \Closure  $closure
     * @return void
     *
     * @deprecated since version 5.1. Use singleton instead.
     */
    public function bindShared($abstract, Closure $closure)
    {
        $this->bind($abstract, $this->share($closure), true);
    }

    /**
     * "Extend" an abstract type in the container.
     *
     * @param  string    $abstract
     * @param  \Closure  $closure
     * @return void
     *
     * @throws \InvalidArgumentException
     */
    public function extend($abstract, Closure $closure)
    {
        if (isset($this->instances[$abstract])) {
            $this->instances[$abstract] = $closure($this->instances[$abstract], $this);

            $this->rebound($abstract);
        } else {
            $this->extenders[$abstract][] = $closure;
        }
    }

    /**
     * Register an existing instance as shared in the container.
     *
     * @param  array|string  $abstract
     * @param  mixed   $instance
     * @return void
     */
    public function instance($abstract, $instance)
    {
        // First, we will extract the alias from the abstract if it is an array so we
        // are using the correct name when binding the type. If we get an alias it
        // will be registered with the container so we can resolve it out later.
        // 如果 $abstract 是数组，则
        // 提取第一个键作为 $abstract 的值
        // 提取第一个值作为 $alias 的值
        // 并且设置 $this->aliases[$alias] = $abstract

        if (is_array($abstract)) {
            // $abstract = key($abstract)
            // $alias = current($abstract)
            list($abstract, $alias) = $this->extractAlias($abstract);
            // $this->aliases[$alias] = $abstract;
            $this->alias($abstract, $alias);
        }
        // 取消别名
        // TODO：或许和避免 make() 中检查是否存在别名有关
        unset($this->aliases[$abstract]);

        // We'll check to determine if this type has been bound before, and if it has
        // we will fire the rebound callbacks registered with the container and it
        // can be updated with consuming classes that have gotten resolved here.
        // 实际执行 
        // isset($this->bindings[$abstract]) || isset($this->instances[$abstract]) || isset($this->aliases[$abstract]);
        $bound = $this->bound($abstract);

        $this->instances[$abstract] = $instance;

        //如果已绑定则重新绑定
        if ($bound) {
            $this->rebound($abstract);
        }
    }

    /**
     * Assign a set of tags to a given binding.
     *
     * @param  array|string  $abstracts
     * @param  array|mixed   ...$tags
     * @return void
     */
    public function tag($abstracts, $tags)
    {
        $tags = is_array($tags) ? $tags : array_slice(func_get_args(), 1);

        foreach ($tags as $tag) {
            if (!isset($this->tags[$tag])) {
                $this->tags[$tag] = [];
            }

            foreach ((array) $abstracts as $abstract) {
                $this->tags[$tag][] = $abstract;
            }
        }
    }

    /**
     * Resolve all of the bindings for a given tag.
     *
     * @param  string  $tag
     * @return array
     */
    public function tagged($tag)
    {
        $results = [];

        if (isset($this->tags[$tag])) {
            foreach ($this->tags[$tag] as $abstract) {
                $results[] = $this->make($abstract);
            }
        }

        return $results;
    }

    /**
     * Alias a type to a different name.<br>
     * 为抽象添加一个别名
     *
     * @param  string  $abstract
     * @param  string  $alias
     * @return void
     */
    public function alias($abstract, $alias)
    {
        $this->aliases[$alias] = $abstract;
    }

    /**
     * Extract the type and alias from a given definition.<br>
     * 分解一个数组，返回第一个元素的 [key, value] 数组
     *
     * @param  array  $definition
     * @return array
     */
    protected function extractAlias(array $definition)
    {
        return [key($definition), current($definition)];
    }

    /**
     * Bind a new callback to an abstract's rebind event.
     *
     * @param  string    $abstract
     * @param  \Closure  $callback
     * @return mixed
     */
    public function rebinding($abstract, Closure $callback)
    {
        $this->reboundCallbacks[$abstract][] = $callback;

        if ($this->bound($abstract)) {
            return $this->make($abstract);
        }
    }

    /**
     * Refresh an instance on the given target and method.
     *
     * @param  string  $abstract
     * @param  mixed   $target
     * @param  string  $method
     * @return mixed
     */
    public function refresh($abstract, $target, $method)
    {
        return $this->rebinding($abstract, function ($app, $instance) use ($target, $method) {
                    $target->{$method}($instance);
                });
    }

    /**
     * Fire the "rebound" callbacks for the given abstract type.
     *
     * @param  string  $abstract
     * @return void
     */
    protected function rebound($abstract)
    {
        $instance = $this->make($abstract);

        foreach ($this->getReboundCallbacks($abstract) as $callback) {
            call_user_func($callback, $this, $instance);
        }
    }

    /**
     * Get the rebound callbacks for a given type.
     *
     * @param  string  $abstract
     * @return array
     */
    protected function getReboundCallbacks($abstract)
    {
        if (isset($this->reboundCallbacks[$abstract])) {
            return $this->reboundCallbacks[$abstract];
        }

        return [];
    }

    /**
     * Wrap the given closure such that its dependencies will be injected when executed.
     *
     * @param  \Closure  $callback
     * @param  array  $parameters
     * @return \Closure
     */
    public function wrap(Closure $callback, array $parameters = [])
    {
        return function () use ($callback, $parameters) {
            return $this->call($callback, $parameters);
        };
    }

    /**
     * Call the given Closure / class@method and inject its dependencies.
     *
     * @param  callable|string  $callback
     * @param  array  $parameters
     * @param  string|null  $defaultMethod
     * @return mixed
     */
    public function call($callback, array $parameters = [], $defaultMethod = null)
    {
        if ($this->isCallableWithAtSign($callback) || $defaultMethod) {
            return $this->callClass($callback, $parameters, $defaultMethod);
        }

        // 获取方法的依赖（包含根据方法的形参列表设置的依赖和当前方法的 $parameters 实参）

        $dependencies = $this->getMethodDependencies($callback, $parameters);

        // 将依赖传入方法，并返回方法的执行结果

        return call_user_func_array($callback, $dependencies);
    }

    /**
     * 判断 $callback 是否是字符串，且符合 Class@method 格式
     * 
     * @param  mixed  $callback
     * @return bool
     */
    protected function isCallableWithAtSign($callback)
    {
        if (!is_string($callback)) {
            return false;
        }

        return strpos($callback, '@') !== false;
    }

    /**
     * Get all dependencies for a given method.
     *
     * @param  callable|string  $callback
     * @param  array  $parameters
     * @return array
     */
    protected function getMethodDependencies($callback, array $parameters = [])
    {
        $dependencies = [];

        // 通过反射获得方法的形参，循环处理每一个形参

        foreach ($this->getCallReflector($callback)->getParameters() as $key => $parameter) {
            $this->addDependencyForCallParameter($parameter, $parameters, $dependencies);
        }

        // 此时 $parameters 已经 unset 掉了和方法形参名称相同的键
        // $parameters 中剩余的元素作为额外的数据与依赖数据合并
        // 这里 $dependencies, $parameters 先后位置不能改变
        // 因为 $dependencies 中的元素顺序对应方法形参的顺序

        return array_merge($dependencies, $parameters);
    }

    /**
     * Get the proper reflection instance for the given callback.
     * 
     * @param  callable|string  $callback
     * @return \ReflectionFunctionAbstract
     */
    protected function getCallReflector($callback)
    {
        // 静态方法字符串格式处理，class::method 分割为数组

        if (is_string($callback) && strpos($callback, '::') !== false) {
            $callback = explode('::', $callback);
        }

        if (is_array($callback)) {
            return new ReflectionMethod($callback[0], $callback[1]);
        }

        return new ReflectionFunction($callback);
    }

    /**
     * 设置 $this->call() 调用中方法的依赖数据<br><br>
     * 下面 1,2,3 条互斥，只能同时满足一个条件：<br>
     * 1. 如果 $parameters 中含有与形参名称翔彤的键则使用对应值作为一项依赖<br>
     * 2. 如果形参有类型提示，则 $this->make() 该类型实例，并作为一项依赖<br>
     * 3. 如果形参有默认值，则使用默认值作为一项依赖<br><br>
     * 最后得到的依赖 $dependencies 与方法形参的顺序对应
     *
     * @param  \ReflectionParameter  $parameter
     * @param  array  $parameters   引用类型
     * @param  array  $dependencies 引用类型
     * @return mixed
     */
    protected function addDependencyForCallParameter(ReflectionParameter $parameter, array &$parameters, &$dependencies)
    {
        // 下面的逻辑可以按照如下代码分析
        // 
        // public function boot($a, ServiceProvider $b, $c = 3);
        // $this->call([$provider, 'boot'], ['a' => 1]);
        // 
        // 在调用 $this->call() 时可以传入方法需要的依赖
        // 即此处的 $parameters，也即上面代码中的 ['a' => 1]
        // 传入的依赖按照方法的形参名称对号入座（放入 $dependencies）

        if (array_key_exists($parameter->name, $parameters)) {
            $dependencies[] = $parameters[$parameter->name];

            unset($parameters[$parameter->name]);
        }

        // 当没有提供形参对应的依赖时，根据形参的类型提示实例化一个对象，并放入 $dependencies
        elseif ($parameter->getClass()) {
            $dependencies[] = $this->make($parameter->getClass()->name);
        }

        // 那些即没有提供对应依赖，也没有类型提示的形参会被检查是否设置了默认值
        // 如果有则使用默认值作为依赖放入 $dependencies
        elseif ($parameter->isDefaultValueAvailable()) {
            $dependencies[] = $parameter->getDefaultValue();
        }
    }

    /**
     * 调用 Class@method 字符串格式的方法<br><br>
     * 大致逻辑：<br>
     * 1. 分割 Class@method，分割后数组没有第二个元素（method）则尝试使用 $defaultMethod<br>
     * 2. 没传入有效的 $defaultMethod 则抛出异常<br>
     * 3. 最后调用 $this->call();
     *
     * @param  string  $target
     * @param  array  $parameters
     * @param  string|null  $defaultMethod
     * @return mixed
     */
    protected function callClass($target, array $parameters = [], $defaultMethod = null)
    {
        $segments = explode('@', $target);

        // If the listener has an @ sign, we will assume it is being used to delimit
        // the class name from the handle method name. This allows for handlers
        // to run multiple handler methods in a single class for convenience.
        $method = count($segments) == 2 ? $segments[1] : $defaultMethod;

        if (is_null($method)) {
            throw new InvalidArgumentException('Method not provided.');
        }

        return $this->call([$this->make($segments[0]), $method], $parameters);
    }

    /**
     * Resolve the given type from the container.
     *
     * @param  string  $abstract
     * @param  array   $parameters
     * @return mixed
     */
    public function make($abstract, array $parameters = [])
    {
        // 尝试获取别名对应的真实抽象名，失败则返回 $abstract 原值
        // 实际执行 isset($this->aliases[$abstract]) ? $this->aliases[$abstract] : $abstract;

        $abstract = $this->getAlias($abstract);

        // 单例控制

        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        // $this->instances 未保存具体实现的单例，则根据抽象名获取对应的具体实现
        // 当 $abstract 没有在 $this->contextual 和 $this->bindings 中没有绑定
        // 则可能返回的 $concrete 等于 $abstract
        // 当绑定了 "\" . $abstract 则返回带反斜杠打头的 $concrete
        
        $concrete = $this->getConcrete($abstract);

        // We're ready to instantiate an instance of the concrete type registered for
        // the binding. This will instantiate the types, as well as resolve any of
        // its "nested" dependencies recursively until all have gotten resolved.
        // $this->isBuildable 返回如下结果
        // $concrete === $abstract || $concrete instanceof Closure;
        // 因为 $this->getConcrete() 返回的 $concrete 可能等于 $abstract
        // 所以有必要判断 $concrete === $abstract

        if ($this->isBuildable($concrete, $abstract)) {
            $object = $this->build($concrete, $parameters);
        } else {
            $object = $this->make($concrete, $parameters);
        }

        // If we defined any extenders for this type, we'll need to spin through them
        // and apply them to the object being built. This allows for the extension
        // of services, such as changing configuration or decorating the object.

        foreach ($this->getExtenders($abstract) as $extender) {
            $object = $extender($object, $this);
        }

        // If the requested type is registered as a singleton we'll want to cache off
        // the instances in "memory" so we can return it later without creating an
        // entirely new instance of an object on each subsequent request for it.

        if ($this->isShared($abstract)) {
            $this->instances[$abstract] = $object;
        }

        $this->fireResolvingCallbacks($abstract, $object);

        $this->resolved[$abstract] = true;

        return $object;
    }

    /**
     * Get the concrete type for a given abstract.<br>
     * 获得一个抽象的具体实现
     *
     * @param  string  $abstract
     * @return mixed   $concrete
     */
    protected function getConcrete($abstract)
    {
        // 实际判断条件 
        // ! is_null($concrete = $this->contextual[end($this->buildStack)][$abstract])
        if (!is_null($concrete = $this->getContextualConcrete($abstract))) {
            return $concrete;
        }

        // If we don't have a registered resolver or concrete for the type, we'll just
        // assume each type is a concrete name and will attempt to resolve it as is
        // since the container should be able to resolve concretes automatically.
        // 如果 $this->bindings 中不存在键 $abstract 对应的具体实现内容
        // 就先看下 $abstract 是否是遗漏了打头的 "\"，加上后再检查键是否存在
        // 存在的话 $abstract 就更新为 "\" 打头
        // 返回处理（反斜杠打头）或没处理过的（原本就反斜杠打头）$abstract

        if (!isset($this->bindings[$abstract])) {
            if ($this->missingLeadingSlash($abstract) &&
                    isset($this->bindings['\\' . $abstract])) {
                $abstract = '\\' . $abstract;
            }

            return $abstract;
        }
        // 返回 $this->bindings 中键 $abstract 对应的具体实现内容
        return $this->bindings[$abstract]['concrete'];
    }

    /**
     * Get the contextual concrete binding for the given abstract.
     *
     * @param  string  $abstract
     * @return string|null
     */
    protected function getContextualConcrete($abstract)
    {
        if (isset($this->contextual[end($this->buildStack)][$abstract])) {
            return $this->contextual[end($this->buildStack)][$abstract];
        }
    }

    /**
     * Determine if the given abstract has a leading slash.<br>
     * 判断抽象名是否反斜杠打头
     * 
     * @param  string  $abstract
     * @return bool
     */
    protected function missingLeadingSlash($abstract)
    {
        return is_string($abstract) && strpos($abstract, '\\') !== 0;
    }

    /**
     * Get the extender callbacks for a given type.
     *
     * @param  string  $abstract
     * @return array
     */
    protected function getExtenders($abstract)
    {
        if (isset($this->extenders[$abstract])) {
            return $this->extenders[$abstract];
        }

        return [];
    }

    /**
     * Instantiate a concrete instance of the given type.<br>
     * 实例化一个具体实现的对象<br>
     * 如果具体实现不是一个闭包则利用反射实现
     * 
     * @param  string  $concrete
     * @param  array   $parameters
     * @return mixed
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function build($concrete, array $parameters = [])
    {
        // If the concrete type is actually a Closure, we will just execute it and
        // hand back the results of the functions, which allows functions to be
        // used as resolvers for more fine-tuned resolution of these objects.
        if ($concrete instanceof Closure) {
            return $concrete($this, $parameters);
        }

        // 如果具体实现 $concrete 不是一个闭包函数的话，通常会被设置成和 $abstract 一样的值
        // $abstract 要求是完整限定的类名，别名需要通过 $this->getAlias($abstract) 获取真实的抽象名/类名

        $reflector = new ReflectionClass($concrete);

        // If the type is not instantiable, the developer is attempting to resolve
        // an abstract type such as an Interface of Abstract Class and there is
        // no binding registered for the abstractions so we need to bail out.
        // 检查类是否可以被实例化
        // 不能实例化的类可能是抽象类或者接口等
        // 遇此情况退出

        if (!$reflector->isInstantiable()) {
            $message = "Target [$concrete] is not instantiable.";

            throw new BindingResolutionContractException($message);
        }

        $this->buildStack[] = $concrete;

        // 获取构造函数，没有则得到 null

        $constructor = $reflector->getConstructor();

        // If there are no constructors, that means there are no dependencies then
        // we can just resolve the instances of the objects right away, without
        // resolving any other types or dependencies out of these containers.
        // 没有构造函数就直接 new 了，并且返回实例对象

        if (is_null($constructor)) {
            array_pop($this->buildStack);

            return new $concrete;
        }

        $dependencies = $constructor->getParameters();

        // Once we have all the constructor's parameters we can create each of the
        // dependency instances and then use the reflection instances to make a
        // new instance of this class, injecting the created dependencies in.
        $parameters = $this->keyParametersByArgument(
                $dependencies, $parameters
        );

        $instances = $this->getDependencies(
                $dependencies, $parameters
        );

        array_pop($this->buildStack);

        return $reflector->newInstanceArgs($instances);
    }

    /**
     * Resolve all of the dependencies from the ReflectionParameters.
     *
     * @param  array  $parameters
     * @param  array  $primitives
     * @return array
     */
    protected function getDependencies(array $parameters, array $primitives = [])
    {
        $dependencies = [];

        foreach ($parameters as $parameter) {
            $dependency = $parameter->getClass();

            // If the class is null, it means the dependency is a string or some other
            // primitive type which we can not resolve since it is not a class and
            // we will just bomb out with an error since we have no-where to go.
            if (array_key_exists($parameter->name, $primitives)) {
                $dependencies[] = $primitives[$parameter->name];
            } elseif (is_null($dependency)) {
                $dependencies[] = $this->resolveNonClass($parameter);
            } else {
                $dependencies[] = $this->resolveClass($parameter);
            }
        }

        return (array) $dependencies;
    }

    /**
     * Resolve a non-class hinted dependency.
     *
     * @param  \ReflectionParameter  $parameter
     * @return mixed
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    protected function resolveNonClass(ReflectionParameter $parameter)
    {
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        $message = "Unresolvable dependency resolving [$parameter] in class {$parameter->getDeclaringClass()->getName()}";

        throw new BindingResolutionContractException($message);
    }

    /**
     * Resolve a class based dependency from the container.
     *
     * @param  \ReflectionParameter  $parameter
     * @return mixed
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    protected function resolveClass(ReflectionParameter $parameter)
    {
        try {
            return $this->make($parameter->getClass()->name);
        }

        // If we can not resolve the class instance, we will check to see if the value
        // is optional, and if it is we will return the optional parameter value as
        // the value of the dependency, similarly to how we do this with scalars.
        catch (BindingResolutionContractException $e) {
            if ($parameter->isOptional()) {
                return $parameter->getDefaultValue();
            }

            throw $e;
        }
    }

    /**
     * If extra parameters are passed by numeric ID, rekey them by argument name.<br>
     * 将 $parameters 中的数字键改用 $dependencies 中对应数字键的值的 name 属性替换<br>
     * $dependencies 是 ReflectionParameter 对象数组，结构如下：<br>
     * Array ( [0] => ReflectionParameter Object ( [name] => a ) )
     * 
     * @param  array  $dependencies
     * @param  array  $parameters
     * @return array
     */
    protected function keyParametersByArgument(array $dependencies, array $parameters)
    {
        // 假设 $dependencies 和 $parameters 分别为：
        // $dependencies: Array ( [0] => ReflectionParameter Object ( [name] => abc ) )
        // $parameters: Array ( [0] => new_name )
        // 处理后 $parameters： Array ( [abc] => new_name )
        
        foreach ($parameters as $key => $value) {
            if (is_numeric($key)) {
                unset($parameters[$key]);

                $parameters[$dependencies[$key]->name] = $value;
            }
        }

        return $parameters;
    }

    /**
     * Register a new resolving callback.
     *
     * @param  string    $abstract
     * @param  \Closure|null  $callback
     * @return void
     */
    public function resolving($abstract, Closure $callback = null)
    {
        if ($callback === null && $abstract instanceof Closure) {
            $this->resolvingCallback($abstract);
        } else {
            $this->resolvingCallbacks[$abstract][] = $callback;
        }
    }

    /**
     * Register a new after resolving callback for all types.
     *
     * @param  string   $abstract
     * @param  \Closure|null $callback
     * @return void
     */
    public function afterResolving($abstract, Closure $callback = null)
    {
        if ($abstract instanceof Closure && $callback === null) {
            $this->afterResolvingCallback($abstract);
        } else {
            $this->afterResolvingCallbacks[$abstract][] = $callback;
        }
    }

    /**
     * Register a new resolving callback by type of its first argument.
     *
     * @param  \Closure  $callback
     * @return void
     */
    protected function resolvingCallback(Closure $callback)
    {
        $abstract = $this->getFunctionHint($callback);

        if ($abstract) {
            $this->resolvingCallbacks[$abstract][] = $callback;
        } else {
            $this->globalResolvingCallbacks[] = $callback;
        }
    }

    /**
     * Register a new after resolving callback by type of its first argument.
     *
     * @param  \Closure  $callback
     * @return void
     */
    protected function afterResolvingCallback(Closure $callback)
    {
        $abstract = $this->getFunctionHint($callback);

        if ($abstract) {
            $this->afterResolvingCallbacks[$abstract][] = $callback;
        } else {
            $this->globalAfterResolvingCallbacks[] = $callback;
        }
    }

    /**
     * Get the type hint for this closure's first argument.<br>
     * 获取函数第一个参数的类型提示
     * 
     * @param  \Closure  $callback
     * @return mixed
     */
    protected function getFunctionHint(Closure $callback)
    {
        $function = new ReflectionFunction($callback);

        if ($function->getNumberOfParameters() == 0) {
            return;
        }

        $expected = $function->getParameters()[0];

        if (!$expected->getClass()) {
            return;
        }

        return $expected->getClass()->name;
    }

    /**
     * Fire all of the resolving callbacks.
     *
     * @param  string  $abstract
     * @param  mixed   $object
     * @return void
     */
    protected function fireResolvingCallbacks($abstract, $object)
    {
        $this->fireCallbackArray($object, $this->globalResolvingCallbacks);

        $this->fireCallbackArray(
                $object, $this->getCallbacksForType(
                        $abstract, $object, $this->resolvingCallbacks
                )
        );

        $this->fireCallbackArray($object, $this->globalAfterResolvingCallbacks);

        $this->fireCallbackArray(
                $object, $this->getCallbacksForType(
                        $abstract, $object, $this->afterResolvingCallbacks
                )
        );
    }

    /**
     * Get all callbacks for a given type.
     *
     * @param  string  $abstract
     * @param  object  $object
     * @param  array   $callbacksPerType
     *
     * @return array
     */
    protected function getCallbacksForType($abstract, $object, array $callbacksPerType)
    {
        $results = [];

        foreach ($callbacksPerType as $type => $callbacks) {
            if ($type === $abstract || $object instanceof $type) {
                $results = array_merge($results, $callbacks);
            }
        }

        return $results;
    }

    /**
     * Fire an array of callbacks with an object.
     *
     * @param  mixed  $object
     * @param  array  $callbacks
     * @return void
     */
    protected function fireCallbackArray($object, array $callbacks)
    {
        foreach ($callbacks as $callback) {
            $callback($object, $this);
        }
    }

    /**
     * Determine if a given type is shared.<br>
     * 判断一个抽象是否是 shared<br>
     * $this->bindings[$abstract]['shared'] 为真，或者<br>
     * $this->instances 存在键 $abstract 就认为是 shared
     * 
     * @param  string  $abstract
     * @return bool
     */
    public function isShared($abstract)
    {
        if (isset($this->bindings[$abstract]['shared'])) {
            $shared = $this->bindings[$abstract]['shared'];
        } else {
            $shared = false;
        }

        // $this->make() 中只有当 $this->bindings[$abstract]['shared'] 为 true
        // 才将具体实现的实例对象放入 $this->instances[$abstract] 中
        // 因此这里可以判断 $this->instances 是否存在键 $abstract 或者
        // $this->bindings[$abstract]['shared'] 的值

        return isset($this->instances[$abstract]) || $shared === true;
    }

    /**
     * Determine if the given concrete is buildable.<br>
     * 判断具体实现是否可以 build<br>
     * 返回 $concrete === $abstract || $concrete instanceof Closure
     *
     * @param  mixed   $concrete
     * @param  string  $abstract
     * @return bool
     */
    protected function isBuildable($concrete, $abstract)
    {
        return $concrete === $abstract || $concrete instanceof Closure;
    }

    /**
     * Get the alias for an abstract if available.<br>
     * 尝试从 $this->aliases 中获取指定键的元素，不存在则返回传入的键<br>
     * 用于获取别名对应的真实抽象名
     * 
     * @param  string  $abstract
     * @return string
     */
    protected function getAlias($abstract)
    {
        return isset($this->aliases[$abstract]) ? $this->aliases[$abstract] : $abstract;
    }

    /**
     * Get the container's bindings.<br>
     * 返回 $this->bindings
     * 
     * @return array
     */
    public function getBindings()
    {
        return $this->bindings;
    }

    /**
     * Drop all of the stale instances and aliases.<br>
     * 从 $this->instances, $this->aliases 中删除指定键
     * 
     * @param  string  $abstract
     * @return void
     */
    protected function dropStaleInstances($abstract)
    {
        unset($this->instances[$abstract], $this->aliases[$abstract]);
    }

    /**
     * Remove a resolved instance from the instance cache.<br>
     * 从 $this->instances 中删除指定键
     * 
     * @param  string  $abstract
     * @return void
     */
    public function forgetInstance($abstract)
    {
        unset($this->instances[$abstract]);
    }

    /**
     * Clear all of the instances from the container.<br>
     * 清空 $this->instances
     * 
     * @return void
     */
    public function forgetInstances()
    {
        $this->instances = [];
    }

    /**
     * Flush the container of all bindings and resolved instances.<br>
     * 清空 $this->aliases, $this->resolved, $this->bindings, $this->instances
     * 
     * @return void
     */
    public function flush()
    {
        $this->aliases = [];
        $this->resolved = [];
        $this->bindings = [];
        $this->instances = [];
    }

    /**
     * Set the globally available instance of the container.
     *
     * @return static
     */
    public static function getInstance()
    {
        return static::$instance;
    }

    /**
     * Set the shared instance of the container.
     *
     * @param  \Illuminate\Contracts\Container\Container  $container
     * @return void
     */
    public static function setInstance(ContainerContract $container)
    {
        static::$instance = $container;
    }

    /**
     * Determine if a given offset exists.<br>
     * PHP 5 >= 5.0.0, PHP 7 实现的 ArrayAccess interface 中的方法
     * 
     * @param  string  $key
     * @return bool
     */
    public function offsetExists($key)
    {
        return isset($this->bindings[$key]);
    }

    /**
     * Get the value at a given offset.<br>
     * PHP 5 >= 5.0.0, PHP 7 实现的 ArrayAccess interface 中的方法<br>
     * 实际执行 $this->make($key)
     * 
     * @param  string  $key
     * @return mixed
     */
    public function offsetGet($key)
    {
        return $this->make($key);
    }

    /**
     * Set the value at a given offset.<br>
     * PHP 5 >= 5.0.0, PHP 7 实现的 ArrayAccess interface 中的方法<br>
     * 如果 $value 不是闭包则包装成只返回 $value 的闭包函数<br>
     * 最后执行 $this->bind($key, $value)
     * 
     * @param  string  $key
     * @param  mixed   $value
     * @return void
     */
    public function offsetSet($key, $value)
    {
        // If the value is not a Closure, we will make it one. This simply gives
        // more "drop-in" replacement functionality for the Pimple which this
        // container's simplest functions are base modeled and built after.
        if (!$value instanceof Closure) {
            $value = function () use ($value) {
                return $value;
            };
        }

        $this->bind($key, $value);
    }

    /**
     * PHP 5 >= 5.0.0, PHP 7 实现的 ArrayAccess interface 中的方法<br>
     * unset($this->bindings[$key], $this->instances[$key], $this->resolved[$key])
     * 
     * @param  string  $key
     * @return void
     */
    public function offsetUnset($key)
    {
        unset($this->bindings[$key], $this->instances[$key], $this->resolved[$key]);
    }

    /**
     * Dynamically access container services.<br>
     * 获取对象属性，实际执行 $this->make($key)
     *
     * @param  string  $key
     * @return mixed
     */
    public function __get($key)
    {
        return $this[$key];
    }

    /**
     * Dynamically set container services.<br>
     * 设置对象属性，实际执行 $this->offsetSet($key, $value)
     *
     * @param  string  $key
     * @param  mixed   $value
     * @return void
     */
    public function __set($key, $value)
    {
        $this[$key] = $value;
    }

}
