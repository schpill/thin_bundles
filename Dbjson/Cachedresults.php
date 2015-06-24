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

    namespace Dbjson;

    class Cachedresults
    {
        private $results;

        public function __construct($results)
        {
            $this->results = unserialize($results);
        }

        public function exec()
        {
            return $this->results;
        }

        public function execute()
        {
            return $this->results;
        }

        public function count()
        {
            return count($this->results);
        }

        public function __call($meth, $args)
        {
            return $this;
        }
    }
