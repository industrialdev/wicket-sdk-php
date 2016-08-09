<?php
namespace Wicket;

use Exception;
use Firebase\JWT\JWT;
use GuzzleHttp;
use Psr\Http\Message\ResponseInterface;

class Client
{
	private $api_endpoint;
	private $app_key;
	private $api_key;
	/** @var \GuzzleHttp\Client */
	private $client;
	private $last_error = false;
	private $last_request = [];
	private $last_response = null;
	private $timeout = 10;

	protected $person_id;
	protected $access_token;

	public $locale;
	public $organizations;
	public $people;
	public $orders;

	/**
	 * Wicket constructor.
	 * @param null $app_key
	 * @param $api_key
	 * @param string $api_endpoint
	 */
	public function __construct($app_key = null, $api_key = null, $api_endpoint = 'http://localhost:3000/')
	{
		$this->app_key = $app_key;
		$this->api_key = $api_key;
		$this->api_endpoint = rtrim($api_endpoint, '/') . '/';
		$this->locale = 'en'; //default to english unless otherwise set

		$this->client = new GuzzleHttp\Client(['base_uri' => $this->api_endpoint, 'verify' => false]);

		// init certain api entities to expose them 'fluently'

		$this->people = new ApiResource($this, 'people');
		$this->orders = new ApiResource($this, 'orders');
		$this->emails = new ApiResource($this, 'emails');
		$this->phones = new ApiResource($this, 'phones');
		$this->addresses = new ApiResource($this, 'addresses');
		$this->organizations = new ApiResource($this, 'organizations');
		$this->intervals = new ApiResource($this, 'intervals');
	}

	public function authorize($person_id)
	{
		$this->person_id = $person_id;
		$this->access_token = $this->generateJwt($person_id);
	}

	protected function generateJwt($person_id, $expiresIn = 60 * 60 * 8)
	{
		$iat = time();

		$token = [
			// 'iss' => $this->api_endpoint,
			// 'aud' => $orguuid,
			// 'nbf' => $iat, // relax this not-before time-sync requirement as it causes huge headaches
			'sub' => $person_id,
			'iat' => $iat,
			'exp' => $iat + $expiresIn,
		];

		return JWT::encode($token, $this->api_key);
	}

	/**
	 * @return null
	 */
	public function getLastError()
	{
		return $this->last_error ? $this->last_error : false;
	}

	/**
	 * @return null
	 */
	public function getLastRequest()
	{
		return $this->last_request;
	}

	/**
	 * @return null
	 */
	public function getLastResponse()
	{
		return $this->last_response ? $this->last_response : false;
	}

	/**
	 * get current connection timeout
	 *
	 * @return int
	 */
	public function getTimeout()
	{
		return $this->timeout;
	}

	/**
	 * set current connection timeout
	 *
	 * @param int $timeout
	 */
	public function setTimeout($timeout)
	{
		$this->timeout = $timeout;
	}

	/**
	 * Returns the current access token for the client, generated by calling `authorize`
	 *
	 * @return string
	 */
	public function getAccessToken()
	{
		return $this->access_token;
	}

	/**
	 * @return string
	 */
	public function getApiEndpoint()
	{
		return $this->api_endpoint;
	}

	/**
	 * Make an HTTP GET request - for retrieving data
	 * @param   string $method URL of the API request method
	 * @param   array $args Assoc array of arguments (usually your data)
	 * @return  array|false   Assoc array of API response, decoded from JSON
	 */
	public function get($method, $args = [])
	{
		return $this->makeRequest('get', $method, $args);
	}

	public function post($method, $payload = [])
	{
		return $this->makeRequest('post', $method, $payload);
	}

	public function put($method, $payload = [])
	{
		return $this->makeRequest('put', $method, $payload);
	}

	public function patch($method, $payload = [])
	{
		return $this->makeRequest('patch', $method, $payload);
	}

	public function delete($method, $args = [])
	{
		return $this->makeRequest('delete', $method, $args);
	}

	/**
	 * Decode the response and format any error messages for debugging
	 * @param ResponseInterface $response The response from the http request
	 * @return array|false The JSON decoded into an array
	 */
	private function formatResponse(ResponseInterface $response)
	{
		$this->last_response = $response;

		$body = $response->getBody();

		if (!empty($body)) {
			$contents = json_decode($body->getContents(), true);
			$statusCode = $response->getStatusCode();
			if ($statusCode !== 200) {
				$this->last_error = sprintf('%d: %s', $statusCode, $response->getReasonPhrase());
			}

			return $contents;
		}

		return false;
	}

	private function makeRequest($http_verb = 'GET', $method, $args)
	{
		$http_verb = strtoupper($http_verb);
		$this->last_error = false;
		$this->last_request = [
			'method'  => $http_verb,
			'uri'     => $method,
			'options' => $args,
			'timeout' => $this->timeout,
		];
		$this->last_response = null;

		$request = new GuzzleHttp\Psr7\Request($http_verb, $method, [
			'Authorization' => 'Bearer ' . $this->access_token,
		]);
		// merge in locale before any request
		$args['locale'] = $this->locale;

		$response = $this->client->send($request, $args);   # may override if array_key_exists('Authorization', $args['headers'])

		//printf("wSDK attempt %s connect: %s\n", $http_verb, $request->getUri());
		return $this->formatResponse($response);
	}

	/**
	 * Attempt to authenticate credentials with the API server.
	 * @param $username string The username, likely an email address.
	 * @param $password string The plantext password.
	 * @return string|false The UUID of the user when successful, or false.
	 */
	public function auth_attempt($username, $password)
	{
		$payload = [
			'json' => [
				'user' => [
					'email'    => $username,
					'password' => $password,
				],
			],
		];

		try {
			$response = $this->post('/users/sign_in', $payload);

			if (array_key_exists('id_token', $response)) {
				$jwt = $response['id_token'];
				$this->access_token = $jwt;
				$decoded = JWT::decode($jwt, $this->api_key, array('HS256'));
				$response = $decoded->sub;      // Person.uuid
			} else $response = false;
		} catch (Exception $e) {
			$response = false;
		}

		return $response;
	}

}
