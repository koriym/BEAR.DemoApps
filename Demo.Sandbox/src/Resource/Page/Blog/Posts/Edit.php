<?php

namespace Demo\Sandbox\Resource\Page\Blog\Posts;

use BEAR\Resource\ResourceObject as Page;
use BEAR\Sunday\Inject\ResourceInject;
use BEAR\Resource\Annotation\Link;
use BEAR\Resource\Code;
use BEAR\Sunday\Annotation\Form;
use BEAR\Resource\Annotation\Embed;


class Edit extends Page
{
    use ResourceInject;

    public $links = [
        'update' => [Link::HREF => 'app://self/blog/posts'],
        'next' => [Link::HREF => '/blog/posts']
    ];

    /**
     * @var array
     */
    public $body = [
        'errors' => ['title' => '', 'body' => '']
    ];

    /**
     * @Embed(rel="submit", src="app://self/blog/posts{?id}")
     */
    public function onGet($id)
    {
        $this['id'] = $id;

        return $this;
    }

    /**
     * @param int    $id
     * @param string $title
     * @param string $body
     *
     * @Form
     */
    public function onPut($id, $title, $body)
    {
        $this['id'] = $id;
        // update post
        $updateUri = $this->links['update'][Link::HREF];
        $this->resource
            ->put
            ->uri($updateUri)
            ->withQuery(['id' => $id, 'title' => $title, 'body' => $body])
            ->eager
            ->request();

        // redirect
        $this->code = Code::SEE_OTHER;
        $this->headers = ['Location' => $this->links['next'][Link::HREF]];

        return $this;
    }
}
