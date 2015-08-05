<?php
namespace GuzzleHttp\Tests\Subscriber\Cache;

require_once __DIR__ . '/../vendor/guzzlehttp/ringphp/tests/Client/Server.php';
require_once __DIR__ . '/../vendor/guzzlehttp/guzzle/tests/Server.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Stream\Stream;
use GuzzleHttp\Subscriber\Cache\CacheSubscriber;
use GuzzleHttp\Subscriber\History;
use GuzzleHttp\Tests\Server;

class IntegrationTest extends \PHPUnit_Framework_TestCase
{
    protected function setUp()
    {
        Server::start();
    }

    protected function tearDown()
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

        $history = new History();
        $client = $this->setupClient($history);

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

    /**
     * Test that Warning headers aren't added to cache misses.
     */
    public function testCacheMissNoWarning()
    {
        Server::enqueue([
            new Response(200, [
                'Vary' => 'Accept-Encoding,Cookie,X-Use-HHVM',
                'Date' => 'Wed, 29 Oct 2014 20:52:15 GMT',
                'Cache-Control' => 'private, s-maxage=0, max-age=0, must-revalidate',
                'Last-Modified' => 'Wed, 29 Oct 2014 20:30:57 GMT',
                'Age' => '1277',
            ]),
        ]);

        $client = $this->setupClient();
        $response = $client->get('/foo');
        $this->assertFalse($response->hasHeader('warning'));
    }

    /**
     * Test that the Vary header creates unique cache entries.
     *
     * @throws \Exception
     */
    public function testVaryUniqueResponses()
    {
        $now = $this->date();

        Server::enqueue(
            [
                new Response(
                    200, [
                    'Vary' => 'Accept',
                    'Content-type' => 'text/html',
                    'Date' => $now,
                    'Cache-Control' => 'public, s-maxage=1000, max-age=1000',
                    'Last-Modified' => $now,
                ], Stream::factory('It works!')
                ),
                new Response(
                    200, [
                    'Vary' => 'Accept',
                    'Content-type' => 'application/json',
                    'Date' => $now,
                    'Cache-Control' => 'public, s-maxage=1000, max-age=1000',
                    'Last-Modified' => $now,
                ], Stream::factory(json_encode(['body' => 'It works!']))
                ),
            ]
        );

        $client = $this->setupClient();

        $response1 = $client->get(
            '/foo',
            ['headers' => ['Accept' => 'text/html']]
        );
        $this->assertEquals('It works!', $this->getResponseBody($response1));

        $response2 = $client->get(
            '/foo',
            ['headers' => ['Accept' => 'application/json']]
        );
        $this->assertEquals(
            'MISS from GuzzleCache',
            $response2->getHeader('x-cache')
        );

        $decoded = json_decode($this->getResponseBody($response2));

        if (!isset($decoded) || !isset($decoded->body)) {
            $this->fail('JSON response could not be decoded.');
        } else {
            $this->assertEquals('It works!', $decoded->body);
        }
    }

    public function testCachesResponsesForWithoutVaryHeader()
    {
        $now = $this->date();

        Server::enqueue(
            [
                new Response(
                    200, [
                    'Content-type' => 'text/html',
                    'Date' => $now,
                    'Cache-Control' => 'public, s-maxage=1000, max-age=1000, must-revalidate',
                    'Last-Modified' => $now,
                ], Stream::factory()
                ),
                new Response(
                    200, [
                    'Content-type' => 'text/html',
                    'Date' => $now,
                    'Cache-Control' => 'public, s-maxage=1000, max-age=1000, must-revalidate',
                    'Last-Modified' => $now,
                    ], Stream::factory()
                ),
            ]
        );

        $client = $this->setupClient();

        $response1 = $client->get('/foo');
        $this->assertEquals(200, $response1->getStatusCode());
        $response2 = $client->get('/foo');
        $this->assertEquals(200, $response2->getStatusCode());
        $this->assertEquals('HIT from GuzzleCache', $response2->getHeader('X-Cache'));
    }

