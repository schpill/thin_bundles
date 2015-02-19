<?php
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
