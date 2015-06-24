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

    namespace Phalway;

    class Mvc
    {
        public static function init()
        {
            try {
                $loader = new \Phalcon\Loader();
                $loader->registerDirs([
                    __DIR__ . DS . 'controllers' . DS,
                    __DIR__ . DS . 'views' . DS,
                    __DIR__ . DS . 'models' . DS,
                    __DIR__ . DS . 'lib' . DS
                ])->registerNamespaces(['Phalway' => __DIR__])->register();

                context()->set('phalwayLoader', $loader);

                $di = new \Phalcon\DI\FactoryDefault();

                return new \Phalcon\Mvc\Application($di);
            } catch (\Phalcon\Exception $e) {
                dd($e);
            }
        }
    }
