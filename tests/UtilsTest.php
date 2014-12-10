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
}
