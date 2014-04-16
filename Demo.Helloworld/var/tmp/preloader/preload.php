<?php
namespace Ray\Di {
/**
 * This file is part of the Ray package.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

/**
 * Interface for instance container.
 */
interface InstanceInterface
{
    /**
     * Get instance from container / injector
     *
     * @param string $class The class to instantiate.
     *
     * @return object
     */
    public function getInstance($class);
}
}
namespace Ray\Di {
/**
 * This file is part of the Ray package.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use Doctrine\Common\Cache\Cache;

/**
 * Defines the interface for dependency injector.
 */
interface InjectorInterface extends InstanceInterface
{
    /**
     * Return container
     *
     * @return Container
     */
    public function getContainer();

    /**
     * Return module
     *
     * @return AbstractModule
     */
    public function getModule();

    /**
     * Set module
     *
     * @param AbstractModule $module
     *
     * @return self
     */
    public function setModule(AbstractModule $module);

    /**
     * Return injection logger
     *
     * @return LoggerInterface
     */
    public function getLogger();
}
}
namespace Ray\Di {
/**
 * This file is part of the Ray package.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use Aura\Di\ContainerInterface;
use Aura\Di\Lazy;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\AnnotationRegistry;
use Doctrine\Common\Annotations\CachedReader;
use Doctrine\Common\Cache\Cache;
use LogicException;
use Ray\Aop\Bind;
use Ray\Aop\BindInterface;
use Ray\Aop\Compiler;
use Ray\Aop\CompilerInterface;
use Ray\Di\Exception;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use SplObjectStorage;
use ArrayObject;
use PHPParser_PrettyPrinter_Default;
use Serializable;
use Ray\Di\Di\Inject;

/**
 * Dependency Injector
 */
class Injector implements InjectorInterface, \Serializable
{
    /**
     * Config
     *
     * @var Config
     */
    protected $config;

    /**
     * Container
     *
     * @var \Ray\Di\Container
     */
    protected $container;

    /**
     * Binding module
     *
     * @var AbstractModule
     */
    protected $module;

    /**
     * Pre-destroy objects
     *
     * @var SplObjectStorage
     */
    private $preDestroyObjects;

    /**
     * Logger
     *
     * @var LoggerInterface
     */
    private $logger;

    /**
     * Compiler(Aspect Weaver)
     *
     * @var Compiler
     */
    private $compiler;

    /**
     * Target classes
     *
     * @var array
     */
    private $classes = [];

    /**
     * @param ContainerInterface $container
     * @param AbstractModule     $module
     * @param BindInterface      $bind
     * @param CompilerInterface  $compiler
     * @param LoggerInterface    $logger
     *
     * @Inject
     */
    public function __construct(
        ContainerInterface $container,
        AbstractModule $module,
        BindInterface $bind,
        CompilerInterface $compiler,
        LoggerInterface $logger = null
    ) {
        $this->container = $container;
        $this->module = $module;
        $this->bind = $bind;
        $this->compiler = $compiler;
        $this->logger = $logger;

        $this->preDestroyObjects = new SplObjectStorage;
        $this->config = $container->getForge()->getConfig();
        $this->module->activate($this);

        AnnotationRegistry::registerAutoloadNamespace('Ray\Di\Di', dirname(dirname(__DIR__)));
    }

    public function __destruct()
    {
        $this->notifyPreShutdown();
    }

    /**
     * {@inheritdoc}
     */
    public static function create(array $modules = [], Cache $cache = null)
    {
        $annotationReader = ($cache instanceof Cache) ? new CachedReader(new AnnotationReader, $cache) : new AnnotationReader;
        $injector = new self(
            new Container(new Forge(new Config(new Annotation(new Definition, $annotationReader)))),
            new EmptyModule,
            new Bind,
            new Compiler(
                sys_get_temp_dir(),
                new PHPParser_PrettyPrinter_Default
            ),
            new Logger
        );

        if (count($modules) > 0) {
            $module = array_shift($modules);
            foreach ($modules as $extraModule) {
                /* @var $module AbstractModule */
                $module->install($extraModule);
            }
            $injector->setModule($module);
        }

        return $injector;
    }

    /**
     * {@inheritdoc}
     */
    public function getModule()
    {
        return $this->module;
    }

    /**
     * {@inheritdoc}
     */
    public function setModule(AbstractModule $module)
    {
        $module->activate($this);
        $this->module = $module;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getContainer()
    {
        return $this->container;
    }

    /**
     * {@inheritdoc}
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /**
     * Return aop generated file path
     *
     * @return string
     */
    public function getAopClassDir()
    {
        return $this->compiler->classDir;
    }

    public function __clone()
    {
        $this->container = clone $this->container;
    }

    public function __invoke(AbstractModule $module)
    {
        $this->module = $module;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getInstance($class)
    {
        // log
        $this->classes[] = $class;

        $bound = $this->getBound($class);

        // return singleton bound object if exists
        if (is_object($bound)) {
            return $bound;
        }

        // get bound config
        list($class, $isSingleton, $interfaceClass, $params, $setter, $definition) = $bound;

        // instantiate parameters
        $params = $this->instantiateParams($params);

        // be all parameters ready
        $this->constructorInject($class, $params, $this->module);

        $refClass = new \ReflectionClass($class);

        if ($refClass->isInterface()) {
            return $this->getInstance($class);
        }

        // weave aspect
        $module = $this->module;
        $bind = $module($class, new $this->bind);
        /* @var $bind \Ray\Aop\Bind */

        $object = $bind->hasBinding() ?
            $this->compiler->newInstance($class, $params, $bind) : $this->newInstance($class, $params) ;

        // do not call constructor twice. ever.
        unset($setter['__construct']);

        // call setter methods
        $this->setterMethod($setter, $object);

        // logger inject info
        if ($this->logger) {
            $this->logger->log($class, $params, $setter, $object, $bind);
        }

        // Object life cycle, Singleton, and Save cache
        $this->postInject($object, $definition, $isSingleton, $interfaceClass);

        return $object;
    }

    /**
     * Notify pre-destroy
     *
     * @return void
     */
    private function notifyPreShutdown()
    {
        $this->preDestroyObjects->rewind();
        while ($this->preDestroyObjects->valid()) {
            $object = $this->preDestroyObjects->current();
            $method = $this->preDestroyObjects->getInfo();
            $object->$method();
            $this->preDestroyObjects->next();
        }
    }

    /**
     * Return parameters
     *
     * @param array $params
     *
     * @return array
     */
    private function instantiateParams(array $params)
    {
        // lazy-load params as needed
        $keys = array_keys($params);
        foreach ($keys as $key) {
            if ($params[$key] instanceof Lazy) {
                $params[$key] = $params[$key]();
            }
        }

        return $params;
    }

    /**
     * Post inject procedure
     *
     * @param object     $object
     * @param Definition $definition
     * @param bool       $isSingleton
     * @param string     $interfaceClass
     */
    private function postInject($object, Definition $definition, $isSingleton, $interfaceClass)
    {
        // set life cycle
        if ($definition) {
            $this->setLifeCycle($object, $definition);
        }

        // set singleton object
        if ($isSingleton) {
            $this->container->set($interfaceClass, $object);
        }
    }

    /**
     * Return new instance
     *
     * @param string $class
     * @param array  $params
     *
     * @return object
     */
    private function newInstance($class, array $params)
    {
        return call_user_func_array(
            [$this->config->getReflect($class), 'newInstance'],
            $params
        );
    }

    /**
     * Return bound object or inject info
     *
     * @param $class
     *
     * @return array|object
     * @throws Exception\NotReadable
     */
    private function getBound($class)
    {
        $class = $this->removeLeadingBackSlash($class);
        $isAbstract = $this->isAbstract($class);
        list($config, $setter, $definition) = $this->config->fetch($class);
        $interfaceClass = $isSingleton = false;
        if ($isAbstract) {
            $bound = $this->getBoundClass($this->module->bindings, $definition, $class);
            if (is_object($bound)) {
                return $bound;
            }
            list($class, $isSingleton, $interfaceClass) = $bound;
            list($config, $setter, $definition) = $this->config->fetch($class);
        } elseif (! $isAbstract) {
            try {
                $bound = $this->getBoundClass($this->module->bindings, $definition, $class);
                if (is_object($bound)) {
                    return $bound;
                }
            } catch (Exception\NotBound $e) {

            }
        }
        $hasDirectBinding = isset($this->module->bindings[$class]);
        /** @var $definition Definition */
        if ($definition->hasDefinition() || $hasDirectBinding) {
            list($config, $setter) = $this->bindModule($setter, $definition);
        }

        return [$class, $isSingleton, $interfaceClass, $config, $setter, $definition];
    }

    /**
     * return isAbstract ?
     *
     * @param $class
     *
     * @return bool
     * @throws Exception\NotReadable
     */
    private function isAbstract($class)
    {
        try {
            $refClass = new ReflectionClass($class);
            $isAbstract = $refClass->isInterface() || $refClass->isAbstract();
        } catch (ReflectionException $e) {
            throw new Exception\NotReadable($class);
        }

        return $isAbstract;

    }
    /**
     * Remove leading back slash
     *
     * @param string $class
     *
     * @return string
     */
    private function removeLeadingBackSlash($class)
    {
        $isLeadingBackSlash = (strlen($class) > 0 && $class[0] === '\\');
        if ($isLeadingBackSlash === true) {
            $class = substr($class, 1);
        }

        return $class;
    }

    /**
     * Get bound class or object
     *
     * @param mixed  $bindings   array | \ArrayAccess
     * @param mixed  $definition
     * @param string $class
     *
     * @return array|object
     * @throws Exception\NotBound
     */
    private function getBoundClass($bindings, $definition, $class)
    {
        $this->checkNotBound($bindings, $class);

        $toType = $bindings[$class]['*']['to'][0];

        if ($toType === AbstractModule::TO_PROVIDER) {
            return $this->getToProviderBound($bindings, $class);
        }

        list($isSingleton, $interfaceClass) = $this->getBindingInfo($class, $definition, $bindings);

        if ($isSingleton && $this->container->has($interfaceClass)) {
            $object = $this->container->get($interfaceClass);

            return $object;
        }

        if ($toType === AbstractModule::TO_INSTANCE) {
            return $bindings[$class]['*']['to'][1];
        }

        if ($toType === AbstractModule::TO_CLASS) {
            $class = $bindings[$class]['*']['to'][1];
        }

        return [$class, $isSingleton, $interfaceClass];
    }

    /**
     * Return $isSingleton, $interfaceClass
     *
     * @param string $class
     * @param array  $definition
     * @param mixed  $bindings
     *
     * @return array [$isSingleton, $interfaceClass]
     */
    private function getBindingInfo($class, $definition, $bindings)
    {
        $inType = isset($bindings[$class]['*'][AbstractModule::IN]) ? $bindings[$class]['*'][AbstractModule::IN] : null;
        $inType = is_array($inType) ? $inType[0] : $inType;
        $isSingleton = $inType === Scope::SINGLETON || $definition['Scope'] == Scope::SINGLETON;
        $interfaceClass = $class;

        return [$isSingleton, $interfaceClass];

    }
    /**
     * Throw exception if not bound
     *
     * @param mixed  $bindings
     * @param string $class
     *
     * @throws Exception\NotBound
     */
    private function checkNotBound($bindings, $class)
    {
        if (!isset($bindings[$class]) || !isset($bindings[$class]['*']['to'][0])) {
            $msg = "Interface \"$class\" is not bound.";
            throw new Exception\NotBound($msg);
        }
    }

    /**
     * @param ArrayObject $bindings
     * @param $class
     *
     * @return object
     */
    private function getToProviderBound(ArrayObject $bindings, $class)
    {
        $provider = $bindings[$class]['*']['to'][1];
        $in = isset($bindings[$class]['*']['in']) ? $bindings[$class]['*']['in'] : null;
        if ($in !== Scope::SINGLETON) {
            return $this->getInstance($provider)->get();
        }
        if (!$this->container->has($class)) {
            $object = $this->getInstance($provider)->get();
            $this->container->set($class, $object);

        }

        return $this->container->get($class);

    }
    /**
     * Return dependency using modules.
     *
     * @param array      $setter
     * @param Definition $definition
     *
     * @return array <$constructorParams, $setter>
     * @throws Exception\Binding
     * @throws \LogicException
     */
    private function bindModule(array $setter, Definition $definition)
    {
        // main
        $setterDefinitions = (isset($definition[Definition::INJECT][Definition::INJECT_SETTER])) ? $definition[Definition::INJECT][Definition::INJECT_SETTER] : null;
        if ($setterDefinitions) {
            $setter = $this->getSetter($setterDefinitions);
        }

        // constructor injection ?
        $params = isset($setter['__construct']) ? $setter['__construct'] : [];
        $result = [$params, $setter];

        return $result;
    }

    /**
     * @param array $setterDefinitions
     *
     * @return array
     */
    private function getSetter(array $setterDefinitions)
    {
        $injected = [];
        foreach ($setterDefinitions as $setterDefinition) {
            try {
                $injected[] = $this->bindMethod($setterDefinition);
            } catch (Exception\OptionalInjectionNotBound $e) {
            }
        }
        $setter = [];
        foreach ($injected as $item) {
            list($setterMethod, $object) = $item;
            $setter[$setterMethod] = $object;
        }

        return $setter;
    }

    /**
     * Bind method
     *
     * @param array $setterDefinition
     *
     * @return array
     */
    private function bindMethod(array $setterDefinition)
    {
        list($method, $settings) = each($setterDefinition);
        array_walk($settings, [$this, 'bindOneParameter']);

        return [$method, $settings];
    }

    /**
     * Return parameter using TO_CONSTRUCTOR
     *
     * 1) If parameter is provided, return. (check)
     * 2) If parameter is NOT provided and TO_CONSTRUCTOR binding is available, return parameter with it
     * 3) No binding found, throw exception.
     *
     * @param string         $class
     * @param array          &$params
     * @param AbstractModule $module
     *
     * @return void
     * @throws Exception\NotBound
     */
    private function constructorInject($class, array &$params, AbstractModule $module)
    {
        $ref = method_exists($class, '__construct') ? new ReflectionMethod($class, '__construct') : false;
        if ($ref === false) {
            return;
        }
        $parameters = $ref->getParameters();
        foreach ($parameters as $index => $parameter) {
            /* @var $parameter \ReflectionParameter */
            $this->constructParams($params, $index, $parameter, $module, $class);
        }
    }

    /**
     * @param array                &$params
     * @param int                  $index
     * @param \ReflectionParameter $parameter
     * @param AbstractModule       $module
     * @param string               $class
     *
     * @return void
     * @throws Exception\NotBound
     */
    private function constructParams(&$params, $index, \ReflectionParameter $parameter, AbstractModule $module, $class)
    {
        // has binding ?
        $params = array_values($params);
        if (isset($params[$index])) {
            return;
        }
        $hasConstructorBinding = ($module[$class]['*'][AbstractModule::TO][0] === AbstractModule::TO_CONSTRUCTOR);
        if ($hasConstructorBinding) {
            $params[$index] = $module[$class]['*'][AbstractModule::TO][1][$parameter->name];
            return;
        }
        // has constructor default value ?
        if ($parameter->isDefaultValueAvailable() === true) {
            return;
        }
        // is typehint class ?
        $classRef = $parameter->getClass();
        if ($classRef && !$classRef->isInterface()) {
            $params[$index] = $this->getInstance($classRef->getName());
            return;
        }
        $msg = is_null($classRef) ? "Valid interface is not found. (array ?)" : "Interface [{$classRef->name}] is not bound.";
        $msg .= " Injection requested at argument #{$index} \${$parameter->name} in {$class} constructor.";
        throw new Exception\NotBound($msg);
    }

    /**
     * @param array $setter
     * @param       $object
     */
    private function setterMethod(array $setter, $object)
    {
        foreach ($setter as $method => $value) {
            call_user_func_array([$object, $method], $value);
        }
    }

    /**
     * Set object life cycle
     *
     * @param object     $instance
     * @param Definition $definition
     *
     * @return void
     */
    private function setLifeCycle($instance, Definition $definition = null)
    {
        $postConstructMethod = $definition[Definition::POST_CONSTRUCT];
        if ($postConstructMethod) {
            call_user_func(array($instance, $postConstructMethod));
        }
        if (!is_null($definition[Definition::PRE_DESTROY])) {
            $this->preDestroyObjects->attach($instance, $definition[Definition::PRE_DESTROY]);
        }

    }

    /**
     * Return module information as string
     *
     * @return string
     */
    public function __toString()
    {
        return (string)($this->module);
    }

    /**
     * Set one parameter with definition, or JIT binding.
     *
     * @param array  &$param
     * @param string $key
     *
     * @return void
     * @throws Exception\OptionalInjectionNotBound
     * @SuppressWarnings(PHPMD)
     */
    private function bindOneParameter(array &$param, $key)
    {
        $annotate = $param[Definition::PARAM_ANNOTATE];
        $typeHint = $param[Definition::PARAM_TYPEHINT];
        $hasTypeHint = isset($this->module[$typeHint]) && isset($this->module[$typeHint][$annotate]) && ($this->module[$typeHint][$annotate] !== []);
        $binding = $hasTypeHint ? $this->module[$typeHint][$annotate] : false;
        $isNotBinding = $binding === false || isset($binding[AbstractModule::TO]) === false;
        if ($isNotBinding && array_key_exists(Definition::DEFAULT_VAL, $param)) {
            // default value
            $param = $param[Definition::DEFAULT_VAL];
            return;
        }
        if ($isNotBinding) {
            // default binding by @ImplementedBy or @ProviderBy
            $binding = $this->jitBinding($param, $typeHint, $annotate, $key);
        }
        list($bindingToType, $target) = $binding[AbstractModule::TO];

        $bound = $this->instanceBound($param, $bindingToType, $target, $binding);
        if ($bound) {
            return;
        }

        if ($typeHint === '') {
            $param = $this->getInstanceWithContainer(Scope::PROTOTYPE, $bindingToType, $target);
            return;
        }

        $this->typeBound($param, $typeHint, $bindingToType, $target);
    }

    /**
     * Set param by type bound
     *
     * @param mixed  $param
     * @param string $typeHint
     * @param string $bindingToType
     * @param mixed  $target
     */
    private function typeBound(&$param, $typeHint, $bindingToType, $target)
    {
        list($param, , $definition) = $this->config->fetch($typeHint);
        $in = isset($definition[Definition::SCOPE]) ? $definition[Definition::SCOPE] : Scope::PROTOTYPE;
        $param = $this->getInstanceWithContainer($in, $bindingToType, $target);
    }
    /**
     * Set param by instance bound(TO_INSTANCE, TO_CALLABLE, or already set in container)
     *
     * @param $param
     * @param $bindingToType
     * @param $target
     * @param $binding
     * @return bool
     */
    private function instanceBound(&$param, $bindingToType, $target, $binding)
    {
        if ($bindingToType === AbstractModule::TO_INSTANCE) {
            $param = $target;
            return true;
        }

        if ($bindingToType === AbstractModule::TO_CALLABLE) {
            /* @var $target \Closure */
            $param = $target();
            return true;
        }

        if (isset($binding[AbstractModule::IN])) {
            $param = $this->getInstanceWithContainer($binding[AbstractModule::IN], $bindingToType, $target);
            return true;
        }

        return false;

    }

    /**
     * Get instance with container
     *
     * @param string $in (Scope::SINGLETON | Scope::PROTOTYPE)
     * @param string $bindingToType
     * @param mixed  $target
     *
     * @return mixed
     */
    private function getInstanceWithContainer($in, $bindingToType, $target)
    {
        if ($in === Scope::SINGLETON && $this->container->has($target)) {
            $instance = $this->container->get($target);

            return $instance;
        }
        $isToClassBinding = ($bindingToType === AbstractModule::TO_CLASS);
        $instance = $isToClassBinding ? $this->getInstance($target) : $this->getInstance($target)->get();

        if ($in === Scope::SINGLETON) {
            $this->container->set($target, $instance);
        }

        return $instance;
    }

    /**
     * JIT binding
     *
     * @param array  $param
     * @param string $typeHint
     * @param string $annotate
     * @param $key
     *
     * @return array
     * @throws Exception\OptionalInjectionNotBound
     * @throws Exception\NotBound
     */
    private function jitBinding(array $param, $typeHint, $annotate, $key)
    {
        $typeHintBy = $param[Definition::PARAM_TYPEHINT_BY];
        if ($typeHintBy == []) {
            $this->raiseNotBoundException($param, $key, $typeHint, $annotate);
        }
        if ($typeHintBy[0] === Definition::PARAM_TYPEHINT_METHOD_IMPLEMETEDBY) {
            return [AbstractModule::TO => [AbstractModule::TO_CLASS, $typeHintBy[1]]];
        }

        return [AbstractModule::TO => [AbstractModule::TO_PROVIDER, $typeHintBy[1]]];
    }

    /**
     * @param $param
     * @param $key
     * @param $typeHint
     * @param $annotate
     *
     * @throws Exception\OptionalInjectionNotBound
     * @throws Exception\NotBound
     */
    private function raiseNotBoundException($param, $key, $typeHint, $annotate)
    {
        if ($param[Definition::OPTIONAL] === true) {
            throw new Exception\OptionalInjectionNotBound($key);
        }
        $name = $param[Definition::PARAM_NAME];
        $class = array_pop($this->classes);
        $msg = "typehint='{$typeHint}', annotate='{$annotate}' for \${$name} in class '{$class}'";
        $e = (new Exception\NotBound($msg))->setModule($this->module);
        throw $e;
    }

    public function serialize()
    {
        $data = serialize(
            [
                $this->container,
                $this->module,
                $this->bind,
                $this->compiler,
                $this->logger,
                $this->preDestroyObjects,
                $this->config
            ]
        );

        return $data;
    }

    public function unserialize($data)
    {
        list(
            $this->container,
            $this->module,
            $this->bind,
            $this->compiler,
            $this->logger,
            $this->preDestroyObjects,
            $this->config
        ) = unserialize($data);

        AnnotationRegistry::registerAutoloadNamespace('Ray\Di\Di', dirname(dirname(__DIR__)));
        register_shutdown_function(function () {
            // @codeCoverageIgnoreStart
            $this->notifyPreShutdown();
            // @codeCoverageIgnoreEnd
        });
    }
}
}
namespace Ray\Di {
/**
 * This file is part of the Ray package.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use ArrayAccess;
use ArrayObject;
use Doctrine\Common\Annotations\AnnotationReader as Reader;
use Ray\Aop\AbstractMatcher;
use Ray\Aop\Bind;
use Ray\Aop\Matcher;
use Ray\Aop\Pointcut;

/**
 * A module contributes configuration information, typically interface bindings,
 *  which will be used to create an Injector.
 */
abstract class AbstractModule implements ArrayAccess
{
    /**
     * Bind
     *
     * @var string
     */
    const BIND = 'bind';

    /**
     * Name
     *
     * @var string
     */
    const NAME = 'name';

    /**
     * In (Scope)
     *
     * @var string
     */
    const IN = 'in';

    /**
     * To
     *
     * @var string
     */
    const TO = 'to';

    /**
     * To Class
     *
     * @var string
     */
    const TO_CLASS = 'class';

    /**
     * Provider
     *
     * @var string
     */
    const TO_PROVIDER = 'provider';

    /**
     * To Instance
     *
     * @var string
     */
    const TO_INSTANCE = 'instance';

    /**
     * To Closure
     *
     * @var string
     */
    const TO_CALLABLE = 'callable';

    /**
     * To Constructor
     *
     * @var string
     */
    const TO_CONSTRUCTOR = 'constructor';

    /**
     * To Constructor
     *
     * @var string
     */
    const TO_SETTER = 'setter';

    /**
     * To Scope
     *
     * @var string
     */
    const SCOPE = 'scope';

    /**
     * Unspecified name
     *
     * @var string
     */
    const NAME_UNSPECIFIED = '*';

    /**
     * Binding
     *
     * @var ArrayObject
     */
    public $bindings;

    /**
     * Pointcuts
     *
     * @var ArrayObject
     */

    /**
     * Current Binding
     *
     * @var string
     */
    protected $currentBinding;

    /**
     * Current Name
     *
     * @var string
     */
    protected $currentName = self::NAME_UNSPECIFIED;

    /**
     * Scope
     *
     * @var array
     */
    protected $scope = [Scope::PROTOTYPE, Scope::SINGLETON];

    /**
     * Pointcuts
     *
     * @var array
     */
    public $pointcuts = [];

    /**
     * @var InjectorInterface
     */
    protected $dependencyInjector;

    /**
     * Is activated
     *
     * @var bool
     */
    protected $activated = false;

    /**
     * Installed modules
     *
     * @var array
     */
    public $modules = [];


    /**
     * @var ModuleStringerInterface
     */
    private $stringer;

    /**
     * @param AbstractModule          $module
     * @param Matcher                 $matcher
     * @param ModuleStringerInterface $stringer
     */
    public function __construct(
        AbstractModule $module = null,
        Matcher $matcher = null,
        ModuleStringerInterface $stringer = null
    ) {
        $this->modules[] = get_class($this);
        $this->matcher = $matcher ? : new Matcher(new Reader);
        $this->stringer = $stringer ?: new ModuleStringer;
        if (is_null($module)) {
            $this->bindings = new ArrayObject;
            $this->pointcuts = new ArrayObject;
            return;
        }
        $module->activate();
        $this->bindings = $module->bindings;
        $this->pointcuts = $module->pointcuts;
    }

