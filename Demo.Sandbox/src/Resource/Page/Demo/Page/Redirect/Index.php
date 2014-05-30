<?php

namespace Demo\Sandbox\Resource\Page\Demo\Page\Redirect;

use BEAR\Resource\Header;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Code;

/**
 * Redirect page
 */
class Index extends ResourceObject
{
    public function onGet()
    {
        $this->code = Code::MOVED_PERMANENTLY;
        $this->headers = [Header::LOCATION => '/'];

        return $this;
    }
}
