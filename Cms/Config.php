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

    namespace Thin;

    class Cmsconfig
    {
        /**
         * All of the loaded configuration items.
         *
         * The configuration arrays are keyed by their owning bundle and file.
         *
         * @var array
         */
        public static $items = array();

        public static function load($file)
        {
            if (File::exists($file)) {
                $config = include $file;
                static::$items = arrayMergeRecursive(static::$items, $config);
            } else {
                $file = APPLICATION_PATH . DS . '..' . DS . 'public' . DS . 'content' . DS . SITE_NAME . DS . 'config' . DS . $file . '.php';
                if (File::exists($file)) {
                    $config = include $file;
                    static::$items = arrayMergeRecursive(static::$items, $config);
                }
            }
        }

        public static function gets()
        {
            return static::$items;
        }

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
            return !is_null(static::get($key));
        }

        public static function forget($key)
        {
            if (static::has($key)) {
                arrayUnset(static::$items, $key);
            }
        }
    }