    /**
     * Activation
     *
     * @param InjectorInterface $injector
     */
    public function activate(InjectorInterface $injector = null)
    {
        if ($this->activated === true) {
            return;
        }
        $this->activated = true;
        $this->dependencyInjector = $injector ? : Injector::create([$this]);
        $this->configure();
    }

    /**
     * Configures a Binder via the exposed methods.
     *
     * @return void
     */
    abstract protected function configure();

    /**
     * Set bind interface
     *
     * @param string $interface
     *
     * @return AbstractModule
     */
    protected function bind($interface = '')
    {
        if (strlen($interface) > 0 && $interface[0] === '\\') {
            // remove leading back slash
            $interface = substr($interface, 1);
        }

        $this->currentBinding = $interface;
        $this->currentName = self::NAME_UNSPECIFIED;

        return $this;
    }

    /**
     * Set binding annotation.
     *
     * @param string $name
     *
     * @return AbstractModule
     */
    protected function annotatedWith($name)
    {
        $this->currentName = $name;
        $this->bindings[$this->currentBinding][$name] = [self::IN => Scope::SINGLETON];

        return $this;
    }

    /**
     * Set scope
     *
     * @param string $scope
     *
     * @return AbstractModule
     */
    protected function in($scope)
    {
        $this->bindings[$this->currentBinding][$this->currentName][self::IN] = $scope;

        return $this;
    }

    /**
     * To class
     *
     * @param string $class
     *
     * @return AbstractModule
     * @throws Exception\ToBinding
     */
    protected function to($class)
    {
        $this->bindings[$this->currentBinding][$this->currentName] = [self::TO => [self::TO_CLASS, $class]];

        return $this;
    }

    /**
     * To provider
     *
     * @param string $provider provider class
     *
     * @return AbstractModule
     * @throws Exception\InvalidProvider
     */
    protected function toProvider($provider)
    {
        $hasProviderInterface = class_exists($provider) && in_array(
            'Ray\Di\ProviderInterface',
            class_implements($provider)
        );
        if ($hasProviderInterface === false) {
            throw new Exception\InvalidProvider($provider);
        }
        $this->bindings[$this->currentBinding][$this->currentName] = [self::TO => [self::TO_PROVIDER, $provider]];

        return $this;
    }

    /**
     * To instance
     *
     * @param mixed $instance
     *
     * @return AbstractModule
     */
    protected function toInstance($instance)
    {
        $this->bindings[$this->currentBinding][$this->currentName] = [self::TO => [self::TO_INSTANCE, $instance]];
    }

    /**
     * To closure
     *
     * @param callable $callable
     *
     * @return void
     */
    protected function toCallable(callable $callable)
    {
        $this->bindings[$this->currentBinding][$this->currentName] = [self::TO => [self::TO_CALLABLE, $callable]];
    }

    /**
     * To constructor
     *
     * @param array $params
     */
    protected function toConstructor(array $params)
    {
        $this->bindings[$this->currentBinding][$this->currentName] = [self::TO => [self::TO_CONSTRUCTOR, $params]];
    }

    /**
     * Bind interceptor
     *
     * @param AbstractMatcher $classMatcher
     * @param AbstractMatcher $methodMatcher
     * @param array           $interceptors
     */
    protected function bindInterceptor(AbstractMatcher $classMatcher, AbstractMatcher $methodMatcher, array $interceptors)
    {
        $id = uniqid();
        $this->pointcuts[$id] = new Pointcut($classMatcher, $methodMatcher, $interceptors);
    }

    /**
     * Install module
     *
     * @param AbstractModule $module
     *
     * @return void
     */
    public function install(AbstractModule $module)
    {
        $module->activate($this->dependencyInjector);
        $this->pointcuts = new ArrayObject(array_merge((array)$module->pointcuts, (array)$this->pointcuts));
        $this->bindings = $this->mergeBindings($module);
        if ($module->modules) {
            $this->modules = array_merge($this->modules, $module->modules);
        }
    }

    /**
     * Merge binding
     *
     * @param AbstractModule $module
     *
     * @return ArrayObject
     */
    private function mergeBindings(AbstractModule $module)
    {
        return new ArrayObject($this->mergeArray((array)$this->bindings, (array)$module->bindings));
    }

    /**
     * Merge array recursive but not add array in same key like merge_array_recursive()
     *
     * @param array $origin
     * @param array $new
     *
     * @return array
     */
    private function mergeArray(array $origin, array $new)
    {
        foreach ($new as $key => $value) {
            $beMergeable = isset($origin[$key]) && is_array($value) && is_array($origin[$key]);
            $origin[$key] = $beMergeable ? $this->mergeArray($value, $origin[$key]) : $value;
        }

        return $origin;
    }

    /**
     * Request injection
     *
     * Get instance with current module.
     *
     * @param string $class
     *
     * @return object
     */
    public function requestInjection($class)
    {
        $di = $this->dependencyInjector;
        $module = $di->getModule();
        $di($this);
        $instance = $di->getInstance($class);
        if ($module instanceof AbstractModule) {
            $di($module);
        }

        return $instance;
    }

    /**
     * Return matched binder
     *
     * @param string $class
     * @param Bind   $bind
     *
     * @return Bind $bind
     */
    public function __invoke($class, Bind $bind)
    {
        $bind->bind($class, (array)$this->pointcuts);

        return $bind;
    }

    /**
     * ArrayAccess::offsetExists
     *
     * @param mixed $offset
     *
     * @return bool
     */
    public function offsetExists($offset)
    {
        return isset($this->bindings[$offset]);
    }

    /**
     * ArrayAccess::offsetGet
     *
     * @param string $offset
     *
     * @return mixed
     */
    public function offsetGet($offset)
    {
        return isset($this->bindings[$offset]) ? $this->bindings[$offset] : null;
    }

    /**
     * ArrayAccess::offsetSet
     *
     * @param string $offset
     * @param mixed  $value
     *
     * @throws Exception\ReadOnly
     * @SuppressWarnings(PHPMD)
     */
    public function offsetSet($offset, $value)
    {
        throw new Exception\ReadOnly;
    }

    /**
     * ArrayAccess::offsetUnset
     *
     * @param string $offset
     *
     * @throws Exception\ReadOnly
     * @SuppressWarnings(PHPMD)
     */
    public function offsetUnset($offset)
    {
        throw new Exception\ReadOnly;
    }

    /**
     * Return binding information
     *
     * @return string
     */
    public function __toString()
    {
        $this->stringer = new ModuleStringer();
        return $this->stringer->toString($this);

    }

    /**
     * Keep only bindings and pointcuts.
     *
     * @return array
     */
    public function __sleep()
    {
        return ['bindings', 'pointcuts'];
    }
}
}
namespace Demo\Helloworld\Module {


use Ray\Di\AbstractModule;
use BEAR\Package;
use BEAR\Package\Module;
use BEAR\Package\Provide as ProvideModule;
use BEAR\Sunday\Module as SundayModule;
use Ray\Di\Module\InjectorModule;

class AppModule extends AbstractModule
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        // di - application
        $this->bind()->annotatedWith('app_name')->toInstance('Demo\Helloworld');
        $this->bind('BEAR\Sunday\Extension\Application\AppInterface')->to('Demo\Helloworld\App');
        $this->install(new SundayModule\Framework\FrameworkModule);
        $this->install(new SundayModule\Resource\ResourceCacheModule);
        $this->install(new SundayModule\Constant\NamedModule(['tmp_dir' => sys_get_temp_dir()]));
        $this->install(new InjectorModule($this));
    }
}
}
namespace Ray\Di {
/**
 * This file is part of the Ray package.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

/**
 * Scope Definition
 */
class Scope
{
    /**
     * Singleton scope
     *
     * @var string
     */
    const SINGLETON = 'Singleton';

    /**
     * Prototype scope
     *
     * @var string
     */
    const PROTOTYPE = 'Prototype';
}
}
namespace Ray\Aop {
/**
 * This file is part of the Ray.Aop package
 *
 * @package Ray.Aop
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

abstract class AbstractMatcher
{
    /**
     * Match CLASS
     *
     * @var bool
     */
    const TARGET_CLASS = true;

    /**
     * Match Method
     *
     * @var bool
     */
    const TARGET_METHOD = false;

    /**
     * Lazy match method
     *
     * @var string
     */
    protected $method;

    /**
     * Lazy match args
     *
     * @var array
     */
    protected $args;


    protected function createMatcher($method, $args)
    {
        $this->method = $method;
        $this->args = $args;

        return clone $this;
    }

    /**
     * Return match result
     *
     * @param string $class
     * @param bool   $target self::TARGET_CLASS | self::TARGET_METHOD
     *
     * @return bool | array [$matcher, method]
     */
    public function __invoke($class, $target)
    {
        $args = [$class, $target];
        $thisArgs = is_array($this->args) ? $this->args : [$this->args];
        foreach ($thisArgs as $arg) {
            $args[] = $arg;
        }
        $method = 'is' . $this->method;
        $matched = call_user_func_array([$this, $method], $args);

        return $matched;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        $result = $this->method . ':' . json_encode($this->args);

        return $result;
    }
}
}
namespace Ray\Aop {
/**
 * This file is part of the Ray.Aop package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

/**
 * Supports matching classes and methods
 */
interface Matchable
{
    /**
     * Any match
     *
     * @return Matchable
     */
    public function any();

    /**
     * Match binding annotation
     *
     * @param string $annotationName
     *
     * @return Matchable
     */
    public function annotatedWith($annotationName);

    /**
     * Return subclass matched result
     *
     * @param string $superClass
     *
     * @return Matchable
     */
    public function subclassesOf($superClass);

    /**
     * Return prefix match result
     *
     * @param string $prefix
     *
     * @return Matchable
     */
    public function startsWith($prefix);

    /**
     * Match logical or
     *
     * @param Matchable $matcherA
     * @param Matchable $matcherB
     *
     * @return Matchable
     */
    public function logicalOr(Matchable $matcherA, Matchable $matcherB);

    /**
     * Match logical and
     *
     * @param Matchable $matchableA
     * @param Matchable $matchableB
     *
     * @return Matchable
     */

    /**
     * @param Matchable $matcherA
     * @param Matchable $matcherB
     *
     * @return mixed
     */
    public function logicalAnd(Matchable $matcherA, Matchable $matcherB);


    /**
     * Match logical xor
     *
     * @param Matchable $matcherA
     * @param Matchable $matcherB
     *
     * @return self
     */
    public function logicalXor(Matchable $matcherA, Matchable $matcherB);

    /**
     * Match logical not
     *
     * @param Matchable $matcher
     *
     * @return Matchable
     */
    public function logicalNot(Matchable $matcher);

    /**
     * Return match result
     *
     * @param string $class
     * @param bool   $target self::TARGET_CLASS | self::TARGET_METHOD
     *
     * @return bool | array [$matcher, method]
     */
    public function __invoke($class, $target);
}
}
namespace Ray\Aop {
/**
 * This file is part of the Ray.Aop package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use Doctrine\Common\Annotations\Reader;
use Ray\Aop\Exception\InvalidAnnotation;
use Ray\Aop\Exception\InvalidArgument as InvalidArgumentException;
use ReflectionClass;

class Matcher extends AbstractMatcher implements Matchable
{
    /**
     * Annotation reader
     *
     * @var Reader
     */
    private $reader;

    /**
     * @param Reader $reader
     */
    public function __construct(Reader $reader)
    {
        $this->reader = $reader;
    }

    /**
     * {@inheritdoc}
     */
    public function any()
    {
        return $this->createMatcher(__FUNCTION__, null);
    }

    /**
     * {@inheritdoc}
     */
    public function annotatedWith($annotationName)
    {
        if (!class_exists($annotationName)) {
            throw new InvalidAnnotation($annotationName);
        }

        return $this->createMatcher(__FUNCTION__, $annotationName);
    }

    /**
     * {@inheritdoc}
     */
    public function subclassesOf($superClass)
    {
        return $this->createMatcher(__FUNCTION__, $superClass);
    }

    /**
     * @deprecated
     */
    public function startWith($prefix)
    {
        return $this->startsWith($prefix);
    }

    /**
     * {@inheritdoc}
     */
    public function startsWith($prefix)
    {
        return $this->createMatcher(__FUNCTION__, $prefix);
    }

    /**
     * Return isAnnotateBinding
     *
     * @return bool
     */
    public function isAnnotateBinding()
    {
        $isAnnotateBinding = $this->method === 'annotatedWith';

        return $isAnnotateBinding;
    }

    /**
     * {@inheritdoc}
     */
    public function logicalOr(Matchable $matcherA, Matchable $matcherB)
    {
        $this->method = __FUNCTION__;
        $this->args = func_get_args();

        return clone $this;
    }

    /**
     * {@inheritdoc}
     */
    public function logicalAnd(Matchable $matcherA, Matchable $matcherB)
    {
        $this->method = __FUNCTION__;
        $this->args = func_get_args();

        return clone $this;
    }

    /**
     * {@inheritdoc}
     */
    public function logicalXor(Matchable $matcherA, Matchable $matcherB)
    {
        $this->method = __FUNCTION__;
        $this->args = func_get_args();

        return clone $this;
    }

    /**
     * {@inheritdoc}
     */
    public function logicalNot(Matchable $matcher)
    {
        $this->method = __FUNCTION__;
        $this->args = $matcher;

        return clone $this;
    }

    /**
     * Return isAny
     *
     * @param string $name   class or method name
     * @param bool   $target self::TARGET_CLASS | self::TARGET_METHOD
     *
     * @return bool
     *
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function isAny($name, $target)
    {
        if ($target === self::TARGET_CLASS) {
            return true;
        }
        if (substr($name, 0, 2) === '__') {
            return false;
        }
        if (in_array(
            $name,
            [
                'offsetExists',
                'offsetGet',
                'offsetSet',
                'offsetUnset',
                'append',
                'getArrayCopy',
                'count',
                'getFlags',
                'setFlags',
                'asort',
                'ksort',
                'uasort',
                'uksort',
                'natsort',
                'natcasesort',
                'unserialize',
                'serialize',
                'getIterator',
                'exchangeArray',
                'setIteratorClass',
                'getIterator',
                'getIteratorClass'
            ]
        )
        ) {
            return false;
        }

        return true;
    }

    /**
     * Return is annotated with
     *
     * Return Match object if annotate bindings, which containing multiple results.
     * Otherwise return bool.
     *
     * @param string $class
     * @param bool   $target self::TARGET_CLASS | self::TARGET_METHOD
     * @param string $annotationName
     *
     * @return bool | Matched[]
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
     */
    protected function isAnnotatedWith($class, $target, $annotationName)
    {
        $reader = $this->reader;
        if ($target === self::TARGET_CLASS) {
            $annotation = $reader->getClassAnnotation(new ReflectionClass($class), $annotationName);
            $hasAnnotation = $annotation ? true : false;

            return $hasAnnotation;
        }
        $methods = (new ReflectionClass($class))->getMethods();
        $result = [];
        foreach ($methods as $method) {
            new $annotationName;
            $annotation = $reader->getMethodAnnotation($method, $annotationName);
            if ($annotation) {
                $matched = new Matched;
                $matched->methodName = $method->name;
                $matched->annotation = $annotation;
                $result[] = $matched;
            }
        }

        return $result;
    }

    /**
     * Return is subclass of
     *
     * @param string $class
     * @param bool   $target self::TARGET_CLASS | self::TARGET_METHOD
     * @param string $superClass
     *
     * @return bool
     * @throws InvalidArgumentException
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
     */
    protected function isSubclassesOf($class, $target, $superClass)
    {
        if ($target === self::TARGET_METHOD) {
            throw new InvalidArgumentException($class);
        }
        try {
            $isSubClass = (new ReflectionClass($class))->isSubclassOf($superClass);
            if ($isSubClass === false) {
                $isSubClass = ($class === $superClass);
            }

            return $isSubClass;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Return prefix match
     *
     * @param string $name
     * @param string $target
     * @param string $startsWith
     *
     * @return bool
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
     */
    protected function isStartsWith($name, $target, $startsWith)
    {
        unset($target);
        $result = (strpos($name, $startsWith) === 0) ? true : false;

        return $result;
    }

    /**
     * Return logical or matching result
     *
     * @param string    $name
     * @param bool      $target
     * @param Matchable $matcherA
     * @param Matchable $matcherB
     *
     * @return bool
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
     */
    protected function isLogicalOr($name, $target, Matchable $matcherA, Matchable $matcherB)
    {
        // a or b
        $isOr = ($matcherA($name, $target) or $matcherB($name, $target));
        if (func_num_args() <= 4) {
            return $isOr;
        }
        // a or b or c ...
        $args = array_slice(func_get_args(), 4);
        foreach ($args as $arg) {
            $isOr = ($isOr or $arg($name, $target));
        }

        return $isOr;
    }

    /**
     * Return logical and matching result
     *
     * @param string    $name
     * @param bool      $target
     * @param Matchable $matcherA
     * @param Matchable $matcherB
     *
     * @return bool
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
     */
    protected function isLogicalAnd($name, $target, Matchable $matcherA, Matchable $matcherB)
    {
        $isAnd = ($matcherA($name, $target) and $matcherB($name, $target));
        if (func_num_args() <= 4) {
            return $isAnd;
        }
        $args = array_slice(func_get_args(), 4);
        foreach ($args as $arg) {
            $isAnd = ($isAnd and $arg($name, $target));
        }

        return $isAnd;
    }

    /**
     * Return logical xor matching result
     *
     * @param string    $name
     * @param bool      $target
     * @param Matchable $matcherA
     * @param Matchable $matcherB
     *
     * @return bool
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
     */
    protected function isLogicalXor($name, $target, Matchable $matcherA, Matchable $matcherB)
    {
        $isXor = ($matcherA($name, $target) xor $matcherB($name, $target));
        if (func_num_args() <= 4) {
            return $isXor;
        }
        $args = array_slice(func_get_args(), 4);
        foreach ($args as $arg) {
            $isXor = ($isXor xor $arg($name, $target));
        }

        return $isXor;
    }

    /**
     * Return logical not matching result
     *
     * @param string    $name
     * @param bool      $target
     * @param Matchable $matcher
     *
     * @return bool
     */
    protected function isLogicalNot($name, $target, Matchable $matcher)
    {
        $isNot = !($matcher($name, $target));

        return $isNot;
    }
}
}
namespace Doctrine\Common\Lexer {
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license. For more information, see
 * <http://www.doctrine-project.org>.
 */


/**
 * Base class for writing simple lexers, i.e. for creating small DSLs.
 *
 * @since   2.0
 * @author  Guilherme Blanco <guilhermeblanco@hotmail.com>
 * @author  Jonathan Wage <jonwage@gmail.com>
 * @author  Roman Borschel <roman@code-factory.org>
 */
abstract class AbstractLexer
{
    /**
     * @var array Array of scanned tokens
     */
    private $tokens = array();

    /**
     * @var integer Current lexer position in input string
     */
    private $position = 0;

    /**
     * @var integer Current peek of current lexer position
     */
    private $peek = 0;

    /**
     * @var array The next token in the input.
     */
    public $lookahead;

    /**
     * @var array The last matched/seen token.
     */
    public $token;

    /**
     * Sets the input data to be tokenized.
     *
     * The Lexer is immediately reset and the new input tokenized.
     * Any unprocessed tokens from any previous input are lost.
     *
     * @param string $input The input to be tokenized.
     */
    public function setInput($input)
    {
        $this->tokens = array();
        $this->reset();
        $this->scan($input);
    }

    /**
     * Resets the lexer.
     */
    public function reset()
    {
        $this->lookahead = null;
        $this->token = null;
        $this->peek = 0;
        $this->position = 0;
    }

    /**
     * Resets the peek pointer to 0.
     */
    public function resetPeek()
    {
        $this->peek = 0;
    }

    /**
     * Resets the lexer position on the input to the given position.
     *
     * @param integer $position Position to place the lexical scanner
     */
    public function resetPosition($position = 0)
    {
        $this->position = $position;
    }

    /**
     * Checks whether a given token matches the current lookahead.
     *
     * @param integer|string $token
     * @return boolean
     */
    public function isNextToken($token)
    {
        return null !== $this->lookahead && $this->lookahead['type'] === $token;
    }

    /**
     * Checks whether any of the given tokens matches the current lookahead
     *
     * @param array $tokens
     * @return boolean
     */
    public function isNextTokenAny(array $tokens)
    {
        return null !== $this->lookahead && in_array($this->lookahead['type'], $tokens, true);
    }

    /**
     * Moves to the next token in the input string.
     *
     * A token is an associative array containing three items:
     *  - 'value'    : the string value of the token in the input string
     *  - 'type'     : the type of the token (identifier, numeric, string, input
     *                 parameter, none)
     *  - 'position' : the position of the token in the input string
     *
     * @return array|null the next token; null if there is no more tokens left
     */
    public function moveNext()
    {
        $this->peek = 0;
        $this->token = $this->lookahead;
        $this->lookahead = (isset($this->tokens[$this->position]))
            ? $this->tokens[$this->position++] : null;

        return $this->lookahead !== null;
    }

    /**
     * Tells the lexer to skip input tokens until it sees a token with the given value.
     *
     * @param string $type The token type to skip until.
     */
    public function skipUntil($type)
    {
        while ($this->lookahead !== null && $this->lookahead['type'] !== $type) {
            $this->moveNext();
        }
    }

    /**
     * Checks if given value is identical to the given token
     *
     * @param mixed $value
     * @param integer $token
     * @return boolean
     */
    public function isA($value, $token)
    {
        return $this->getType($value) === $token;
    }

    /**
     * Moves the lookahead token forward.
     *
     * @return array | null The next token or NULL if there are no more tokens ahead.
     */
    public function peek()
    {
        if (isset($this->tokens[$this->position + $this->peek])) {
            return $this->tokens[$this->position + $this->peek++];
        } else {
            return null;
        }
    }

    /**
     * Peeks at the next token, returns it and immediately resets the peek.
     *
     * @return array|null The next token or NULL if there are no more tokens ahead.
     */
    public function glimpse()
    {
        $peek = $this->peek();
        $this->peek = 0;
        return $peek;
    }

    /**
     * Scans the input string for tokens.
     *
     * @param string $input a query string
     */
    protected function scan($input)
    {
        static $regex;

        if ( ! isset($regex)) {
            $regex = '/(' . implode(')|(', $this->getCatchablePatterns()) . ')|'
                   . implode('|', $this->getNonCatchablePatterns()) . '/i';
        }

        $flags = PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_OFFSET_CAPTURE;
        $matches = preg_split($regex, $input, -1, $flags);

        foreach ($matches as $match) {
            // Must remain before 'value' assignment since it can change content
            $type = $this->getType($match[0]);

            $this->tokens[] = array(
                'value' => $match[0],
                'type'  => $type,
                'position' => $match[1],
            );
        }
    }

    /**
     * Gets the literal for a given token.
     *
     * @param integer $token
     * @return string
     */
    public function getLiteral($token)
    {
        $className = get_class($this);
        $reflClass = new \ReflectionClass($className);
        $constants = $reflClass->getConstants();

        foreach ($constants as $name => $value) {
            if ($value === $token) {
                return $className . '::' . $name;
            }
        }

        return $token;
    }

    /**
     * Lexical catchable patterns.
     *
     * @return array
     */
    abstract protected function getCatchablePatterns();

    /**
     * Lexical non-catchable patterns.
     *
     * @return array
     */
    abstract protected function getNonCatchablePatterns();

    /**
     * Retrieve token type. Also processes the token value if necessary.
     *
     * @param string $value
     * @return integer
     */
    abstract protected function getType(&$value);
}
}
namespace Ray\Di {
/**
 * This file is part of the Ray package.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

/**
 * String for module
 */
class ModuleStringer
{
    /**
     * Return module information as string
     *
     * @param AbstractModule $module
     *
     * @return string
     */
    public function toString(AbstractModule $module)
    {
        $output = '';
        foreach ((array)$module->bindings as $bind => $bindTo) {
            foreach ($bindTo as $annotate => $to) {
                $type = $to['to'][0];
                $output .= ($annotate !== '*') ? "bind:{$bind} annotatedWith:{$annotate}" : "bind:{$bind}";
                if ($type === 'class') {
                    $output .= " to:" . $to['to'][1];
                }
                if ($type === 'instance') {
                    $output .= $this->getInstanceString($to);
                }
                if ($type === 'provider') {
                    $provider = $to['to'][1];
                    $output .= " toProvider:" . $provider;
                }
                $output .= PHP_EOL;
            }
        }

        return $output;
    }

    /**
     * @param array $to
     *
     * @return string
     */
    private function getInstanceString(array $to)
    {
        $instance = $to['to'][1];
        $type = gettype($instance);
        switch ($type) {
            case "object":
                $instance = '(object) ' . get_class($instance);
                break;
            case "array":
                $instance = json_encode($instance);
                break;
            case "string":
                $instance = "'{$instance}'";
                break;
            case "boolean":
                $instance = '(bool) ' . ($instance ? 'true' : 'false');
                break;
            default:
                $instance = "($type) $instance";
        }
        return " toInstance:" . $instance;
    }
}
}
namespace Aura\Di {
/**
 *
 * This file is part of the Aura Project for PHP.
 *
 * @package Aura.Di
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 *
 */

/**
 *
 * Interface for dependency injection containers.
 *
 * @package Aura.Di
 *
 */
interface ContainerInterface
{
    /**
     *
     * Lock the Container so that configuration cannot be accessed externally,
     * and no new service definitions can be added.
     *
     * @return void
     *
     */
    public function lock();

    /**
     *
     * Is the Container locked?
     *
     * @return bool
     *
     */
    public function isLocked();

