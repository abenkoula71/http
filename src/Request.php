<?php namespace Framework\HTTP;

use InvalidArgumentException;
use LogicException;
use UnexpectedValueException;

/**
 * Class Request.
 *
 * @see     https://developer.mozilla.org/en-US/docs/Web/HTTP/Messages#HTTP_Requests
 */
class Request extends Message implements RequestInterface
{
	protected array $files = [];
	protected ?array $parsedBody = null;
	/**
	 * HTTP Authorization Header parsed.
	 */
	protected ?array $auth = null;
	protected ?string $authType = null;
	protected string $host;
	protected int $port;
	/**
	 * Request Identifier. 32 bytes.
	 */
	protected ?string $id = null;
	protected array $negotiation = [
		'ACCEPT' => null,
		'CHARSET' => null,
		'ENCODING' => null,
		'LANGUAGE' => null,
	];
	/**
	 * @var false|URL
	 */
	protected $referrer;
	/**
	 * @var false|UserAgent
	 */
	protected $userAgent;
	protected ?bool $isAJAX = null;
	protected ?bool $isSecure = null;

	/**
	 * Request constructor.
	 *
	 * @param array<string>|null $allowed_hosts set allowed hosts if your server dont serve by Host
	 *                                          header, as Nginx do
	 *
	 * @throws UnexpectedValueException if invalid Host
	 */
	public function __construct(array $allowed_hosts = null)
	{
		if ($allowed_hosts !== null) {
			$this->validateHost($allowed_hosts);
		}
		$this->prepareStatusLine();
		$this->prepareHeaders();
		$this->prepareCookies();
		$this->prepareUserAgent();
		$this->prepareFiles();
	}

	/**
	 * Check if Host header is allowed.
	 *
	 * @see https://expressionengine.com/blog/http-host-and-server-name-security-issues
	 * @see http://nginx.org/en/docs/http/request_processing.html
	 *
	 * @param array<string> $allowed_hosts
	 */
	protected function validateHost(array $allowed_hosts) : void
	{
		$host = $this->getServerVariable('HTTP_HOST');
		if ( ! \in_array($host, $allowed_hosts, true)) {
			throw new UnexpectedValueException('Invalid Host: ' . \htmlentities($host));
		}
	}

	protected function prepareStatusLine() : void
	{
		$this->setProtocol($this->getServerVariable('SERVER_PROTOCOL'));
		$this->setMethod($this->getServerVariable('REQUEST_METHOD'));
		$url = $this->isSecure() ? 'https' : 'http';
		$url .= '://' . $this->getServerVariable('HTTP_HOST');
		//$url .= ':' . $this->getPort();
		$url .= $this->getServerVariable('REQUEST_URI');
		$this->setURL($url);
		$this->setHost($this->getURL()->getHost());
	}

	protected function prepareHeaders() : void
	{
		foreach ($this->getServerVariable() as $name => $value) {
			if (\strpos($name, 'HTTP_') !== 0) {
				continue;
			}
			$name = \strtr(\substr($name, 5), ['_' => '-']);
			$this->setHeader($name, $value);
		}
	}

	protected function prepareCookies() : void
	{
		foreach ($this->filterInput(\INPUT_COOKIE) as $name => $value) {
			$this->setCookie(new Cookie($name, $value));
		}
	}

	protected function prepareUserAgent() : void
	{
		$userAgent = $this->getServerVariable('HTTP_USER_AGENT');
		if ($userAgent) {
			$this->setUserAgent($userAgent);
		}
	}

	/**
	 * @see https://php.net/manual/en/wrappers.php.php#wrappers.php.input
	 *
	 * @return string
	 */
	public function getBody() : string
	{
		$contentType = $this->getContentType();
		if ($contentType
			&& $this->getMethod() === 'POST'
			&& \strpos($contentType, 'multipart/form-data;') === 0
		) {
			return \http_build_query($this->getPOST() ?? []);
		}
		return \file_get_contents('php://input') ?: '';
	}

