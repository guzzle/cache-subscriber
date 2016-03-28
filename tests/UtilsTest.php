<?php

namespace GuzzleHttp\Subscriber\Cache;

use GuzzleHttp\Message\MessageFactory;
use PHPUnit_Framework_TestCase;

/**
 * Test the Utils class.
 *
 * @class UtilsTest
 */
class UtilsTest extends PHPUnit_Framework_TestCase
{
    /**
     * Test that a max-age of zero isn't returned as null.
     */
    public function testGetMaxAgeZero()
    {
        $messageFactory = new MessageFactory();
        $response = $messageFactory->createResponse(200, ['Cache-Control' => 's-maxage=0']);
        $this->assertSame(0, Utils::getMaxAge($response));

        $response = $messageFactory->createResponse(200, ['Cache-Control' => 'max-age=0']);
        $this->assertSame(0, Utils::getMaxAge($response));

        $response = $messageFactory->createResponse(200, ['Expires' => gmdate('D, d M Y H:i:s') . ' GMT']);
        $this->assertLessThanOrEqual(0, Utils::getMaxAge($response));
    }

    /**
     * Test that a response with no max-age information returns null.
     */
    public function testGetMaxAgeNull()
    {
        $messageFactory = new MessageFactory();
        $response = $messageFactory->createResponse(200);
        $this->assertSame(null, Utils::getMaxAge($response));
    }

    /**
     * Test that a response that is zero fresh returns zero and not null.
     */
    public function testGetFreshnessZero()
    {
        $messageFactory = new MessageFactory();
        $response = $messageFactory->createResponse(200,
            [
                'Cache-Control' => 'max-age=0',
                'Age' => 0,
            ]);

        $this->assertSame(0, Utils::getFreshness($response));
    }

    /**
     * Test that responses that can't have freshness determined return null.
     */
    public function testGetFreshnessNull()
    {
        $messageFactory = new MessageFactory();
        $response = $messageFactory->createResponse(200);
        $this->assertSame(null, Utils::getFreshness($response));
    }

    /**
     * Test that responses are cache-able.
     * 
     * @dataProvider exampleResponses
     */
    public function testCanCacheResponse($canCache, $statusCode, $headers)
    {
        $messageFactory = new MessageFactory();
        $response = $messageFactory->createResponse($statusCode);
        $response->setHeaders($headers);
        $this->assertSame($canCache, Utils::canCacheResponse($response));
    }

    public function exampleResponses()
    {
        return [
            [true,  200, []],
            [false, 200, ['Cache-Control' => 'no-store']],
            [false, 200, ['Content-Range' => '0-5']],
            [true,  206, ['Content-Range' => '0-5']],
            [true,  203, []],
            [true,  300, []],
            [true,  301, []],
            [true,  308, []],
            [true,  410, []],
            [false, 500, []],
            [false, 201, []],
        ];
    }
}