    /**
     *
     * Gets the Forge object used for creating new instances.
     *
     * @return ForgeInterface
     *
     */
    public function getForge();

    /**
     *
     * Does a particular service exist?
     *
     * @param string $key The service key to look up.
     *
     * @return bool
     *
     */
    public function has($key);

    /**
     *
     * Sets a service object by name.
     *
     * @param string $key The service key.
     *
     * @param object $val The service object.
     *
     */
    public function set($key, $val);

    /**
     *
     * Gets a service object by key, lazy-loading it as needed.
     *
     * @param string $key The service to get.
     *
     * @return object
     *
     * @throws \Aura\Di\Exception\ServiceNotFound when the requested service
     * does not exist.
     *
     */
    public function get($key);

    /**
     *
     * Gets the list of services provided.
     *
     * @return array
     *
     */
    public function getServices();

    /**
     *
     * Gets the list of service definitions.
     *
     * @return array
     *
     */
    public function getDefs();

    /**
     *
     * Returns a Lazy that gets a service.
     *
     * @param string $key The service name; it does not need to exist yet.
     *
     * @return Lazy A lazy-load object that gets the named service.
     *
     */
    public function lazyGet($key);

    /**
     *
     * Returns a new instance of the specified class, optionally
     * with additional override parameters.
     *
     * @param string $class The type of class of instantiate.
     *
     * @param array $params Override parameters for the instance.
     *
     * @param array $setters Override setters for the instance.
     *
     * @return object An instance of the requested class.
     *
     */
    public function newInstance($class, array $params = [], array $setters = []);

    /**
     *
     * Returns a Lazy that creates a new instance.
     *
     * @param string $class The type of class of instantiate.
     *
     * @param array $params Override parameters for the instance.
     *
     * @param array $setters Override setters for the instance.
     *
     * @return Lazy A lazy-load object that creates the new instance.
     *
     */
    public function lazyNew($class, array $params = [], array $setters = []);
}
}
namespace Aura\Di {
/**
 *
 * This file is part of the Aura Project for PHP.
 *
 * @package Aura.Di
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 *
 */

/**
 *
 * Dependency injection container.
 *
 * @package Aura.Di
 *
 */
class Container implements ContainerInterface
{
    /**
     *
     * A Forge object to create classes through reflection.
     *
     * @var array
     *
     */
    protected $forge;

    /**
     *
     * A convenient reference to the Config::$params object, which itself
     * is contained by the Forge object.
     *
     * @var \ArrayObject
     *
     */
    protected $params;

    /**
     *
     * A convenient reference to the Config::$setter object, which itself
     * is contained by the Forge object.
     *
     * @var \ArrayObject
     *
     */
    protected $setter;

    /**
     *
     * Retains named service definitions.
     *
     * @var array
     *
     */
    protected $defs = [];

    /**
     *
     * Retains the actual service objects.
     *
     * @var array
     *
     */
    protected $services = [];

    /**
     *
     * Is the Container locked?  (When locked, you cannot access configuration
     * properties from outside the object, and cannot set services.)
     *
     * @var bool
     *
     * @see __get()
     *
     * @see set()
     *
     */
    protected $locked = false;

    /**
     *
     * Constructor.
     *
     * @param ForgeInterface $forge A forge for creating objects using
     * keyword parameter configuration.
     *
     */
    public function __construct(ForgeInterface $forge)
    {
        $this->forge  = $forge;
        $this->params = $this->getForge()->getConfig()->getParams();
        $this->setter = $this->getForge()->getConfig()->getSetter();
    }

    /**
     *
     * Magic get to provide access to the Config::$params and $setter
     * objects.
     *
     * @param string $key The property to retrieve ('params' or 'setter').
     *
     * @return mixed
     *
     */
    public function __get($key)
    {
        if ($this->isLocked()) {
            throw new Exception\ContainerLocked;
        }

        if ($key == 'params' || $key == 'setter') {
            return $this->$key;
        }

        throw new \UnexpectedValueException($key);
    }

    /**
     *
     * When cloning this Container, *do not* make a copy of the service
     * objects.  Leave the configuration and definitions intact.
     *
     * @return void
     *
     */
    public function __clone()
    {
        $this->services = [];
        $this->forge = clone $this->forge;
    }

    /**
     *
     * Lock the Container so that configuration cannot be accessed externally,
     * and no new service definitions can be added.
     *
     * @return void
     *
     */
    public function lock()
    {
        $this->locked = true;
    }

    /**
     *
     * Is the Container locked?
     *
     * @return bool
     *
     */
    public function isLocked()
    {
        return $this->locked;
    }

    /**
     *
     * Gets the Forge object used for creating new instances.
     *
     * @return array
     *
     */
    public function getForge()
    {
        return $this->forge;
    }

    /**
     *
     * Does a particular service definition exist?
     *
     * @param string $key The service key to look up.
     *
     * @return bool
     *
     */
    public function has($key)
    {
        return isset($this->defs[$key]);
    }

    /**
     *
     * Sets a service definition by name. If you set a service as a Closure,
     * it is automatically treated as a Lazy. (Note that is has to be a
     * Closure, not just any callable, to be treated as a Lazy; this is
     * because the actual service object itself might be callable via an
     * __invoke() method.)
     *
     * @param string $key The service key.
     *
     * @param object $val The service object; if a Closure, is treated as a
     * Lazy.
     *
     * @throws Exception\ContainerLocked when the Container is locked.
     *
     * @throws Exception\ServiceNotObject
     *
     * @return $this
     *
     */
    public function set($key, $val)
    {
        if ($this->isLocked()) {
            throw new Exception\ContainerLocked;
        }

        if (! is_object($val)) {
            throw new Exception\ServiceNotObject($key);
        }

        if ($val instanceof \Closure) {
            $val = new Lazy($val);
        }

        $this->defs[$key] = $val;

        return $this;
    }

    /**
     *
     * Gets a service object by key, lazy-loading it as needed.
     *
     * @param string $key The service to get.
     *
     * @return object
     *
     * @throws Exception\ServiceNotFound when the requested service
     * does not exist.
     *
     */
    public function get($key)
    {
        // does the definition exist?
        if (! $this->has($key)) {
            throw new Exception\ServiceNotFound($key);
        }

        // has it been instantiated?
        if (! isset($this->services[$key])) {
            // instantiate it from its definition.
            $service = $this->defs[$key];
            // lazy-load as needed
            if ($service instanceof Lazy) {
                $service = $service();
            }
            // retain
            $this->services[$key] = $service;
        }

        // done
        return $this->services[$key];
    }

    /**
     *
     * Gets the list of instantiated services.
     *
     * @return array
     *
     */
    public function getServices()
    {
        return array_keys($this->services);
    }

    /**
     *
     * Gets the list of service definitions.
     *
     * @return array
     *
     */
    public function getDefs()
    {
        return array_keys($this->defs);
    }

    /**
     *
     * Returns a Lazy containing a general-purpose callable. Use this when you
     * have complex logic or heavy overhead when creating a param that may or
     * may not need to be loaded.
     *
     *      $di->params['ClassName']['param_name'] = $di->lazy(function () {
     *          return include 'filename.php';
     *      });
     *
     * @param callable $callable The callable functionality.
     *
     * @return Lazy A lazy-load object that contains the callable.
     *
     */
    public function lazy(callable $callable)
    {
        return new Lazy($callable);
    }

    /**
     *
     * Returns a Lazy that gets a service. This allows you to replace the
     * following idiom ...
     *
     *      $di->params['ClassName']['param_name'] = $di->lazy(function() use ($di)) {
     *          return $di->get('service');
     *      }
     *
     * ... with the following:
     *
     *      $di->params['ClassName']['param_name'] = $di->lazyGet('service');
     *
     * @param string $key The service name; it does not need to exist yet.
     *
     * @return Lazy A lazy-load object that gets the named service.
     *
     */
    public function lazyGet($key)
    {
        $self = $this;
        return $this->lazy(
            function () use ($self, $key) {
                return $self->get($key);
            }
        );
    }

    /**
     *
     * Returns a new instance of the specified class, optionally
     * with additional override parameters.
     *
     * @param string $class The type of class of instantiate.
     *
     * @param array $params Override parameters for the instance.
     *
     * @param array $setters Override setters for the instance.
     *
     * @return object An instance of the requested class.
     *
     */
    public function newInstance($class, array $params = [], array $setters = [])
    {
        return $this->forge->newInstance($class, $params, $setters);
    }

    /**
     *
     * Returns a Lazy that creates a new instance. This allows you to replace
     * the following idiom:
     *
     *      $di->params['ClassName']['param_name'] = $di->lazy(function () use ($di)) {
     *          return $di->newInstance('OtherClass', [...]);
     *      });
     *
     * ... with the following:
     *
     *      $di->params['ClassName']['param_name'] = $di->lazyNew('OtherClass', [...]);
     *
     * @param string $class The type of class of instantiate.
     *
     * @param array $params Override parameters for the instance.
     *
     * @param array $setters Override setters for the instance
     *
     * @return Lazy A lazy-load object that creates the new instance.
     *
     */
    public function lazyNew($class, array $params = [], array $setters = [])
    {
        $forge = $this->getForge();
        return $this->lazy(
            function () use ($forge, $class, $params, $setters) {
                return $forge->newInstance($class, $params, $setters);
            }
        );
    }

    /**
     *
     * Returns a lazy that requires a file.  This replaces the idiom ...
     *
     *     $di->params['ClassName']['foo'] = $di->lazy(function () {
     *         return require "/path/to/file.php";
     *     };
     *
     * ... with:
     *
     *     $di->params['ClassName']['foo'] = $di->lazyRequire("/path/to/file.php");
     *
     * @param string $file The file to require.
     *
     * @return Lazy
     *
     */
    public function lazyRequire($file)
    {
        return $this->lazy(function () use ($file) {
            return require $file;
        });
    }

    /**
     *
     * Returns a lazy that includes a file.  This replaces the idiom ...
     *
     *     $di->params['ClassName']['foo'] = $di->lazy(function () {
     *         return include "/path/to/file.php";
     *     };
     *
     * ... with:
     *
     *     $di->params['ClassName']['foo'] = $di->lazyRequire("/path/to/file.php");
     *
     * @param string $file The file to include.
     *
     * @return Lazy
     *
     */
    public function lazyInclude($file)
    {
        return $this->lazy(function () use ($file) {
            return include $file;
        });
    }

    /**
     *
     * Returns a Lazy that invokes a callable (e.g., to call a method on an
     * object).
     *
     * @param $callable callable The callable.  Params after this one are
     * treated as params for the call.
     *
     * @return Lazy
     *
     */
    public function lazyCall($callable)
    {
        // get params, if any, after removing $callable
        $params = func_get_args();
        array_shift($params);

        // create the closure to invoke the callable
        $call = function () use ($callable, $params) {

            // convert Lazy objects in the callable
            if (is_array($callable)) {
                foreach ($callable as $key => $val) {
                    if ($val instanceof Lazy) {
                        $callable[$key] = $val();
                    }
                }
            }

            // convert Lazy objects in the params
            foreach ($params as $key => $val) {
                if ($val instanceof Lazy) {
                    $params[$key] = $val();
                }
            }

            // make the call
            return call_user_func_array($callable, $params);
        };

        // return wrapped in a Lazy, and done
        return $this->lazy($call);
    }

    /**
     *
     * Returns a Factory that creates an object over and over again (as vs
     * creating it one time like the lazyNew() or newInstance() methods).
     *
     * @param string $class THe factory will create an instance of this class.
     *
     * @param array $params Override parameters for the instance.
     *
     * @param array $setters Override setters for the instance.
     *
     * @return Factory
     *
     */
    public function newFactory($class, array $params = [], array $setters = [])
    {
        return new Factory($this->forge, $class, $params, $setters);
    }
}
}
namespace Ray\Di {
/**
 * This file is part of the Ray package.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use Aura\Di\Container as AuraContainer;
use Aura\Di\ContainerInterface;
use Aura\Di\ForgeInterface;
use Ray\Di\Di\Inject;

/**
 * Dependency injection container.
 */
class Container extends AuraContainer implements ContainerInterface
{
    /**
     * @param ForgeInterface $forge
     *
     * @Inject
     */
    public function __construct(ForgeInterface $forge)
    {
        parent::__construct($forge);
    }
}
}
namespace Aura\Di {
/**
 *
 * This file is part of the Aura Project for PHP.
 *
 * @package Aura.Di
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 *
 */

/**
 *
 * Defines the interface for Forge dependencies.
 *
 * @package Aura.Di
 *
 */
interface ForgeInterface
{
    /**
     *
     * Gets the injected Config object.
     *
     * @return ConfigInterface
     *
     */
    public function getConfig();

    /**
     *
     * Creates and returns a new instance of a class using
     * the configuration parameters, optionally with overriding params and setters.
     *
     * @param string $class The class to instantiate.
     *
     * @param array $params An associative array of override parameters where
     * the key is the name of the constructor parameter and the value is the
     * parameter value to use.
     *
     * @param array $setters An associative array of override setters where
     * the key is the name of the setter method to call and the value is the
     * value to be passed to the setter method.
     *
     * @return object
     *
     */
    public function newInstance($class, array $params = [], array $setters = []);
}
}
namespace Aura\Di {
/**
 *
 * This file is part of the Aura Project for PHP.
 *
 * @package Aura.Di
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 *
 */

/**
 *
 * Creates objects using reflection and the specified configuration values.
 *
 * @package Aura.Di
 *
 */
class Forge implements ForgeInterface
{
    /**
     *
     * A Config object to get parameters for object instantiation and
     * \ReflectionClass instances.
     *
     * @var Config
     *
     */
    protected $config;

    /**
     *
     * Constructor.
     *
     * @param ConfigInterface $config A configuration object.
     *
     */
    public function __construct(ConfigInterface $config)
    {
        $this->config = $config;
    }

    /**
     *
     * When cloning this Forge, create a separate Config object for the clone.
     *
     * @return void
     *
     */
    public function __clone()
    {
        $this->config = clone $this->config;
    }

    /**
     *
     * Gets the injected Config object.
     *
     * @return ConfigInterface
     *
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     *
     * Creates and returns a new instance of a class using reflection and
     * the configuration parameters, optionally with overrides, invoking Lazy
     * values along the way.
     *
     * @param string $class The class to instantiate.
     *
     * @param array $merge_params An array of override parameters; the key may
     * be the name *or* the numeric position of the constructor parameter, and
     * the value is the parameter value to use.
     *
     * @param array $merge_setter An array of override setters; the key is the
     * name of the setter method to call and the value is the value to be
     * passed to the setter method.
     *
     * @return object
     *
     */
    public function newInstance(
        $class,
        array $merge_params = [],
        array $merge_setter = []
    ) {
        // base configs
        list($params, $setter) = $this->config->fetch($class);

        // merge configs
        $params = $this->mergeParams($params, $merge_params);
        $setter = array_merge($setter, $merge_setter);

        // create the new instance
        $rclass = $this->config->getReflect($class);
        $object = $rclass->newInstanceArgs($params);

        // call setters after creation
        foreach ($setter as $method => $value) {
            // does the specified setter method exist?
            if (method_exists($object, $method)) {
                // lazy-load setter values as needed
                if ($value instanceof Lazy) {
                    $value = $value();
                }
                // call the setter
                $object->$method($value);
            } else {
                throw new Exception\SetterMethodNotFound("$class::$method");
            }
        }

        // done!
        return $object;
    }

    /**
     *
     * Returns the params after merging with overides; also invokes Lazy param
     * values.
     *
     * @param array $params The constructor parameters.
     *
     * @param array $merge_params An array of override parameters; the key may
     * be the name *or* the numeric position of the constructor parameter, and
     * the value is the parameter value to use.
     *
     * @return array
     *
     */
    protected function mergeParams($params, array $merge_params = [])
    {
        $pos = 0;
        foreach ($params as $key => $val) {

            // positional overrides take precedence over named overrides
            if (array_key_exists($pos, $merge_params)) {
                // positional override
                $val = $merge_params[$pos];
            } elseif (array_key_exists($key, $merge_params)) {
                // named override
                $val = $merge_params[$key];
            }

            // invoke Lazy values
            if ($val instanceof Lazy) {
                $val = $val();
            }

            // retain the merged value
            $params[$key] = $val;

            // next position
            $pos += 1;
        }

        // done
        return $params;
    }
}
}
namespace Ray\Di {
/**
 * This file is part of the Ray package.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use Aura\Di\ConfigInterface;
use Aura\Di\Forge as AuraForge;
use Aura\Di\ForgeInterface;
use Ray\Di\Di\Inject;

/**
 * Creates objects using reflection and the specified configuration values.
 */
class Forge extends AuraForge implements ForgeInterface
{
    /**
     * @param ConfigInterface $config
     *
     * @Inject
     */
    public function __construct(ConfigInterface $config)
    {
        parent::__construct($config);
    }
}
}
namespace Aura\Di {
/**
 *
 * This file is part of the Aura Project for PHP.
 *
 * @package Aura.Di
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 *
 */

/**
 *
 * Retains and unifies class constructor parameter values with external values.
 *
 * @package Aura.Di
 *
 */
interface ConfigInterface
{
    /**
     *
     * Fetches the unified constructor values and external values.
     *
     * @param string $class The class name to fetch values for.
     *
     * @return array An associative array of constructor values for the class.
     *
     */
    public function fetch($class);

    /**
     *
     * Gets the $params property.
     *
     * @return \ArrayObject
     *
     */
    public function getParams();

    /**
     *
     * Gets the $setter property.
     *
     * @return \ArrayObject
     *
     */
    public function getSetter();

    /**
     *
     * Gets a retained ReflectionClass; if not already retained, creates and
     * retains one before returning it.
     *
     * @throws Exception\ServiceNotObject In case reflection could not reflect a class
     *
     * @param string $class The class to reflect on.
     *
     * @return \ReflectionClass
     *
     */
    public function getReflect($class);
}
}
namespace Ray\Di {
/**
 * This file is taken from Aura.Di(https://github.com/auraphp/Aura.Di) and modified.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 * @see     https://github.com/auraphp/Aura.Di
 */

use Aura\Di\ConfigInterface;
use ArrayObject;
use ReflectionClass;
use ReflectionMethod;
use Ray\Di\Di\Inject;

/**
 * Retains and unifies class configurations.
 */
class Config implements ConfigInterface
{
    /**
     * Parameter index number
     */
    const INDEX_PARAM = 0;

    /**
     * Setter index number
     */
    const INDEX_SETTER = 1;

    /**
     * Definition index number
     */
    const INDEX_DEFINITION = 2;

    /**
     *
     * Constructor params from external configuration in the form
     * `$params[$class][$name] = $value`.
     *
     * @var \ArrayObject
     *
     */
    protected $params;

    /**
     *
     * An array of retained ReflectionClass instances; this is as much for
     * the Forge as it is for Config.
     *
     * @var array
     *
     */
    protected $reflect = [];

    /**
     *
     * Setter definitions in the form of `$setter[$class][$method] = $value`.
     *
     * @var \ArrayObject
     *
     */
    protected $setter;

    /**
     *
     * Constructor params and setter definitions, unified across class
     * defaults, inheritance hierarchies, and external configurations.
     *
     * @var array
     *
     */
    protected $unified = [];

    /**
     * Method parameters
     *
     * $params[$class][$method] = [$param1varName, $param2varName ...]
     *
     * @var array
     */
    protected $methodReflect;

    /**
     * Class annotated definition. object life cycle, dependency injection.
     *
     * `$definition[$class]['Scope'] = $value`
     * `$definition[$class]['PostConstruct'] = $value`
     * `$definition[$class]['PreDestroy'] = $value`
     * `$definition[$class]['Inject'] = $value`
     *
     * @var Definition
     */
    protected $definition;

    /**
     * Annotation scanner
     *
     * @var AnnotationInterface
     */
    protected $annotation;

    /**
     * Constructor
     *
     * @param AnnotationInterface $annotation
     *
     * @Inject
     */
    public function __construct(AnnotationInterface $annotation)
    {
        $this->reset();
        $this->annotation = $annotation;
    }

    /**
     *
     * When cloning this object, reset the params and setter values (but
     * leave the reflection values in place).
     *
     * @return void
     *
     */
    public function __clone()
    {
        $this->reset();
    }

    /**
     *
     * Resets the params and setter values.
     *
     * @return void
     *
     */
    protected function reset()
    {
        $this->params = new ArrayObject;
        $this->params['*'] = [];
        $this->setter = new ArrayObject;
        $this->setter['*'] = [];
        $this->definition = new Definition([]);
        $this->definition['*'] = [];
        $this->methodReflect = new ArrayObject;
    }

    /**
     * {@inheritdoc}
     */
    public function getParams()
    {
        return $this->params;
    }

    /**
     * {@inheritdoc}
     */
    public function getSetter()
    {
        return $this->setter;
    }

    /**
     *
     * Gets the $definition property.
     *
     * @return Definition
     *
     */
    public function getDefinition()
    {
        return $this->definition;
    }

    /**
     * {@inheritdoc}
     */
    public function getReflect($class)
    {
        if (!isset($this->reflect[$class])) {
            $this->reflect[$class] = new ReflectionClass($class);
        }

        return $this->reflect[$class];
    }

    /**
     * {@inheritdoc}
     */
    public function fetch($class)
    {
        // have values already been unified for this class?
        if (isset($this->unified[$class])) {
            return $this->unified[$class];
        }

        // fetch the values for parents so we can inherit them
        $parentClass = get_parent_class($class);
        list($parentParams, $parentSetter, $parentDefinition) =
        $parentClass ? $this->fetch($parentClass) : [$this->params['*'], $this->setter['*'], $this->annotation->getDefinition($class)];

        // class have a constructor?
        $constructorReflection = $this->getReflect($class)->getConstructor();
        $unifiedParams = $constructorReflection ? $this->getUnifiedParams($constructorReflection, $parentParams, $class) : [];

        $this->unified[$class] = $this->mergeConfig($class, $unifiedParams, $parentSetter, $parentDefinition);

        return $this->unified[$class];
    }

    /**
     * @param string     $class
     * @param array      $unifiedParams
     * @param array      $parentSetter
     * @param Definition $parentDefinition
     *
     * @return mixed
     */
    private function mergeConfig($class, $unifiedParams, $parentSetter, Definition $parentDefinition)
    {
        // merge the setters
        $unifiedSetter = isset($this->setter[$class]) ? array_merge($parentSetter, $this->setter[$class]) : $parentSetter;

        // merge the definitions
        $definition = isset($this->definition[$class]) ? $this->definition[$class] : $this->annotation->getDefinition($class);
        /** @var $parentDefinition \ArrayObject */
        $unifiedDefinition = new Definition(array_merge($parentDefinition->getArrayCopy(), $definition->getArrayCopy()));
        $this->definition[$class] = $unifiedDefinition;

        // done, return the unified values
        $this->unified[$class] = [$unifiedParams, $unifiedSetter, $unifiedDefinition];

        return $this->unified[$class];
    }

    /**
     * @param ReflectionMethod $constructorReflection
     * @param string           $parentParams
     * @param string           $class
     *
     * @return array
     */
    private function getUnifiedParams(\ReflectionMethod $constructorReflection, $parentParams, $class)
    {
        $unifiedParams = [];

        // reflect on what params to pass, in which order
        $params = $constructorReflection->getParameters();
        foreach ($params as $param) {
            /* @var $param \ReflectionParameter */
            $name = $param->name;
            $explicit = $this->params->offsetExists($class) && isset($this->params[$class][$name]);
            if ($explicit) {
                // use the explicit value for this class
                $unifiedParams[$name] = $this->params[$class][$name];
                continue;
            } elseif (isset($parentParams[$name])) {
                // use the implicit value for the parent class
                $unifiedParams[$name] = $parentParams[$name];
                continue;
            } elseif ($param->isDefaultValueAvailable()) {
                // use the external value from the constructor
                $unifiedParams[$name] = $param->getDefaultValue();
                continue;
            }
            // no value, use a null placeholder
            $unifiedParams[$name] = null;
        }

        return $unifiedParams;
    }
    /**
     *
     * Returns a \ReflectionClass for a named class.
     *
     * @param mixed  $class  The class to reflect on
     * @param string $method The method to reflect on
     *
     * @return \ReflectionMethod
     *
     */
    public function getMethodReflect($class, $method)
    {
        if (is_object($class)) {
            $class = get_class($class);
        }
        if (!isset($this->reflect[$class]) || !is_array($this->reflect[$class])) {
            $methodRef = new ReflectionMethod($class, $method);
            $this->methodReflect[$class][$method] = $methodRef;
        }

        return $this->methodReflect[$class][$method];
    }

    /**
     * Remove reflection property
     *
     * @return array
     */
    public function __sleep()
    {
        return ['params', 'setter', 'unified', 'definition', 'annotation'];
    }
}
}
namespace Ray\Di {
/**
 * This file is part of the Ray package.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

/**
 * Interface for Annotation scanner.
 */
interface AnnotationInterface
{
    /**
     * Get class definition by annotation
     *
     * @param string $class
     *
     * @return Definition
     */
    public function getDefinition($class);
}
}
namespace Ray\Di {
/**
 * This file is part of the Ray package.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use Doctrine\Common\Annotations\Reader;
use Ray\Di\Exception\NotReadable;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use Ray\Di\Di\Inject;

/**
 * Annotation scanner.
 */
class Annotation implements AnnotationInterface
{
    /**
     * User defined annotation
     *
     * $definition[Annotation::USER][$methodName] = [$annotation1, $annotation2 .. ]
     *
     * @var array
     */
    const USER = 'user';

