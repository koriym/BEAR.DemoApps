<?php

namespace Sandbox\tests\Resource\App\Blog\Posts;

class PagerTest extends \PHPUnit_Framework_TestCase
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

    public function testOnGet()
    {
        $resource = $this->resource->get->uri('app://self/blog/posts/pager')->eager->request();
        $this->assertArrayHasKey('pager', $resource->headers);
        $this->assertArrayHasKey('maxPerPage', $resource->headers['pager']);
        $this->assertArrayHasKey('current', $resource->headers['pager']);
        $this->assertArrayHasKey('total', $resource->headers['pager']);
        $this->assertArrayHasKey('hasNext', $resource->headers['pager']);
        $this->assertArrayHasKey('hasPrevious', $resource->headers['pager']);
        $this->assertArrayHasKey('html', $resource->headers['pager']);
    }
}