	protected function prepareFiles() : void
	{
		$this->files = $this->getInputFiles();
	}

	/**
	 * @param int            $type
	 * @param string|null    $variable
	 * @param int|null       $filter
	 * @param array|int|null $options
	 *
	 * @return mixed
	 */
	protected function filterInput(
		int $type,
		string $variable = null,
		int $filter = null,
		$options = null
	) {
		$variable = $variable === null
			? (array) \filter_input_array($type)
			: \ArraySimple::value($variable, (array) \filter_input_array($type));
		return $filter
			? \filter_var($variable, $filter, $options)
			: $variable;
	}

	public function forceHTTPS() : void
	{
		if ( ! $this->isSecure()) {
			\header('Location: ' . $this->getURL()->setScheme('https')->getURL(), true, 301);
			exit;
		}
	}

	public function getAuthType() : ?string
	{
		if ($this->authType === null) {
			$auth = $this->getHeader('Authorization');
			if ($auth) {
				$this->parseAuth($auth);
			}
		}
		return $this->authType;
	}

	/**
	 * @return array<string>|null
	 */
	public function getBasicAuth() : ?array
	{
		return $this->getAuthType() === 'Basic'
			? $this->auth
			: null;
	}

	/**
	 * @return array<string>|null
	 */
	public function getDigestAuth() : ?array
	{
		return $this->getAuthType() === 'Digest'
			? $this->auth
			: null;
	}

	/**
	 * @return array<string>
	 */
	protected function parseAuth(string $authorization) : array
	{
		$this->auth = [];
		[$type, $attributes] = \array_pad(\explode(' ', $authorization, 2), 2, null);
		if ($type === 'Basic') {
			$this->authType = $type;
			$this->auth = $this->parseBasicAuth($attributes);
		} elseif ($type === 'Digest') {
			$this->authType = $type;
			$this->auth = $this->parseDigestAuth($attributes);
		}
		return $this->auth;
	}

	/**
	 * @return array<string>
	 */
	protected function parseBasicAuth(string $attributes) : array
	{
		$data = [
			'username' => null,
			'password' => null,
		];
		$attributes = \base64_decode($attributes);
		if ($attributes) {
			[
				$data['username'],
				$data['password'],
			] = \array_pad(\explode(':', $attributes, 2), 2, null);
		}
		return $data;
	}

	/**
	 * @return array<string>
	 */
	protected function parseDigestAuth(string $attributes) : array
	{
		$data = [
			'username' => null,
			'realm' => null,
			'nonce' => null,
			'uri' => null,
			'response' => null,
			'opaque' => null,
			'qop' => null,
			'nc' => null,
			'cnonce' => null,
		];
		\preg_match_all(
			'#(username|realm|nonce|uri|response|opaque|qop|nc|cnonce)=(?:([\'"])([^\2]+?)\2|([^\s,]+))#',
			$attributes,
			$matches,
			\PREG_SET_ORDER
		);
		foreach ($matches as $match) {
			if (isset($match[1], $match[3])) {
				$data[$match[1]] = $match[3] ?: $match[4] ?? '';
			}
		}
		return $data;
	}

	public function getParsedBody(string $name = null, int $filter = null, $filter_options = null)
	{
		if ($this->getMethod() === 'POST') {
			return $this->getPOST($name, $filter, $filter_options);
		}
		if ($this->parsedBody === null) {
			\parse_str($this->getBody(), $this->parsedBody);
		}
		$variable = $name === null
			? $this->parsedBody
			: \ArraySimple::value($name, $this->parsedBody);
		return $filter
			? \filter_var($variable, $filter, $filter_options)
			: $variable;
	}

