<?php
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
