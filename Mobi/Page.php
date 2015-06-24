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

    use Thin\Api;

    class Page
    {
        public function success(array $array)
        {
            $array['status'] = 200;
            Api::render($array);
        }

        public function error(array $array)
        {
            $array['status'] = 500;
            Api::render($array);
        }

        public function status(array $array, $status = 200)
        {
            $array['status'] = $status;
            Api::render($array);
        }

        public function render($what)
        {
            die($what);
        }
    }
