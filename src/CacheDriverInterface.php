<?php
namespace GuzzleHttp\Subscriber\Cache;

/**
 * Interface used to cache.
 */
interface CacheDriverInterface
{
    /**
     * Get serialised data by key
     *
     * @param string $key
     *
     * @return string
     */
    public function fetch($key);

    /**
     * Save serialised data by key
     *
     * @param string $key
     * @param string $value
     * @param int    $ttl
     *
     * @return null
     */
    public function save($key, $value, $ttl = null);

    /**
     * Delete serialised data by key
     *
     * @param string $key
     *
     * @return null
     */
    public function delete($key);
}
