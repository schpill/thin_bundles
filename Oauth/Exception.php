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

    namespace Oauth;

    class Exception extends \Exception
    {
        const CURL_NOT_FOUND                     = 0x01;
        const CURL_ERROR                         = 0x02;
        const GRANT_TYPE_ERROR                   = 0x03;
        const INVALID_CLIENT_AUTHENTICATION_TYPE = 0x04;
        const INVALID_ACCESS_TOKEN_TYPE          = 0x05;
    }
