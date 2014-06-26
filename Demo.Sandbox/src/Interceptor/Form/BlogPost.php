<?php

namespace Demo\Sandbox\Interceptor\Form;

use BEAR\Sunday\Inject\NamedArgsInject;
use Ray\Aop\MethodInterceptor;
use Ray\Aop\MethodInvocation;

/**
 * Post form
 */
class BlogPost implements MethodInterceptor
{
    use NamedArgsInject;

    /**
     * Error
     *
     * @var array
     */
    private $errors = [
        'title' => '',
        'body' => ''
    ];

    /**
     * {@inheritdoc}
     */
    public function invoke(MethodInvocation $invocation)
    {
        // retrieve query (reference)
        $args = $invocation->getArguments();
        // retrieve page
        $page = $invocation->getThis();

        // change values of query
        // strip tags
        foreach ($args as &$arg) {
            $arg = strip_tags($arg);
        }

        // retrieve named query. this is copy of values, not reference
        $args = $this->namedArgs->get($invocation); // this is copy of args

        // required title
        if ($args['title'] === '') {
            $this->errors['title'] = 'title required.';
        }

        // required body
        if ($args['body'] === '') {
            $this->errors['body'] = 'body required.';
        }

        // valid form ?
        if (implode('', $this->errors) === '') {
            return $invocation->proceed();
        }

        // on PUT we need id
        $id = isset($args['id']) ? $args['id'] : null;

        // error, modify 'GET' page wih error message.
        $page['errors'] = $this->errors;
        $page['submit'] = [
            'title' => $args['title'],
            'body' => $args['body']
        ];

        return $page->onGet($id);
    }
}