    /**
     * Test that requests varying on both Accept and User-Agent properly split
     * different User-Agents into different cache items.
     */
    public function testVaryUserAgent()
    {
        $this->setupMultipleVaryResponses();
        $client = $this->setupClient();

        $response1 = $client->get(
            '/foo',
            [
                'headers' => [
                    'Accept' => 'text/html',
                    'User-Agent' => 'Testing/1.0',
                ]
            ]
        );
        $this->assertEquals(
            'Test/1.0 request.',
            $this->getResponseBody($response1)
        );

        $response2 = $client->get(
            '/foo',
            [
                'headers' => [
                    'Accept' => 'text/html',
                    'User-Agent' => 'Testing/2.0',
                ]
            ]
        );
        $this->assertEquals(
            'MISS from GuzzleCache',
            $response2->getHeader('x-cache')
        );
        $this->assertEquals(
            'Test/2.0 request.',
            $this->getResponseBody($response2)
        );

        // Test that we get cache hits where both Vary headers match.
        $response5 = $client->get(
            '/foo',
            [
                'headers' => [
                    'Accept' => 'text/html',
                    'User-Agent' => 'Testing/2.0',
                ]
            ]
        );
        $this->assertEquals(
            'HIT from GuzzleCache',
            $response5->getHeader('x-cache')
        );
        $this->assertEquals(
            'Test/2.0 request.',
            $this->getResponseBody($response5)
        );
    }

    /**
     * Test that requests varying on Accept but not User-Agent return different responses.
     */
    public function testVaryAccept()
    {
        $this->setupMultipleVaryResponses();
        $client = $this->setupClient();

        // Prime the cache.
        $client->get(
            '/foo',
            [
                'headers' => [
                    'Accept' => 'text/html',
                    'User-Agent' => 'Testing/1.0',
                ]
            ]
        );
        $client->get(
            '/foo',
            [
                'headers' => [
                    'Accept' => 'text/html',
                    'User-Agent' => 'Testing/2.0',
                ]
            ]
        );

        $response1 = $client->get(
            '/foo',
            [
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'Testing/1.0',
                ]
            ]
        );
        $this->assertEquals(
            'MISS from GuzzleCache',
            $response1->getHeader('x-cache')
        );
        $this->assertEquals(
            'Test/1.0 request.',
            json_decode($this->getResponseBody($response1))->body
        );

