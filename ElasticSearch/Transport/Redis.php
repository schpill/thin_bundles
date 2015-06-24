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

    use \ElasticSearch\DSL\Stringify;
    use Thin\Arrays;


    class Redis extends Base
    {
        public function __construct($host = "127.0.0.1", $port = 6379)
        {
            parent::__construct($host, $port);
        }

        /**
         * Index a new document or update it if existing
         *
         * @return array
         * @param array $document
         * @param mixed $id Optional
         * @param array $options
         * @throws \ElasticSearch\Exception
         */
        public function index($document, $id = false, array $options = [])
        {
            if ($id === false) throw new \ElasticSearch\Exception("Redis transport requires id when indexing");

            $document   = json_encode($document);
            $url        = $this->buildUrl(array($this->type, $id));
            $response   = redis()->set($url, $document);

            return array(
                'ok' => $response
            );
        }

        /**
         * Search
         *
         * @return array
         * @param array|string $query
         * @throws \ElasticSearch\Exception
         */
        public function search($query)
        {
            if (Arrays::is($query)) {
                if (Arrays::exists("query", $query)) {
                    $dsl    = new Stringify($query);
                    $q      = (string) $dsl;

                    $url    = $this->buildUrl(array(
                        $this->type, "_search?q=" . $q
                    ));

                    $result = json_decode(redis()->get($url), true);

                    return $result;
                }

                throw new \ElasticSearch\Exception("Redis protocol doesn t support the full DSL, only query");
            } elseif (is_string($query)) {
                /**
                 * String based search means http query string search
                 */
                $url = $this->buildUrl(
                    array(
                        $this->type,
                        '_search?q=' . $query
                    )
                );

                $result = json_decode(redis()->get($url), true);

                return $result;
            }
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
            $url = $this->buildUrl($path);

            switch ($method) {
                case 'GET':
                    $result = redis()->get($url);
                    break;
                case 'DELETE':
                    $result = redis()->del($url);
                    break;
            }

            return json_decode($result, true);
        }

        /**
         * Flush this index/type combination
         *
         * @return array
         * @param mixed $id
         * @param array $options Parameters to pass to delete action
         */
        public function delete($id=false, array $options = [])
        {
            if ($id) return $this->request(array($this->type, $id), "DELETE");
            else return $this->request(false, "DELETE");
        }
    }
