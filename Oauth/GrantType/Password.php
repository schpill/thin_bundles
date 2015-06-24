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
     * Password Parameters
     */
    class Password implements IGrantType
    {
        /**
         * Defines the Grant Type
         *
         * @var string  Defaults to 'password'.
         */
        const GRANT_TYPE = 'password';

        /**
         * Adds a specific Handling of the parameters
         *
         * @return array of Specific parameters to be sent.
         * @param  mixed  $parameters the parameters array (passed by reference)
         */
        public function validateParameters(&$parameters)
        {
            if (!isset($parameters['username']))
            {
                throw new Invalidargumentexception(
                    'The \'username\' parameter must be defined for the Password grant type',
                    Invalidargumentexception::MISSING_PARAMETER
                );
            }
            elseif (!isset($parameters['password']))
            {
                throw new Invalidargumentexception(
                    'The \'password\' parameter must be defined for the Password grant type',
                    Invalidargumentexception::MISSING_PARAMETER
                );
            }
        }
    }