	/**
	 * @param bool $assoc
	 * @param int  $options
	 * @param int  $depth
	 *
	 * @return array|false|object
	 */
	public function getJSON(bool $assoc = false, int $options = null, int $depth = 512)
	{
		if ($options === null) {
			$options = \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES;
		}
		$body = \json_decode($this->getBody(), $assoc, $depth, $options);
		if (\json_last_error() !== \JSON_ERROR_NONE) {
			return false;
		}
		return $body;
	}

	/**
	 * @param string $type
	 *
	 * @return array
	 */
	protected function getNegotiableValues(string $type) : array
	{
		if ($this->negotiation[$type]) {
			return $this->negotiation[$type];
		}
		$this->negotiation[$type] = \array_keys(static::parseQualityValues(
			$this->getServerVariable('HTTP_ACCEPT' . ($type !== 'ACCEPT' ? '_' . $type : ''))
		));
		$this->negotiation[$type] = \array_map('strtolower', $this->negotiation[$type]);
		return $this->negotiation[$type];
	}

	/**
	 * @param string        $type
	 * @param array<string> $negotiable
	 *
	 * @return string
	 */
	protected function negotiate(string $type, array $negotiable) : string
	{
		$negotiable = \array_map('strtolower', $negotiable);
		foreach ($this->getNegotiableValues($type) as $item) {
			if (\in_array($item, $negotiable, true)) {
				return $item;
			}
		}
		return $negotiable[0];
	}

	/**
	 * @return array<string>
	 */
	public function getAccepts() : array
	{
		return $this->getNegotiableValues('ACCEPT');
	}

	/**
	 * @param array<string> $negotiable
	 *
	 * @return string
	 */
	public function negotiateAccept(array $negotiable) : string
	{
		return $this->negotiate('ACCEPT', $negotiable);
	}

	/**
	 * @return array<string>
	 */
	public function getCharsets() : array
	{
		return $this->getNegotiableValues('CHARSET');
	}

	/**
	 * @param array<string> $negotiable
	 *
	 * @return string
	 */
	public function negotiateCharset(array $negotiable) : string
	{
		return $this->negotiate('CHARSET', $negotiable);
	}

	/**
	 * @return array<string>
	 */
	public function getEncodings() : array
	{
		return $this->getNegotiableValues('ENCODING');
	}

	/**
	 * @param array<string> $negotiable
	 *
	 * @return string
	 */
	public function negotiateEncoding(array $negotiable) : string
	{
		return $this->negotiate('ENCODING', $negotiable);
	}

	/**
	 * @return array<string>
	 */
	public function getLanguages() : array
	{
		return $this->getNegotiableValues('LANGUAGE');
	}

	/**
	 * @param array<string> $negotiable
	 *
	 * @return string
	 */
	public function negotiateLanguage(array $negotiable) : string
	{
		return $this->negotiate('LANGUAGE', $negotiable);
	}

	/**
	 * @return string|null
	 */
	public function getContentType() : ?string
	{
		return $this->getServerVariable('HTTP_CONTENT_TYPE');
	}

	public function getCSRFToken() : ?string
	{
		return ($cookie = $this->getCookie('X-CSRF-Token')) ? $cookie->getValue() : null;
	}

	public function validateCSRFToken(string $token) : bool
	{
		//https://stackoverflow.com/questions/6287903/how-to-properly-add-csrf-token-using-php
		return $this->getCSRFToken()
			? \hash_equals($token, $this->getCSRFToken())
			: false;
	}

	/**
	 * @param string|null    $name
	 * @param int|null       $filter
	 * @param array|int|null $filter_options
	 *
	 * @return array|mixed|null
	 */
	public function getENV(string $name = null, int $filter = null, $filter_options = null)
	{
		return $this->filterInput(\INPUT_ENV, $name, $filter, $filter_options);
	}

	/**
	 * @return string|null
	 */
	public function getETag() : ?string
	{
		return $this->getServerVariable('HTTP_ETAG');
	}

