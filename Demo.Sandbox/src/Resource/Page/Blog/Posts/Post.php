<?php

namespace Demo\Sandbox\Resource\Page\Blog\Posts;

use BEAR\Resource\ResourceObject as Page;
use BEAR\Sunday\Inject\ResourceInject;
use BEAR\Sunday\Annotation\Cache;
use Ray\Di\Di\Inject;
use BEAR\Resource\Annotation\Embed;

/**
 * BLog post page
 */
class Post extends Page
{
    use ResourceInject;

    /**
     * @var array
     */
    public $body = [
        'post' => [
            'title' => '',
            'body' => ''
        ],
    ];

    /**
     * @param int $id
     *
     * @Cache(5)
     * @Embed(rel="post", src="app://self/blog/posts{?id}")
     */
    public function onGet($id)
    {
        return $this;
    }

    /**
     * Delete entry
     *
     * @param int $id entry id
     */
    public function onDelete($id)
    {
        // delete
        $this->resource
            ->delete
            ->uri('app://self/blog/posts')
            ->withQuery(['id' => $id])
            ->eager
            ->request();

        // no content
        $this->code = 204;
        return $this;
    }

}
