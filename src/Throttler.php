<?php namespace Framework\HTTP;

use Framework\Cache\Cache;
use Framework\Log\Logger;

/**
 * Class Throttler.
 *
 * @see https://en.wikipedia.org/wiki/Token_bucket#Properties
 * @see https://en.wikipedia.org/wiki/Rate_limiting#Web_servers
 * @see https://datatracker.ietf.org/doc/html/rfc6585#section-4
 */
class Throttler
{
	protected Response $response;
	protected Cache $cache;
	protected ?Logger $logger = null;
	protected string $cacheKeyPrefix = 'throttler:';
	protected int $rateLimit = 60;

	public function __construct(Response $response, Cache $cache, Logger $logger = null)
	{
		$this->response = $response;
		$this->cache = $cache;
		$this->logger = $logger;
	}

	protected function log(string $message, int $level = Logger::WARNING) : void
	{
		if ($this->logger) {
			$this->logger->log($level, $message);
		}
	}

	public function isAllowed(string $ip = null) : bool
	{
		$ip ??= $this->response->getRequest()->getIP();
		$key = 'throttler:' . $ip;
		$cached = $this->cache->get($key) ?? \time() . ':0';
		[$lastRequestTime, $requests] = \explode(':', $cached, 2);
		$requests = (int) $requests;
		if ($requests >= $this->rateLimit) {
			$this->cache->set($key, \time() . ':' . $requests + 1, 60);
			$this->response->setStatusLine(429);
			$this->response->setHeader('Retry-After', $lastRequestTime + 60 - \time());
			return false;
		}
		if ($requests === $this->rateLimit) {
			$this->log(
				'Throttler: IP ' . $ip . ' reached the limit of '
				. $this->rateLimit . ' requests in the last minute'
			);
		}
		$this->cache->set($key, \time() . ':' . $requests + 1, 60);
		return true;
	}

	public function sendResponse() : void
	{
		$this->response->send();
	}
}