	/**
	 * @return array|UploadedFile[]
	 */
	public function getFiles() : array
	{
		return $this->files;
	}

	public function hasFiles() : bool
	{
		return ! empty($this->files);
	}

	public function getFile(string $name) : ?UploadedFile
	{
		$file = \ArraySimple::value($name, $this->files);
		return \is_array($file) ? null : $file;
	}

	public function getQuery(string $name = null, int $filter = null, $filter_options = null)
	{
		return $this->filterInput(\INPUT_GET, $name, $filter, $filter_options);
	}

	/**
	 * @return string
	 */
	public function getHost() : string
	{
		return $this->host;
	}

	/**
	 * Get the Request ID.
	 *
	 * @return string
	 */
	public function getId() : string
	{
		if ($this->id !== null) {
			return $this->id;
		}
		$this->id = $this->getServerVariable('HTTP_X_REQUEST_ID');
		if (empty($this->id)) {
			$this->id = \md5(\uniqid($this->getIP(), true));
		}
		return $this->id;
	}

	/**
	 * @return string
	 */
	public function getIP() : string
	{
		return $this->getServerVariable('REMOTE_ADDR');
	}

	public function getMethod() : string
	{
		return parent::getMethod();
	}

	/**
	 * Gets data from the last request, if it was redirected.
	 *
	 * @param string $key a key name or null to get all data
	 *
	 * @see Response::redirect
	 *
	 * @throws LogicException if PHP Session is not active to get redirect data
	 *
	 * @return mixed|null an array containing all data, the key value or null if the key was not
	 *                    found
	 */
	public function getRedirectData(string $key = null)
	{
		static $data;
		if ($data === null && \session_status() !== \PHP_SESSION_ACTIVE) {
			throw new LogicException('Session must be active to get redirect data');
		}
		if ($data === null) {
			$data = $_SESSION['$__REDIRECT'] ?? false;
			unset($_SESSION['$__REDIRECT']);
		}
		if ($key !== null && $data) {
			return \ArraySimple::value($key, $data);
		}
		return $data === false ? null : $data;
	}

	/**
	 * @return int
	 */
	public function getPort() : int
	{
		return $this->port ?? $this->getServerVariable('SERVER_PORT');
	}

	/**
	 * @param string|null    $name
	 * @param int|null       $filter
	 * @param array|int|null $filter_options
	 *
	 * @return array|string|null
	 */
	public function getPOST(string $name = null, int $filter = null, $filter_options = null)
	{
		return $this->filterInput(\INPUT_POST, $name, $filter, $filter_options);
	}

	/**
	 * @return string|null
	 */
	public function getProxiedIP() : ?string
	{
		foreach ([
			'HTTP_X_FORWARDED_FOR',
			'HTTP_CLIENT_IP',
			'HTTP_X_CLIENT_IP',
			'HTTP_X_CLUSTER_CLIENT_IP',
		] as $header) {
			if ($header = $this->getServerVariable($header)) {
				return $header;
			}
		}
		return null;
	}

	/**
	 * @return URL|null
	 */
	public function getReferer() : ?URL
	{
		if ($this->referrer === null) {
			$this->referrer = false;
			$referer = $this->getServerVariable('HTTP_REFERER');
			if ($referer !== null) {
				try {
					$this->referrer = new URL($referer);
				} catch (InvalidArgumentException $e) {
					$this->referrer = false;
				}
			}
		}
		return $this->referrer === false ? null : $this->referrer;
	}

	public function getServerVariable(
		string $name = null,
		int $filter = null,
		$filter_options = null
	) {
		return $this->filterInput(\INPUT_SERVER, $name, $filter, $filter_options);
	}

	/**
	 * Gets the requested URL.
	 *
	 * @return URL
	 */
	public function getURL() : URL
	{
		return parent::getURL();
	}

