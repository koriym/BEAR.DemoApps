<?php

namespace Demo\Sandbox\Resource\Page\Blog\Posts;

use BEAR\Resource\ResourceObject as Page;
use BEAR\Sunday\Inject\ResourceInject;
use BEAR\Resource\Annotation\Link;
use BEAR\Sunday\Annotation\Cache;
use BEAR\Resource\Annotation\Embed;

/**
 * BLog post page
 */
class Post extends Page
{
    use ResourceInject;

    public $link = [
        'delete' => [Link::HREF => 'app://self/blog/posts']
    ];

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
        $deleteUri = $this->links['delete'][Link::HREF];
        $this->resource
            ->delete
            ->uri($deleteUri)
            ->withQuery(['id' => $id])
            ->eager
            ->request();

        // no content
        $this->code = 204;
        return $this;
    }

}
