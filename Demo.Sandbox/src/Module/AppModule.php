<?php

namespace Demo\Sandbox\Module;

use Ray\Di\AbstractModule;
use BEAR\Package\Provide\TemplateEngine\Smarty\SmartyModule;
use BEAR\Package\Module\Package\StandardPackageModule;
use BEAR\Package\Module\Session\AuraSession\SessionModule;
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
        $this->install(new StandardPackageModule('Demo\Sandbox', $this->context, dirname(dirname(__DIR__))));

        // override view package (default:Twig)
        $this->install(new SmartyModule($this));
//        $this->install(new AuraViewModule($this));

        // install aura.session
        $this->install(new SessionModule());

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