	/**
	 * Gets the User Agent client.
	 *
	 * @return UserAgent|null the UserAgent object or null if no
	 *                        user-agent header was received
	 */
	public function getUserAgent() : ?UserAgent
	{
		if ($this->userAgent !== null) {
			return $this->userAgent;
		}
		$userAgent = $this->getServerVariable('HTTP_USER_AGENT');
		$userAgent ? $this->setUserAgent($userAgent) : $this->userAgent = false;
		return $this->userAgent ?: null;
	}

	/**
	 * @param string|UserAgent $user_agent
	 *
	 * @return $this
	 */
	protected function setUserAgent($user_agent)
	{
		if ( ! $user_agent instanceof UserAgent) {
			$user_agent = new UserAgent($user_agent);
		}
		$this->userAgent = $user_agent;
		return $this;
	}

	/**
	 * Check if is an AJAX Request based in the X-Requested-With Header.
	 *
	 * The X-Requested-With Header containing the "XMLHttpRequest" value is
	 * used by various javascript libraries.
	 *
	 * @return bool
	 */
	public function isAJAX() : bool
	{
		if ($this->isAJAX !== null) {
			return $this->isAJAX;
		}
		$received = $this->getServerVariable('HTTP_X_REQUESTED_WITH');
		return $this->isAJAX = $received
			? \strtolower($received) === 'xmlhttprequest'
			: false;
	}

	/**
	 * @return bool
	 */
	public function isSecure() : bool
	{
		if ($this->isSecure !== null) {
			return $this->isSecure;
		}
		return $this->isSecure = ($this->getServerVariable('REQUEST_SCHEME') === 'https'
			|| $this->getServerVariable('HTTPS') === 'on');
	}

	public function isForm() : bool
	{
		return $this->parseContentType() === 'application/x-www-form-urlencoded';
	}

	public function isJSON() : bool
	{
		return $this->parseContentType() === 'application/json';
	}

	public function isPOST() : bool
	{
		return $this->getMethod() === 'POST';
	}

	protected function getInputFiles() : array
	{
		if (empty($_FILES)) {
			return [];
		}
		// See: https://stackoverflow.com/a/33261775/6027968
		$walker = static function ($array, $fileInfokey, callable $walker) {
			$return = [];
			foreach ($array as $k => $v) {
				if (\is_array($v)) {
					$return[$k] = $walker($v, $fileInfokey, $walker);
					continue;
				}
				$return[$k][$fileInfokey] = $v;
			}
			return $return;
		};
		$files = [];
		foreach ($_FILES as $name => $values) {
			// init for array_merge
			if ( ! isset($files[$name])) {
				$files[$name] = [];
			}
			if ( ! \is_array($values['error'])) {
				// normal syntax
				$files[$name] = $values;
				continue;
			}
			// html array feature
			foreach ($values as $fileInfoKey => $subArray) {
				$files[$name] = \array_replace_recursive(
					$files[$name],
					$walker($subArray, $fileInfoKey, $walker)
				);
			}
		}
		// See: https://www.sitepoint.com/community/t/-files-array-structure/2728/5
		$make_objects = static function ($array, callable $make_objects) {
			$return = [];
			foreach ($array as $k => $v) {
				if (\is_array($v)) {
					$return[$k] = $make_objects($v, $make_objects);
					continue;
				}
				return new UploadedFile($array);
			}
			return $return;
		};
		return $make_objects($files, $make_objects);
	}

	/**
	 * @param string $host
	 *
	 * @throws InvalidArgumentException for invalid host
	 *
	 * @return $this
	 */
	protected function setHost(string $host)
	{
		$filtered_host = 'http://' . $host;
		if ( ! $filtered_host = \filter_var($filtered_host, \FILTER_VALIDATE_URL)) {
			throw new InvalidArgumentException("Invalid host: {$host}");
		}
		$host = \parse_url($filtered_host);
		$this->host = $host['host'];
		if (isset($host['port'])) {
			$this->port = $host['port'];
		}
		return $this;
	}
}
