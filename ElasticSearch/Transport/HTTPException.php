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

    namespace ElasticSearch\Transport;

    use Thin\Arrays;

    class HTTPException extends \Exception
    {
        /**
         * Exception data
         * @var array
         */
        protected $data = array(
            'payload'   => null,
            'protocol'  => null,
            'port'      => null,
            'host'      => null,
            'url'       => null,
            'method'    => null,
        );

        /**
         * Setter
         * @param mixed $key
         * @param mixed $value
         */
        public function __set($key, $value)
        {
            if (Arrays::exists($key, $this->data)) $this->data[$key] = $value;
        }

        /**
         * Getter
         * @param mixed $key
         * @return mixed
         */
        public function __get($key)
        {
            if (Arrays::exists($key, $this->data)) return $this->data[$key];
            else return false;
        }

        /**
         * Rebuild CLI command using curl to further investigate the failure
         * @return string
         */
        public function getCLICommand()
        {
            $postData = json_encode($this->payload);
            $curlCall = "curl -X{$method} 'http://{$this->host}:{$this->port}$this->url' -d '$postData'";

            return $curlCall;
        }
    }