    /**
     * Class definition (new)
     *
     * @var Definition
     */
    protected $newDefinition;

    /**
     * Class definition
     *
     * @var Definition
     */
    protected $definition;

    /**
     * Class definitions for in-memory cache
     *
     * @var Definition[]
     */
    protected $definitions = [];

    /**
     * Annotation reader
     *
     * @var \Doctrine\Common\Annotations\Reader;
     */
    protected $reader;

    /**
     * Constructor
     *
     * @param Definition $definition
     * @param Reader     $reader
     *
     * @Inject
     */
    public function __construct(Definition $definition, Reader $reader)
    {
        $this->newDefinition = $definition;
        $this->reader = $reader;
    }

    /**
     * {@inheritdoc}
     */
    public function getDefinition($className)
    {
        if (! class_exists($className) && ! interface_exists($className)) {
            throw new NotReadable($className);
        }
        if (isset($this->definitions[$className])) {
            return $this->definitions[$className];
        }
        $this->definition = clone $this->newDefinition;
        $class = new ReflectionClass($className);
        $annotations = $this->reader->getClassAnnotations($class);
        $classDefinition = $this->getClassDefinition($annotations);
        foreach ($classDefinition as $key => $value) {
            $this->definition[$key] = $value;
        }
        // Method Annotation
        $this->setMethodDefinition($class);
        $this->definitions[$className] = $this->definition;

        return $this->definition;
    }

    /**
     * Return class definition from annotations
     *
     * @param Annotation[] $annotations
     *
     * @return array
     */
    private function getClassDefinition(array $annotations)
    {
        $result = [];
        foreach ($annotations as $annotation) {
            $annotationName = $this->getAnnotationName($annotation);
            $value = isset($annotation->value) ? $annotation->value : null;
            $result[$annotationName] = $value;
        }

        return $result;
    }

    /**
     * Return method definition from annotations
     *
     * @param Annotation[] $annotations
     *
     * @return array
     */
    private function getMethodDefinition(array $annotations)
    {
        $result = [];
        foreach ($annotations as $annotation) {
            $annotationName = $this->getAnnotationName($annotation);
            $value = $annotation;
            $result[$annotationName] = $value;
        }

        return $result;
    }

    /**
     * Return annotation name from annotation class name
     *
     * @param $annotation
     *
     * @return mixed
     */
    private function getAnnotationName($annotation)
    {
        $classPath = explode('\\', get_class($annotation));
        $annotationName = array_pop($classPath);

        return $annotationName;
    }

    /**
     * Set method definition
     *
     * @param ReflectionClass $class
     *
     * @return void
     */
    private function setMethodDefinition(ReflectionClass $class)
    {
        $methods = $class->getMethods();
        foreach ($methods as $method) {
            $annotations = $this->reader->getMethodAnnotations($method);
            $methodAnnotation = $this->getMethodDefinition($annotations);
            $keys = array_keys($methodAnnotation);
            foreach ($keys as $key) {
                $this->setAnnotationName($key, $method, $methodAnnotation);
            }
            // user land annotation by method
            foreach ($annotations as $annotation) {
                $annotationName = $this->getAnnotationName($annotation);
                $this->definition->setUserAnnotationByMethod($annotationName, $method->name, $annotation);
            }
        }
    }

    /**
     * Set annotation key-value for DI
     *
     * @param string           $name        annotation name
     * @param ReflectionMethod $method
     * @param array            $annotations
     *
     * @return void
     * @throws Exception\MultipleAnnotationNotAllowed
     */
    private function setAnnotationName($name, ReflectionMethod $method, array $annotations)
    {
        if ($name === Definition::POST_CONSTRUCT || $name == Definition::PRE_DESTROY) {
            if (isset($this->definition[$name]) && $this->definition[$name]) {
                $msg = "@{$name} in " . $method->getDeclaringClass()->name;
                throw new Exception\MultipleAnnotationNotAllowed($msg);
            }
            $this->definition[$name] = $method->name;

            return;
        }
        if ($name === Definition::INJECT) {
            $this->setSetterInjectDefinition($annotations, $method);

            return;
        }
        if ($name === Definition::NAMED) {
            return;
        }
        // user land annotation by name
        $this->definition->setUserAnnotationMethodName($name, $method->name);
    }

    /**
     * Set setter inject definition
     *
     * @param array            $methodAnnotation
     * @param ReflectionMethod $method
     *
     * @return void
     */
    private function setSetterInjectDefinition($methodAnnotation, ReflectionMethod $method)
    {
        $nameParameter = false;
        if (isset($methodAnnotation[Definition::NAMED])) {
            $named = $methodAnnotation[Definition::NAMED];
            $nameParameter = $named->value;
        }
        $named = ($nameParameter !== false) ? $this->getNamed($nameParameter) : [];
        $parameters = $method->getParameters();
        $paramInfo[$method->name] = $this->getParamInfo($methodAnnotation, $parameters, $named);
        $this->definition[Definition::INJECT][Definition::INJECT_SETTER][] = $paramInfo;
    }

    /**
     * @param ReflectionParameter[] $parameters
     *
     * @return array
     */

    /**
     * @param array $methodAnnotation
     * @param array $parameters
     * @param $named
     *
     * @return array
     */
    private function getParamInfo($methodAnnotation, array $parameters, $named)
    {
        $paramsInfo = [];
        foreach ($parameters as $parameter) {
            /** @var $parameter \ReflectionParameter */
            $class = $parameter->getClass();
            $typehint = $class ? $class->getName() : '';
            $typehintBy = $typehint ? $this->getTypeHintDefaultInjection($typehint) : [];
            $pos = $parameter->getPosition();
            $name = $this->getName($named, $parameter);
            $optionalInject = $methodAnnotation[Definition::INJECT]->optional;
            $definition = [
                Definition::PARAM_POS => $pos,
                Definition::PARAM_TYPEHINT => $typehint,
                Definition::PARAM_NAME => $parameter->name,
                Definition::PARAM_ANNOTATE => $name,
                Definition::PARAM_TYPEHINT_BY => $typehintBy,
                Definition::OPTIONAL => $optionalInject
            ];
            if ($parameter->isOptional()) {
                $definition[Definition::DEFAULT_VAL] = $parameter->getDefaultValue();
            }
            $paramsInfo[] = $definition;
        }

        return $paramsInfo;
    }

    /**
     * Return name
     *
     * @param mixed $named
     * @param $parameter
     *
     * @return string
     */
    private function getName($named, ReflectionParameter $parameter)
    {
        if (is_string($named)) {
            return $named;
        }
        if (is_array($named) && isset($named[$parameter->name])) {
            return $named[$parameter->name];
        }

        return Definition::NAME_UNSPECIFIED;
    }
    /**
     * Get Named
     *
     * @param string $nameParameter "value" or "key1=value1,ke2=value2"
     *
     * @return array [$paramName => $named][]
     * @throws Exception\Named
     */
    private function getNamed($nameParameter)
    {
        // single annotation @Named($annotation)
        if (preg_match("/^[a-zA-Z0-9_]+$/", $nameParameter)) {
            return $nameParameter;
        }
        // multi annotation @Named($varName1=$annotate1,$varName2=$annotate2)
        // http://stackoverflow.com/questions/168171/regular-expression-for-parsing-name-value-pairs
        preg_match_all('/([^=,]*)=("[^"]*"|[^,"]*)/', $nameParameter, $matches);
        if ($matches[0] === []) {
            throw new Exception\Named;
        }
        $result = [];
        $count = count($matches[0]);
        for ($i = 0; $i < $count; $i++) {
            $result[$matches[1][$i]] = $matches[2][$i];
        }

        return $result;
    }

    /**
     * Get default injection by typehint
     *
     * @param string $typehint
     *
     * @return array
     */
    private function getTypeHintDefaultInjection($typehint)
    {
        $annotations = $this->reader->getClassAnnotations(new ReflectionClass($typehint));
        $classDefinition = $this->getClassDefinition($annotations);

        // @ImplementBy as default
        if (isset($classDefinition[Definition::IMPLEMENTEDBY])) {
            $result = [Definition::PARAM_TYPEHINT_METHOD_IMPLEMETEDBY, $classDefinition[Definition::IMPLEMENTEDBY]];

            return $result;
        }
        // @ProvidedBy as default
        if (isset($classDefinition[Definition::PROVIDEDBY])) {
            $result = [Definition::PARAM_TYPEHINT_METHOD_PROVIDEDBY, $classDefinition[Definition::PROVIDEDBY]];

            return $result;
        }
        // this typehint is class, not a interface.
        if (class_exists($typehint)) {
            $class = new ReflectionClass($typehint);
            if ($class->isAbstract() === false) {
                $result = [Definition::PARAM_TYPEHINT_METHOD_IMPLEMETEDBY, $typehint];

                return $result;
            }
        }

        return [];
    }
}
}
namespace Ray\Di {
/**
 * This file is part of the Ray package.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use ArrayObject;

/**
 * Retains target class inject definition.
 */
class Definition extends ArrayObject
{
    /**
     * Post construct annotation
     *
     * @var string
     */
    const POST_CONSTRUCT = "PostConstruct";

    /**
     * PreDestroy annotation
     *
     * @var string
     */
    const PRE_DESTROY = "PreDestroy";

    /**
     * Inject annotation
     *
     * @var string
     */
    const INJECT = "Inject";

    /**
     * Provide annotation
     *
     * @var string
     */
    const PROVIDE = "Provide";

    /**
     * Scope annotation
     *
     * @var string
     */
    const SCOPE = "Scope";

    /**
     * ImplementedBy annotation (Just-in-time Binding)
     *
     * @var string
     */
    const IMPLEMENTEDBY = "ImplementedBy";

    /**
     * ProvidedBy annotation (Just-in-time Binding)
     *
     * @var string
     */
    const PROVIDEDBY = "ProvidedBy";

    /**
     * Named annotation
     *
     * @var string
     */
    const NAMED = "Named";

    /**
     * PreDestroy annotation
     *
     * @var string
     */
    const NAME_UNSPECIFIED = '*';

    /**
     * Setter inject definition
     *
     * @var string
     */
    const INJECT_SETTER = 'setter';

    /**
     * Parameter position
     *
     * @var string
     */
    const PARAM_POS = 'pos';

    /**
     * Typehint
     *
     * @var string
     */
    const PARAM_TYPEHINT = 'typehint';

    /**
     * Param typehint default concrete class / provider class
     *
     * @var string
     */
    const PARAM_TYPEHINT_BY = 'typehint_by';

    /**
     * Param typehint default concrete class
     *
     * @var string
     */
    const PARAM_TYPEHINT_METHOD_IMPLEMETEDBY = 'implementedby';

    /**
     * Param typehint default provider
     *
     * @var string
     */
    const PARAM_TYPEHINT_METHOD_PROVIDEDBY = 'providedby';

    /**
     * Param var name
     *
     * @var string
     */
    const PARAM_NAME = 'name';

    /**
     * Param named annotation
     *
     * @var string
     */
    const PARAM_ANNOTATE = 'annotate';

    /**
     * Aspect annotation
     *
     * @var string
     */
    const ASPECT = 'Aspect';

    /**
     * User defined interceptor annotation
     *
     * @var string
     */
    const USER = 'user';

    /**
     * OPTIONS
     *
     * @var string
     */
    const OPTIONS = 'options';

    /**
     * BINDING
     *
     * @var string
     */
    const BINDING = 'binding';

    /**
     * BY_METHOD
     *
     * @var string
     */
    const BY_METHOD = 'by_method';

    /**
     * BY_NAME
     *
     * @var string
     */
    const BY_NAME = 'by_name';

    /**
     * Optional Inject
     *
     * @var string
     */
    const OPTIONAL = 'optional';

    /**
     * Default value
     */
    const DEFAULT_VAL = 'default_val';

    /**
     * Definition default
     *
     * @var array
     */
    private $defaults = [
        self::SCOPE => Scope::PROTOTYPE,
        self::POST_CONSTRUCT => null,
        self::PRE_DESTROY => null,
        self::INJECT => [],
        self::IMPLEMENTEDBY => [],
        self::USER => [],
        self::OPTIONAL => []
    ];

    /**
     * Constructor
     *
     * @param array $defaults default definition set
     */
    public function __construct(array $defaults = null)
    {
        $defaults = $defaults ? : $this->defaults;
        parent::__construct($defaults);
    }

    /**
     * Return is-defined
     *
     * @return bool
     */
    public function hasDefinition()
    {
        $hasDefinition = ($this->getArrayCopy() !== $this->defaults);

        return $hasDefinition;
    }

    /**
     * Set user annotation by name
     *
     * @param string $annotationName
     * @param string $methodName
     *
     * @return void
     */
    public function setUserAnnotationMethodName($annotationName, $methodName)
    {
        $this[self::BY_NAME][$annotationName][] = $methodName;
    }

    /**
     * Return user annotation by annotation name
     *
     * @param $annotationName
     *
     * @return array [$methodName, $methodAnnotation]
     */
    public function getUserAnnotationMethodName($annotationName)
    {
        $hasUserAnnotation = isset($this[self::BY_NAME]) && isset($this[self::BY_NAME][$annotationName]);
        $result = $hasUserAnnotation ? $this[Definition::BY_NAME][$annotationName] : null;

        return $result;
    }

    /**
     * setUserAnnotationByMethod
     *
     * @param string $annotationName
     * @param string $methodName
     * @param object $methodAnnotation
     *
     * @return void
     */
    public function setUserAnnotationByMethod($annotationName, $methodName, $methodAnnotation)
    {
        $this[self::BY_METHOD][$methodName][$annotationName][] = $methodAnnotation;
    }

    /**
     * Return user annotation by method name
     *
     * @param string $methodName
     *
     * @return array [$annotationName, $methodAnnotation][]
     */
    public function getUserAnnotationByMethod($methodName)
    {
        $result = isset($this[self::BY_METHOD]) && isset($this[self::BY_METHOD][$methodName]) ? $this[self::BY_METHOD][$methodName] : null;

        return $result;
    }

    /**
     * Return class annotation definition information.
     *
     * @return string
     */
    public function __toString()
    {
        return var_export($this, true);
    }
}
}
namespace Ray\Di {
/**
 * This file is part of the Ray package.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

/**
 * Empty Module.
 */
class EmptyModule extends AbstractModule
{
    public function __construct()
    {
        $this->bindings = new \ArrayObject;
        $this->container = new \ArrayObject;
        $this->configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
    }
}
}
namespace Ray\Aop {
/**
 * This file is part of the Ray.Aop package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

/**
 * Bind method name to interceptors
 */
interface BindInterface
{
    /**
     * Return has binding
     *
     * @return bool
     */
    public function hasBinding();

    /**
     * Make pointcuts to binding information
     *
     * @param string $class
     * @param array  $pointcuts
     *
     * @return Bind
     */
    public function bind($class, array $pointcuts);

    /**
     * Bind method to interceptors
     *
     * @param string $method
     * @param array  $interceptors
     * @param object $annotation   Binding annotation if annotate bind
     *
     * @return Bind
     */
    public function bindInterceptors($method, array $interceptors, $annotation = null);

    /**
     * Get matched Interceptor
     *
     * @param string $name class name
     *
     * @return mixed string|boolean matched method name
     */
    public function __invoke($name);


    /**
     * to String for logging
     *
     * @return string
     */
    public function __toString();
}
}
namespace Ray\Aop {
/**
 * This file is part of the Ray.Aop package
 *
 * @package Ray.Aop
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use ArrayObject;
use ReflectionClass;
use ReflectionMethod;

final class Bind extends ArrayObject implements BindInterface
{
    /**
     * Annotated binding annotation
     *
     * @var array [$method => $annotations]
     */
    public $annotation = [];

    /**
     * {@inheritdoc}
     */
    public function hasBinding()
    {
        $hasImplicitBinding = (count($this)) ? true : false;

        return $hasImplicitBinding;
    }

    /**
     * {@inheritdoc}
     */
    public function bind($class, array $pointcuts)
    {
        foreach ($pointcuts as $pointcut) {
            /** @var $pointcut Pointcut */
            $classMatcher = $pointcut->classMatcher;
            $isClassMatch = $classMatcher($class, Matcher::TARGET_CLASS);
            if ($isClassMatch !== true) {
                continue;
            }
            if (method_exists($pointcut->methodMatcher, 'isAnnotateBinding') && $pointcut->methodMatcher->isAnnotateBinding()) {
                $this->bindByAnnotateBinding($class, $pointcut->methodMatcher, $pointcut->interceptors);
                continue;
            }
            $this->bindByCallable($class, $pointcut->methodMatcher, $pointcut->interceptors);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function bindInterceptors($method, array $interceptors, $annotation = null)
    {
        $this[$method] = !isset($this[$method]) ? $interceptors : array_merge($this[$method], $interceptors);
        if ($annotation) {
            $this->annotation[$method] = $annotation;
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function __invoke($name)
    {
        // pre compiled implicit matcher
        $interceptors = isset($this[$name]) ? $this[$name] : false;

        return $interceptors;
    }

    /**
     * {@inheritdoc}
     */
    public function __toString()
    {
        $binds = [];
        foreach ($this as $method => $interceptors) {
            $inspectorsInfo = [];
            foreach ($interceptors as $interceptor) {
                $inspectorsInfo[] .= get_class($interceptor);
            }
            $inspectorsInfo = implode(',', $inspectorsInfo);
            $binds[] = "{$method} => " . $inspectorsInfo;
        }
        $result = implode(',', $binds);

        return $result;
    }

    /**
     * Bind interceptor by callable matcher
     *
     * @param                 $class
     * @param AbstractMatcher $methodMatcher
     * @param array           $interceptors
     */
    private function bindByCallable($class, AbstractMatcher $methodMatcher, array $interceptors)
    {
        $methods = (new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC);
        foreach ($methods as $method) {
            $isMethodMatch = ($methodMatcher($method->name, Matcher::TARGET_METHOD) === true);
            if ($isMethodMatch) {
                $this->bindInterceptors($method->name, $interceptors);
            }
        }
    }

    /**
     * Bind interceptor by annotation binding
     *
     * @param         $class
     * @param Matcher $methodMatcher
     * @param array   $interceptors
     */
    private function bindByAnnotateBinding($class, Matcher $methodMatcher, array $interceptors)
    {
        $matches = (array)$methodMatcher($class, Matcher::TARGET_METHOD);
        if (!$matches) {
            return;
        }
        foreach ($matches as $matched) {
            if ($matched instanceof Matched) {
                $this->bindInterceptors($matched->methodName, $interceptors, $matched->annotation);
            }
        }
    }
}
}
namespace Ray\Aop {
/**
 * This file is part of the Ray.Aop package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

/**
 * Interface for compiler
 */
interface CompilerInterface
{
    /**
     * Compile
     *
     * @param string $class
     * @param Bind   $bind
     *
     * @return string
     */
    public function compile($class, Bind $bind);

    /**
     * Return new aspect weaved object instance
     *
     * @param string $class
     * @param array  $args
     * @param Bind   $bind
     *
     * @return object
     */
    public function newInstance($class, array $args, Bind $bind);
}
}
namespace Ray\Aop {
/**
 * This file is part of the Ray.Aop package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use PHPParser_BuilderFactory;
use PHPParser_Parser;
use PHPParser_PrettyPrinterAbstract;
use ReflectionClass;
use ReflectionMethod;
use PHPParser_Comment_Doc;
use PHPParser_Builder_Class;
use PHPParser_Node_Stmt_Class;
use PHPParser_Builder_Method;
use PHPParser_Lexer;
use Serializable;

/**
 * AOP compiler
 */
final class Compiler implements CompilerInterface, Serializable
{
    /**
     * @var string
     */
    public $classDir;

    /**
     * @var \PHPParser_Parser
     */
    private $parser;

    /**
     * @var \PHPParser_BuilderFactory
     */
    private $factory;

    /**
     * @param string                          $classDir
     * @param PHPParser_PrettyPrinterAbstract $printer
     */
    public function __construct(
        $classDir,
        PHPParser_PrettyPrinterAbstract $printer
    ) {
        $this->classDir = $classDir;
        $this->printer = $printer;
    }

    /**
     * {@inheritdoc}
     */
    public function compile($class, Bind $bind)
    {
        $this->parser = new PHPParser_Parser(new PHPParser_Lexer);
        $this->factory = new PHPParser_BuilderFactory;

        $refClass = new ReflectionClass($class);
        $newClassName = $this->getClassName($refClass, $bind);
        if (class_exists($newClassName, false)) {
            return $newClassName;
        }
        $file = $this->classDir . "/{$newClassName}.php";
        $stmt = $this
                ->getClass($newClassName, $refClass)
                ->addStmts($this->getMethods($refClass, $bind))
                ->getNode();
        $stmt = $this->addClassDocComment($stmt, $refClass);
        $code = $this->printer->prettyPrint([$stmt]);
        file_put_contents($file, '<?php ' . PHP_EOL . $code);
        include_once $file;

        return $newClassName;
    }

    /**
     * {@inheritdoc}
     */
    public function newInstance($class, array $args, Bind $bind)
    {
        $class = $this->compile($class, $bind);
        $instance = (new ReflectionClass($class))->newInstanceArgs($args);
        $instance->rayAopBind = $bind;

        return $instance;
    }

    /**
     * Return new class name
     *
     * @param \ReflectionClass $class
     * @param Bind             $bind
     *
     * @return string
     */
    private function getClassName(\ReflectionClass $class, Bind $bind)
    {
        $className = str_replace('\\', '_', $class->getName()) . '_' . md5($bind) .'RayAop';

        return $className;
    }

    /**
     * Return class statement
     *
     * @param string          $newClassName
     * @param ReflectionClass $class
     *
     * @return \PHPParser_Builder_Class
     */
    private function getClass($newClassName, \ReflectionClass $class)
    {
        $parentClass = $class->name;
        $builder = $this->factory
            ->class($newClassName)
            ->extend($parentClass)
            ->implement('Ray\Aop\WeavedInterface')
            ->addStmt(
                $this->factory->property('rayAopIntercept')->makePrivate()->setDefault(true)
            )->addStmt(
                $this->factory->property('rayAopBind')->makePublic()
            );

        return $builder;
    }

    /**
     * Add class doc comment
     *
     * @param PHPParser_Node_Stmt_Class $node
     * @param ReflectionClass           $class
     *
     * @return PHPParser_Node_Stmt_Class
     */
    private function addClassDocComment(PHPParser_Node_Stmt_Class $node, \ReflectionClass $class)
    {
        $docComment = $class->getDocComment();
        if ($docComment) {
            $node->setAttribute('comments', [new PHPParser_Comment_Doc($docComment)]);
        }

        return $node;
    }

    /**
     * Return method statements
     *
     * @param ReflectionClass $class
     *
     * @return \PHPParser_Builder_Method[]
     */
    private function getMethods(ReflectionClass $class)
    {
        $stmts = [];
        $methods = $class->getMethods();
        foreach ($methods as $method) {
            /** @var $method ReflectionMethod */
            if ($method->isPublic()) {
                $stmts[] = $this->getMethod($method);
            }
        }

        return $stmts;
    }

    /**
     * Return method statement
     *
     * @param \ReflectionMethod $method
     *
     * @return \PHPParser_Builder_Method
     */
    private function getMethod(\ReflectionMethod $method)
    {
        $methodStmt = $this->factory->method($method->name);
        $params = $method->getParameters();
        foreach ($params as $param) {
            /** @var $param \ReflectionParameter */
            $paramStmt = $this->factory->param($param->name);
            $typeHint = $param->getClass();
            if ($typeHint) {
                $paramStmt->setTypeHint($typeHint->name);
            }
            if ($param->isDefaultValueAvailable()) {
                $paramStmt->setDefault($param->getDefaultValue());
            }
            $methodStmt->addParam(
                $paramStmt
            );
        }
        $methodInsideStatements = $this->getMethodInsideStatement();
        $methodStmt->addStmts($methodInsideStatements);
        $node = $this->addMethodDocComment($methodStmt, $method);

        return $node;
    }

    /**
     * Add method doc comment
     *
     * @param PHPParser_Builder_Method $methodStmt
     * @param ReflectionMethod         $method
     *
     * @return \PHPParser_Node_Stmt_ClassMethod
     */
    private function addMethodDocComment(PHPParser_Builder_Method $methodStmt, \ReflectionMethod $method)
    {
        $node = $methodStmt->getNode();
        $docComment = $method->getDocComment();
        if ($docComment) {
            $node->setAttribute('comments', [new PHPParser_Comment_Doc($docComment)]);
        }
        return $node;
    }

    /**
     * @return \PHPParser_Node[]
     */
    private function getMethodInsideStatement()
    {
        $code = $this->getWeavedMethodTemplate();
        $node = $this->parser->parse($code)[0];
        /** @var $node \PHPParser_Node_Stmt_Class */
        $node = $node->getMethods()[0];

        return $node->stmts;
    }

    /**
     * @return string
     */
    private function getWeavedMethodTemplate()
    {

        return file_get_contents(__DIR__ . '/Compiler/Template.php');
    }

    public function serialize()
    {
        unset($this->factory);
        unset($this->parser);
        return serialize([$this->classDir, $this->printer]);
    }

    public function unserialize($data)
    {
        list($this->classDir, $this->printer) = unserialize($data);
    }
}
}
namespace Ray\Di {
/**
 * This file is part of the Ray package.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use Ray\Aop\Bind;

/**
 * Interface for dependency injector logger.
 */
interface LoggerInterface
{
    /**
     * log prototype instance
     *
     * @param string $class
     * @param array  $params
     * @param array  $setter
     * @param object $object
     * @param Bind   $bind
     *
     * @return void
     */
    public function log($class, array $params, array $setter, $object, Bind $bind);
}
}
namespace Ray\Di {
/**
 * This file is part of the Ray package.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use Ray\Aop\Bind;

/**
 * Dependency injection loggers
 */
class Logger implements LoggerInterface, \IteratorAggregate, \Serializable
{
    /**
     * @var string
     */
    private $logMessages = [];

    /**
     * @var array [
     */
    private $logs = []; // [$class, array $params, array $setter, $object, Bind $bind]

    /**
     * @var array
     */
    private $hashes = [];

    /**
     * logger injection information
     *
     * @param string        $class
     * @param array         $params
     * @param array         $setter
     * @param object        $object
     * @param \Ray\Aop\Bind $bind
     */
    public function log($class, array $params, array $setter, $object, Bind $bind)
    {
        $this->logs[] = [$class, $params, $setter, $object, $bind];
        $setterLog = [];
        foreach ($setter as $method => $methodParams) {
            $setterLog[] = $method . ':'. $this->getParamString((array)$methodParams);
        }
        $setter = $setter ? implode(' ', $setterLog) : '';
        $logMessage = "class:{$class} $setter";
        $this->logMessages[] = $logMessage;
    }

    private function getParamString(array $params)
    {
        foreach ($params as &$param) {
            if (is_object($param)) {
                $param = get_class($param) . '#' . $this->getScope($param);
            } elseif (is_callable($param)) {
                $param = "(callable) {$param}";
            } elseif (is_scalar($param)) {
                $param = '(' . gettype($param) . ') ' . (string)$param;
            } elseif (is_array($param)) {
                $param = str_replace(["\n", " "], '', print_r($param, true));
            }
        }
        return implode(', ', $params);
    }

    private function getScope($object)
    {
        $hash = spl_object_hash($object);
        if (in_array($hash, $this->hashes)) {
            return 'singleton';
        }
        $this->hashes[] = $hash;

        return 'prototype';
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return implode(PHP_EOL, $this->logMessages);
    }

    public function getIterator()
    {
        return new \ArrayIterator($this->logs);
    }

    public function serialize()
    {
        return '';
    }

    public function unserialize($serialized)
    {
        unset($serialized);
        return '';
    }
}
}
namespace BEAR\Sunday\Module\Framework {
/**
 * This file is part of the BEAR.Sunday package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use BEAR\Sunday\Module;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;

/**
 * Application module
 */
class FrameworkModule extends AbstractModule
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->install(new Module\Cache\CacheModule);
        $this->install(new Module\Code\CachedAnnotationModule);
        $this->install(new Module\Resource\ResourceModule($this));
    }
}
}
namespace BEAR\Sunday\Module\Cache {
/**
 * This file is part of the BEAR.Sunday package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use Ray\Di\AbstractModule;
use Ray\Di\Di\Scope;

/**
 * Cache module
 */
class CacheModule extends AbstractModule
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->bind('Guzzle\Cache\AbstractCacheAdapter')
            ->toProvider('BEAR\Sunday\Module\Cache\CacheProvider')
            ->in(Scope::SINGLETON);
    }
}
}
namespace Ray\Di {
/**
 * This file is part of the Ray package.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

/**
 * Interface for object provider. (lazy-loading)
 */
interface ProviderInterface
{
    /**
     * Get object
     *
     * @return object
     */
    public function get();
}
}
namespace BEAR\Sunday\Inject {
/**
 * This file is part of the BEAR.Sunday package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

/**
 * Inject temporary directory path
 */
trait TmpDirInject
{
    /**
     * Tmp dir
     *
     * @var string
     */
    private $tmpDir;

