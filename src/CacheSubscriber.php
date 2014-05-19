<?php
namespace GuzzleHttp\Subscriber\Cache;

use GuzzleHttp\Message\MessageInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Event\BeforeEvent;
use GuzzleHttp\Event\CompleteEvent;
use GuzzleHttp\Event\ErrorEvent;
use GuzzleHttp\Event\RequestEvents;
use GuzzleHttp\Event\SubscriberInterface;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Message\ResponseInterface;

/**
 * Plugin to enable the caching of GET and HEAD requests.
 *
 * Caching can be done on all requests passing through this plugin or only
 * after retrieving resources with cacheable response headers.
 *
 * This is a simple implementation of RFC 2616 and should be considered a
 * private transparent proxy cache, meaning authorization and private data can
 * be cached.
 *
 * It also implements RFC 5861's `stale-if-error` Cache-Control extension,
 * allowing stale cache responses to be used when an error is encountered
 * (such as a `500 Internal Server Error` or DNS failure).
 */
class CacheSubscriber implements SubscriberInterface
{
    /** @var callable Determines if a request is cacheable */
    protected $canCache;

    /** @var CacheStorageInterface $cache Object used to cache responses */
    protected $storage;

    /**
     * @param CacheStorageInterface $cache    Cache storage
     * @param callable              $canCache Callable used to determine if a
     *                                        request can be cached. Accepts a
     *                                        RequestInterface and returns a
     *                                        boolean value.
     */
    public function __construct(
        CacheStorageInterface $cache,
        callable $canCache
    ) {
        $this->storage = $cache;
        $this->canCache = $canCache;
    }

    public function getEvents()
    {
        return [
            'before'   => ['onBefore', RequestEvents::LATE],
            'complete' => ['onComplete', RequestEvents::EARLY],
            'error'    => ['onError', RequestEvents::EARLY]
        ];
    }

    /**
     * Checks if a request can be cached, and if so, intercepts with a cached
     * response is available.
     */
    public function onBefore(BeforeEvent $event)
    {
        $request = $event->getRequest();
        $this->addViaHeader($request);

        if (!$this->canCacheRequest($request)) {
            return;
        }

        if (!($response = $this->storage->fetch($request))) {
            return;
        }

        $response->setHeader('Age', Utils::getResponseAge($response));
        $valid = $this->validate($request, $response);

        // Validate that the response satisfies the request
        if ($valid) {
            $request->getConfig()->set('cache_lookup', 'HIT');
            $event->intercept($response);
        } else {
            $request->getConfig()->set('cache_lookup', 'MISS');
        }
    }

    /**
     * Checks if the request and response can be cached, and if so, store it
     */
    public function onComplete(CompleteEvent $event)
    {
        $request = $event->getRequest();
        $response = $event->getResponse();

        // Cache the response if it can be cached and isn't already
        if (call_user_func($this->canCache, $request) &&
            $this->canCacheResponse($response)
        ) {
            $this->storage->cache($request, $response);
        }

        $this->addResponseHeaders($request, $response);
    }

    /**
     * If the request failed, then check if a cached response would suffice
     */
    public function onError(ErrorEvent $event)
    {
        $request = $event->getRequest();

        if (!call_user_func($this->canCache, $request)) {
            return;
        }

        $response = $this->storage->fetch($request);

        // Intercept the failed response if possible
        if ($response && $this->validateFailed($request, $response)) {
            $request->getConfig()->set('cache_hit', 'error');
            $response->setHeader('Age', Utils::getResponseAge($response));
            $this->addResponseHeaders($request, $response);
            $event->intercept($response);
        }
    }

    private function validate(
        RequestInterface $request,
        ResponseInterface $response
    ) {
        $responseAge = Utils::getResponseAge($response);

        // Check the request's max-age header against the age of the response
        if ($responseAge > Utils::getDirective($response, 'max-age')) {
            return false;
        }

        // Check the response's max-age header against the fresness level
        $freshness = Utils::getFreshness($response);
        if ($freshness === null) {
            $maxStale = Utils::getDirective($request, 'max-stale');
            $maxAge = Utils::getDirective($response, 'max-age');
            if ($maxStale !== null) {
                if ($freshness < (-1 * $maxStale)) {
                    return false;
                }
            } elseif ($maxAge !== null && $responseAge > $maxAge) {
                return false;
            }
        }

        return true;
    }

    private function validateFailed(
        RequestInterface $request,
        ResponseInterface $response
    ) {
        $req = Utils::getDirective($request, 'stale-if-error');
        $res = Utils::getDirective($response, 'stale-if-error');

        if (!$req && !$res) {
            return false;
        }

        $responseAge = Utils::getResponseAge($response);
        $maxAge = Utils::getMaxAge($response);

        if (($req && $responseAge - $maxAge > $req) ||
            ($responseAge - $maxAge > $res)
        ) {
            return false;
        }

        return true;
    }

    /**
     * Add the plugin's headers to a response
     *
     * @param RequestInterface  $request  Request
     * @param ResponseInterface $response Response to add headers to
     */
    private function addResponseHeaders(
        RequestInterface $request,
        ResponseInterface $response
    ) {
        $this->addViaHeader($response);
        $params = $request->getConfig();
        $lookup = $params['cache_lookup'] . ' from GuzzleCache';
        $this->addUniqueHeader($response, 'X-Cache-Lookup', $lookup);

        if ($params['cache_hit'] === true) {
            $xcache = 'HIT from GuzzleCache';
        } elseif ($params['cache_hit'] == 'error') {
            $xcache = 'HIT_ERROR from GuzzleCache';
        } else {
            $xcache = 'MISS from GuzzleCache';
        }

        $this->addUniqueHeader($response, 'X-Cache', $xcache);
        $this->addFreshnessWarnings($response, $params['cache_hit']);
    }

    private function addUniqueHeader(MessageInterface $msg, $header, $value)
    {
        if (!$msg->hasHeader($header)) {
            $msg->setHeader($header, $value);
        } else {
            $values = $msg->getHeader($header, true);
            $values[] = $value;
            $msg->setHeader($header, array_unique($values));
        }
    }

    private function addFreshnessWarnings(ResponseInterface $response)
    {
        if (Utils::getFreshness($response) <= 0) {
            $template = '%d GuzzleCache/' . ClientInterface::VERSION . ' "%s"';
            $response->addHeader(
                'Warning',
                sprintf($template, 110, 'Response is stale')
            );
        }
    }

    private function addViaHeader(MessageInterface $message)
    {
        $message->addHeader('Via', sprintf(
            '%s GuzzleCache/%s',
            $message->getProtocolVersion(),
            ClientInterface::VERSION
        ));
    }

    private function canCacheRequest(RequestInterface $request)
    {
        return call_user_func($this->canCache, $request);
    }
}
