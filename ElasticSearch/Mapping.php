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

    namespace ElasticSearch;

    use Thin\Arrays;

    class Mapping
    {
        protected $properties   = [];
        protected $config       = [];

        /**
         * Build mapping data
         *
         * @param array $properties
         * @param array $config
         * @return \ElasticSearch\Mapping
         */
        public function __construct(array $properties = [], array $config = [])
        {
            $this->properties = $properties;
            $this->config = $config;
        }

        /**
         * Export mapping data as a json-ready array
         *
         * @return string
         */
        public function export()
        {
            return array(
                'properties' => $this->properties
            );
        }

        /**
         * Add or overwrite existing field by name
         *
         * @param string $field
         * @param string|array $config
         * @return $this
         */
        public function field($field, $config = [])
        {
            if (is_string($config)) $config = array('type' => $config);

            $this->properties[$field] = $config;

            return $this;
        }

        /**
         * Get or set a config
         *
         * @param string $key
         * @param mixed $value
         * @throws \Exception
         * @return array|void
         */
        public function config($key, $value = null)
        {
            if (Arrays::is($key)) $this->config = $key + $this->config;
            else {
                if ($value !== null) $this->config[$key] = $value;

                if (!isset($this->config[$key])) throw new Exception("Configuration key `type` is not set");

                return $this->config[$key];
            }
        }
    }
