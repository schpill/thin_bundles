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

    class Sql
    {
        public static function set($key, $value, $expire = 0)
        {
            /* CLI case */
            if (!defined('APPLICATION_ENV')) {
                define('APPLICATION_ENV', 'production');
            }

            $db = static::connect();
            self::clean($db);

            $expire = 0 < $expire ? $expire + time() : 0;

            self::del($key);

            $q = "INSERT INTO kvs (hkey, val, expire, env) VALUES ('" . addslashes($key) . "', '" . serialize($value) . "', $expire, '" . APPLICATION_ENV . "')";
            $db->query($q);
        }

        public static function expire($key, $expire = 0)
        {
            /* CLI case */
            if (!defined('APPLICATION_ENV')) {
                define('APPLICATION_ENV', 'production');
            }

            $expire = 0 < $expire ? $expire + time() : 0;

            if (self::has($key)) {
                $db = static::connect();
                $q = "UPDATE kvs SET expire = $expire WHERE hkey = '" . addslashes($key) . "' AND env = '" . APPLICATION_ENV . "'";
                $db->query($q);
            }
        }

        public static function get($key, $default = null)
        {
            /* CLI case */
            if (!defined('APPLICATION_ENV')) {
                define('APPLICATION_ENV', 'production');
            }

            $db = static::connect();

            self::clean($db);

            $q = "SELECT val FROM kvs WHERE hkey = '" . addslashes($key) . "' AND env = '" . APPLICATION_ENV . "'";

            $res = $db->query($q);

            if (false === $res) {
                return $default;
            }

            foreach ($res as $row) {
                return unserialize($row['val']);
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
                $db = static::connect();
                $q = "DELETE FROM kvs WHERE hkey = '" . addslashes($key) . "' AND env = '" . APPLICATION_ENV . "'";
                $db->query($q);
            }
        }

        public static function keys($pattern = '*')
        {
            /* CLI case */
            if (!defined('APPLICATION_ENV')) {
                define('APPLICATION_ENV', 'production');
            }

            $collection = [];

            $pattern = str_replace('*', '%', $pattern);

            $db = static::connect();
            self::clean($db);

            $q = "SELECT hkey FROM kvs WHERE hkey LIKE '$pattern' AND env = '" . APPLICATION_ENV . "'";

            $res = $db->query($q);

            if (false === $res) {
                return $collection;
            }

            foreach ($res as $row) {
                array_push($collection, $row['hkey']);
            }

            return $collection;
        }

        public static function has($key)
        {
            return static::get($key, 'dummyresponse') != 'dummyresponse';
        }

        private static function clean($db)
        {
            /* CLI case */
            if (!defined('APPLICATION_ENV')) {
                define('APPLICATION_ENV', 'production');
            }

            $query = 'DELETE FROM kvs WHERE expire > 0 AND expire < ' . time() . ' AND env = \'' . APPLICATION_ENV . '\'';
            $db->query($query);
        }
    }
