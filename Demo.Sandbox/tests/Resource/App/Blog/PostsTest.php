<?php

namespace Sandbox\tests\Resource\App\Blog;

use BEAR\Resource\Code;
use BEAR\Resource\Header;

class PostsTest extends \PHPUnit_Extensions_Database_TestCase
{
    /**
     * Resource client
     *
     * @var \BEAR\Resource\Resource
     */
    private $resource;

    /**
     * @return \PHPUnit_Extensions_Database_DB_IDatabaseConnection
     */
    public function getConnection()
    {
        $pdo = require $_ENV['APP_DIR'] . '/tests/scripts/db.php';

        return $this->createDefaultDBConnection($pdo, 'sqlite');
    }

    /**
     * @return \PHPUnit_Extensions_Database_DataSet_IDataSet
     */
    public function getDataSet()
    {
        $seed = $this->createFlatXmlDataSet($_ENV['APP_DIR'] . '/tests/mock/seed.xml');
        return $seed;
    }

    protected function setUp()
    {
        parent::setUp();
        $this->resource = clone $GLOBALS['RESOURCE'];
    }

    /**
     * page://self/blog/posts
     *
     * @test
     */
    public function testOnGet()
    {
        // resource request
        $resource = $this->resource->get->uri('app://self/blog/posts')->eager->request();
        $this->assertSame(200, $resource->code);
        $this->assertInternalType('array', $resource->body);

        return $resource;
    }

    /**
     * Renderable ?
     *
     * @depends testOnGet
     */
    public function testOnGetHtml($resource)
    {
        $html = (string)$resource;
        $this->assertInternalType('string', $html);
    }

    public function testOnPost()
    {
        // inc 1
        $before = $this->getConnection()->getRowCount('posts');
        $resourceObject = $this->resource
            ->post
            ->uri('app://self/blog/posts')
            ->withQuery(['title' => 'test_title', 'body' => 'test_body'])
            ->eager
            ->request();

        $this->assertSame(Code::CREATED, $resourceObject->code);
        $this->assertArrayHasKey(Header::LOCATION, $resourceObject->headers);
        $this->assertArrayHasKey(Header::X_ID, $resourceObject->headers);
        $this->assertEquals($before + 1, $this->getConnection()->getRowCount('posts'), "failed to add");
    }

    /**
     * @depends testOnPost
     */
    public function testOnPostNewRow()
    {
        $this->resource
            ->post
            ->uri('app://self/blog/posts')
            ->withQuery(['title' => 'test_title', 'body' => 'test_body'])
            ->eager
            ->request();

        // new post
        $entries = $this->resource->get->uri('app://self/blog/posts')->withQuery([])->eager->request()->body;
        $body = array_pop($entries);

        $this->assertEquals('test_title', $body['title']);
        $this->assertEquals('test_body', $body['body']);
    }

    public function testOnDelete()
    {
        $before = $this->getConnection()->getRowCount('posts');
        $this->resource->delete->uri('app://self/blog/posts')->withQuery(['id' => 1])->eager->request();
        $this->assertEquals($before - 1, $this->getConnection()->getRowCount('posts'), "failed to delete");
    }
}
