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

    use Thin\Api;
    use Thin\Utils;
    use Thin\Model;

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

        public function token()
        {
            return Utils::token();
        }

        public function uuid()
        {
            return Utils::UUID();
        }

        public function isValidToken($token)
        {
            $check = Model::Wstoken()
            ->where(['token', '=', (string) $token])
            ->cursor()
            ->count();

            return $check == 1 ? true : false;
        }
    }
