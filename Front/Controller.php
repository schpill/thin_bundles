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

    use Thin\Utils;

    class Controller
    {
        private $datas = [];

        public function __construct()
        {
            $this->route = Mvc::$route;
        }

        public function __set($key, $value)
        {
            $this->datas[$key] = $value;

            return $this;
        }

        public function __get($key)
        {
            return isAke($this->datas, $key, null);
        }

        public function __unset($key)
        {
            unset($this->datas[$key]);
        }

        public function __isset($key)
        {
            $check = uniqid();

            return isAke($this->datas, $key, $check) != $check;
        }

        public function close()
        {
            $html = '<body onload="self.close();">';

            die($html);
        }

        public function redirect($action, $controller = null, $args = [])
        {
            $controller = is_null($controller) ? $this->route['controller'] : $controller;

            $url = URLSITE . $controller . '/' . $action;

            foreach ($args as $k => $v) {
                $url .= "/$k/$v";
            }

            Utils::go($url);
        }

        public function isPost($except = [])
        {
            if (!empty($_POST) && !empty($except)) {
                foreach ($except as $key) {
                    if (Arrays::exists($key, $_POST)) {
                        unset($_POST[$key]);
                    }
                }
            }

            return !empty($_POST);
        }

        public function forward($action, $controller = null, $method = null)
        {
            $controller = is_null($controller) ? $this->route['controller'] : $controller;
            $method     = is_null($controller) ? Mvc::$method : $method;

            Mvc::$method = $method;
            Mvc::$route = ['controller' => $controller, 'action' => $action];

            Mvc::dispatch();

            exit;
        }

        public function __call($method, $args)
        {
            if (isset($this->$method)) {
                if (is_callable($this->$method)) {
                    return call_user_func_array($this->$method, $args);
                }
            }
        }
    }
