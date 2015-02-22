<?php
    namespace Front;

    use Thin\Request;
    use Thin\Exception;
    use Thin\Api;
    use Thin\File;
    use Thin\Arrays;

    class Mvc
    {
        private static $method;

        public static function dispatch()
        {
            static::$method = Request::method();

            $uri = substr(str_replace('/api/', '/', $_SERVER['REQUEST_URI']), 1);

            $tab = explode('/', $uri);

            if (count($tab) < 3) {
                Api::forbidden();
            }

            $namespace  = current($tab);
            $controller = $tab[1];
            $action     = $tab[2];

            $tab = array_slice($tab, 3);

            $count = count($tab);

            if (0 < $count && $count % 2 == 0) {
                for ($i = 0; $i < $count; $i += 2) {
                    $_REQUEST[$tab[$i]] = $tab[$i + 1];
                }
            }

            $file = APPLICATION_PATH . DS . 'api' . DS . $namespace . DS . $controller . '.php';

            if (!File::exists($file)) {
                Api::NotFound();
            }

            require_once $file;

            $class = 'Thin\\' . ucfirst($controller) . 'Api';

            $i = new $class;

            $methods = get_class_methods($i);

            $call = strtolower(static::$method) . ucfirst($action);

            if (!Arrays::in($call, $methods)) {
                Api::NotFound();
            }

            if (Arrays::in('init', $methods)) {
                $i->init();
            }

            $i->$call();

            if (Arrays::in('after', $methods)) {
                $i->after();
            }

        }
    }
