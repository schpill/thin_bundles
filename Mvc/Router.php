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

    use Thin\File;
    use Thin\Config;
    use Thin\Request;
    use Thin\Container;
    use Thin\Arrays;
    use Thin\Inflector;
    use Thin\Utils;
    use Thin\View;

    class Router
    {
        private static $uri;
        private static $method;
        private static $routes = [];

        public static function dispatch($module = null)
        {
            $module = is_null($module) ? 'front' : $module;
            $ever = context()->get('MVC404');

            if (true !== $ever) {
                $file = APPLICATION_PATH . DS . 'config' . DS . SITE_NAME . '_routes.php';

                if (File::exists($file)) {
                    $routes = include($file);
                    static::$routes = isAke($routes, $module, []);
                }

                if (count(static::$routes)) {
                    $baseUri = Config::get('application.base_uri', '');

                    if (strlen($baseUri)) {
                        $uri = strReplaceFirst($baseUri, '', $_SERVER['REQUEST_URI']);
                    } else {
                        $uri = $_SERVER['REQUEST_URI'];
                    }

                    static::$uri    = $uri;
                    static::$method = Request::method();

                    return static::find();
                }
            }

            return false;
        }

        private static function find()
        {
            foreach (static::$routes as $route) {
                if ($route['method'] == static::$method) {
                    $match = static::match($route['path']);

                    if (false !== $match) {
                        if (count($route['args'])) {
                            if (count($match) == count($route['args'])) {
                                $i = 1;
                                $continue = false;

                                foreach ($route['args'] as $key => $closure) {
                                    $val = $closure($match[$i]);

                                    if (false === $val) {
                                        $continue = true;
                                        break;
                                    }

                                    $_REQUEST[$key] = $val;

                                    $i++;
                                }

                                if (true === $continue) {
                                    continue;
                                }
                            }
                        }

                        if (count($route['options'])) {
                            foreach ($route['options'] as $key => $value) {
                                $_REQUEST[$key] = $value;
                            }
                        }

                        $dispatch = new Container;
                        $dispatch->setModule($route['module'])
                        ->setController($route['controller'])
                        ->setAction($route['action']);

                        return $dispatch;
                    }
                }
            }

            return false;
        }

        private static function match($routePath)
        {
            $path = trim(urldecode(static::$uri), '/');

            if (!strlen($path)) {
                $path = '/';
            }

            $path       = '/' == $path[0]       ? substr($path, 1)      : $path;
            $routePath  = '/' == $routePath[0]  ? substr($routePath, 1) : $routePath;
            $pathComp   = rtrim($routePath, '/');
            $regex      = '#^' . $routePath . '$#';
            $res        = preg_match($regex, $path, $values);

            if ($res === 0) {
                return false;
            }

            foreach ($values as $i => $value) {
                if (!is_int($i) || $i === 0) {
                    unset($values[$i]);
                }
            }

            return $values;
        }

        public static function forward($name)
        {
            $route = static::getRouteByName($name);

            if (null !== $route) {
                $module     = isAke($route, 'module', 'www');
                $controller = isAke($route, 'controller', 'static');
                $action     = isAke($route, 'action', 'index');

                $route = new Container;
                $route->setModule($module)->setController($controller)->setAction($action);
                context()->dispatch($route);

                exit;
            }
        }

        public static function redirect($name, $args = [])
        {
            Utils::go(static::to($name, $args));

            exit;
        }

        public static function to($name, $args = [])
        {
            $route = static::getRouteByName($name);

            if (null !== $route) {
                $path = $route['path'];

                if (count($args)) {
                    foreach ($args as $key => $value) {
                        $path = strReplaceFirst('(.*)', $value, $path);
                    }
                }

                return trim(urldecode(URLSITE), '/') . $path;
            }

            return urldecode(URLSITE);
        }

        public static function getRouteByName($name)
        {
            if (empty(static::$routes)) {
                $file = APPLICATION_PATH . DS . 'config' . DS . SITE_NAME . '_routes.php';

                if (File::exists($file)) {
                    static::$routes = include($file);
                }
            }

            if (count(static::$routes)) {
                foreach (static::$routes as $route) {
                    if ($route['name'] == $name) {
                        return $route;
                    }
                }
            }

            return null;
        }

        public static function dispatchModule($module)
        {
            $uri = $_SERVER['REQUEST_URI'];

            $controller = null;
            $action = null;

            if ($uri == '/') {
                $controller = 'index';
                $action     = 'index';
            } else {
                $tab = explode('/', $uri);
                array_shift($tab);

                if (count($tab) == 2) {
                    $controller = current($tab);
                    $action     = end($tab);
                } elseif (count($tab) == 1) {
                    $controller = current($tab);
                    $action     = 'index';
                } else {
                    $controller = current($tab);
                    $action     = $tab[1];

                    array_shift($tab);
                    array_shift($tab);

                    if (!empty($tab) && count($tab) % 2 == 0) {
                        for ($i = 0; $i < count($tab); $i += 2) {
                            $_REQUEST[trim($tab[$i])] = trim($tab[$i + 1]);
                        }
                    }
                }
            }

            if (!empty($controller) && !empty($action)) {
                static::go($module, $controller, $action);
            } else {
                static::is404();
            }

            exit;
        }

        private static function go($module, $controller, $action)
        {
            $cdir = APPLICATION_PATH . DS . 'modules' . DS . SITE_NAME . DS . Inflector::lower($module) . DS . 'controllers';

            if (!is_dir($cdir)) {
                static::is404();
            } else {
                $dirApps = realpath(APPLICATION_PATH);
                $tplDir = realpath($dirApps . DS . 'modules' . DS . SITE_NAME . DS . Inflector::lower($module) . DS . 'views');
                $controllerDir = realpath($dirApps . DS . 'modules' . DS . SITE_NAME . DS . Inflector::lower($module) . DS . 'controllers');

                $tpl = $tplDir . DS . Inflector::lower($controller) . DS . Inflector::lower($action) . '.phtml';
                $controllerFile = $controllerDir . DS . Inflector::lower($controller) . 'Controller.php';

                if (!file::exists($controllerFile)) {
                    return static::is404();
                } else {
                    if (File::exists($tpl)) {
                        $view = new View($tpl);
                    }

                    require_once $controllerFile;

                    $controllerClass    = 'Thin\\' . Inflector::lower($controller) . 'Controller';
                    $instance           = new $controllerClass;

                    if (File::exists($tpl)) {
                        $instance->view   = $view;
                    }

                    if (strstr($action, '-')) {
                        $words = explode('-', $action);
                        $newAction = '';

                        for ($i = 0; $i < count($words); $i++) {
                            $word = trim($words[$i]);

                            if ($i > 0) {
                                $word = ucfirst($word);
                            }

                            $newAction .= $word;
                        }

                        $action = $newAction;
                    }

                    $actionName = $action . 'Action';

                    $actions = get_class_methods($controllerClass);

                    if (!Arrays::in($actionName, $actions)) {
                        $continue = false;

                        foreach ($actions as $act) {
                            if (Inflector::lower($act) == Inflector::lower($actionName)) {
                                $actionName = $act;
                                $continue = true;
                                break;
                            }
                        }

                        if (false === $continue) {
                            return static::is404();
                        }
                    }

                    if (Arrays::in('init', $actions)) {
                        $instance->init();
                    }

                    if (Arrays::in('preDispatch', $actions)) {
                        $instance->preDispatch();
                    }

                    $instance->$actionName();

                    if (File::exists($tpl)) {
                        $instance->view->render();
                    }

                    /* stats */
                    if (File::exists($tpl) && null === container()->getNoShowStats()) {
                        echo View::showStats();
                    }

                    if (Arrays::in('postDispatch', $actions)) {
                        $instance->postDispatch();
                    }

                    if (Arrays::in('exit', $actions)) {
                        $instance->exit();
                    }
                }
            }

            exit;
        }

        private static function is404()
        {
            $response = thin('response');

            $response->setStatusCode(404, 'Not Found')
            ->sendHeaders()
            ->setContent('Page not found')
            ->send();

            exit;
        }
    }
