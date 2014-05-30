<?php
namespace Sandbox\tests\Resource\Page\Blog;

class PostsTest extends \PHPUnit_Extensions_Database_TestCase
{
    /**
     * @var \BEAR\Resource\Resource
     */
    private $resource;

    /**
     * @return \PHPUnit_Extensions_Database_DB_IDatabaseConnection
     */
    public function getConnection()
    {
        $pdo = require $_ENV['APP_DIR'] . '/tests/scripts/db.php';

        return $this->createDefaultDBConnection($pdo, 'sq_lite');
    }

    /**
     * @return \PHPUnit_Extensions_Database_DataSet_IDataSet
     */
    public function getDataSet()
    {
        return $this->createFlatXmlDataSet($_ENV['APP_DIR'] .'/tests/mock/seed.xml');
    }

    protected function setUp()
    {
        parent::setUp();
        $this->resource = clone $GLOBALS['RESOURCE'];
    }

    public function testOnGet()
    {
        $page = $this->resource->get->uri('page://self/blog/posts')->eager->request();
        $this->assertSame(200, $page->code);

        return $page;
    }


    /**
     * @depends testOnGet
     */
    public function testOnGetEmbedPosts($page)
    {
        $this->assertArrayHasKey('posts', $page->body);
        $this->assertInstanceOf('BEAR\Resource\Request', $page->body['posts']);
        $this->assertSame('app://self/blog/posts', $page->body['posts']->toUri());
    }

    /**
     * @depends testOnGet
     */
    public function testOnGetRendering($page)
    {
        $html = (string)$page;
        $this->assertInternalType('string', $html);
        $this->assertContains('</html>', $html);
        $this->assertContains('<a href="/blog/posts/post?id=1">title1</a>', $html);
    }
}
