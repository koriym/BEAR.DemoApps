<?php

namespace Demo\Sandbox\Resource\Page\Blog\Posts;

use BEAR\Resource\Code;
use BEAR\Resource\Header;
use BEAR\Resource\ResourceObject;
use BEAR\Sunday\Inject\ResourceInject;
use BEAR\Resource\Annotation\Link;
use BEAR\Sunday\Annotation\Cache;
use BEAR\Resource\Annotation\Embed;

class Post extends ResourceObject
{
    use ResourceInject;

    public $links = [
        'delete' => [Link::HREF => 'app://self/blog/posts']
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
        $this->code = Code::SEE_OTHER;
        $this->headers[Header::LOCATION] = '/blog/posts';

        return $this;
    }
}
