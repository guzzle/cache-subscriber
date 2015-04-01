<?php

namespace GuzzleHttp\Tests\Subscriber\Cache;

use Doctrine\Common\Cache\ArrayCache;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Subscriber\Cache\CacheStorage;

/**
 * Test the CacheStorage class.
 *
 * @class CacheStorageTest
 */
class CacheStorageTest extends \PHPUnit_Framework_TestCase
{
    /**
     * Test that a Response's max-age returns the correct TTL.
     */
    public function testGetTtlMaxAge()
    {
        $response = new Response(200, [
            'Cache-control' => 'max-age=10',
        ]);

        $getTtl = $this->getMethod('getTtl');
        $cache = new CacheStorage(new ArrayCache());
        $ttl = $getTtl->invokeArgs($cache, [$response]);
        $this->assertEquals(10, $ttl);
    }

    /**
     * Test that the default TTL for cachable responses with no max-age headers
     * is zero.
     */
    public function testGetTtlDefault()
    {
        $response = new Response(200);

        $getTtl = $this->getMethod('getTtl');
        $cache = new CacheStorage(new ArrayCache());
        $ttl = $getTtl->invokeArgs($cache, [$response]);

        // assertSame() here to be specific about null / false returns.
        $this->assertSame(0, $ttl);
    }

    /**
     * Test setting the default TTL.
     */
    public function testSetTtlDefault()
    {
        $response = new Response(200);

        $getTtl = $this->getMethod('getTtl');
        $cache = new CacheStorage(new ArrayCache(), null, 10);
        $ttl = $getTtl->invokeArgs($cache, [$response]);
        $this->assertEquals(10, $ttl);
    }

    /**
     * Test that stale-if-error is added to the max-age header.
     */
    public function testGetTtlMaxAgeStaleIfError()
    {
        $response = new Response(200, [
            'Cache-control' => 'max-age=10, stale-if-error=10',
        ]);

        $getTtl = $this->getMethod('getTtl');
        $cache = new CacheStorage(new ArrayCache());
        $ttl = $getTtl->invokeArgs($cache, [$response]);
        $this->assertEquals(20, $ttl);
    }

    /**
     * Test that stale-if-error works without a max-age header.
     */
    public function testGetTtlStaleIfErrorAlone()
    {
        $response = new Response(200, [
            'Cache-control' => 'stale-if-error=10',
        ]);

        $getTtl = $this->getMethod('getTtl');
        $cache = new CacheStorage(new ArrayCache());
        $ttl = $getTtl->invokeArgs($cache, [$response]);
        $this->assertEquals(10, $ttl);
    }

    /**
     * Test that expires is considered when cache-control is not available.
     */
    public function testGetTtlExpires()
    {
        $expires = new \DateTime('+100 seconds');
        $response = new Response(200, [
            'Expires' => $expires->format(DATE_RFC1123),
        ]);

        $getTtl = $this->getMethod('getTtl');
        $cache = new CacheStorage(new ArrayCache());
        $ttl = $getTtl->invokeArgs($cache, [$response]);
        $this->assertEquals(100, $ttl);
    }

    /**
     * Test that cache-control is considered before expires.
     */
    public function testGetTtlCacheControlExpires()
    {
        $expires = new \DateTime('+100 seconds');
        $response = new Response(200, [
            'Expires' => $expires->format(DATE_RFC1123),
            'Cache-control' => 'max-age=10',
        ]);

        $getTtl = $this->getMethod('getTtl');
        $cache = new CacheStorage(new ArrayCache());
        $ttl = $getTtl->invokeArgs($cache, [$response]);
        $this->assertEquals(10, $ttl);
    }

    /**
     * Return a protected or private method.
     *
     * @param string $name The name of the method.
     *
     * @return \ReflectionMethod A method object.
     */
    protected static function getMethod($name)
    {
        $class = new \ReflectionClass('GuzzleHttp\Subscriber\Cache\CacheStorage');
        $method = $class->getMethod($name);
        $method->setAccessible(true);

        return $method;
    }
}
