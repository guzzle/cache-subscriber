<?php
namespace GuzzleHttp\Tests\Subscriber\Cache;

require_once __DIR__ . '/../vendor/guzzlehttp/ringphp/tests/Client/Server.php';
require_once __DIR__ . '/../vendor/guzzlehttp/guzzle/tests/Server.php';

use GuzzleHttp\Client;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Subscriber\Cache\CacheSubscriber;
use GuzzleHttp\Subscriber\History;
use GuzzleHttp\Tests\Server;

class IntegrationTest extends \PHPUnit_Framework_TestCase
{
    public static function setupBeforeClass()
    {
        Server::start();
    }

    public static function tearDownAfterClass()
    {
        Server::stop();
    }

    public function testCachesResponses()
    {
        Server::enqueue([
            new Response(200, [
                'Vary' => 'Accept-Encoding,Cookie,X-Use-HHVM',
                'Date' => 'Wed, 29 Oct 2014 20:52:15 GMT',
                'Cache-Control' => 'private, s-maxage=0, max-age=0, must-revalidate',
                'Last-Modified' => 'Wed, 29 Oct 2014 20:30:57 GMT',
                'Age' => '1277'
            ]),
            new Response(304, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Vary' => 'Accept-Encoding,Cookie,X-Use-HHVM',
                'Date' => 'Wed, 29 Oct 2014 20:52:16 GMT',
                'Cache-Control' => 'private, s-maxage=0, max-age=0, must-revalidate',
                'Last-Modified' => 'Wed, 29 Oct 2014 20:30:57 GMT',
                'Age' => '1278'
            ]),
            new Response(200, [
              'Vary' => 'Accept-Encoding,Cookie,X-Use-HHVM',
              'Date' => 'Wed, 29 Oct 2014 20:52:15 GMT',
              'Cache-Control' => 'private, s-maxage=0, max-age=0',
              'Last-Modified' => 'Wed, 29 Oct 2014 20:30:57 GMT',
              'Age' => '1277'
            ]),
            new Response(200, [
              'Vary' => 'Accept-Encoding,Cookie,X-Use-HHVM',
              'Date' => 'Wed, 29 Oct 2014 20:53:15 GMT',
              'Cache-Control' => 'private, s-maxage=0, max-age=0',
              'Last-Modified' => 'Wed, 29 Oct 2014 20:53:00 GMT',
              'Age' => '1277'
            ]),
        ]);

        $client = new Client(['base_url' => Server::$url]);
        CacheSubscriber::attach($client);
        $history = new History();
        $client->getEmitter()->attach($history);
        $response1 = $client->get('/foo');
        $this->assertEquals(200, $response1->getStatusCode());
        $response2 = $client->get('/foo');
        $this->assertEquals(200, $response2->getStatusCode());
        $last = $history->getLastResponse();
        $this->assertEquals('HIT from GuzzleCache', $last->getHeader('X-Cache-Lookup'));
        $this->assertEquals('HIT from GuzzleCache', $last->getHeader('X-Cache'));

        // Validate that expired requests without must-revalidate expire.
        $response3 = $client->get('/foo');
        $this->assertEquals(200, $response3->getStatusCode());
        $response4 = $client->get('/foo');
        $this->assertEquals(200, $response4->getStatusCode());
        $last = $history->getLastResponse();
        $this->assertEquals('MISS from GuzzleCache', $last->getHeader('X-Cache-Lookup'));
        $this->assertEquals('MISS from GuzzleCache', $last->getHeader('X-Cache'));

        // Validate that all of our requests were received.
        $this->assertCount(4, Server::received());
    }
}
