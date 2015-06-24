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

    namespace Kvs;

    use Elasticsearch\Common\Exceptions\Missing404Exception as E404;

    class Es
    {
        public static function set($key, $value, $expire = 0)
        {
            /* CLI case */
            if (!defined('APPLICATION_ENV')) {
                define('APPLICATION_ENV', 'production');
            }

            $expire = 0 < $expire ? $expire + time() : 0;

            es()->index([
                'index' => 'kvs_' . APPLICATION_ENV,
                'type'  => 'data',
                'id'    => $key,
                'body'  => json_encode(['key' => $key, 'value' => $value])
            ]);

            es()->index([
                'index' => 'kvs_' . APPLICATION_ENV,
                'type'  => 'expiration',
                'id'    => $key,
                'body'  => json_encode(['date' => $expire])
            ]);
        }

        public static function expire($key, $expire = 0)
        {
            /* CLI case */
            if (!defined('APPLICATION_ENV')) {
                define('APPLICATION_ENV', 'production');
            }

            $expire = 0 < $expire ? $expire + time() : 0;

            es()->index([
                'index' => 'kvs_' . APPLICATION_ENV,
                'type'  => 'expiration',
                'id'    => $key,
                'body'  => json_encode(['date' => $expire])
            ]);
        }

        public static function get($key, $default = null)
        {
            /* CLI case */
            if (!defined('APPLICATION_ENV')) {
                define('APPLICATION_ENV', 'production');
            }

            static::clean($key);

            try {
                    $data = es()->get([
                    'index' => 'kvs_' . APPLICATION_ENV,
                    'type'  => 'data',
                    'id'    => $key
                ]);

                if (!empty($data)) {
                    $data = isAke($data, '_source', []);

                    if (!empty($data)) {
                        return isAke($data, 'value', $default);
                    }
                }
            } catch (E404 $e) {
                return $default;
            }

            return $default;
        }

        public static function delete($key)
        {
            return static::del($key);
        }

        public static function del($key)
        {
            /* CLI case */
            if (!defined('APPLICATION_ENV')) {
                define('APPLICATION_ENV', 'production');
            }

            if (static::has($key)) {
                es()->delete([
                    'index' => 'kvs_' . APPLICATION_ENV,
                    'type'  => 'expiration',
                    'id'    => $key
                ]);

                es()->delete([
                    'index' => 'kvs_' . APPLICATION_ENV,
                    'type'  => 'data',
                    'id'    => $key
                ]);
            }
        }

        public static function keys($pattern = '*')
        {
            /* CLI case */
            if (!defined('APPLICATION_ENV')) {
                define('APPLICATION_ENV', 'production');
            }

            $collection = [];

            $results = es()->search([
                'index' => 'kvs_' . APPLICATION_ENV,
                'type'  => 'data',
                'size'  => 100000,
                'body'  => [
                    'query' => [
                        'wildcard' => [
                            'key' => $pattern
                        ]
                    ]
                ]
            ]);

            $hits = $results['hits'];

            $total = isAke($hits, 'total', 0);

            if (0 < $total) {
                $rows = isAke($hits, 'hits', []);

                foreach ($rows as $row) {
                    $data = isAke($row, '_source', []);

                    array_push($collection, isAke($data, 'key', null));
                }
            }

            return $collection;
        }

        public static function has($key)
        {
            return static::get($key, 'dummyresponse') != 'dummyresponse';
        }

        private static function clean($key)
        {
            /* CLI case */
            if (!defined('APPLICATION_ENV')) {
                define('APPLICATION_ENV', 'production');
            }

            try {
                $data = es()->get([
                    'index' => 'kvs_' . APPLICATION_ENV,
                    'type'  => 'expiration',
                    'id'    => $key
                ]);

                if (!empty($data)) {
                    $data = isAke($data, '_source', []);

                    if (!empty($data)) {
                        $expiration = isAke($data, 'date', 0);

                        if (0 < $expiration) {
                            if (time() > $expiration) {
                                es()->delete([
                                    'index' => 'kvs_' . APPLICATION_ENV,
                                    'type'  => 'expiration',
                                    'id'    => $key
                                ]);

                                es()->delete([
                                    'index' => 'kvs_' . APPLICATION_ENV,
                                    'type'  => 'data',
                                    'id'    => $key
                                ]);
                            }
                        }
                    }
                }
            } catch (E404 $e) {
                return false;
            }
        }
    }
