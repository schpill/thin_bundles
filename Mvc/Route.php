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

    namespace Mvc;

    use Thin\Inflector;
    use Thin\Exception;

    class Route
    {
        public static function __callStatic($fn, $args)
        {
            $method = Inflector::upper($fn);

            if (count($args) < 2) {
                throw new Exception("You must provide at least a path and a mvc pattern.");
            }

            $path   = $args[0];
            $mvc    = $args[1];

            $argsRoute      = [];
            $optionsRoute   = [];

            if (count($args) > 2) {
                $argsRoute = $args[2];

                if (count($args) > 3) {
                    array_shift($args);
                    array_shift($args);
                    array_shift($args);

                    $optionsRoute = $args;
                }
            }

            list($module, $controller, $action) = explode('::', $mvc, 3);

            if (!isset($module) || !isset($controller) || !isset($action)) {
                throw new Exception("MVC '$mvc' is incorrect.");
            }

            return [
                'name'          => Inflector::lower($method) . '::' . $module . '::' . $controller . '::' . $action,
                'method'        => $method,
                'path'          => $path,
                'module'        => $module,
                'controller'    => $controller,
                'action'        => $action,
                'args'          => $argsRoute,
                'options'       => $optionsRoute
            ];
        }
    }
