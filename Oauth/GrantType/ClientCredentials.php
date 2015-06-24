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

    namespace Oauth\GrantType;

    /**
     * Client Credentials Parameters
     */
    class ClientCredentials implements IGrantType
    {
        /**
         * Defines the Grant Type
         *
         * @var string  Defaults to 'client_credentials'.
         */
        const GRANT_TYPE = 'client_credentials';

        /**
         * Adds a specific Handling of the parameters
         *
         * @return array of Specific parameters to be sent.
         * @param  mixed  $parameters the parameters array (passed by reference)
         */
        public function validateParameters(&$parameters)
        {
        }
    }
