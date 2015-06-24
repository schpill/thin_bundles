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

    namespace Dbjson;

    use Dbjson\Dbjson as Db;

    class Facade
    {
        public static function __callStatic($method, $args)
        {
            $instance = Db::instance(static::$database, static::$table);

            return call_user_func_array([$instance, $method], $args);
        }
    }
