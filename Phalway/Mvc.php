<?php
    namespace Phalway;

    /**
     * author: Gérald Plusquellec
     * date: 20/11/2014
     * subject: a brief phalcon abstraction
     *
     **/

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
