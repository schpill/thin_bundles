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
    use Thin\Inflector;

    if (!defined('CURLE_OPERATION_TIMEDOUT')) define('CURLE_OPERATION_TIMEDOUT', 28);


    class HTTP extends Base
    {
        /**
         * How long before timing out CURL call
         */
        private $timeout = 5;

        /**
         * curl handler which is needed for reusing existing http connection to the server
         * @var resource
         */
        protected $ch;


        public function __construct($host = 'localhost', $port = 9200, $timeout = null)
        {
            parent::__construct($host, $port);

            if(null !== $timeout) {
                $this->setTimeout($timeout);
            }

            $this->ch = curl_init();
        }

        /**
         * Index a new document or update it if existing
         *
         * @return array
         * @param array $document
         * @param mixed $id Optional
         * @param array $options
         */
        public function index($document, $id = false, array $options = [])
        {
            $url    = $this->buildUrl(array($this->type, $id), $options);
            $method = ($id == false) ? "POST" : "PUT";

            return $this->call($url, $method, $document);
        }

        /**
         * Search
         *
         * @return array
         * @param array|string $query
         * @param array $options
         */
        public function search($query, array $options = [])
        {
            $result = false;

            if (Arrays::is($query)) {
                /**
                 * Array implies using the JSON query DSL
                 */
                $arg = "_search";

                if(isset($options['routing'])) {
                    $arg = "_search?routing=" . $options['routing'];
                }

                $url = $this->buildUrl(
                    array(
                        $this->type,
                        $arg
                    )
                );

                $result = $this->call($url, "GET", $query);
            } elseif (is_string($query)) {
                /**
                 * String based search means http query string search
                 */
                $url = $this->buildUrl(
                    array(
                        $this->type,
                        "_search?q=" . $query
                    )
                );
                $result = $this->call($url, "POST", $options);
            } else {
                /**
                 * no http query string search
                 */
                $url = $this->buildUrl(
                    array(
                        $this->type,
                        "_search?"
                    )
                );

                $result = $this->call($url, "POST", $options);
            }

            return $result;
        }

        /**
         * Search
         *
         * @return array
         * @param mixed $query
         * @param array $options Parameters to pass to delete action
         */
        public function deleteByQuery($query, array $options = [])
        {
            $options += array(
                'refresh' => true
            );

            if (Arrays::is($query)) {
                /**
                 * Array implies using the JSON query DSL
                 */
                $url    = $this->buildUrl(
                    array(
                        $this->type,
                        "_query"
                    )
                );

                $result = $this->call(
                    $url,
                    "DELETE",
                    $query
                );
            } elseif (is_string($query)) {
                /**
                 * String based search means http query string search
                 */
                $url = $this->buildUrl(
                    array(
                        $this->type,
                        "_query"
                    ),
                    array(
                        'q' => $query
                    )
                );

                $result = $this->call($url, "DELETE");
            }

            if ($options['refresh']) {
                $this->request('_refresh', "POST");
            }

            return !isset($result['error']);
        }

        /**
         * Perform a request against the given path/method/payload combination
         * Example:
         * $es->request('/_status');
         *
         * @param string|array $path
         * @param string $method
         * @param array|bool $payload
         * @return array
         */
        public function request($path, $method = "GET", $payload = false)
        {
            return $this->call(
                $this->buildUrl($path),
                $method,
                $payload
            );
        }

        /**
         * Flush this index/type combination
         *
         * @return array
         * @param mixed $id Id of document to delete
         * @param array $options Parameters to pass to delete action
         */
        public function delete($id = false, array $options = [])
        {
            if ($id) return $this->call($this->buildUrl(array($this->type, $id), $options), "DELETE");
            else return $this->request(false, "DELETE");
        }

        /**
         * Perform a http call against an url with an optional payload
         *
         * @return array
         * @param string $url
         * @param string $method (GET/POST/PUT/DELETE)
         * @param array|bool $payload The document/instructions to pass along
         * @throws HTTPException
         */
        protected function call($url, $method = "GET", $payload = null)
        {
            $conn       = $this->ch;
            $protocol   = "http";
            $requestURL = $protocol . "://" . $this->host . $url;

            curl_setopt($conn, CURLOPT_URL, $requestURL);
            curl_setopt($conn, CURLOPT_TIMEOUT, $this->timeout);
            curl_setopt($conn, CURLOPT_PORT, $this->port);
            curl_setopt($conn, CURLOPT_RETURNTRANSFER, 1) ;
            curl_setopt($conn, CURLOPT_CUSTOMREQUEST, Inflector::upper($method));
            curl_setopt($conn, CURLOPT_FORBID_REUSE , 0) ;

            if (Arrays::is($payload) && count($payload) > 0) curl_setopt($conn, CURLOPT_POSTFIELDS, json_encode($payload)) ;
            else curl_setopt($conn, CURLOPT_POSTFIELDS, $payload);

            $response = curl_exec($conn);

            if ($response !== false) {
                $data = json_decode($response, true);

                if (!$data) {
                    $data = array(
                        'error' => $response,
                        "code"  => curl_getinfo($conn, CURLINFO_HTTP_CODE)
                    );
                }
            } else {
                /**
                 * cUrl error code reference can be found here:
                 * http://curl.haxx.se/libcurl/c/libcurl-errors.html
                 */
                $errno = curl_errno($conn);

                switch ($errno) {
                    case CURLE_UNSUPPORTED_PROTOCOL:
                        $error = "Unsupported protocol [$protocol]";
                        break;
                    case CURLE_FAILED_INIT:
                        $error = "Internal cUrl error?";
                        break;
                    case CURLE_URL_MALFORMAT:
                        $error = "Malformed URL [$requestURL] -d " . json_encode($payload);
                        break;
                    case CURLE_COULDNT_RESOLVE_PROXY:
                        $error = "Couldn t resolve proxy";
                        break;
                    case CURLE_COULDNT_RESOLVE_HOST:
                        $error = "Couldn t resolve host";
                        break;
                    case CURLE_COULDNT_CONNECT:
                        $error = "Couldn t connect to host [{$this->host}], ElasticSearch down?";
                        break;
                    case CURLE_OPERATION_TIMEDOUT:
                        $error = "Operation timed out on [$requestURL]";
                        break;
                    default:
                        $error = "Unknown error";
                        if ($errno == 0) $error .= ". Non-cUrl error";

                        break;
                }

                $exception              = new HTTPException($error);
                $exception->payload     = $payload;
                $exception->port        = $this->port;
                $exception->protocol    = $protocol;
                $exception->host        = $this->host;
                $exception->method      = $method;

                throw $exception;
            }

            return $data;
        }

        public function setTimeout($timeout)
        {
            $this->timeout = $timeout;
        }

        public function getTimeout()
        {
            return $this->timeout;
        }
    }
