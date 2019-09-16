# CHANGELOG

## 0.2.0 - 2019-09-16

* Improvement: Tests for expired items without must-revalidate #20
* Improvement: Support for including Vary headers in cache keys #21
* Improvement: Test for the can_cache option #23
* Bugfix: Not adding timezone to dates #24
* Improvement: Add $defaultTtl to CacheStorage constructor #25
* Bugfix: Error caches responses for without vary headers #26
* Bugfix: stale-if-header not being added to max-age #27
* Bugfix: max-age and freshness confusing zero and null #28
* Bugfix: Use date() method to fix missing GMT #29
* Improvement: Tests for stale-if-error behaviour #30
* Improvement: Delete cache entries on both 404 and 410 responses #33
* Refactoring: Minor ValidationSubscriber.php cleanup #35
* Refactoring: Minor ValidationSubscriber.php cleanup #35
* Improvement: Extend caching ttl considerations #56
* Improvement: Add purge method #57
* Improvement: Integration test for the calculation of the "resident_time" #60
* Improvement: Support for PHP 7.0 & 7.1 #73

## 0.1.0 - 2014-10-29

* Initial release.
