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

    namespace Mobi;

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
            header("Access-Control-Allow-Origin: *");

            static::$method = Request::method();

            $uri = substr(str_replace('/mobi/', '/', $_SERVER['REQUEST_URI']), 1);

            $tab = explode('/', $uri);

            if (!strlen($uri) || $uri == '/') {
                $namespace  = 'static';
                $controller = 'home';
                $action     = 'index';
            } else {
                if (count($tab) < 3) {
                    self::isForbidden();
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
            }

            $file = APPLICATION_PATH . DS . 'mobi' . DS . $namespace . DS  . 'controllers' . DS . $controller . '.php';

            // dd($file);

            if (!File::exists($file)) {
                self::is404();
            }

            require_once $file;

            $class = 'Thin\\' . ucfirst($controller) . 'Mobi';

            $i = new $class;

            $methods = get_class_methods($i);

            $call = strtolower(static::$method) . ucfirst($action);

            if (!Arrays::in($call, $methods)) {
                self::is404();
            }

            if (Arrays::in('init', $methods)) {
                $i->init($call);
            }

            $i->$call();

            if ($i->view === true) {
                $tpl = APPLICATION_PATH . DS . 'mobi' . DS . $namespace . DS . 'views' . DS . $controller . DS . $action . '.phtml';

                if (File::exists($tpl)) {
                    $content = File::read($tpl);
                    $content = str_replace('$this->', '$i->', $content);
                    $fileTpl = CACHE_PATH . DS . sha1($content) . '.display';

                    File::put($fileTpl, $content);

                    ob_start();

                    include $fileTpl;

                    $html = ob_get_contents();

                    ob_end_clean();

                    File::delete($fileTpl);

                    self::render($html);
                } else {
                    self::render('OK');
                }
            }

            if (Arrays::in('after', $methods)) {
                $i->after();
            }
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

        private static function isForbidden()
        {
            $response = thin('response');

            $response->setStatusCode(503, 'Forbidden')
            ->sendHeaders()
            ->setContent('Access forbidden.')
            ->send();

            exit;
        }

        private static function render($html)
        {
            $response = thin('response');

            $response->setStatusCode(200, 'OK')
            ->sendHeaders()
            ->setContent($html)
            ->send();

            exit;
        }
    }
