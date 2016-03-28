<?php
namespace GuzzleHttp\Tests\Subscriber\Cache;

require_once __DIR__ . '/../vendor/guzzlehttp/ringphp/tests/Client/Server.php';
require_once __DIR__ . '/../vendor/guzzlehttp/guzzle/tests/Server.php';

use GuzzleHttp\Client;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Subscriber\Cache\CacheStorage;
use GuzzleHttp\Subscriber\Cache\CacheSubscriber;
use Doctrine\Common\Cache\ArrayCache;
use GuzzleHttp\Tests\Server;

class CacheTagsTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var CacheStorage
     */
    protected $storage;

    protected function setUp()
    {
        Server::start();
        $this->storage = new CacheStorage(new ArrayCache());
    }

    protected function tearDown()
    {
        Server::stop();
    }

    public function testCachesResponses()
    {
        Server::enqueue([
            new Response(200, [
                'Cache-Control' => 'max-age=600',
                'X-Cache-Tags' => 'tag-a, tag-b',
            ]),
            new Response(200, [
                'Cache-Control' => 'max-age=500',
                'X-Cache-Tags' => 'tag-a, tag-b, tag-c',
            ]),
        ]);

        $client = $this->setupClient();

        $response1 = $client->get('/foo');
        $this->assertEquals(200, $response1->getStatusCode());
        $this->assertEquals('MISS from GuzzleCache', $response1->getHeader('X-Cache-Lookup'));
        $this->assertEquals('MISS from GuzzleCache', $response1->getHeader('X-Cache'));
        $this->assertEquals('tag-a, tag-b', $response1->getHeader('X-Cache-Tags'));

        $response2 = $client->get('/foo');
        $this->assertEquals(200, $response2->getStatusCode());
        $this->assertEquals('HIT from GuzzleCache', $response2->getHeader('X-Cache-Lookup'));
        $this->assertEquals('HIT from GuzzleCache', $response2->getHeader('X-Cache'));
        $this->assertEquals('tag-a, tag-b', $response2->getHeader('X-Cache-Tags'));

        $this->assertCount(1, Server::received());

        // clear a cached tag
        $this->storage->purgeTags(['tag-a', 'tag-z']);

        $response3 = $client->get('/foo');
        $this->assertEquals(200, $response3->getStatusCode());
        $this->assertEquals('MISS from GuzzleCache', $response3->getHeader('X-Cache-Lookup'));
        $this->assertEquals('MISS from GuzzleCache', $response3->getHeader('X-Cache'));
        $this->assertEquals('tag-a, tag-b, tag-c', $response3->getHeader('X-Cache-Tags'));

        $response4 = $client->get('/foo');
        $this->assertEquals(200, $response4->getStatusCode());
        $this->assertEquals('HIT from GuzzleCache', $response4->getHeader('X-Cache-Lookup'));
        $this->assertEquals('HIT from GuzzleCache', $response4->getHeader('X-Cache'));
        $this->assertEquals('tag-a, tag-b, tag-c', $response4->getHeader('X-Cache-Tags'));

        $this->assertCount(2, Server::received());

        // clear a different tag
        $this->storage->purgeTags(['tag-z']);

        $response5 = $client->get('/foo');
        $this->assertEquals(200, $response5->getStatusCode());
        $this->assertEquals('HIT from GuzzleCache', $response5->getHeader('X-Cache-Lookup'));
        $this->assertEquals('HIT from GuzzleCache', $response5->getHeader('X-Cache'));
        $this->assertEquals('tag-a, tag-b, tag-c', $response5->getHeader('X-Cache-Tags'));

        $this->assertCount(2, Server::received());
    }

    /**
     * Setup a Guzzle client for testing.
     *
     * @return Client A client ready to run test requests against.
     */
    private function setupClient()
    {
        $client = new Client(['base_url' => Server::$url]);
        CacheSubscriber::attach($client, [
            'storage' => $this->storage
        ]);

        return $client;
    }
}
