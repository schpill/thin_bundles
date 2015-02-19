<?php
    namespace Oauth\GrantType;

    use Oauth\Invalidargumentexception;

    /**
     * Authorization code  Grant Type Validator
     */
    class AuthorizationCode implements IGrantType
    {
        /**
         * Defines the Grant Type
         *
         * @var string  Defaults to 'authorization_code'.
         */
        const GRANT_TYPE = 'authorization_code';

        /**
         * Adds a specific Handling of the parameters
         *
         * @return array of Specific parameters to be sent.
         * @param  mixed  $parameters the parameters array (passed by reference)
         */
        public function validateParameters(&$parameters)
        {
            if (!isset($parameters['code']))
            {
                throw new Invalidargumentexception(
                    'The \'code\' parameter must be defined for the Authorization Code grant type',
                    Invalidargumentexception::MISSING_PARAMETER
                );
            }
            elseif (!isset($parameters['redirect_uri']))
            {
                throw new Invalidargumentexception(
                    'The \'redirect_uri\' parameter must be defined for the Authorization Code grant type',
                    Invalidargumentexception::MISSING_PARAMETER
                );
            }
        }
    }
