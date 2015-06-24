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

    use Thin\Utils;

    class Config
    {
        /**
         * All of the loaded configuration items.
         *
         * The configuration arrays are keyed by their owning bundle and file.
         *
         * @var array
         */
        public static $items = [];

        public static function get($key, $default = null)
        {
            return arrayGet(static::$items, $key, $default);
        }

        public static function set($key, $value = null)
        {
            static::$items = arraySet(static::$items, $key, $value);
        }

        public static function has($key)
        {
            $dummy = Utils::token();

            return $dummy != static::get($key, $dummy);
        }

        public static function forget($key)
        {
            if (static::has($key)) {
                arrayUnset(static::$items, $key);
            }
        }
    }
