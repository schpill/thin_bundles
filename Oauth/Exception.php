<?php
    namespace Oauth;

    class Exception extends \Exception
    {
        const CURL_NOT_FOUND                     = 0x01;
        const CURL_ERROR                         = 0x02;
        const GRANT_TYPE_ERROR                   = 0x03;
        const INVALID_CLIENT_AUTHENTICATION_TYPE = 0x04;
        const INVALID_ACCESS_TOKEN_TYPE          = 0x05;
    }
