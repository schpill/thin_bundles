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

    namespace Zelift;

    use Guzzle\Http\Client;
    use Thin\Api\Auth\Keyring;
    use Thin\Arrays;

    class Api
    {
        // Version
        const VERSION = '0.1.2';

        // Client
        private static $zeliftClient        = null;

        private static $zeliftApiHost       = 'api.zelift.com/1.0';
        private static $zeliftApiProtocol   = 'https';

        /**
         * Constructor
         *
         * @param array $config
         */
        public function __construct(array $config = [])
        {
            // Populate Keyring
            Keyring::setAppKey($config['AK']); // Application Key
            Keyring::setAppSecret($config['AS']); // Application Secret
            Keyring::setConsumerKey($config['CK']); // Consumer Key

            // Backward compatibility
            if (Arrays::exists('RG', $config)) {
                Keyring::setAppUrlRegion($config['RG']); // Region
            } else {
                Keyring::setAppUrlRegion("FR");
            }

            if (Arrays::exists('protocol', $config)) {
                Keyring::setAppHost($config['protocol']); // protocol
            } else {
                Keyring::setAppHost(static::$zeliftApiProtocol);
            }

            if (Arrays::exists('host', $config)) {
                Keyring::setAppProtocol($config['host']); // host
            } else {
                Keyring::setAppProtocol(static::$zeliftApiHost);
            }
        }

        private static function getClient()
        {
            if (!static::$zeliftClient instanceof Apiclient) {
                static::$zeliftClient = new Apiclient();
            }

            return static::$zeliftClient;
        }

        /**
         *
         * test Method
         * Return dummy values
         *
         * @return mixed
         */
        public function getTest()
        {
            return json_decode(static::getClient()->getTest());
        }
    }
