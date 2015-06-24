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

    namespace Dbredis;

    class Redis
    {
        public static $instance;

        public function __construct($db, $table)
        {
            self::$instance = Db::instance($db, $table)->inCache(false);
        }

        public function exec($object = false, $count = false, $first = false)
        {
            if (!$object) {
                $hash   = self::$instance->getHash($object, $count, $first);
                $ageDb  = self::$instance->getAge();

                $key    = 'dbredis.exec.' . $hash . '.' . $ageDb;

                $cache = redis()->get($key);

                if ($cache) {
                    self::$instance->reset();

                    return unserialize($cache);
                }

                $collection = call_user_func_array([static::$instance, 'exec'], func_get_args());

                redis()->set($key, serialize($collection));

                return $collection;
            } else {
                return call_user_func_array([static::$instance, 'exec'], func_get_args());
            }
        }

        public static function __callStatic($method, $args)
        {
            return call_user_func_array([static::$instance, $method], $args);
        }
    }
