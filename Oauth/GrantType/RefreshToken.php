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

    use Oauth\Invalidargumentexception;

    /**
     * Refresh Token  Parameters
     */
    class RefreshToken implements IGrantType
    {
        /**
         * Defines the Grant Type
         *
         * @var string  Defaults to 'refresh_token'.
         */
        const GRANT_TYPE = 'refresh_token';

        /**
         * Adds a specific Handling of the parameters
         *
         * @return array of Specific parameters to be sent.
         * @param  mixed  $parameters the parameters array (passed by reference)
         */
        public function validateParameters(&$parameters)
        {
            if (!isset($parameters['refresh_token']))
            {
                throw new Invalidargumentexception(
                    'The \'refresh_token\' parameter must be defined for the refresh token grant type',
                    Invalidargumentexception::MISSING_PARAMETER
                );
            }
        }
    }
