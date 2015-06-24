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

    namespace Dbredis;

    class Repository
    {
        public $_db;
        private $_events = [];
        private $_data = [];

        public function __construct(Db $db)
        {
            $this->_db = $db;
        }

        public function event($name, Closure $cb)
        {
            $this->_events[$name] = $cb;
        }

        public function __set($key, $value)
        {
            $this->_data[$key] = $value;
        }

        public function __get($key)
        {
            return isAke($this->_data, $key, null);
        }

        public function __isset($key)
        {
            $check = Utils::token();

            return $check != isake($this->_data, $key, $check);
        }

        public function __unset($key)
        {
            unset($this->_data[$key]);
        }
    }
