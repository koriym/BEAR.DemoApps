<?php

namespace Demo\Sandbox\Interceptor\Form;

use BEAR\Sunday\Inject\NamedArgsInject;
use Ray\Aop\MethodInterceptor;
use Aura\Input\FilterInterface;
use Ray\Di\Di\Inject;
use Ray\Di\Di\Named;
use BEAR\Package\Module\Form\AuraForm\AuraFormTrait;
use Aura\Session\Manager as Session;
use BEAR\Package\Module\Session\AuraSession\AntiCsrf;

/**
 * Aura.Input form
 *
 * @see https://github.com/auraphp/Aura.Input
 */
class AuraContact implements MethodInterceptor
{
    use AuraFormTrait;

    /**
     * @var \Aura\Session\Manager
     */
    private $session;

    /**
     * @param \Aura\Session\Manager $session
     *
     * @Inject
     */
    public function setSession(Session $session)
    {
        $this->session = $session;
    }

    /**
     * Set form
     *
     * @param FilterInterface $filter
     */
    private function setForm(FilterInterface &$filter)
    {
        $anti_csrf = new AntiCsrf($this->session->getCsrfToken());
        $this->form->setAntiCsrf($anti_csrf);

        $this->form
            ->setField('name')
            ->setAttribs(
                [
                    'class' => 'form-control',
                    'id' => 'name',
                    'name' => 'name',
                    'size' => 20,
                    'maxlength' => 20
                ]
            );
        $this->form
            ->setField('email')
            ->setAttribs(
                [
                    'class' => 'form-control',
                    'id' => 'email',
                    'name' => 'email',
                    'size' => 20,
                    'maxlength' => 20,
                ]
            );
        $this->form
            ->setField('url')
            ->setAttribs(
                [
                    'class' => 'form-control',
                    'id' => 'url',
                    'name' => 'url',
                    'size' => 20,
                    'maxlength' => 20,
                ]
            );
        $this->form
            ->setField('message', 'textarea')
            ->setAttribs(
                [
                    'class' => 'form-control',
                    'id' => 'message',
                    'name' => 'message',
                    'cols' => 40,
                    'rows' => 5,
                ]
            );

        $filter->setRule(
            'name',
            'Name must be alphabetic only.',
            function ($value) {
                return ctype_alpha($value);
            }
        );

        $filter->setRule(
            'email',
            'Enter a valid email address',
            function ($value) {
                return filter_var($value, FILTER_VALIDATE_EMAIL);
            }
        );

        $filter->setRule(
            'url',
            'Enter a valid url',
            function ($value) {
                return filter_var($value, FILTER_VALIDATE_URL);
            }
        );

        $filter->setRule(
            'message',
            'Message should be more than 7 characters',
            function ($value) {
                return (strlen($value) > 7) ? true : false;
            }
        );

        $this->form
            ->setField('submit', 'submit')
            ->setAttribs(['value' => 'send']);
    }
}