    /**
     * Set tmp dir path
     *
     * @param string $tmpDir
     *
     * @Ray\Di\Di\Inject
     * @Ray\Di\Di\Named("tmp_dir")
     */
    public function setTmpDir($tmpDir)
    {
        $this->tmpDir = $tmpDir;
    }
}
}
namespace BEAR\Sunday\Module\Cache {
/**
 * This file is part of the BEAR.Sunday package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use BEAR\Sunday\Inject\TmpDirInject;
use Doctrine\Common\Cache\ApcCache;
use Doctrine\Common\Cache\FilesystemCache;
use Guzzle\Cache\DoctrineCacheAdapter as CacheAdapter;
use Ray\Di\ProviderInterface as Provide;

/**
 * Cache provider
 *
 * (primary:APC, secondary:FileCache)
 */
class CacheProvider implements Provide
{
    use TmpDirInject;

    /**
     * Return instance
     *
     * @return CacheAdapter
     */
    public function get()
    {
        if (ini_get('apc.enabled')) {
            return new CacheAdapter(new ApcCache);
        }

        // @codeCoverageIgnoreStart
        return new CacheAdapter(new FilesystemCache($this->tmpDir . '/cache'));
        // @codeCoverageIgnoreEnd

    }
}
}
namespace Ray\Di\Di {
/**
 * This file is part of the Ray package.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

/**
 * Annotation interface
 */
interface Annotation
{
}
}
namespace Ray\Di\Di {
/**
 * This file is part of the Ray package.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

/**
 * Scope
 *
 * @Annotation
 * @Target("CLASS")
 */
final class Scope implements Annotation
{
    /**
     * Singleton
     *
     * @var string
     */
    const SINGLETON = 'Singleton';

    /**
     * Prototype
     *
     * @var string
     */
    const PROTOTYPE = 'Prototype';

    /**
     * Object lifecycle
     *
     * @var string
     */
    public $value = self::PROTOTYPE;
}
}
namespace BEAR\Sunday\Module\Code {
/**
 * This file is part of the BEAR.Sunday package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use Ray\Di\AbstractModule;

/**
 * Cached annotation reader module
 */
class CachedAnnotationModule extends AbstractModule
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->bind('Doctrine\Common\Annotations\Reader')
            ->toProvider('BEAR\Sunday\Module\Code\CachedReaderProvider');
    }
}
}
namespace BEAR\Sunday\Module\Code {
/**
 * This file is part of the BEAR.Sunday package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\CachedReader;
use Doctrine\Common\Cache\ApcCache;
use Ray\Di\ProviderInterface as Provide;

/**
 * APC cached reader provider
 */
class CachedReaderProvider implements Provide
{
    /**
     * {@inheritdoc}
     *
     * @return CachedReader
     */
    public function get()
    {
        $reader = new CachedReader(new AnnotationReader, new ApcCache, true);

        return $reader;
    }
}
}
namespace BEAR\Sunday\Module\Resource {
/**
 * This file is part of the BEAR.Sunday package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use Ray\Di\AbstractModule;
use Ray\Di\Injector;
use Ray\Di\Scope;
use BEAR\Resource\Module\ResourceModule as BearResourceModule;
use Ray\Di\Di\Inject;
use Ray\Di\Di\Named;


/**
 * Resource module
 */
class ResourceModule extends AbstractModule
{
    protected $appName;

    /**
     * @param string $appName {Vendor}\{NameSpace}
     *
     * @Inject
     * @Named("app_name")
     */
    public function setAppName($appName)
    {
        $this->appName = $appName;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->bind('BEAR\Resource\LoggerInterface')->toProvider(__NAMESPACE__ . '\ResourceLoggerProvider')->in(Scope::SINGLETON);
        $this->install(new BearResourceModule($this->appName));
    }
}
}
namespace BEAR\Sunday\Module\Resource {
/**
 * This file is part of the BEAR.Sunday package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use BEAR\Resource\LoggerInterface;
use BEAR\Sunday\Extension\Application\AppInterface;
use Ray\Di\ProviderInterface;
use Ray\Di\Di\Inject;
use Ray\Di\Di\Named;

/**
 * Resource logger
 *
 * @see https://github.com/auraphp/Aura.Web.git
 */
class ResourceLoggerProvider implements ProviderInterface
{
    /**
     * Logger instance
     *
     * @var \BEAR\Resource\Logger
     */
    private static $instance;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * Set logger name
     *
     * @param LoggerInterface $logger
     *
     * @Inject
     * @Named("resource_logger")
     */
    public function setLoggerClassName(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     *
     * @return AppInterface
     */
    public function get()
    {
        if (!self::$instance) {
            self::$instance = $this->logger;
        }

        return self::$instance;
    }
}
}
namespace BEAR\Resource\Module {
/**
 * This file is part of the BEAR.Resource package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use Ray\Di\AbstractModule;
use Ray\Di\Module\InjectorModule;
use Ray\Di\Scope;

class ResourceModule extends AbstractModule
{
    /**
     * @var string
     */
    private $appName;

    /**
     * @param string $appName
     */
    public function __construct($appName)
    {
        $this->appName = $appName;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        // install Injector
        $this->install(new InjectorModule($this));
        // bind app name
        $this->bind()->annotatedWith('app_name')->toInstance($this->appName);

        // bind resource client component
        $this->bind('BEAR\Resource\ResourceInterface')->to('BEAR\Resource\Resource')->in(Scope::SINGLETON);
        $this->bind('BEAR\Resource\InvokerInterface')->to('BEAR\Resource\Invoker')->in(Scope::SINGLETON);
        $this->bind('BEAR\Resource\LinkerInterface')->to('BEAR\Resource\Linker')->in(Scope::SINGLETON);
        $this->bind('BEAR\Resource\LoggerInterface')->annotatedWith("resource_logger")->to('BEAR\Resource\Logger');
        $this->bind('BEAR\Resource\HrefInterface')->to('BEAR\Resource\A');
        $this->bind('BEAR\Resource\SignalParameterInterface')->to('BEAR\Resource\SignalParameter');
        $this->bind('BEAR\Resource\FactoryInterface')->to('BEAR\Resource\Factory')->in(Scope::SINGLETON);
        $this->bind('BEAR\Resource\SchemeCollectionInterface')->toProvider('BEAR\Resource\Module\SchemeCollectionProvider')->in(Scope::SINGLETON);
        $this->bind('Aura\Signal\Manager')->toProvider('BEAR\Resource\Module\SignalProvider')->in(Scope::SINGLETON);
        $this->bind('Guzzle\Parser\UriTemplate\UriTemplateInterface')->to('Guzzle\Parser\UriTemplate\UriTemplate')->in(Scope::SINGLETON);
        $this->bind('Ray\Di\InjectorInterface')->toInstance($this->dependencyInjector);
        $this->bind('BEAR\Resource\ParamInterface')->to('BEAR\Resource\Param');
    }
}
}
namespace Ray\Di\Module {
/**
 * This file is part of the Ray package.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use Ray\Di\AbstractModule;
use Ray\Di\Exception;
use Ray\Di\Scope;
use Ray\Aop\Bind;

/**
 * Dependency Injector Module.
 */
class InjectorModule extends AbstractModule
{
    protected function configure()
    {
        $this->bind('Aura\Di\ConfigInterface')->to('Ray\Di\Config');
        $this->bind('Aura\Di\ContainerInterface')->to('Ray\Di\Container');
        $this->bind('Aura\Di\ForgeInterface')->to('Ray\Di\Forge');
        $this->bind('Ray\Di\InjectorInterface')->to('Ray\Di\Injector')->in(Scope::SINGLETON);
        $this->bind('Ray\Di\AnnotationInterface')->to('Ray\Di\Annotation');
        $this->bind('Ray\Aop\CompilerInterface')->toProvider(__NAMESPACE__ . '\Provider\CompilerProvider');
        $this->bind('Ray\Aop\BindInterface')->toInstance(new Bind);
        $this->bind('Ray\Di\AbstractModule')->toInstance($this);
        $this->bind('Doctrine\Common\Annotations\Reader')->to('Doctrine\Common\Annotations\AnnotationReader')->in(Scope::SINGLETON);
    }
}
}
namespace Ray\Di\Module\Provider {
/**
 * This file is part of the Ray package.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use Ray\Aop\Compiler;
use PHPParser_PrettyPrinter_Default;
use PHPParser_Parser;
use PHPParser_Lexer;
use PHPParser_BuilderFactory;
use Ray\Di\ProviderInterface;

/**
 * Compiler provider for InjectorModule.
 */
class CompilerProvider implements ProviderInterface
{
    /**
     * {@inheritdoc}
     *
     * @return object|Compiler
     */
    public function get()
    {
        return new Compiler(
            sys_get_temp_dir(),
            new PHPParser_PrettyPrinter_Default
        );
    }
}
}
namespace BEAR\Resource\Module {
/**
 * This file is part of the BEAR.Sunday package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use BEAR\Resource\Adapter\App as AppAdapter;
use BEAR\Resource\Adapter\Http as HttpAdapter;
use BEAR\Resource\Exception\AppName;
use BEAR\Resource\SchemeCollection;
use Ray\Di\ProviderInterface as Provide;
use Ray\Di\InjectorInterface;
use Ray\Di\Di\Inject;
use Ray\Di\Di\Named;

/**
 * SchemeCollection provider
 */
class SchemeCollectionProvider implements Provide
{
    /**
     * @var string
     */
    private $appName;

    /**
     * @param string $appName
     *
     * @return void
     *
     * @throws \BEAR\Resource\Exception\InvalidAppName
     * @Inject
     * @Named("app_name")
     */
    public function setAppName($appName)
    {
        if (is_null($appName)) {
            throw new AppName($appName);
        }
        $this->appName = $appName;
    }

    /**
     * @param InjectorInterface $injector
     *
     * @Inject
     */
    public function setInjector(InjectorInterface $injector)
    {
        $this->injector = $injector;
    }

    /**
     * Return instance
     *
     * @return SchemeCollection
     */
    public function get()
    {
        $schemeCollection = new SchemeCollection;
        $pageAdapter = new AppAdapter($this->injector, $this->appName, 'Resource\Page');
        $appAdapter = new AppAdapter($this->injector, $this->appName, 'Resource\App');
        $schemeCollection->scheme('page')->host('self')->toAdapter($pageAdapter);
        $schemeCollection->scheme('app')->host('self')->toAdapter($appAdapter);
        $schemeCollection->scheme('http')->host('*')->toAdapter(new HttpAdapter);

        return $schemeCollection;
    }
}
}
namespace BEAR\Resource\Module {
/**
 * This file is part of the BEAR.Sunday package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use Aura\Signal\HandlerFactory;
use Aura\Signal\Manager;
use Aura\Signal\ResultCollection;
use Aura\Signal\ResultFactory;
use Ray\Di\ProviderInterface;

class SignalProvider implements ProviderInterface
{
    /**
     * @return Manager
     */
    public function get()
    {
        return new Manager(new HandlerFactory, new ResultFactory, new ResultCollection);
    }
}
}
namespace BEAR\Sunday\Module\Resource {
/**
 * This file is part of the BEAR.Sunday package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use Ray\Di\AbstractModule;

/**
 * Resource cache module
 */
class ResourceCacheModule extends AbstractModule
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->bind('Guzzle\Cache\CacheAdapterInterface')
            ->annotatedWith('resource_cache')
            ->toProvider('BEAR\Sunday\Module\Cache\CacheProvider');
    }
}
}
namespace BEAR\Sunday\Module\Constant {
/**
 * This file is part of the BEAR.Sunday package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use Ray\Di\AbstractModule;

/**
 * Constants 'Named' module
 */
class NamedModule extends AbstractModule
{
    /**
     * @param array $names
     */
    public function __construct(array $names)
    {
        $names += [
            'sunday_dir' =>dirname(dirname(dirname(dirname(dirname(__DIR__)))))
        ];
        $this->names = $names;
        parent::__construct();
    }

    protected function configure()
    {
        foreach ($this->names as $annotatedWith => $instance) {
            $this->bind()->annotatedWith($annotatedWith)->toInstance($instance);
        }
    }
}
}
namespace BEAR\Sunday\Extension {
/**
 * This file is part of the BEAR.Sunday package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

/**
 * Interface for application extension
 */
interface ExtensionInterface
{
}
}
namespace BEAR\Sunday\Extension\Application {
/**
 * This file is part of the BEAR.Sunday package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use BEAR\Sunday\Extension\ExtensionInterface;

/**
 * Interface for application context
 */
interface AppInterface extends ExtensionInterface
{
}
}
namespace Demo\Helloworld {


use BEAR\Resource\ResourceInterface;
use BEAR\Sunday\Extension\Application\AppInterface;
use Ray\Di\Di\Inject;

final class App implements AppInterface
{
    /**
     * @var \BEAR\Resource\ResourceInterface
     */
    public $resource;

    /**
     * @param \BEAR\Resource\ResourceInterface $resource
     *
     * @Inject
     */
    public function __construct(ResourceInterface $resource)
    {
        $this->resource = $resource;
    }
}
}
namespace BEAR\Resource {
/**
 * This file is part of the BEAR.Resource package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use Ray\Di\Di\ImplementedBy;

interface ResourceInterface
{
    /**
     * Return new resource object instance
     *
     * @param string $uri
     *
     * @return $this
     */
    public function newInstance($uri);

    /**
     * Set resource object
     *
     * @param mixed $ro
     *
     * @return ResourceObject
     */
    public function object($ro);

    /**
     * Set resource object created by URI.
     *
     * @param string $uri
     *
     * @return $this
     */
    public function uri($uri);

    /**
     * Set named parameter query
     *
     * @param  array $query
     *
     * @return $this
     */
    public function withQuery(array $query);

    /**
     * Add query
     *
     * @param array $query
     *
     * @return $this
     */
    public function addQuery(array $query);

    /**
     * Return Request
     *
     * @return Request | ResourceObject
     */
    public function request();

    /**
     * Link self
     *
     * @param string $linkKey
     *
     * @return $this
     */
    public function linkSelf($linkKey);

    /**
     * Link new
     *
     * @param string $linkKey
     *
     * @return $this
     */
    public function linkNew($linkKey);

    /**
     * Link crawl
     *
     * @param string $linkKey
     *
     * @return $this
     */
    public function linkCrawl($linkKey);

    /**
     * Attach parameter provider
     *
     * @param                        $varName
     * @param ParamProviderInterface $provider
     *
     * @return $this
     */
    public function attachParamProvider($varName, ParamProviderInterface $provider);

    /**
     * Hyper reference (Hypertext As The Engine Of Application State)
     *
     * @param string $rel
     * @param array  $query
     *
     * @return mixed
     */
    public function href($rel, array $query = []);

    /**
     * Add resource invoker exception handler
     *
     * @param ExceptionHandlerInterface $exceptionHandler
     *
     * @return mixed
     */
    public function setExceptionHandler(ExceptionHandlerInterface $exceptionHandler);
}
}
namespace BEAR\Resource {
/**
 * This file is part of the BEAR.Resource package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use BEAR\Resource\Exception;
use Guzzle\Cache\CacheAdapterInterface;
use SplObjectStorage;
use Ray\Di\Di\Scope;
use Ray\Di\Di\Inject;
use Ray\Di\Di\Named;

/**
 * Resource client
 *
 * @SuppressWarnings(PHPMD.TooManyMethods)
 *
 * @Scope("singleton")
 */
class Resource implements ResourceInterface
{
    /**
     * Resource factory
     *
     * @var Factory
     */
    private $factory;

    /**
     * Resource request invoker
     *
     * @var Invoker
     */
    private $invoker;

    /**
     * Resource request
     *
     * @var Request
     */
    private $request;

    /**
     * Requests
     *
     * @var \SplObjectStorage
     */
    private $requests;

    /**
     * Cache
     *
     * @var CacheAdapterInterface
     */
    private $cache;

    /**
     * @var string
     */
    private $appName = '';

    /**
     * @var Anchor
     */
    private $anchor;

    /**
     * @param $appName
     *
     * @Inject(optional = true)
     * @Named("app_name")
     *
     */
    public function setAppName($appName)
    {
        $this->appName = $appName;
    }

    /**
     * Set cache adapter
     *
     * @param CacheAdapterInterface $cache
     *
     * @Inject(optional = true)
     * @Named("resource_cache")
     */
    public function setCacheAdapter(CacheAdapterInterface $cache)
    {
        $this->cache = $cache;
    }

    /**
     * Set scheme collection
     *
     * @param SchemeCollectionInterface $scheme
     *
     * @Inject(optional = true)
     */
    public function setSchemeCollection(SchemeCollectionInterface $scheme)
    {
        $this->factory->setSchemeCollection($scheme);
    }

    /**
     * @param Factory          $factory resource object factory
     * @param InvokerInterface $invoker resource request invoker
     * @param Request          $request resource request
     * @param Anchor           $anchor  resource linker
     *
     * @Inject
     */
    public function __construct(
        Factory $factory,
        InvokerInterface $invoker,
        Request $request,
        Anchor $anchor
    ) {
        $this->factory = $factory;
        $this->invoker = $invoker;
        $this->newRequest = $request;
        $this->requests = new SplObjectStorage;
        $this->invoker->setResourceClient($this);
        $this->anchor = $anchor;
    }

    /**
     * {@inheritDoc}
     */
    public function newInstance($uri)
    {
        $useCache = $this->cache instanceof CacheAdapterInterface;
        if ($useCache === true) {
            $key = $this->appName . 'res-' . str_replace('/', '-', $uri);
            $cached = $this->cache->fetch($key);
            if ($cached) {
                return $cached;
            }
        }
        $instance = $this->factory->newInstance($uri);
        if ($useCache === true) {
            /** @noinspection PhpUndefinedVariableInspection */
            $this->cache->save($key, $instance);
        }

        return $instance;
    }

