<?php

namespace Demo\Sandbox\Resource\Page\Blog\Posts;

use BEAR\Resource\ResourceObject;
use BEAR\Resource\Annotation\Link;
use BEAR\Sunday\Inject\AInject;
use BEAR\Sunday\Inject\ResourceInject;
use BEAR\Resource\Header;
use BEAR\Sunday\Annotation\Form;

/**
 * New post page
 */
class Newpost extends ResourceObject
{
    use ResourceInject;

    public $links = [
        'back' => [Link::HREF => 'page://self/blog/posts'],
        'created' => [Link::HREF => 'page://self/blog/posts/post{?id}', Link::TEMPLATED => true],
        'create' => [Link::HREF => 'app://self/blog/posts']
    ];

    public $body = [
        'errors' => ['title' => '', 'body' => ''],
        'submit' => ['title' => '', 'body' => '']
    ];

    public function onGet()
    {
        return $this;
    }

    /**
     * @param string $title
     * @param string $body
     *
     * @Form
     */
    public function onPost($title, $body)
    {
        $uri = $this->links['create'][Link::HREF];
        $response = $this
            ->resource
            ->uri($uri)
            ->withQuery(
                ['title' => $title, 'body' => $body]
            )
            ->eager
            ->request();

        $this->code = $this['code'] = $response->code;
        $this['id'] = $response->headers[Header::X_ID];

        return $this;
    }
}
