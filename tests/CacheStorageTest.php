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
        $ttl = $getTtl->invokeArgs($cache, array($response));
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
        $ttl = $getTtl->invokeArgs($cache, array($response));

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
        $ttl = $getTtl->invokeArgs($cache, array($response));
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