    /**
     * {@inheritDoc}
     */
    public function object($ro)
    {
        $this->request->ro = $ro;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function uri($uri)
    {
        if ($uri instanceof Uri) {
            $this->request->ro = $this->newInstance($uri->uri);
            $this->withQuery($uri->query);

            return $this;
        }
        if (! $this->request) {
            throw new Exception\BadRequest('Request method (get/put/post/delete/options) required before uri()');
        }
        if (filter_var($uri, FILTER_VALIDATE_URL) === false) {
            throw new Exception\Uri($uri);
        }
        // uri with query parsed
        if (strpos($uri, '?') !== false) {
            $parsed = parse_url($uri);
            $uri = $parsed['scheme'] . '://' . $parsed['host'] . $parsed['path'];
            if (isset($parsed['query'])) {
                parse_str($parsed['query'], $query);
                $this->withQuery($query);
            }
        }
        $this->request->ro = $this->newInstance($uri);
        $this->request->uri = $uri;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function withQuery(array $query)
    {
        $this->request->query = $query;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function addQuery(array $query)
    {
        $this->request->query = array_merge($this->request->query, $query);

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function linkSelf($linkKey)
    {
        $this->request->links[] = new LinkType($linkKey, LinkType::SELF_LINK);

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function linkNew($linkKey)
    {
        $this->request->links[] = new LinkType($linkKey, LinkType::NEW_LINK);

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function linkCrawl($linkKey)
    {
        $this->request->links[] = new LinkType($linkKey, LinkType::CRAWL_LINK);

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function request()
    {
        $this->request->ro->uri = $this->request->toUri();
        if (isset($this->request->options['sync'])) {
            $this->requests->attach($this->request);
            $this->request = clone $this->newRequest;

            return $this;
        }
        if ($this->request->in !== 'eager') {
            return clone $this->request;
        }

        return $this->invoke();
    }

    public function href($rel, array $query = [])
    {
        list($method, $uri) = $this->anchor->href($rel, $this->request, $query);
        $linkedResource = $this->{$method}->uri($uri)->eager->request();

        return $linkedResource;
    }

    /**
     * @return ResourceObject|mixed
     */
    private function invoke()
    {
        if ($this->requests->count() === 0) {
            return $this->invoker->invoke($this->request);
        }
        $this->requests->attach($this->request);

        return $this->invoker->invokeSync($this->requests);
    }


    /**
     * {@inheritDoc}
     */
    public function attachParamProvider($varName, ParamProviderInterface $provider)
    {
        $this->invoker->attachParamProvider($varName, $provider);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setExceptionHandler(ExceptionHandlerInterface $exceptionHandler)
    {
        $this->invoker->setExceptionHandler($exceptionHandler);
    }

    /**
     * {@inheritDoc}
     * @throws Exception\Request
     */
    public function __get($name)
    {
        if (in_array($name, ['get', 'post', 'put', 'delete', 'head', 'options'])) {
            $this->request = clone $this->newRequest;
            $this->request->method = $name;

            return $this;
        }
        if (in_array($name, ['lazy', 'eager'])) {
            $this->request->in = $name;

            return $this;
        }
        if (in_array($name, ['sync'])) {
            $this->request->options[$name] = $name;

            return $this;
        }
        throw new Exception\BadRequest($name, 400);
    }

    /**
     * Return request string
     *
     * @return string
     */
    public function __toString()
    {
        return $this->request->toUri();
    }
}
}
namespace BEAR\Resource {

/**
 * This file is part of the {package} package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use Ray\Aop\MethodInvocation;
use ReflectionParameter;

interface ParamInterface
{
    /**
     * Set method invocation and parameter reflection
     *
     * @param MethodInvocation    $invocation
     * @param ReflectionParameter $parameter
     *
     * @return mixed
     */
    public function set(MethodInvocation $invocation, ReflectionParameter $parameter);

    /**
     * Return method invocation
     *
     * @return MethodInvocation
     */
    public function getMethodInvocation();

    /**
     * Return parameter
     *
     * @return ReflectionParameter
     */
    public function getParameter();

    /**
     * Inject argument
     *
     * @param mixed $arg
     *
     * @return string 'Aura\Signal\Manager::STOP'
     */
    public function inject($arg);
}
}
namespace Guzzle\Cache {


/**
 * Interface for cache adapters.
 *
 * Cache adapters allow Guzzle to utilize various frameworks for caching HTTP responses.
 *
 * @link http://www.doctrine-project.org/ Inspired by Doctrine 2
 */
interface CacheAdapterInterface
{
    /**
     * Test if an entry exists in the cache.
     *
     * @param string $id      cache id The cache id of the entry to check for.
     * @param array  $options Array of cache adapter options
     *
     * @return bool Returns TRUE if a cache entry exists for the given cache id, FALSE otherwise.
     */
    public function contains($id, array $options = null);

    /**
     * Deletes a cache entry.
     *
     * @param string $id      cache id
     * @param array  $options Array of cache adapter options
     *
     * @return bool TRUE on success, FALSE on failure
     */
    public function delete($id, array $options = null);

    /**
     * Fetches an entry from the cache.
     *
     * @param string $id      cache id The id of the cache entry to fetch.
     * @param array  $options Array of cache adapter options
     *
     * @return string The cached data or FALSE, if no cache entry exists for the given id.
     */
    public function fetch($id, array $options = null);

    /**
     * Puts data into the cache.
     *
     * @param string   $id       The cache id
     * @param string   $data     The cache entry/data
     * @param int|bool $lifeTime The lifetime. If != false, sets a specific lifetime for this cache entry
     * @param array    $options  Array of cache adapter options
     *
     * @return bool TRUE if the entry was successfully stored in the cache, FALSE otherwise.
     */
    public function save($id, $data, $lifeTime = false, array $options = null);
}
}
namespace BEAR\Resource {
/**
 * This file is part of the BEAR.Resource package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use BEAR\Resource\Adapter\AdapterInterface;

/**
 * Interface for resource client
 */
interface SchemeCollectionInterface
{
    /**
     * Set scheme
     *
     * @param $scheme
     *
     * @return SchemeCollection
     */
    public function scheme($scheme);

    /**
     * Set host
     *
     * @param $host
     *
     * @return SchemeCollection
     */
    public function host($host);

    /**
     * Set resource adapter
     *
     * @param AdapterInterface $adapter
     *
     * @return SchemeCollection
     */
    public function toAdapter(AdapterInterface $adapter);
}
}
namespace BEAR\Resource {
/**
 * This file is part of the BEAR.Resource package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use Ray\Di\Di\ImplementedBy;

/**
 * Interface for resource factory
 *
 * @ImplementedBy("Factory")
 */
interface FactoryInterface
{
    /**
     * Return new resource object instance
     *
     * @param string $uri resource URI
     *
     * @return \BEAR\Resource\ResourceObject;
     */
    public function newInstance($uri);
}
}
namespace BEAR\Resource {
/**
 * This file is part of the BEAR.Resource package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use Ray\Di\Di\Inject;
use Ray\Di\Di\Scope;
use Ray\Di\Exception\NotReadable;

/**
 * Resource object factory
 *
 * @Scope("singleton")
 */
class Factory implements FactoryInterface
{
    /**
     * Resource adapter biding config
     *
     * @var SchemeCollection
     */
    private $scheme = [];

    /**
     * @param SchemeCollectionInterface $scheme
     *
     * @Inject
     */
    public function __construct(SchemeCollectionInterface $scheme)
    {
        $this->scheme = $scheme;
    }

    /**
     * Set scheme collection
     *
     * @param SchemeCollectionInterface $scheme
     *
     * @Inject(optional = true)
     */
    public function setSchemeCollection(SchemeCollectionInterface $scheme)
    {
        $this->scheme = $scheme;
    }

    /**
     * {@inheritDoc}
     * @throws Exception\Scheme
     */
    public function newInstance($uri)
    {
        $parsedUrl = parse_url($uri);
        if (!(isset($parsedUrl['scheme']) && isset($parsedUrl['scheme']))) {
            throw new Exception\Uri;
        }
        $scheme = $parsedUrl['scheme'];
        $host = $parsedUrl['host'];
        if (!isset($this->scheme[$scheme])) {
            throw new Exception\Scheme($uri);
        }
        if (!isset($this->scheme[$scheme][$host])) {
            if (!(isset($this->scheme[$scheme]['*']))) {
                throw new Exception\Scheme($uri);
            }
            $host = '*';
        }
        try {
            $adapter = $this->scheme[$scheme][$host];
            /** @var $adapter \BEAR\Resource\Adapter\AdapterInterface */
            $resourceObject = $adapter->get($uri);
        } catch (NotReadable $e) {
            $resourceObject = $this->indexRequest($uri, $e);
        }

        $resourceObject->uri = $uri;

        return $resourceObject;
    }

    /**
     * @param string      $uri
     * @param NotReadable $e
     *
     * @return ResourceObject
     * @throws Exception\ResourceNotFound
     */
    private function indexRequest($uri, NotReadable $e)
    {
        if (substr($uri, -1) !== '/') {
            throw new Exception\ResourceNotFound($uri, 0, $e);
        }
        $resourceObject = $this->newInstance($uri . 'index');

        return $resourceObject;
    }
}
}
namespace BEAR\Resource {
/**
 * This file is part of the BEAR.Resource package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use Ray\Di\Di\ImplementedBy;

/**
 * Resource request invoke interface
 *
 * @ImplementedBy("BEAR\Resource\Invoker")
 */
interface InvokerInterface
{
    /**
     * Invoke resource request
     *
     * @param  Request $request
     *
     * @return ResourceObject
     */
    public function invoke(Request $request);

    /**
     * Invoke traversal
     *
     * invoke callable
     *
     * @param \Traversable $requests
     */
    public function invokeTraversal(\Traversable $requests);

    /**
     * Invoke Sync
     *
     * @param \SplObjectStorage $requests
     *
     * @return mixed
     */
    public function invokeSync(\SplObjectStorage $requests);

    /**
     * Set resource client
     *
     * @param ResourceInterface $resource
     */
    public function setResourceClient(ResourceInterface $resource);

    /**
     * Attach parameter provider
     *
     * @param string                 $varName
     * @param ParamProviderInterface $provider
     *
     * @return $this
     */
    public function attachParamProvider($varName, ParamProviderInterface $provider);

    /**
     * Add resource invoker exception handler
     *
     * @param ExceptionHandlerInterface $exceptionHandler
     *
     * @return mixed
     */
    public function setExceptionHandler(ExceptionHandlerInterface $exceptionHandler);
}
}
namespace BEAR\Resource {
/**
 * This file is part of the BEAR.Resource package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use Ray\Di\Di\ImplementedBy;

/**
 * Interface for resource request
 *
 * @ImplementedBy("BEAR\Resource\Request")
 */
interface RequestInterface
{
    /**
     * @param InvokerInterface $invoker
     *
     * @Inject
     */
    public function __construct(InvokerInterface $invoker);

    /**
     * Set query
     *
     * @param array $query
     *
     * @return $this
     */
    public function withQuery(array $query);

    /**
     * Add(merge) query
     *
     * @param array $query
     *
     * @return $this
     */
    public function addQuery(array $query);

    /**
     * InvokerInterface resource request
     *
     * @param array $query
     *
     * @return ResourceObject
     */
    public function __invoke(array $query = null);

    /**
     * To Request URI string
     *
     * @return string
     */
    public function toUri();

    /**
     * To Request URI string with request method
     *
     * @return string
     */
    public function toUriWithMethod();

    /**
     * Return request hash
     *
     * @return string
     */
    public function hash();
}
}
namespace BEAR\Resource {
/**
 * This file is part of the BEAR.Resource package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use ArrayIterator;
use Traversable;

/**
 * Trait for array access
 */
trait BodyArrayAccessTrait
{
    /**
     * Body
     *
     * @var mixed
     */
    public $body;

    /**
     * Returns the body value at the specified index
     *
     * @param mixed $offset offset
     *
     * @return mixed
     * @ignore
     */
    public function offsetGet($offset)
    {
        return $this->body[$offset];
    }

    /**
     * Sets the body value at the specified index to renew
     *
     * @param mixed $offset offset
     * @param mixed $value  value
     *
     * @return void
     * @ignore
     */
    public function offsetSet($offset, $value)
    {
        $this->body[$offset] = $value;
    }

    /**
     * Returns whether the requested index in body exists
     *
     * @param mixed $offset offset
     *
     * @return bool
     * @ignore
     */
    public function offsetExists($offset)
    {
        return isset($this->body[$offset]);
    }

    /**
     * Set the value at the specified index
     *
     * @param mixed $offset offset
     *
     * @return void
     * @ignore
     */
    public function offsetUnset($offset)
    {
        unset($this->body[$offset]);
    }

    /**
     * Get the number of public properties in the ArrayObject
     *
     * @return int
     */
    public function count()
    {
        return count($this->body);
    }

    /**
     * Sort the entries by key
     *
     * @return bool
     * @ignore
     */
    public function ksort()
    {
        return ksort($this->body);
    }

    /**
     * Sort the entries by key
     *
     * @return bool
     * @ignore
     */
    public function asort()
    {
        return asort($this->body);
    }

    /**
     * Get array iterator
     *
     * @return \ArrayIterator
     */
    public function getIterator()
    {
        $isTraversal = (is_array($this->body) || $this->body instanceof Traversable);
        return ($isTraversal ? new ArrayIterator($this->body) : new ArrayIterator([]));
    }
}
}
namespace BEAR\Resource {
/**
 * This file is part of the BEAR.Resource package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use ArrayAccess;
use ArrayIterator;
use IteratorAggregate;
use OutOfBoundsException;
use Traversable;
use Ray\Di\Di\Inject;
use Ray\Di\Di\Scope;

final class Request implements RequestInterface, ArrayAccess, IteratorAggregate
{
    use BodyArrayAccessTrait;

    /**
     * object URI scheme
     *
     * @var string
     */
    const SCHEME_OBJECT = 'object';

    /**
     * URI
     *
     * @var string
     */
    public $uri;

    /**
     * Resource object
     *
     * @var \BEAR\Resource\ResourceObject
     */
    public $ro;

    /**
     * Method
     *
     * @var string
     */
    public $method = '';

    /**
     * Query
     *
     * @var array
     */
    public $query = [];

    /**
     * Options
     *
     * @var array
     */
    public $options = [];

    /**
     * Request option (eager or lazy)
     *
     * @var string
     */
    public $in;

    /**
     * Links
     *
     * @var LinkType[]
     */
    public $links = [];

    /**
     * Request Result
     *
     * @var Object
     */
    private $result;

    /**
     * {@inheritDoc}
     *
     * @Inject
     */
    public function __construct(InvokerInterface $invoker)
    {
        $this->invoker = $invoker;
    }

    /**
     * Set
     *
     * @param ResourceObject $ro
     * @param string         $uri
     * @param string         $method
     * @param array          $query
     */
    public function set(ResourceObject $ro, $uri, $method, array $query)
    {
        $this->ro = $ro;
        $this->uri = $uri;
        $this->method = $method;
        $this->query = $query;
    }

    /**
     * {@inheritdoc}
     */
    public function withQuery(array $query)
    {
        $this->query = $query;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function addQuery(array $query)
    {
        $this->query = array_merge($this->query, $query);

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function __invoke(array $query = null)
    {
        if (!is_null($query)) {
            $this->query = array_merge($this->query, $query);
        }
        $result = $this->invoker->invoke($this);

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function toUri()
    {
        $uri = isset($this->ro->uri) && $this->ro->uri ? $this->ro->uri : $this->uri;
        $parsed = parse_url($uri);
        if ($this->query === []) {
            return $uri;
        }
        if (!isset($parsed['scheme'])) {
            return $uri;
        }
        $fullUri = $parsed['scheme'] . "://{$parsed['host']}{$parsed['path']}?" . http_build_query(
            $this->query,
            null,
            '&',
            PHP_QUERY_RFC3986
        );

        return $fullUri;
    }

    /**
     * {@inheritDoc}
     */
    public function toUriWithMethod()
    {
        return "{$this->method} " . $this->toUri();
    }

    /**
     * Render view
     *
     * @return string
     */
    public function __toString()
    {
        if (is_null($this->result)) {
            $this->result = $this->__invoke();
        }

        return (string)$this->result;
    }

    /**
     * Returns the body value at the specified index
     *
     * @param mixed $offset offset
     *
     * @return mixed
     * @throws OutOfBoundsException
     */
    public function offsetGet($offset)
    {
        if (is_null($this->result)) {
            $this->result = $this->__invoke();
        }
        if (!isset($this->result->body[$offset])) {
            throw new OutOfBoundsException("[$offset] for object[" . get_class($this->result) . "]");
        }

        return $this->result->body[$offset];
    }


    /**
     * Returns whether the requested index in body exists
     *
     * @param mixed $offset offset
     *
     * @return bool
     */
    public function offsetExists($offset)
    {
        if (is_null($this->result)) {
            $this->result = $this->__invoke();
        }

        return isset($this->result->body[$offset]);
    }

    /**
     * Get array iterator
     *
     * @return \ArrayIterator
     */
    public function getIterator()
    {
        if (is_null($this->result)) {
            $this->result = $this->__invoke();
        }
        $isArray = (is_array($this->result->body) || $this->result->body instanceof Traversable);
        $iterator = $isArray ? new ArrayIterator($this->result->body) : new ArrayIterator([]);

        return $iterator;
    }

    /**
     * {@inheritdoc}
     */
    public function hash()
    {
        return md5(get_class($this->ro) . $this->method . serialize($this->query) . serialize($this->links));
    }
}
}
namespace BEAR\Resource {
/**
 * This file is part of the BEAR.Resource package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use BEAR\Resource\Exception;
use Doctrine\Common\Annotations\AnnotationReader;
use Guzzle\Parser\UriTemplate\UriTemplateInterface;
use Ray\Di\Di\Scope;
use BEAR\Resource\Annotation;
use Ray\Di\Di\Inject;

/**
 * Anchor
 */
class Anchor
{
    /**
     * @var AnnotationReader
     */
    private $reader;

    /**
     * @var Request
     */
    private $request;

    /**
     * @param UriTemplateInterface $uriTemplate
     * @param AnnotationReader     $reader
     * @param Request              $request
     *
     * @Inject
     */
    public function __construct(
        UriTemplateInterface $uriTemplate,
        AnnotationReader $reader,
        Request $request
    ) {
        $this->uriTemplate = $uriTemplate;
        $this->reader = $reader;
        $this->request = $request;
    }

    /**
     * Return linked request with hyper reference
     *
     * @param string  $rel
     * @param array   $query
     * @param Request $request
     *
     * @return Request
     * @throws Exception\Link
     */
    public function href($rel, Request $request, array $query)
    {
        $classMethod = 'on' . ucfirst($request->method);
        $annotations = $this->reader->getMethodAnnotations(new \ReflectionMethod($request->ro, $classMethod));
        foreach ($annotations as $annotation) {
            $isValidLinkAnnotation = $annotation instanceof Annotation\Link && isset($annotation->rel) && $annotation->rel === $rel;
            if ($isValidLinkAnnotation) {
                $query = array_merge($request->ro->body, $query);
                $uri = $this->uriTemplate->expand($annotation->href, $query);

                return [$annotation->method, $uri];
            }
        }

        throw new Exception\Link("rel:{$rel} class:" . get_class($request->ro));
    }
}
}
namespace Ray\Di\Exception {
/**
 * This file is part of the Ray package.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

interface ExceptionInterface
{
}
}
namespace Ray\Di\Exception {
/**
 * This file is part of the Ray package.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use LogicException;

class Binding extends LogicException implements ExceptionInterface
{
}
}
namespace Ray\Di\Exception {
/**
 * This file is part of the Ray package.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use Ray\Di\AbstractModule;

class NotBound extends Binding implements ExceptionInterface
{
    /**
     * @var AbstractModule
     */
    public $module;

    /**
     * @param AbstractModule $module
     *
     * @return NotBound
     */
    public function setModule(AbstractModule $module)
    {
        $this->module = $module;

        return $this;
    }
}
}
namespace Guzzle\Cache {


/**
 * Abstract cache adapter
 */
abstract class AbstractCacheAdapter implements CacheAdapterInterface
{
    protected $cache;

    /**
     * Get the object owned by the adapter
     *
     * @return mixed
     */
    public function getCacheObject()
    {
        return $this->cache;
    }
}
}
namespace Guzzle\Cache {


use Doctrine\Common\Cache\Cache;

/**
 * Doctrine 2 cache adapter
 *
 * @link http://www.doctrine-project.org/
 */
class DoctrineCacheAdapter extends AbstractCacheAdapter
{
    /**
     * @param Cache $cache Doctrine cache object
     */
    public function __construct(Cache $cache)
    {
        $this->cache = $cache;
    }

    public function contains($id, array $options = null)
    {
        return $this->cache->contains($id);
    }

    public function delete($id, array $options = null)
    {
        return $this->cache->delete($id);
    }

    public function fetch($id, array $options = null)
    {
        return $this->cache->fetch($id);
    }

    public function save($id, $data, $lifeTime = false, array $options = null)
    {
        return $this->cache->save($id, $data, $lifeTime);
    }
}
}
namespace Doctrine\Common\Cache {
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license. For more information, see
 * <http://www.doctrine-project.org>.
 */


/**
 * Interface for cache drivers.
 *
 * @link   www.doctrine-project.org
 * @since  2.0
 * @author Benjamin Eberlei <kontakt@beberlei.de>
 * @author Guilherme Blanco <guilhermeblanco@hotmail.com>
 * @author Jonathan Wage <jonwage@gmail.com>
 * @author Roman Borschel <roman@code-factory.org>
 * @author Fabio B. Silva <fabio.bat.silva@gmail.com>
 */
interface Cache
{
    const STATS_HITS             = 'hits';
    const STATS_MISSES           = 'misses';
    const STATS_UPTIME           = 'uptime';
    const STATS_MEMORY_USAGE     = 'memory_usage';
    const STATS_MEMORY_AVAILABLE = 'memory_available';
    /**
     * Only for backward compatibility (may be removed in next major release)
     *
     * @deprecated
     */
    const STATS_MEMORY_AVAILIABLE = 'memory_available';

    /**
     * Fetches an entry from the cache.
     *
     * @param string $id The id of the cache entry to fetch.
     *
     * @return mixed The cached data or FALSE, if no cache entry exists for the given id.
     */
    function fetch($id);

    /**
     * Tests if an entry exists in the cache.
     *
     * @param string $id The cache id of the entry to check for.
     *
     * @return boolean TRUE if a cache entry exists for the given cache id, FALSE otherwise.
     */
    function contains($id);

    /**
     * Puts data into the cache.
     *
     * @param string $id       The cache id.
     * @param mixed  $data     The cache entry/data.
     * @param int    $lifeTime The cache lifetime.
     *                         If != 0, sets a specific lifetime for this cache entry (0 => infinite lifeTime).
     *
     * @return boolean TRUE if the entry was successfully stored in the cache, FALSE otherwise.
     */
    function save($id, $data, $lifeTime = 0);

    /**
     * Deletes a cache entry.
     *
     * @param string $id The cache id.
     *
     * @return boolean TRUE if the cache entry was successfully deleted, FALSE otherwise.
     */
    function delete($id);

    /**
     * Retrieves cached information from the data store.
     *
     * The server's statistics array has the following values:
     *
     * - <b>hits</b>
     * Number of keys that have been requested and found present.
     *
     * - <b>misses</b>
     * Number of items that have been requested and not found.
     *
     * - <b>uptime</b>
     * Time that the server is running.
     *
     * - <b>memory_usage</b>
     * Memory used by this server to store items.
     *
     * - <b>memory_available</b>
     * Memory allowed to use for storage.
     *
     * @since 2.2
     *
     * @return array|null An associative array with server's statistics if available, NULL otherwise.
     */
    function getStats();
}
}
namespace Doctrine\Common\Cache {
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license. For more information, see
 * <http://www.doctrine-project.org>.
 */


/**
 * Base class for cache provider implementations.
 *
 * @since  2.2
 * @author Benjamin Eberlei <kontakt@beberlei.de>
 * @author Guilherme Blanco <guilhermeblanco@hotmail.com>
 * @author Jonathan Wage <jonwage@gmail.com>
 * @author Roman Borschel <roman@code-factory.org>
 * @author Fabio B. Silva <fabio.bat.silva@gmail.com>
 */
abstract class CacheProvider implements Cache
{
    const DOCTRINE_NAMESPACE_CACHEKEY = 'DoctrineNamespaceCacheKey[%s]';

    /**
     * The namespace to prefix all cache ids with.
     *
     * @var string
     */
    private $namespace = '';

    /**
     * The namespace version.
     *
     * @var string
     */
    private $namespaceVersion;

    /**
     * Sets the namespace to prefix all cache ids with.
     *
     * @param string $namespace
     *
     * @return void
     */
    public function setNamespace($namespace)
    {
        $this->namespace        = (string) $namespace;
        $this->namespaceVersion = null;
    }

    /**
     * Retrieves the namespace that prefixes all cache ids.
     *
     * @return string
     */
    public function getNamespace()
    {
        return $this->namespace;
    }

    /**
     * {@inheritdoc}
     */
    public function fetch($id)
    {
        return $this->doFetch($this->getNamespacedId($id));
    }

    /**
     * {@inheritdoc}
     */
    public function contains($id)
    {
        return $this->doContains($this->getNamespacedId($id));
    }

    /**
     * {@inheritdoc}
     */
    public function save($id, $data, $lifeTime = 0)
    {
        return $this->doSave($this->getNamespacedId($id), $data, $lifeTime);
    }

    /**
     * {@inheritdoc}
     */
    public function delete($id)
    {
        return $this->doDelete($this->getNamespacedId($id));
    }

    /**
     * {@inheritdoc}
     */
    public function getStats()
    {
        return $this->doGetStats();
    }

    /**
     * Flushes all cache entries.
     *
     * @return boolean TRUE if the cache entries were successfully flushed, FALSE otherwise.
     */
    public function flushAll()
    {
        return $this->doFlush();
    }

    /**
     * Deletes all cache entries.
     *
     * @return boolean TRUE if the cache entries were successfully deleted, FALSE otherwise.
     */
    public function deleteAll()
    {
        $namespaceCacheKey = $this->getNamespaceCacheKey();
        $namespaceVersion  = $this->getNamespaceVersion() + 1;

        $this->namespaceVersion = $namespaceVersion;

        return $this->doSave($namespaceCacheKey, $namespaceVersion);
    }

    /**
     * Prefixes the passed id with the configured namespace value.
     *
     * @param string $id The id to namespace.
     *
     * @return string The namespaced id.
     */
    private function getNamespacedId($id)
    {
        $namespaceVersion  = $this->getNamespaceVersion();

        return sprintf('%s[%s][%s]', $this->namespace, $id, $namespaceVersion);
    }

    /**
     * Returns the namespace cache key.
     *
     * @return string
     */
    private function getNamespaceCacheKey()
    {
        return sprintf(self::DOCTRINE_NAMESPACE_CACHEKEY, $this->namespace);
    }

    /**
     * Returns the namespace version.
     *
     * @return string
     */
    private function getNamespaceVersion()
    {
        if (null !== $this->namespaceVersion) {
            return $this->namespaceVersion;
        }

        $namespaceCacheKey = $this->getNamespaceCacheKey();
        $namespaceVersion = $this->doFetch($namespaceCacheKey);

        if (false === $namespaceVersion) {
            $namespaceVersion = 1;

            $this->doSave($namespaceCacheKey, $namespaceVersion);
        }

        $this->namespaceVersion = $namespaceVersion;

        return $this->namespaceVersion;
    }

    /**
     * Fetches an entry from the cache.
     *
     * @param string $id The id of the cache entry to fetch.
     *
     * @return string|bool The cached data or FALSE, if no cache entry exists for the given id.
     */
    abstract protected function doFetch($id);

    /**
     * Tests if an entry exists in the cache.
     *
     * @param string $id The cache id of the entry to check for.
     *
     * @return boolean TRUE if a cache entry exists for the given cache id, FALSE otherwise.
     */
    abstract protected function doContains($id);

    /**
     * Puts data into the cache.
     *
     * @param string $id       The cache id.
     * @param string $data     The cache entry/data.
     * @param int    $lifeTime The lifetime. If != 0, sets a specific lifetime for this
     *                           cache entry (0 => infinite lifeTime).
     *
     * @return boolean TRUE if the entry was successfully stored in the cache, FALSE otherwise.
     */
    abstract protected function doSave($id, $data, $lifeTime = 0);

    /**
     * Deletes a cache entry.
     *
     * @param string $id The cache id.
     *
     * @return boolean TRUE if the cache entry was successfully deleted, FALSE otherwise.
     */
    abstract protected function doDelete($id);

    /**
     * Flushes all cache entries.
     *
     * @return boolean TRUE if the cache entry was successfully deleted, FALSE otherwise.
     */
    abstract protected function doFlush();

    /**
     * Retrieves cached information from the data store.
     *
     * @since 2.2
     *
     * @return array|null An associative array with server's statistics if available, NULL otherwise.
     */
    abstract protected function doGetStats();
}
}
namespace Doctrine\Common\Cache {
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license. For more information, see
 * <http://www.doctrine-project.org>.
 */


/**
 * APC cache provider.
 *
 * @link   www.doctrine-project.org
 * @since  2.0
 * @author Benjamin Eberlei <kontakt@beberlei.de>
 * @author Guilherme Blanco <guilhermeblanco@hotmail.com>
 * @author Jonathan Wage <jonwage@gmail.com>
 * @author Roman Borschel <roman@code-factory.org>
 * @author David Abdemoulaie <dave@hobodave.com>
 */
class ApcCache extends CacheProvider
{
    /**
     * {@inheritdoc}
     */
    protected function doFetch($id)
    {
        return apc_fetch($id);
    }

    /**
     * {@inheritdoc}
     */
    protected function doContains($id)
    {
        return apc_exists($id);
    }

    /**
     * {@inheritdoc}
     */
    protected function doSave($id, $data, $lifeTime = 0)
    {
        return (bool) apc_store($id, $data, (int) $lifeTime);
    }

    /**
     * {@inheritdoc}
     */
    protected function doDelete($id)
    {
        return apc_delete($id);
    }

    /**
     * {@inheritdoc}
     */
    protected function doFlush()
    {
        return apc_clear_cache() && apc_clear_cache('user');
    }

    /**
     * {@inheritdoc}
     */
    protected function doGetStats()
    {
        $info = apc_cache_info();
        $sma  = apc_sma_info();

        // @TODO - Temporary fix @see https://github.com/krakjoe/apcu/pull/42
        if (PHP_VERSION_ID >= 50500) {
            $info['num_hits']   = isset($info['num_hits'])   ? $info['num_hits']   : $info['nhits'];
            $info['num_misses'] = isset($info['num_misses']) ? $info['num_misses'] : $info['nmisses'];
            $info['start_time'] = isset($info['start_time']) ? $info['start_time'] : $info['stime'];
        }

        return array(
            Cache::STATS_HITS             => $info['num_hits'],
            Cache::STATS_MISSES           => $info['num_misses'],
            Cache::STATS_UPTIME           => $info['start_time'],
            Cache::STATS_MEMORY_USAGE     => $info['mem_size'],
            Cache::STATS_MEMORY_AVAILABLE => $sma['avail_mem'],
        );
    }
}
}
namespace BEAR\Resource {
/**
 * This file is part of the BEAR.Resource package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use ArrayObject;
use BEAR\Resource\Adapter\AdapterInterface;

/**
 * Resource scheme collection
 */
class SchemeCollection extends ArrayObject implements SchemeCollectionInterface
{
    /**
     * Scheme
     *
     * @var string
     */
    private $scheme;

    /**
     * Host
     *
     * @var string
     */
    private $host;

    /**
     * Set scheme
     *
     * @param $scheme
     *
     * @return SchemeCollection
     */
    public function scheme($scheme)
    {
        $this->scheme = $scheme;

        return $this;
    }

    /**
     * Set host
     *
     * @param $host
     *
     * @return SchemeCollection
     */
    public function host($host)
    {
        $this->host = $host;

        return $this;
    }

    /**
     * Set resource adapter
     *
     * @param AdapterInterface $adapter
     *
     * @return SchemeCollection
     */
    public function toAdapter(AdapterInterface $adapter)
    {
        $this[$this->scheme][$this->host] = $adapter;
        $this->scheme = $this->host = null;

        return $this;
    }
}
}
namespace BEAR\Resource {
/**
 * This file is part of the BEAR.Resource package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

interface ProviderInterface
{
    /**
     * Return new resource object
     *
     * @param string $uri
     *
     * @return ResourceObject
     */
    public function get($uri);
}
}
namespace BEAR\Resource\Adapter {
/**
 * This file is part of the BEAR.Resource package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use BEAR\Resource\ProviderInterface;

/**
 * Interface for resource adapter
 */
interface AdapterInterface extends ProviderInterface
{
}
}
namespace BEAR\Resource\Adapter {
/**
 * This file is part of the BEAR.Resource package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use BEAR\Resource\Exception\AppNamespace;
use Ray\Di\InjectorInterface;
use Ray\Di\Di\Inject;
use Ray\Di\Di\Scope;

/**
 * Application resource adapter
 */
class App implements AdapterInterface
{
    /**
     * Application dependency injector
     *
     * @var \Ray\Di\Injector
     */
    private $injector;

    /**
     * Resource adapter namespace
     *
     * @var array
     */
    private $namespace;

    /**
     * Resource adapter path
     *
     * @var array
     */
    private $path;

    /**
     * @param InjectorInterface $injector  Application dependency injector
     * @param string            $namespace Resource adapter namespace
     * @param string            $path      Resource adapter path
     *
     * @Inject
     * @throws AppNamespace
     */
    public function __construct(
        InjectorInterface $injector,
        $namespace,
        $path
    ) {
        if (!is_string($namespace)) {
            throw new AppNamespace(gettype($namespace));
        }
        $this->injector = $injector;
        $this->namespace = $namespace;
        $this->path = $path;
    }

    /**
     * {@inheritdoc}
     */
    public function get($uri)
    {
        $parsedUrl = parse_url($uri);
        $path = str_replace('/', ' ', $parsedUrl['path']);
        $path = ucwords($path);
        $path = str_replace(' ', '\\', $path);
        $className = "{$this->namespace}\\{$this->path}{$path}";
        $instance = $this->injector->getInstance($className);

        return $instance;
    }
}
}
namespace BEAR\Resource\Adapter {
/**
 * This file is part of the BEAR.Resource package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use Guzzle\Service\Client as GuzzleClient;

/**
 * Http resource adapter
 */
class Http implements AdapterInterface
{
    /**
     * {@inheritdoc}
     */
    public function get($uri)
    {
        $instance = new Http\Guzzle(new GuzzleClient($uri));

        return $instance;
    }
}
}
namespace BEAR\Resource {
/**
 * This file is part of the BEAR.Resource package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use Ray\Di\Definition;
use Ray\Di\Di\Inject;
use Ray\Di\Di\Named;
use Ray\Di\Di\Scope;

/**
 * Resource request invoker
 *
 * @Scope("singleton")
 */
class Invoker implements InvokerInterface
{
    /**
     * @var Linker
     */
    private $linker;

    /**
     * Logger
     *
     * @var Logger
     */
    private $logger;

    /**
     * @var NamedParameter
     */
    protected $params;

    /**
     * @var ExceptionHandlerInterface
     */
    private $exceptionHandler;

    /**
     * Method OPTIONS
     *
     * @var string
     */
    const OPTIONS = 'options';

    /**
     * Method HEAD
     *
     * @var string
     */
    const HEAD = 'head';

    /**
     * ProviderInterface annotation
     *
     * @var string
     */
    const ANNOTATION_PROVIDES = 'Provides';


    /**
     * {@inheritDoc}
     */
    public function setResourceClient(ResourceInterface $resource)
    {
        $this->linker->setResource($resource);
    }

    /**
     * Resource logger setter
     *
     * @param LoggerInterface $logger
     *
     * @return $this
     * @Inject(optional=true)
     */
    public function setResourceLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * @param LinkerInterface           $linker
     * @param NamedParameter            $params
     * @param LoggerInterface           $logger
     * @param ExceptionHandlerInterface $exceptionHandler
     *
     * @Inject
     */
    public function __construct(
        LinkerInterface $linker,
        NamedParameter  $params,
        LoggerInterface $logger = null,
        ExceptionHandlerInterface $exceptionHandler = null
    ) {
        $this->linker = $linker;
        $this->params = $params;
        $this->logger = $logger;
        $this->exceptionHandler = $exceptionHandler ?: new ExceptionHandler;
    }

    /**
     * {@inheritDoc}
     */
    public function invoke(Request $request)
    {
        $onMethod = 'on' . ucfirst($request->method);
        if (method_exists($request->ro, $onMethod) !== true) {
            return $this->methodNotExists($request->ro, $request, $onMethod);
        }
        // invoke with Named param and Signal param
        $args = $this->params->getArgs([$request->ro, $onMethod], $request->query);

        $result = null;
        try {
            $result = call_user_func_array([$request->ro, $onMethod], $args);
        } catch (Exception\Parameter $e) {
            $e =  new Exception\ParameterInService('', 0, $e);
            $result = $this->exceptionHandler->handle($e, $request);
        } catch (\Exception $e) {
            $result = $this->exceptionHandler->handle($e, $request);
        }

        if (!$result instanceof ResourceObject) {
            $request->ro->body = $result;
            $result = $request->ro;
        }

        // link
        completed:
        if ($request->links) {
            $result = $this->linker->invoke($request);
        }

        // log
        if ($this->logger instanceof LoggerInterface) {
            $this->logger->log($request, $result);
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function invokeTraversal(\Traversable $requests)
    {
        foreach ($requests as &$element) {
            if ($element instanceof Request || is_callable($element)) {
                $element = $element();
            }
        }

        return $requests;
    }

    /**
     * {@inheritDoc}
     */
    public function invokeSync(\SplObjectStorage $requests)
    {
        $requests->rewind();
        $data = new \ArrayObject();
        while ($requests->valid()) {
            // each sync request method call.
            $request = $requests->current();
            if (method_exists($request->ro, 'onSync')) {
                call_user_func([$request->ro, 'onSync'], $request, $data);
            }
            $requests->next();
        }
        // onFinalSync summarize all sync request data.
        /** @noinspection PhpUndefinedVariableInspection */
        $result = call_user_func([$request->ro, 'onFinalSync'], $request, $data);

        return $result;
    }

    /**
     * Return available resource request method
     *
     * @param ResourceObject $ro
     *
     * @return array
     */
    protected function getOptions(ResourceObject $ro)
    {
        $ref = new \ReflectionClass($ro);
        $methods = $ref->getMethods();
        $allow = [];
        foreach ($methods as $method) {
            $isRequestMethod = (substr($method->name, 0, 2) === 'on') && (substr($method->name, 0, 6) !== 'onLink');
            if ($isRequestMethod) {
                $allow[] = strtolower(substr($method->name, 2));
            }
        }
        $params = [];
        foreach ($allow as $method) {
            $refMethod = new \ReflectionMethod($ro, 'on' . $method);
            $parameters = $refMethod->getParameters();
            $paramArray = [];
            foreach ($parameters as $parameter) {
                $name = $parameter->getName();
                $param = $parameter->isOptional() ? "({$name})" : $name;
                $paramArray[] = $param;
            }
            $key = "param-{$method}";
            $params[$key] = implode(',', $paramArray);
        }
        $result = ['allow' => $allow, 'params' => $params];

        return $result;
    }

    /**
     * @param ResourceObject $ro
     * @param Request        $request
     * @param                $method
     *
     * @return ResourceObject
     * @throws Exception\MethodNotAllowed
     */
    private function methodNotExists(ResourceObject $ro, Request $request, $method)
    {
        if ($request->method === self::OPTIONS) {
            return $this->onOptions($ro);
        }
        if ($method === 'onHead' && method_exists($ro, 'onGet')) {
            return $this->onHead($request);
        }

        throw new Exception\MethodNotAllowed(get_class($request->ro) . "::$method()", 405);
    }

    /**
     * @param ResourceObject $ro resource object
     *
     * @return ResourceObject
     */
    private function onOptions(ResourceObject $ro)
    {
        $options = $this->getOptions($ro);
        $ro->headers['allow'] = $options['allow'];
        $ro->headers += $options['params'];
        $ro->body = null;

        return $ro;
    }

    /**
     * @param Request $request
     *
     * @return ResourceObject
     * @throws Exception\ParameterInService
     */
    private function onHead(Request $request)
    {
        if (method_exists($request->ro, 'onGet')) {
            // invoke with Named param and Signal param
            $args = $this->params->getArgs([$request->ro, 'onGet'], $request->query);
            try {
                call_user_func_array([$request->ro, 'onGet'], $args);
            } catch (Exception\Parameter $e) {
                throw new Exception\ParameterInService('', 0, $e);
            }
        }
        $request->ro->body = '';

        return $request->ro;
    }

    /**
     * {@inheritdoc}
     */
    public function attachParamProvider($varName, ParamProviderInterface $provider)
    {
        $this->params->attachParamProvider($varName, $provider);
    }

    /**
     * {@inheritdoc}
     */
    public function setExceptionHandler(ExceptionHandlerInterface $exceptionHandler)
    {
        $this->exceptionHandler = $exceptionHandler;
    }
}
}
namespace BEAR\Resource {
/**
 * This file is part of the BEAR.Resource package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use IteratorAggregate;

/**
 * Interface for resource logger
 */
interface LoggerInterface extends IteratorAggregate
{
    /**
     * Log
     *
     * @param RequestInterface $request
     * @param ResourceObject   $result
     *
     * @return void
     */
    public function log(RequestInterface $request, ResourceObject $result);

    /**
     * Set log writer
     *
     * @param LogWriterInterface $writer
     *
     * @return void
     */
    public function setWriter(LogWriterInterface $writer);

    /**
     * write log
     *
     * @return void
     */
    public function write();
}
}
namespace BEAR\Resource {
/**
 * This file is part of the BEAR.Resource package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use Ray\Di\Di\ImplementedBy;

/**
 * Interface for resource link
 *
 * @ImplementedBy("BEAR\Resource\Linker")
 */
interface LinkerInterface
{
    /**
     * InvokerInterface link
     *
     * @param Request $request
     *
     * @return mixed
     */
    public function invoke(Request $request);
}
}
namespace BEAR\Resource {
/**
 * This file is part of the BEAR.Resource package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

interface NamedParameterInterface
{
    /**
     * Get arguments
     *
     * @param array $callable
     * @param array $query
     *
     * @return array
     */
    public function getArgs(array $callable, array $query);
}
}
namespace BEAR\Resource {
/**
 * This file is part of the BEAR.Resource package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use BEAR\Resource\Exception;
use Ray\Aop\ReflectiveMethodInvocation;
use ReflectionParameter;
use Ray\Di\Di\Inject;

final class NamedParameter implements NamedParameterInterface
{
    /**
     * @var SignalParameterInterface
     */
    private $signalParameter;

    /**
     * @param SignalParameterInterface $signalParameter
     *
     * @Inject(optional=true)
     */
    public function __construct(SignalParameterInterface $signalParameter = null)
    {
        $this->signalParameter = $signalParameter;
    }

    /**
     * {@inheritdoc}
     */
    public function getArgs(array $callable, array $query)
    {
        $namedArgs = $query;
        $method = new \ReflectionMethod($callable[0], $callable[1]);
        $parameters = $method->getParameters();
        $args = [];
        foreach ($parameters as $parameter) {
            /** @var $parameter \ReflectionParameter */
            if (isset($namedArgs[$parameter->name])) {
                $args[] = $namedArgs[$parameter->name];
                continue;
            }
            if ($parameter->isDefaultValueAvailable() === true) {
                $args[] = $parameter->getDefaultValue();
                continue;
            }
            if ($this->signalParameter) {
                $invocation = new ReflectiveMethodInvocation($callable, $query);
                $args[] = $this->signalParameter->getArg($parameter, $invocation);
                continue;
            }
            $msg = '$' . "{$parameter->name} in " . get_class($callable[0]) . '::' . $callable[1] . '()';
            throw new Exception\Parameter($msg);
        }

        return $args;
    }

    /**
     * {@inheritdoc}
     */
    public function attachParamProvider($varName, ParamProviderInterface $provider)
    {
        $this->signalParameter->attachParamProvider($varName, $provider);
    }
}
}
namespace BEAR\Resource {
/**
 * This file is part of the BEAR.Resource package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

/**
 * Interface for Resource request invoker
 */
interface ExceptionHandlerInterface
{
    /**
     * Resource request invoke handle exception
     *
     * @param \Exception $e
     * @param Request    $request
     *
     * @return resource object or its body
     */
    public function handle(\Exception $e, Request $request);
}
}
namespace BEAR\Resource {
/**
 * This file is part of the BEAR.Resource package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use Any\Serializer\SerializeInterface;
use Any\Serializer\Serializer;
use ArrayIterator;
use Countable;
use Serializable;
use Ray\Di\Di\Inject;
use Ray\Di\Di\Scope;

/**
 * Resource logger
 */
class Logger implements LoggerInterface, Countable, Serializable
{
    const LOG_REQUEST = 0;

    const LOG_RESULT = 1;

    /**
     * Logs
     *
     * @var array
     */
    private $logs = [];

    /**
     * @var LogWriterInterface
     */
    private $writer;


    /**
     * @var SerializeInterface
     */
    private $serializer;

    /**
     * @param SerializeInterface $serializer
     *
     * @Inject(optional=true)
     */
    public function __construct(SerializeInterface $serializer = null)
    {
        $this->serializer = $serializer ? : new Serializer;
    }

    /**
     * Return new resource object instance
     *
     * {@inheritDoc}
     */
    public function log(RequestInterface $request, ResourceObject $result)
    {
        $this->logs[] = [
            self::LOG_REQUEST => $request,
            self::LOG_RESULT => $result
        ];
    }

    /**
     * {@inheritDoc}
     *
     * @Inject(optional = true)
     */
    public function setWriter(LogWriterInterface $writer)
    {
        $this->writer = $writer;
    }

    /**
     * {@inheritDoc}
     */
    public function write()
    {

        if ($this->writer instanceof LogWriterInterface) {
            foreach ($this->logs as $log) {
                $this->writer->write($log[0], $log[1]);
            }
            $this->logs = [];

            return true;
        }

        return false;
    }

    /**
     * Return iterator
     *
     * @return \ArrayIterator
     */
    public function getIterator()
    {
        return new ArrayIterator($this->logs);
    }

    /**
     * @return int
     */
    public function count()
    {
        return count($this->logs);
    }

    public function serialize()
    {
        $this->logs = [];

        return serialize([$this->writer, $this->serializer]);
    }

    public function unserialize($data)
    {
        list($this->writer, $this->serializer) = unserialize($data);
    }
}
}
namespace Any\Serializer {
/**
 * This file is part of the BEAR.Serializer package
 *
 * @package BEAR.Serializer
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

/**
 * Interface for Serialize
 *
 * @package BEAR.Serializer
 */
interface SerializeInterface
{
    /**
     * Serialize
     *
     * @param $value
     *
     * @return string
     */
    public function serialize($value);

    /**
     * Remove unserializable item
     *
     * @param $object
     *
     * @return mixed
     */
    public function removeUnserializable($object);
}
}
namespace BEAR\Resource {
/**
 * This file is part of the BEAR.Resource package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

/**
 * Interface for resource log writer
 */
interface LogWriterInterface
{
    /**
     * Resource log write
     *
     * @param RequestInterface $request
     * @param ResourceObject   $result
     *
     * @return bool true if log written
     */
    public function write(RequestInterface $request, ResourceObject $result);
}
}
namespace Ray\Di\Exception {
/**
 * This file is part of the Ray package.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

class OptionalInjectionNotBound extends Binding implements ExceptionInterface
{
}
}
namespace Any\Serializer {
/**
 * This file is part of the BEAR.Serializer package
 *
 * @package BEAR.Serializer
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

/**
 * Serialize any object for logging purpose.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */
class Serializer implements SerializeInterface
{
    /**
     * @var array
     */
    private $hash = [];

    /**
     * {@inheritdoc}
     */
    public function serialize($value)
    {
        return serialize(
            $this->removeUnserializable($value)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function removeUnserializable($value)
    {
        if (is_scalar($value)) {
            return $value;
        }
        if (is_array($value)) {
            $this->removeReferenceItemInArray($value);
            $this->serializeArray($value);
            return $value;
        }
        $hash = spl_object_hash($value);
        if (in_array($hash, $this->hash)) {
            $value = null;

            return $value;
        }
        $this->hash[] = $hash;
        $props = (new \ReflectionObject($value))->getProperties();
        foreach ($props as &$prop) {
            $prop->setAccessible(true);
            $propVal = $prop->getValue($value);
            if (is_array($propVal)) {
                $this->removeUnrealizableInArray($propVal);
                $prop->setValue($value, $propVal);
            }
            if (is_object($propVal)) {
                $propVal = $this->removeUnserializable($propVal);
                $prop->setValue($value, $propVal);

            }
            if ($this->isUnserializable($propVal)) {
                $prop->setValue($value, null);
            }
        }

        return $value;
    }

    /**
     * @param array $array
     */
    public function serializeArray(array &$array)
    {
        foreach ($array as &$item) {
            $this->removeUnserializable($item);
        }
    }
    /**
     * remove Unrealizable In Array
     *
     * @param array &$array
     *
     * @return array
     */
    private function removeUnrealizableInArray(array &$array)
    {
        $this->removeReferenceItemInArray($array);
        foreach ($array as &$value) {
            if (is_object($value)) {
                $value = $this->removeUnserializable($value);
            }
            if (is_array($value)) {
                $this->removeUnrealizableInArray($value);
            }
            if ($this->isUnserializable($value)) {
                $value = null;
            }
        }

        return $array;
    }

    /**
     * Remove reference item in array
     *
     * @param array &$room
     *
     * @return void
     *
     * @see http://stackoverflow.com/questions/3148125/php-check-if-object-array-is-a-reference
     * @author Chris Smith (original source)
     */
    private function removeReferenceItemInArray(array &$room)
    {
        $roomCopy = $room;
        $keys = array_keys($room);
        foreach ($keys as $key) {
            if (is_array($roomCopy[$key])) {
                $roomCopy[$key]['_test'] = true;
                if (isset($room[$key]['_test'])) {
                    // It's a reference
                    unset($room[$key]);
                }
            }
        }
    }

    /***
     * Return is unserialize
     *
     * @param mixed $value
     *
     * @return bool
     */
    private function isUnserializable($value)
    {
        return (is_callable($value) || is_resource($value) || $value instanceof \PDO);
    }
}
}
namespace BEAR\Resource {
/**
 * This file is part of the BEAR.Resource package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use BEAR\Resource\Exception;
use Doctrine\Common\Annotations\Reader;
use Doctrine\Common\Cache\ArrayCache;
use Doctrine\Common\Cache\Cache;
use Guzzle\Parser\UriTemplate\UriTemplate;
use Guzzle\Parser\UriTemplate\UriTemplateInterface;
use ReflectionMethod;
use Ray\Di\Di\Inject;
use Ray\Di\Di\Scope;

/**
 * Resource linker
 *
 * @Scope("singleton")
 */
final class Linker implements LinkerInterface
{
    /**
     * Resource client
     *
     * @var ResourceInterface
     */
    private $resource;

    /**
     * @var \Guzzle\Parser\UriTemplate\UriTemplate
     */
    private $uriTemplate;

    /**
     * @param Reader               $reader
     * @param Cache                $cache
     * @param UriTemplateInterface $uriTemplate
     *
     * @Inject
     */
    public function __construct(
        Reader $reader,
        Cache $cache = null,
        UriTemplateInterface $uriTemplate = null
    ) {
        $this->reader = $reader;
        $this->cache = $cache ? : new ArrayCache;
        $this->uriTemplate = $uriTemplate ? : new UriTemplate;
    }

    /**
     * Set resource
     *
     * @param $resource $resource
     */
    public function setResource(ResourceInterface $resource)
    {
        $this->resource = $resource;
    }

    /**
     * {@inheritDoc}
     */
    public function invoke(Request $request)
    {
        $current = clone $request->ro;
        foreach ($request->links as $link) {
            $nextResource = $this->annotationLink($link, $current, $request);
            $current = $this->nextLink($link, $current, $nextResource);
        }

        return $current;
    }

    /**
     * How next linked resource treated (add ? replace ?)
     *
     * @param LinkType       $link
     * @param ResourceObject $ro
     * @param                $nextResource
     *
     * @return ResourceObject
     */
    private function nextLink(LinkType $link, ResourceObject $ro, $nextResource)
    {
        $nextBody = $nextResource instanceof ResourceObject ? $nextResource->body : $nextResource;

        if ($link->type === LinkType::SELF_LINK) {
            $ro->body = $nextBody;

            return $ro;
        }

        if ($link->type === LinkType::NEW_LINK) {
            $ro->body[$link->key] = $nextBody;

            return $ro;
        }

        // crawl
        return $ro;
    }

    /**
     * Annotation link
     *
     * @param LinkType       $link
     * @param ResourceObject $current
     * @param Request        $request
     *
     * @return ResourceObject|mixed
     * @throws Exception\LinkQuery
     */
    private function annotationLink(LinkType $link, ResourceObject $current, Request $request)
    {
        if (!(is_array($current->body))) {
            throw new Exception\LinkQuery('Only array is allowed for link in ' . get_class($current));
        }
        $classMethod = 'on' . ucfirst($request->method);
        $annotations = $this->reader->getMethodAnnotations(new ReflectionMethod($current, $classMethod));
        if ($link->type === LinkType::CRAWL_LINK) {
            return $this->annotationCrawl($annotations, $link, $current);
        }

        return $this->annotationRel($annotations, $link, $current)->body;
    }

    /**
     * Annotation link (new, self)
     *
     * @param array          $annotations
     * @param LinkType       $link
     * @param ResourceObject $current
     *
     * @return ResourceObject
     * @throws Exception\LinkQuery
     * @throws Exception\LinkRel
     */
    private function annotationRel(array $annotations, LinkType $link, ResourceObject $current)
    {
        foreach ($annotations as $annotation) {
            /* @var $annotation Annotation\Link */
            if ($annotation->rel !== $link->key) {
                continue;
            }
            $uri = $this->uriTemplate->expand($annotation->href, $current->body);
            try {
                $linkedResource = $this->resource->{$annotation->method}->uri($uri)->eager->request();
                /* @var $linkedResource ResourceObject */
            } catch (Exception\Parameter $e) {
                $msg = 'class:' . get_class($current) . " link:{$link->key} query:" . json_encode($current->body);
                throw new Exception\LinkQuery($msg, 0, $e);
            }

            return $linkedResource;
        }
        throw new Exception\LinkRel("[{$link->key}] in " . get_class($current) . ' is not available.');
    }

    /**
     * Link annotation crawl
     *
     * @param array          $annotations
     * @param LinkType       $link
     * @param ResourceObject $current
     *
     * @return ResourceObject
     */
    private function annotationCrawl(array $annotations, LinkType $link, ResourceObject $current)
    {
        $isList = $this->isList($current->body);
        $bodyList = $isList ? $current->body : [$current->body];
        foreach ($bodyList as &$body) {
            $this->crawl($annotations, $link, $body);
        }
        $current->body = $isList ? $bodyList : $bodyList[0];

        return $current;
    }

    /**
     * @param array    $annotations
     * @param LinkType $link
     * @param array    $body
     */
    private function crawl(array $annotations, LinkType $link, array &$body)
    {
        foreach ($annotations as $annotation) {
            /* @var $annotation Annotation\Link */
            if ($annotation->crawl !== $link->key) {
                continue;
            }
            $uri = $this->uriTemplate->expand($annotation->href, $body);
            $request = $this->resource->{$annotation->method}->uri($uri)->linkCrawl($link->key)->request();
            /* @var $request Request */
            $hash = $request->hash();
            if ($this->cache->contains($hash)) {
                $body[$annotation->rel] = $this->cache->fetch($hash);
                continue;
            }
            /* @var $linkedResource ResourceObject */
            $body[$annotation->rel] = $request()->body;
            $this->cache->save($hash, $body[$annotation->rel]);
        }
    }

    /**
     * Is data list ?
     *
     * @param mixed $value
     *
     * @return boolean
     */
    private function isList($value)
    {
        $value = array_values((array)$value);
        $isMultiColumnList = (count($value) > 1
            && isset($value[0])
            && isset($value[1])
            && is_array($value[0])
            && is_array($value[1])
            && (array_keys($value[0]) === array_keys($value[1]))
        );
        $isOneColumnList = (count($value) === 1) && is_array($value[0]);

        return ($isOneColumnList | $isMultiColumnList);
    }
}
}
namespace Guzzle\Parser\UriTemplate {


/**
 * Expands URI templates using an array of variables
 *
 * @link http://tools.ietf.org/html/rfc6570
 */
interface UriTemplateInterface
{
    /**
     * Expand the URI template using the supplied variables
     *
     * @param string $template  URI Template to expand
     * @param array  $variables Variables to use with the expansion
     *
     * @return string Returns the expanded template
     */
    public function expand($template, array $variables);
}
}
namespace Guzzle\Parser\UriTemplate {


/**
 * Expands URI templates using an array of variables
 *
 * @link http://tools.ietf.org/html/draft-gregorio-uritemplate-08
 */
class UriTemplate implements UriTemplateInterface
{
    const DEFAULT_PATTERN = '/\{([^\}]+)\}/';

    /** @var string URI template */
    private $template;

    /** @var array Variables to use in the template expansion */
    private $variables;

    /** @var string Regex used to parse expressions */
    private $regex = self::DEFAULT_PATTERN;

    /** @var array Hash for quick operator lookups */
    private static $operatorHash = array(
        '+' => true, '#' => true, '.' => true, '/' => true, ';' => true, '?' => true, '&' => true
    );

    /** @var array Delimiters */
    private static $delims = array(
        ':', '/', '?', '#', '[', ']', '@', '!', '$', '&', '\'', '(', ')', '*', '+', ',', ';', '='
    );

    /** @var array Percent encoded delimiters */
    private static $delimsPct = array(
        '%3A', '%2F', '%3F', '%23', '%5B', '%5D', '%40', '%21', '%24', '%26', '%27', '%28', '%29', '%2A', '%2B', '%2C',
        '%3B', '%3D'
    );

    public function expand($template, array $variables)
    {
        if ($this->regex == self::DEFAULT_PATTERN && false === strpos($template, '{')) {
            return $template;
        }

        $this->template = $template;
        $this->variables = $variables;

        return preg_replace_callback($this->regex, array($this, 'expandMatch'), $this->template);
    }

    /**
     * Set the regex patten used to expand URI templates
     *
     * @param string $regexPattern
     */
    public function setRegex($regexPattern)
    {
        $this->regex = $regexPattern;
    }

    /**
     * Parse an expression into parts
     *
     * @param string $expression Expression to parse
     *
     * @return array Returns an associative array of parts
     */
    private function parseExpression($expression)
    {
        // Check for URI operators
        $operator = '';

        if (isset(self::$operatorHash[$expression[0]])) {
            $operator = $expression[0];
            $expression = substr($expression, 1);
        }

        $values = explode(',', $expression);
        foreach ($values as &$value) {
            $value = trim($value);
            $varspec = array();
            $substrPos = strpos($value, ':');
            if ($substrPos) {
                $varspec['value'] = substr($value, 0, $substrPos);
                $varspec['modifier'] = ':';
                $varspec['position'] = (int) substr($value, $substrPos + 1);
            } elseif (substr($value, -1) == '*') {
                $varspec['modifier'] = '*';
                $varspec['value'] = substr($value, 0, -1);
            } else {
                $varspec['value'] = (string) $value;
                $varspec['modifier'] = '';
            }
            $value = $varspec;
        }

        return array(
            'operator' => $operator,
            'values'   => $values
        );
    }

    /**
     * Process an expansion
     *
     * @param array $matches Matches met in the preg_replace_callback
     *
     * @return string Returns the replacement string
     */
    private function expandMatch(array $matches)
    {
        static $rfc1738to3986 = array(
            '+'   => '%20',
            '%7e' => '~'
        );

        $parsed = self::parseExpression($matches[1]);
        $replacements = array();

        $prefix = $parsed['operator'];
        $joiner = $parsed['operator'];
        $useQueryString = false;
        if ($parsed['operator'] == '?') {
            $joiner = '&';
            $useQueryString = true;
        } elseif ($parsed['operator'] == '&') {
            $useQueryString = true;
        } elseif ($parsed['operator'] == '#') {
            $joiner = ',';
        } elseif ($parsed['operator'] == ';') {
            $useQueryString = true;
        } elseif ($parsed['operator'] == '' || $parsed['operator'] == '+') {
            $joiner = ',';
            $prefix = '';
        }

        foreach ($parsed['values'] as $value) {

            if (!array_key_exists($value['value'], $this->variables) || $this->variables[$value['value']] === null) {
                continue;
            }

            $variable = $this->variables[$value['value']];
            $actuallyUseQueryString = $useQueryString;
            $expanded = '';

            if (is_array($variable)) {

                $isAssoc = $this->isAssoc($variable);
                $kvp = array();
                foreach ($variable as $key => $var) {

                    if ($isAssoc) {
                        $key = rawurlencode($key);
                        $isNestedArray = is_array($var);
                    } else {
                        $isNestedArray = false;
                    }

                    if (!$isNestedArray) {
                        $var = rawurlencode($var);
                        if ($parsed['operator'] == '+' || $parsed['operator'] == '#') {
                            $var = $this->decodeReserved($var);
                        }
                    }

                    if ($value['modifier'] == '*') {
                        if ($isAssoc) {
                            if ($isNestedArray) {
                                // Nested arrays must allow for deeply nested structures
                                $var = strtr(http_build_query(array($key => $var)), $rfc1738to3986);
                            } else {
                                $var = $key . '=' . $var;
                            }
                        } elseif ($key > 0 && $actuallyUseQueryString) {
                            $var = $value['value'] . '=' . $var;
                        }
                    }

                    $kvp[$key] = $var;
                }

                if (empty($variable)) {
                    $actuallyUseQueryString = false;
                } elseif ($value['modifier'] == '*') {
                    $expanded = implode($joiner, $kvp);
                    if ($isAssoc) {
                        // Don't prepend the value name when using the explode modifier with an associative array
                        $actuallyUseQueryString = false;
                    }
                } else {
                    if ($isAssoc) {
                        // When an associative array is encountered and the explode modifier is not set, then the
                        // result must be a comma separated list of keys followed by their respective values.
                        foreach ($kvp as $k => &$v) {
                            $v = $k . ',' . $v;
                        }
                    }
                    $expanded = implode(',', $kvp);
                }

            } else {
                if ($value['modifier'] == ':') {
                    $variable = substr($variable, 0, $value['position']);
                }
                $expanded = rawurlencode($variable);
                if ($parsed['operator'] == '+' || $parsed['operator'] == '#') {
                    $expanded = $this->decodeReserved($expanded);
                }
            }

            if ($actuallyUseQueryString) {
                if (!$expanded && $joiner != '&') {
                    $expanded = $value['value'];
                } else {
                    $expanded = $value['value'] . '=' . $expanded;
                }
            }

            $replacements[] = $expanded;
        }

        $ret = implode($joiner, $replacements);
        if ($ret && $prefix) {
            return $prefix . $ret;
        }

        return $ret;
    }

    /**
     * Determines if an array is associative
     *
     * @param array $array Array to check
     *
     * @return bool
     */
    private function isAssoc(array $array)
    {
        return (bool) count(array_filter(array_keys($array), 'is_string'));
    }

    /**
     * Removes percent encoding on reserved characters (used with + and # modifiers)
     *
     * @param string $string String to fix
     *
     * @return string
     */
    private function decodeReserved($string)
    {
        return str_replace(self::$delimsPct, self::$delims, $string);
    }
}
}
namespace Doctrine\Common\Cache {
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license. For more information, see
 * <http://www.doctrine-project.org>.
 */


/**
 * Array cache driver.
 *
 * @link   www.doctrine-project.org
 * @since  2.0
 * @author Benjamin Eberlei <kontakt@beberlei.de>
 * @author Guilherme Blanco <guilhermeblanco@hotmail.com>
 * @author Jonathan Wage <jonwage@gmail.com>
 * @author Roman Borschel <roman@code-factory.org>
 * @author David Abdemoulaie <dave@hobodave.com>
 */
class ArrayCache extends CacheProvider
{
    /**
     * @var array $data
     */
    private $data = array();

    /**
     * {@inheritdoc}
     */
    protected function doFetch($id)
    {
        return (isset($this->data[$id])) ? $this->data[$id] : false;
    }

    /**
     * {@inheritdoc}
     */
    protected function doContains($id)
    {
        return isset($this->data[$id]);
    }

    /**
     * {@inheritdoc}
     */
    protected function doSave($id, $data, $lifeTime = 0)
    {
        $this->data[$id] = $data;

        return true;
    }

    /**
     * {@inheritdoc}
     */
    protected function doDelete($id)
    {
        unset($this->data[$id]);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    protected function doFlush()
    {
        $this->data = array();

        return true;
    }

    /**
     * {@inheritdoc}
     */
    protected function doGetStats()
    {
        return null;
    }
}
}
namespace BEAR\Resource {
/**
 * This file is part of the BEAR.Resource package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use ReflectionParameter;
use Ray\Aop\MethodInvocation;

interface SignalParameterInterface
{
    /**
     * Return single argument by signal
     *
     * @param ReflectionParameter $parameter
     * @param MethodInvocation    $invocation
     *
     * @return mixed
     */
    public function getArg(ReflectionParameter $parameter, MethodInvocation $invocation);

    /**
     * Attach parameter provider
     *
     * @param string                 $varName
     * @param ParamProviderInterface $provider
     *
     * @return $this
     */
    public function attachParamProvider($varName, ParamProviderInterface $provider);
}
}
namespace BEAR\Resource {
/**
 * This file is part of the BEAR.Resource package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use Aura\Signal\Manager as Signal;
use BEAR\Resource\Exception;
use Ray\Aop\MethodInvocation;
use ReflectionParameter;
use Ray\Di\Di\Inject;

/**
 * Signal Parameter
 */
class SignalParameter implements SignalParameterInterface
{
    /**
     * @var Signal
     */
    private $signal;

    /**
     * @var Param
     */
    private $param;

    /**
     * @param Signal         $signal
     * @param ParamInterface $param
     *
     * @Inject
     */
    public function __construct(Signal $signal, ParamInterface $param)
    {
        $this->signal = $signal;
        $this->param = $param;
    }

    /**
     * {@inheritdoc}
     */
    public function getArg(ReflectionParameter $parameter, MethodInvocation $invocation)
    {
        try {
            $param = clone $this->param;
            $results = $this->sendSignal($parameter->name, $parameter, $param, $invocation, $parameter);
            if ($results->isStopped()) {
                return $param->getArg();
            }
            $results = $this->sendSignal('*', $parameter, $param, $invocation, $parameter);
            if ($results->isStopped()) {
                return $param->getArg();
            }

            // parameter not found
            $msg = '$' . "{$parameter->name} in " . get_class($invocation->getThis()) . '::' . $invocation->getMethod()->name;
            throw new Exception\Parameter($msg);
        } catch (\Exception $e) {
            // exception in provider
            throw new Exception\SignalParameter($e->getMessage(), 0, $e);
        }
    }

    /**
     * Send signal parameter
     *
     * @param string               $sigName
     * @param ReflectionParameter $parameter
     * @param ParamInterface      $param
     * @param MethodInvocation    $invocation
     * @param ReflectionParameter $parameter
     *
     * @return \Aura\Signal\ResultCollection
     */
    private function sendSignal(
        $sigName,
        ReflectionParameter $parameter,
        ParamInterface $param,
        MethodInvocation $invocation,
        ReflectionParameter $parameter
    ) {
        $results = $this->signal->send(
            $this,
            $sigName,
            $param->set($invocation, $parameter)
        );

        return $results;
    }

    /**
     * {@inheritdoc}
     */
    public function attachParamProvider($varName, ParamProviderInterface $provider)
    {
        /** @noinspection PhpParamsInspection */
        $this->signal->handler('*', $varName, $provider);
    }
}
}
namespace Aura\Signal {
/**
 *
 * This file is part of the Aura Project for PHP.
 *
 * @package Aura.Signal
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 *
 */

/**
 *
 * Processes signals through to Handler objects.
 *
 * @package Aura.Signal
 *
 */
class Manager
{
    /**
     *
     * Indicates that the signal should not call more Handler instances.
     *
     * @const string
     *
     */
    const STOP = 'Aura\Signal\Manager::STOP';

    /**
     *
     * A factory to create Handler objects.
     *
     * @var HandlerFactory
     *
     */
    protected $handler_factory;

    /**
     *
     * An array of Handler instances that respond to class signals.
     *
     * @var array
     *
     */
    protected $handlers = [];

    /**
     *
     * A prototype ResultCollection; this will be cloned by `send()` to retain
     * the Result objects from Handler instances.
     *
     * @var ResultCollection
     *
     */
    protected $result_collection;

    /**
     *
     * A factory to create Result objects.
     *
     * @var ResultFactory
     *
     */
    protected $result_factory;

    /**
     *
     * A ResultCollection from the last signal sent.
     *
     * @var ResultCollection
     *
     */
    protected $results;

    /**
     *
     * Have the handlers for a signal been sorted by position?
     *
     * @var array
     *
     */
    protected $sorted = [];

    /**
     *
     * Constructor.
     *
     * @param HandlerFactory $handler_factory A factory to create Handler
     * objects.
     *
     * @param ResultFactory $result_factory A factory to create Result objects.
     *
     * @param ResultCollection $result_collection A prototype ResultCollection.
     *
     * @param array $handlers An array describing Handler params.
     *
     */
    public function __construct(
        HandlerFactory   $handler_factory,
        ResultFactory    $result_factory,
        ResultCollection $result_collection,
        array            $handlers = []
    ) {
        $this->handler_factory   = $handler_factory;
        $this->result_factory    = $result_factory;
        $this->result_collection = $result_collection;
        foreach ($handlers as $handler) {
            list($sender, $signal, $callback) = $handler;
            if (isset($handler[3])) {
                $position = $handler[3];
            } else {
                $position = 5000;
            }
            $this->handler($sender, $signal, $callback, $position);
        }
        $this->results = clone $this->result_collection;
    }

    /**
     *
     * Adds a Handler to respond to a sender signal.
     *
     * @param string|object $sender The class or object sender of the signal.
     * If a class, inheritance will be honored, and '*' will be interpreted
     * as "any class."
     *
     * @param string $signal The name of the signal for that sender.
     *
     * @param callback $callback The callback to execute when the signal is
     * received.
     *
     * @param int $position The handler processing position; lower numbers are
     * processed first. Use this to force a handler to be used before or after
     * others.
     *
     * @return void
     *
     */
    public function handler($sender, $signal, $callback, $position = 5000)
    {
        $handler = $this->handler_factory->newInstance([
            'sender'   => $sender,
            'signal'   => $signal,
            'callback' => $callback
        ]);
        $this->handlers[$signal][(int) $position][] = $handler;
        $this->sorted[$signal] = false;
    }

    /**
     *
     * Gets Handler instances for the Manager.
     *
     * @param string $signal Only get Handler instances for this signal; if
     * null, get all Handler instances.
     *
     * @return array
     *
     */
    public function getHandlers($signal = null)
    {
        if (! $signal) {
            return $this->handlers;
        }

        if (! isset($this->handlers[$signal])) {
            return;
        }

        if (! $this->sorted[$signal]) {
            ksort($this->handlers[$signal]);
        }

        return $this->handlers[$signal];
    }

    /**
     *
     * Invokes the Handler objects for a sender and signal.
     *
     * @param object $origin The object sending the signal. Note that this is
     * always an object, not a class name.
     *
     * @param string $signal The name of the signal from that origin.
     *
     * @params Arguments to pass to the Handler callback.
     *
     * @return ResultCollection The results from each of the Handler objects.
     *
     */
    public function send($origin, $signal)
    {
        // clone a new result collection
        $this->results = clone $this->result_collection;

        // get the arguments to be passed to the handler
        $args = func_get_args();
        array_shift($args);
        array_shift($args);

        // now process the signal through the handlers and return the results
        $this->process($origin, $signal, $args);
        return $this->results;
    }

    /**
     *
     * Invokes the Handler objects for a sender and signal.
     *
     * @param object $origin The object sending the signal. Note that this is
     * always an object, not a class name.
     *
     * @param string $signal The name of the signal from that origin.
     *
     * @param array $args Arguments to pass to the Handler callback.
     *
     */
    protected function process($origin, $signal, array $args)
    {
        // are there any handlers for this signal, regardless of sender?
        $list = $this->getHandlers($signal);
        if (! $list) {
            return;
        }

        // go through the handler positions for the signal
        foreach ($list as $position => $handlers) {

            // go through each handler in this position
            foreach ($handlers as $handler) {

                // try the handler
                $params = $handler->exec($origin, $signal, $args);

                // if it executed, it returned the params for a Result object
                if ($params) {

                    // create a Result object
                    $result = $this->result_factory->newInstance($params);

                    // allow a meta-handler to examine the Result object,
                    // but only if it wasn't sent from the Manager (this
                    // prevents infinite looping). use process() instead
                    // of send() to prevent resetting the $results prop.
                    if ($origin !== $this) {
                        $this->process($this, 'handler_result', [$result]);
                    }

                    // retain the result
                    $this->results->append($result);

                    // should we stop processing?
                    if ($result->value === static::STOP) {
                        // yes, leave the processing loop
                        return;
                    }
                }
            }
        }
    }

    /**
     *
     * Returns the ResultCollection from the last signal processing.
     *
     * @return ResultCollection
     *
     */
    public function getResults()
    {
        return $this->results;
    }
}
}
namespace Aura\Signal {
/**
 *
 * This file is part of the Aura Project for PHP.
 *
 * @package Aura.Signal
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 *
 */

/**
 *
 * A factory to create Handler objects.
 *
 * @package Aura.Signal
 *
 */
class HandlerFactory
{
    /**
     *
     * An array of default parameters for Handler objects.
     *
     * @var array
     *
     */
    protected $params = [
        'sender'   => null,
        'signal'   => null,
        'callback' => null,
    ];

    /**
     *
     * Creates and returns a new Handler object.
     *
     * @param array $params An array of key-value pairs corresponding to
     * Handler constructor params.
     *
     * @return Handler
     *
     */
    public function newInstance(array $params)
    {
        $params = array_merge($this->params, $params);
        return new Handler(
            $params['sender'],
            $params['signal'],
            $params['callback']
        );
    }
}
}
namespace Aura\Signal {
/**
 *
 * This file is part of the Aura Project for PHP.
 *
 * @package Aura.Signal
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 *
 */

/**
 *
 * A factory to create Result objects.
 *
 * @package Aura.Signal
 *
 */
class ResultFactory
{
    /**
     *
     * An array of default parameters for Result objects.
     *
     * @var array
     *
     */
    protected $params = [
        'origin'  => null,
        'sender'  => null,
        'signal'  => null,
        'value'   => null,
    ];

    /**
     *
     * Creates and returns a new Option object.
     *
     * @param array $params An array of key-value pairs corresponding to
     * Result constructor params.
     *
     * @return Result
     *
     */
    public function newInstance(array $params)
    {
        $params = array_merge($this->params, $params);
        return new Result(
            $params['origin'],
            $params['sender'],
            $params['signal'],
            $params['value']
        );
    }
}
}
namespace Aura\Signal {
/**
 *
 * This file is part of the Aura Project for PHP.
 *
 * @package Aura.Signal
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 *
 */

/**
 *
 * Represents a collection of Result objects.
 *
 * @package Aura.Signal
 *
 */
class ResultCollection extends \ArrayObject
{
    /**
     *
     * override to avoid problems with Forge::newInstance() throwing
     * Fatal error: Uncaught exception 'InvalidArgumentException'
     * with message 'Passed variable is not an array or object, using
     * empty array instead' in
     * ~/system/package/Aura.Di/src/Aura/Di/Forge.php on line 103
     *
     */
    public function __construct()
    {
        parent::__construct([]);
    }

    /**
     *
     * Returns the last Result in the collection.
     *
     * @return Result
     *
     */
    public function getLast()
    {
        $k = count($this);
        if ($k > 0) {
            return $this[$k - 1];
        }
    }

    /**
     *
     * Tells if the ResultCollection was stopped during processing.
     *
     * @return bool
     *
     */
    public function isStopped()
    {
        $last = $this->getLast();
        if ($last) {
            return $last->value === Manager::STOP;
        }
    }
}
}
namespace BEAR\Resource {


/**
 * This file is part of the BEAR.Resource package
 *
 * @package BEAR.Resource
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */
class ExceptionHandler implements ExceptionHandlerInterface
{
    /**
     * {@inheritdoc}
     * @throws \Exception
     */
    public function handle(\Exception $e, Request $request)
    {
        throw $e;
    }
}
}
namespace BEAR\Resource {
/**
 * This file is part of the BEAR.Resource package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use Exception;

/**
 * Trait for resource string
 */
trait RenderTrait
{
    /**
     * Renderer
     *
     * @var \BEAR\Resource\RenderInterface
     */
    protected $renderer;

    /**
     * Set renderer
     *
     * @param RenderInterface $renderer
     *
     * @return RenderTrait
     * @Ray\Di\Di\Inject(optional = true)
     */
    public function setRenderer(RenderInterface $renderer)
    {
        $this->renderer = $renderer;

        return $this;
    }

    /**
     * Return representational string
     *
     * Return object hash if representation renderer is not set.
     *
     * @return string
     */
    public function __toString()
    {
        /** @var $this ResourceObject */
        if (is_string($this->view)) {
            return $this->view;
        }
        if ($this->renderer instanceof RenderInterface) {
            try {
                $view = $this->renderer->render($this);
            } catch (Exception $e) {
                $view = '';
                error_log('Exception caught in ' . __METHOD__);
                error_log((string)$e);
            }

            return $view;
        }
        if (is_scalar($this->body)) {
            return (string)$this->body;
        }
        error_log('No renderer bound for \BEAR\Resource\RenderInterface' . get_class($this) . ' in ' . __METHOD__);

        return '';
    }
}
}
namespace BEAR\Resource {
/**
 * This file is part of the BEAR.Resource package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

use ArrayAccess;
use Countable;
use IteratorAggregate;
use Ray\Di\Di\Inject;

/**
 * Abstract resource object
 */
abstract class ResourceObject implements ArrayAccess, Countable, IteratorAggregate
{
    // (array)
    use BodyArrayAccessTrait;

    // (string)
    use RenderTrait;

    /**
     * URI
     *
     * @var string
     */
    public $uri = '';

    /**
     * Resource status code
     *
     * @var int
     */
    public $code = 200;

    /**
     * Resource header
     *
     * @var array
     */
    public $headers = [];

    /**
     * Resource representation
     *
     * @var string
     */
    public $view;

    /**
     * Resource links
     *
     * @var array
     */
    public $links = [];
}
}
namespace Demo\Helloworld\Resource\App {


use BEAR\Resource\ResourceObject;

/**
 * Hello world
 */
class Hello extends ResourceObject
{
    /**
     * @param string $name
     */
    public function onGet($name)
    {
        $this['greeting'] = 'Hello ' . $name;
        $this['time'] = date('r');
        return $this;
    }
}
}
namespace BEAR\Resource {
/**
 * This file is part of the BEAR.Resource package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

/**
 * Interface for render view
 */
interface RenderInterface
{
    /**
     * Render
     *
     * @param ResourceObject $resourceObject
     *
     * @return $this
     */
    public function render(ResourceObject $resourceObject);
}
}
namespace Demo\Helloworld\Resource\Page {


use BEAR\Resource\ResourceObject;

class Hello extends ResourceObject
{
    /**
     * @param string $name
     */
    public function onGet($name = 'World')
    {
        $this->body = 'Hello ' . $name;
        return $this;
    }
}
}
namespace Demo\Helloworld\Resource\Page {


use BEAR\Resource\ResourceObject;

/**
 * Hello world - min
 */
class Minhello extends ResourceObject
{
    /**
     * @var string
     */
    public $body = 'Hello World !';

    public function onGet()
    {
        return $this;
    }
}
}
