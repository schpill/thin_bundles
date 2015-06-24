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

    namespace Ws;

    use Thin\Request;
    use Thin\Exception;
    use Thin\Api;
    use Thin\File;
    use Thin\Arrays;

    class Rest
    {
        private static $method;

        public static function dispatch()
        {
            static::$method = Request::method();

            $uri = substr(str_replace('/ws/', '/', $_SERVER['REQUEST_URI']), 1);

            $tab = explode('/', $uri);

            if (count($tab) < 3) {
                Api::forbidden();
            }

            $namespace  = current($tab);
            $controller = $tab[1];
            $action     = $tab[2];

            $tab        = array_slice($tab, 3);

            $count      = count($tab);

            if (0 < $count && $count % 2 == 0) {
                for ($i = 0; $i < $count; $i += 2) {
                    $_REQUEST[$tab[$i]] = $tab[$i + 1];
                }
            }

            $file = APPLICATION_PATH . DS . 'webservices' . DS . $namespace . DS . $controller . '.php';

            if (!File::exists($file)) {
                Api::NotFound();
            }

            require_once $file;

            $class = 'Thin\\' . ucfirst($controller) . 'Ws';

            $i = new $class;

            $methods = get_class_methods($i);

            $call = strtolower(static::$method) . ucfirst($action);

            if (!Arrays::in($call, $methods)) {
                Api::NotFound();
            }

            if (Arrays::in('init', $methods)) {
                $i->init($call);
            }

            $i->$call();

            if (Arrays::in('after', $methods)) {
                $i->after();
            }

        }
    }
