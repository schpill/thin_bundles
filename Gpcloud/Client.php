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

	use Thin\Arrays;
	use Thin\Inflector;

	class Client
	{
		private $db;
		private $col;
		private $apiKey;
		private $request;
		private $uri 		= 'http://api.gpluscloud.com/apps';
		private $push_uri 	= 'http://push.gpluscloud.com/emit';

		public function __construct($db, $apiKey, $col = '')
		{
			$this->db 		= $db;
			$this->col 		= $col;
			$this->apiKey 	= $apiKey;
			$this->request 	= new \Gpcloud\Request($apiKey);
		}

		public function __destruct()
		{

		}

		public function drop()
		{

		}

		public function collection($col)
		{
			$this->col = $col;

			return $this;
		}

		public function __get($col)
		{
			$col = Inflector::lower($col);

			return $this->collection($col);
		}

		public function get($key = '')
		{
			if (!empty($key)) {
				$url = $this->uri . '/db/' . $this->db . '/collection/' . $this->col . '/key/' . $key . '/apiKey/' . $this->apiKey;
			} else {
				$url = $this->uri . '/db/' . $this->db . '/collection/' . $this->col . '/apiKey/' . $this->apiKey;
			}

			$ret = $this->request->get($url);

			return json_decode($ret, true);
		}

		public function find($query, $meta = [])
		{
			return $this->query($query, $meta);
		}

		public function query($query, $meta = [])
		{
			$q 		= json_encode($query);
			$q 		= urlencode($q);
			$url 	= $this->uri . '/db/' . $this->db . '/collection/' . $this->col . '/apiKey/' . $this->apiKey . '/query/' . $q;

			if (!empty($meta)) {
				$mv = [];

				foreach($meta as $k => $v) {
					if (Arrays::is($v)) {
						$v = json_encode($v);
					}

					$mv[] = $k . '=' . $v;
				}

				$mv 	= implode('&', $mv);
				$url 	.= '&' . $mv;
			}

			$ret = $this->request->get($url);

			return json_decode($ret, true);
		}

		public function insert($vars)
		{
			$row 	= $vars;
			$url 	= $this->uri . '/db/' . $this->db . '/collection/' . $this->col . '/apiKey/' . $this->apiKey;
			$row 	= json_encode($row);
			$ret 	= $this->request->post($url, $row);
			$ret 	= json_decode($ret, true);
			$id 	= $ret['_id']['$oid'];

			return $id;
		}

		public function update($where, $vars)
		{
			$res = $this->find($where);
			$ret = false;

			if (!empty($res)) {
				foreach($res as $row) {
					$key = $row['_id'];
					$ret = $this->updatebyid($vars, $key);
				}
			}

			return $ret;
		}

		public function updatebyid($row, $key)
		{
			$url = $this->uri . '/db/' . $this->db . '/collection/' . $this->col . '/key/' . $key . '/apiKey/' . $this->apiKey;
			$row = json_encode($row);
			$ret = $this->request->put($url, $row);
			$ret = json_decode($ret, true);

			return $ret;
		}

		public function delete($key)
		{
			$url = $this->uri . '/db/' . $this->db . '/collection/' . $this->col . '/key/' . $key . '/apiKey/' . $this->apiKey;
			$ret = $this->request->delete($url);

			return json_decode($ret, true);
		}

		/*
			Real time notifications

			push.gpluscloud.com is our push server, you can use trigger to push a message to clients listening to the same channel.

			Channels are an md5 hash of db name and collection.
		*/
		public function trigger($event, $message)
		{
			//	we create a channel, channels are an sha1 hash of db name and collection...
			$channel 	= sha1($this->db . $this->collection);
			$url 		= $this->push_uri . '/channel/' . $channel . '/event/' . $event . '/message/' . $message;
			$ret 		= $this->request->get($url);
		}
	}
