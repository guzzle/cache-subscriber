<?php

namespace GuzzleHttp\Subscriber\Cache;

use GuzzleHttp\Message\RequestInterface;

/**
 * Default strategy used to determine of an HTTP request can be cached
 */
class DefaultCanCacheStrategy
{
    public function __invoke(RequestInterface $request)
    {
        // Only GET and HEAD requests can be cached
        if ($request->getMethod() != RequestInterface::GET &&
            $request->getMethod() != RequestInterface::HEAD
        ) {
            return false;
        }

        // Never cache requests when using no-store
        $noStore = CacheSubscriber::getDirective($request, 'no-store');
        if ($noStore !== null && $noStore) {
            return false;
        }

        return true;
    }
}
