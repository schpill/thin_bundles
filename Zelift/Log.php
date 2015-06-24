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

    namespace Zelift;

    use Thin\Inflector;
    use Thin\Exception;

    class Log
    {
        public static function __callStatic($method, $args)
        {
            if (count($args)) {
                $type = Inflector::upper($method);

                $fnArgs = array_merge([$type], $args);

                return call_user_func_array('staticLog', $fnArgs);
            }

            throw new Exception('You must provide a message to log.');
        }
    }
