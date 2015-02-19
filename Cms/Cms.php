<?php
    namespace Cms;
    require_once __DIR__ . DS . 'Controller.php';
    require_once __DIR__ . DS . 'Config.php';

    use \Thin\Cmscontroller;

    class Cms
    {
        public static function init()
        {
            $controller = new Cmscontroller();
        }
    }
