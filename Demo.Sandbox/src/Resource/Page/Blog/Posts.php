<?php

namespace Demo\Sandbox\Resource\Page\Blog;

use BEAR\Resource\ResourceObject;
use BEAR\Sunday\Annotation\Cache;
use BEAR\Resource\Annotation\Embed;

class Posts extends ResourceObject
{
    /**
     * @Cache
     * @Embed(rel="posts", src="app://self/blog/posts")
     */
    public function onGet()
    {
        return $this;
    }
}
