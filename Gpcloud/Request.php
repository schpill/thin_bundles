<?php
    /**
     * Thin is a swift Framework for PHP 5.4+
     *
     * @package    Thin
     * @version    1.0
     * @author     Gerald Plusquellec
     * @license    BSD License
     * @copyright  1996 - 2015 Gerald Plusquellec
     * @link       http://github.com/schpill/thin
     */

	namespace Gpcloud;

	/*	private functions for calling the API. */

	class Request
	{
		private $apikey;

		public function __construct($apikey)
		{
			$this->apikey = $apikey;

			return true;
		}

		public function get($url)
		{
			return $this->call($url, '', 'GET');
		}

		public function delete($url)
		{
			return $this->call($url, $args, 'DELETE');
		}

		public function put($url, $args)
		{
			return $this->call($url, $args, 'PUT');
		}

		public function post($url, $args)
		{
			return $this->call($url, $args, 'POST');
		}

	/*
		call is a private function which handles all API requests.

			-	$url:	URL to the API
			-	$args:	Array of fields to pass
			-	$type:	either GET, PUT, POST or DELETE
	*/
		private function call($url, $args, $type = 'GET')
		{
			$headers = array(
				'X-Gpcloud-API-Key: ' . $this->apikey
			);

			$timeout = 5;
			$ch = curl_init($url);

			if ($type == 'GET') {
				curl_setopt($ch, CURLOPT_HEADER, false);
				curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				$data = curl_exec($ch);
				curl_close($ch);
			} else {
				$headers[] = 'Content-Type: application/json; charset=utf-8';
				curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
				curl_setopt($ch, CURLOPT_URL, $url);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
				curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);

				if ($type != 'POST') {
					curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $type);
				} else {
					curl_setopt($ch, CURLOPT_POST, true);
				}

				curl_setopt($ch, CURLOPT_POSTFIELDS, $args);
				$data = curl_exec($ch);
				curl_close($ch);
			}

			return $data;
		}
	}
