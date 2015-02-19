<?php
    namespace Dbjson;

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