        $response2 = $client->get(
            '/foo',
            [
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'Testing/2.0',
                ]
            ]
        );
        $this->assertEquals(
            'MISS from GuzzleCache',
            $response2->getHeader('x-cache')
        );
        $this->assertEquals(
            'Test/2.0 request.',
            json_decode($this->getResponseBody($response2))->body
        );
    }

    /**
     * Test that we return cached responses when multiple Vary headers match.
     */
    public function testMultipleVaryMatch()
    {
        $this->setupMultipleVaryResponses();
        $client = $this->setupClient();

        // Prime the cache.
        $client->get('/foo',
            [
                'headers' => [
                    'Accept' => 'text/html',
                    'User-Agent' => 'Testing/1.0',
                ]
            ]
        );
        $client->get('/foo',
            [
                'headers' => [
                    'Accept' => 'text/html',
                    'User-Agent' => 'Testing/2.0',
                ]
            ]
        );
        $client->get('/foo',
            [
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'Testing/1.0',
                ]
            ]
        );
        $client->get('/foo',
            [
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'Testing/2.0',
                ]
            ]
        );

        $response = $client->get(
            '/foo',
            [
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'Testing/2.0',
                ]
            ]
        );
        $this->assertEquals(
            'HIT from GuzzleCache',
            $response->getHeader('x-cache')
        );
        $this->assertEquals(
            'Test/2.0 request.',
            json_decode($this->getResponseBody($response))->body
        );
    }

    /**
     * Test that stale responses are used on errors if allowed.
     */
    public function testOnErrorStaleResponse()
    {
        $now = $this->date();

        Server::enqueue([
            new Response(200, [
                'Date' => $now,
                'Cache-Control' => 'private, max-age=0, must-revalidate, stale-if-error=666',
                'Last-Modified' => 'Wed, 29 Oct 2014 20:30:57 GMT',
            ], Stream::factory('It works!')),
            new Response(503, [
                'Date' => $now,
                'Cache-Control' => 'private, s-maxage=0, max-age=0, must-revalidate',
                'Last-Modified' => 'Wed, 29 Oct 2014 20:30:57 GMT',
                'Age' => '1277'
            ]),
        ]);

        $client = $this->setupClient();

        // Prime the cache.
        $response1 = $client->get('/foo');
        $this->assertEquals(200, $response1->getStatusCode());

        // This should return the first request.
        $response2 = $client->get('/foo');
        $this->assertEquals(200, $response2->getStatusCode());
        $this->assertEquals('It works!', $this->getResponseBody($response2));
        $this->assertEquals('HIT_ERROR from GuzzleCache', $response2->getHeader('x-cache'));
        $this->assertCount(2, Server::received());
    }

    /**
     * Test that expired stale responses aren't returned.
     */
    public function testOnErrorStaleResponseExpired()
    {
        // These dates are in the past, so the responses will be expired.
        Server::enqueue([
            new Response(200, [
                'Date' => 'Wed, 29 Oct 2014 20:52:15 GMT',
                'Cache-Control' => 'private, max-age=0, must-revalidate, stale-if-error=10',
                'Last-Modified' => 'Wed, 29 Oct 2014 20:30:57 GMT',
            ]),
            new Response(503, [
                'Date' => 'Wed, 29 Oct 2014 20:55:15 GMT',
                'Cache-Control' => 'private, s-maxage=0, max-age=0, must-revalidate',
                'Last-Modified' => 'Wed, 29 Oct 2014 20:30:57 GMT',
            ]),
        ]);

        $client = $this->setupClient();

        // Prime the cache.
        $response1 = $client->get('/foo');
        $this->assertEquals(200, $response1->getStatusCode());
        $this->assertEquals('Wed, 29 Oct 2014 20:52:15 GMT', $response1->getHeader('Date'));

        try {
            $client->get('/foo');
            $this->fail('503 was not thrown with an expired cache entry.');
        } catch (ServerException $e) {
            $this->assertEquals(503, $e->getCode());
            $this->assertEquals('Wed, 29 Oct 2014 20:55:15 GMT', $e->getResponse()->getHeader('Date'));
            $this->assertCount(2, Server::received());
        }
    }

    /**
     * Test that the can_cache option can modify cache behaviour.
     */
    public function testCanCache()
    {
        $now = $this->date();

        // Return an uncacheable response, that is then cached by can_cache
        // returning TRUE.
        Server::enqueue(
            [
                new Response(
                    200, [
                    'Date' => $now,
                    'Cache-Control' => 'private, max-age=0, no-cache',
                    'Last-Modified' => $now,
                ], Stream::factory('It works!')),
                new Response(
                    304, [
                    'Date' => $now,
                    'Cache-Control' => 'private, max-age=0, no-cache',
                    'Last-Modified' => $now,
                    'Age' => 0,
                ]),
            ]
        );

        $client = new Client(['base_url' => Server::$url]);
        CacheSubscriber::attach(
            $client,
            [
                'can_cache' => function (RequestInterface $request) {
                    return true;
                }
            ]
        );

        $response1 = $client->get('/foo');
        $this->assertEquals('MISS from GuzzleCache', $response1->getHeader('X-Cache-Lookup'));
        $response2 = $client->get('/foo');
        $this->assertEquals('HIT from GuzzleCache', $response2->getHeader('X-Cache-Lookup'));
        $this->assertEquals('It works!', $this->getResponseBody($response2));
    }

    /**
     * Test that PURGE can delete cached responses.
     */
    public function testCanPurge()
    {
        $now = $this->date();

        // Return a cached response that is then purged, and requested again
        Server::enqueue(
            [
                new Response(
                    200, [
                    'Date' => $now,
                    'Cache-Control' => 'public, max-age=60',
                    'Last-Modified' => $now,
                ], Stream::factory('It is foo!')),
                new Response(
                    200, [
                    'Date' => $now,
                    'Cache-Control' => 'public, max-age=60',
                    'Last-Modified' => $now,
                ], Stream::factory('It is bar!')),
            ]
        );

        $client = $this->setupClient();

        $response1 = $client->get('/foo');
        $this->assertEquals('MISS from GuzzleCache', $response1->getHeader('X-Cache-Lookup'));
        $this->assertEquals('It is foo!', $this->getResponseBody($response1));

        $response2 = $client->get('/foo');
        $this->assertEquals('HIT from GuzzleCache', $response2->getHeader('X-Cache-Lookup'));
        $this->assertEquals('It is foo!', $this->getResponseBody($response2));

        $response3 = $client->send($client->createRequest('PURGE', '/foo'));
        $this->assertEquals(204, $response3->getStatusCode());

        $response4 = $client->get('/foo');
        $this->assertEquals('MISS from GuzzleCache', $response4->getHeader('X-Cache-Lookup'));
        $this->assertEquals('It is bar!', $this->getResponseBody($response4));
    }

    /**
     * Test that cache entries are deleted when a response 404s.
     */
    public function test404CacheDelete()
    {
        $this->fourXXCacheDelete(404);
    }

    /**
     * Test that cache entries are deleted when a response 410s.
     */
    public function test410CacheDelete()
    {
        $this->fourXXCacheDelete(410);
    }

    /**
     * Test the resident_time calculation (RFC7234 4.2.3)
     */
    public function testAgeIsIncremented()
    {
        Server::enqueue([
            new Response(200, [
                'Date' => $this->date(),
                'Cache-Control' => 'public, max-age=60',
                'Age' => '59'
            ], Stream::factory('Age is 59!')),
            new Response(200, [
                'Date' => $this->date(),
                'Cache-Control' => 'public, max-age=60',
                'Age' => '0'
            ], Stream::factory('It works!')),
        ]);

        $client = $this->setupClient();

        // First request : the response is cached
        $response1 = $client->get('/foo');
        $this->assertEquals(200, $response1->getStatusCode());
        $this->assertEquals('MISS from GuzzleCache', $response1->getHeader('X-Cache-Lookup'));
        $this->assertEquals('Age is 59!', $this->getResponseBody($response1));

        // Second request : cache hit, age is now 60
        sleep(1);
        $response2 = $client->get('/foo');
        $this->assertEquals(200, $response1->getStatusCode());
        $this->assertEquals('HIT from GuzzleCache', $response2->getHeader('X-Cache-Lookup'));

        // This request should not be valid anymore : age is 59 + 2 = 61 which is strictly greater than 60
        sleep(1);
        $response3 = $client->get('/foo');
        $this->assertEquals(200, $response3->getStatusCode());
        $this->assertEquals('MISS from GuzzleCache', $response3->getHeader('X-Cache-Lookup'));
        $this->assertEquals('It works!', $this->getResponseBody($response3));

        $this->assertCount(2, Server::received());
    }

    /**
     * Decode a response body from TestServer.
     *
     * TestServer encodes all responses with base64, so we need to decode them
     * before we can do any assert's on them.
     *
     * @param Response $response The response with a body to decode.
     *
     * @return string
     */
    private function getResponseBody($response)
    {
        return base64_decode($response->getBody());
    }

    /**
     * Set up responses used by our Vary tests.
     *
     * @throws \Exception
     */
    private function setupMultipleVaryResponses()
    {
        $now = $this->date();

        Server::enqueue(
            [
                new Response(
                    200, [
                    'Vary' => 'Accept, User-Agent',
                    'Content-type' => 'text/html',
                    'Date' => $now,
                    'Cache-Control' => 'public, s-maxage=1000, max-age=1000',
                    'Last-Modified' => $now,
                ], Stream::factory('Test/1.0 request.')
                ),
                new Response(
                    200,
                    [
                        'Vary' => 'Accept, User-Agent',
                        'Content-type' => 'text/html',
                        'Date' => $now,
                        'Cache-Control' => 'public, s-maxage=1000, max-age=1000',
                        'Last-Modified' => $now,
                    ],
                    Stream::factory('Test/2.0 request.')
                ),
                new Response(
                    200, [
                    'Vary' => 'Accept, User-Agent',
                    'Content-type' => 'application/json',
                    'Date' => $now,
                    'Cache-Control' => 'public, s-maxage=1000, max-age=1000',
                    'Last-Modified' => $now,
                ], Stream::factory(json_encode(['body' => 'Test/1.0 request.']))
                ),
                new Response(
                    200, [
                    'Vary' => 'Accept, User-Agent',
                    'Content-type' => 'application/json',
                    'Date' => $now,
                    'Cache-Control' => 'public, s-maxage=1000, max-age=1000',
                    'Last-Modified' => $now,
                ], Stream::factory(json_encode(['body' => 'Test/2.0 request.']))
                ),
            ]
        );
    }

    /**
     * Setup a Guzzle client for testing.
     *
     * @param History $history (optional) parameter of a History to track
     *                         requests in.
     *
     * @return Client A client ready to run test requests against.
     */
    private function setupClient(History $history = null)
    {
        $client = new Client(['base_url' => Server::$url]);
        CacheSubscriber::attach($client);
        if ($history) {
            $client->getEmitter()->attach($history);
        }

        return $client;
    }

    /**
     * Return a date string suitable for using in an HTTP header.
     *
     * @param int $timestamp (optional) A Unix timestamp to generate the date.
     *
     * @return string The generated date string.
     */
    private function date($timestamp = null)
    {
        if (!$timestamp) {
            $timestamp = time();
        }

        return gmdate("D, d M Y H:i:s", $timestamp) . ' GMT';
    }

    /**
     * Helper to test that a 400 response deletes cache entries.
     *
     * @param int $errorCode The error code to test, such as 404 or 410.
     *
     * @throws \Exception
     */
    private function fourXXCacheDelete($errorCode)
    {
        $now = $this->date();

        Server::enqueue(
            [
                new Response(
                    200, [
                    'Date' => $now,
                    'Cache-Control' => 'public, max-age=1000, must-revalidate',
                    'Last-Modified' => $now,
                ]
                ),
                new Response(
                    304, [
                    'Date' => $now,
                    'Cache-Control' => 'public, max-age=1000, must-revalidate',
                    'Last-Modified' => $now,
                    'Age' => 0,
                ]
                ),
                new Response(
                    $errorCode, [
                    'Date' => $now,
                    'Cache-Control' => 'public, max-age=1000, must-revalidate',
                    'Last-Modified' => $now,
                ]
                ),
                new Response(
                    200, [
                    'Date' => $now,
                    'Cache-Control' => 'public, max-age=1000, must-revalidate',
                    'Last-Modified' => $now,
                ]
                ),
            ]
        );

        $client = $this->setupClient();
        $response1 = $client->get('/foo');
        $this->assertEquals('MISS from GuzzleCache', $response1->getHeader('X-Cache-Lookup'));
        $response2 = $client->get('/foo');
        $this->assertEquals('HIT from GuzzleCache', $response2->getHeader('X-Cache-Lookup'));

        try {
            $client->get('/foo');
            $this->fail($errorCode . ' was not thrown.');
        } catch (RequestException $e) {
            $response3 = $e->getResponse();
            $this->assertEquals($errorCode, $response3->getStatusCode());
            $this->assertEquals('MISS from GuzzleCache', $response3->getHeader('X-Cache-Lookup'));
        }

        $response4 = $client->get('/foo');
        $this->assertEquals('MISS from GuzzleCache', $response4->getHeader('X-Cache-Lookup'));
    }
}
