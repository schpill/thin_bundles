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

    namespace Front;

    use Thin\Request;
    use Thin\Container;
    use Thin\View;
    use Thin\Exception;
    use Thin\File;
    use Thin\Arrays;

    class Mvc
    {
        public static $pjax = false;
        public static $method;
        public static $route;

        public static function route()
        {
            static::$method = Request::method();
            $pjax = isAke(Request::headers(), 'x-pjax', []);

            static::$pjax = !empty($pjax);

            $uri = substr($_SERVER['REQUEST_URI'], 1);

            if (static::$pjax) {
                static::$method = 'GET';
            }

            if (fnmatch("*?*", $uri) && static::$pjax) {
                $uri = str_replace('?', '/', $uri);
                $uri = str_replace('=', '/', $uri);
                $uri = str_replace('&', '/', $uri);
            }

            if (!strlen($uri)) {
                $controller = 'index';
                $action     = 'home';
            } else {
                $tab = explode('/', $uri);

                if (count($tab) == 1) {
                    $seg = current($tab);

                    if (strlen($seg) == 2) {
                        $_REQUEST['lng']    = strtolower($seg);
                        $controller         = 'index';
                        $action             = 'home';
                    } else {
                        $controller = strtolower($seg);
                        $action     = 'index';
                    }
                } elseif (count($tab) == 2) {
                    $first  = current($tab);
                    $second = end($tab);

                    if (strlen($first) == 2) {
                        $_REQUEST['lng']    = strtolower($first);
                        $controller         = $second;
                        $action             = 'index';
                    } else {
                        $controller = strtolower($first);
                        $action     = strtolower($second);
                    }
                } else {
                    $first  = current($tab);
                    $second = $tab[1];
                    $third  = end($tab);

                    if (strlen($first) == 2) {
                        $_REQUEST['lng']    = strtolower($first);
                        $controller         = $second;
                        $action             = 'index';
                    } else {
                        $controller = strtolower($first);
                        $action     = strtolower($second);

                        $tab = array_slice($tab, 2);

                        $count = count($tab);

                        if (0 < $count && $count % 2 == 0) {
                            for ($i = 0; $i < $count; $i += 2) {
                                $_REQUEST[$tab[$i]] = $tab[$i + 1];
                            }
                        }
                    }
                }
            }

            static::$route = ['controller' => $controller, 'action' => $action];
        }

        public static function dispatch()
        {
            lib('lang')->locale('web');
            $controller = isAke(static::$route, 'controller', false);
            $action     = isAke(static::$route, 'action', false);

            $file = APPLICATION_PATH . DS . 'front' . DS . 'controllers' . DS . $controller . '.php';
            $tpl = APPLICATION_PATH . DS .
            'front' . DS . 'views' . DS . $controller . DS . $action . '.phtml';

            if (!File::exists($file)) {
                static::is404();
            }

            require_once $file;

            $class = 'Thin\\' . ucfirst($controller) . 'Controller';

            $i = new $class;

            if (File::exists($tpl)) {
                $i->view = new Container;
                if (static::$pjax) {
                    $i->view->partial = function ($partial) {
                        return true;
                    };
                } else {
                    $i->view->partial = function ($partial) use ($i) {
                        $tpl = APPLICATION_PATH . DS .
                        'front' . DS . 'views' . DS . 'partials' . DS . $partial . '.phtml';

                        if (File::exists($tpl)) {
                            $code = View::lng(File::read($tpl));
                            $code = str_replace('$this', '$i->view', $code);
                            eval('; ?>' . $code . '<?php ;');
                        }
                    };
                }
            }

            $methods = get_class_methods($i);

            $call = strtolower(static::$method) . ucfirst($action);

            if (!Arrays::in($call, $methods)) {
                static::is404();
            }

            if (Arrays::in('init', $methods)) {
                $i->init();
            }

            $i->$call();

            if (Arrays::in('after', $methods)) {
                $i->after();
            }

            if (File::exists($tpl)) {
                $code = View::lng(File::read($tpl));
                $code = str_replace('$this', '$i->view', $code);

                header("HTTP/1.0 200 OK");
                eval('; ?>' . $code . '<?php ;');

                exit;
            }
        }

        public static function is404($html = null)
        {
            $html = is_null($html) ? '<title>Error 404</title><center><img alt="Error 404" title="Error 404" src="/themes/default/assets/img/404.jpg" /></center>' : $html;

            header("HTTP/1.0 404 Not Found");

            die($html);
        }
    }
