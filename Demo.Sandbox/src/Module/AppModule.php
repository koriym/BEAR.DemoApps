<?php

namespace Demo\Sandbox\Module;

use Ray\Di\AbstractModule;
use BEAR\Package\Provide\TemplateEngine\Smarty\SmartyModule;
use BEAR\Package\Module\Package\StandardPackageModule;
use Ray\Di\Di\Inject;
use Ray\Di\Di\Named;
use Ray\Di\Di\Scope;

class AppModule extends AbstractModule
{
    /**
     * @var string
     */
    private $context;

    /**
     * @param string $context
     *
     * @Inject
     * @Named("app_context")
     */
    public function __construct($context = 'prod')
    {
        $this->context = $context;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $appDir = dirname(dirname(__DIR__));
        $this->install(new StandardPackageModule($appDir, $this->context));

        // override view package (default:Twig)
        $this->install(new SmartyModule($this));
//        $this->install(new AuraViewModule($this));

        // install develop module
        if ($this->context === 'dev') {
            $this->install(new App\Aspect\DevModule($this));
        }

        // install application dependency
        $this->install(new App\Dependency);

        // install application aspect
        $this->install(new App\Aspect($this));
    }
}
