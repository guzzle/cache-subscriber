<?php
namespace GuzzleHttp\Subscriber\Cache;

use GuzzleHttp\Message\RequestInterface;

/**
 * Default strategy used to determine of an HTTP request can be cached
 */
class CanCache
{
    public function __invoke(RequestInterface $request)
    {
        $method = $request->getMethod();

        // Only GET and HEAD requests can be cached
        if ($method !== 'GET' && $method !== 'HEAD') {
            return false;
        }

        // Never cache requests when using no-store
        return Utils::getDirective($request, 'no-store') === null;
    }
}
