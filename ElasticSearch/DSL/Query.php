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

    namespace ElasticSearch\DSL;

    class Query
    {
        protected $range;
        protected $term             = null;
        protected $prefix           = null;
        protected $wildcard         = null;
        protected $matchAll         = null;
        protected $queryString      = null;
        protected $bool             = null;
        protected $disMax           = null;
        protected $constantScore    = null;
        protected $filteredQuery    = null;

        public function __construct(array $options = []) {}

        /**
         * Add a term to this query
         *
         * @return \ElasticSearch\DSL\Query
         * @param string $term
         * @param bool|string $field
         */
        public function term($term, $field = false)
        {
            $this->term = ($field)
                ? array($field => $term)
                : $term;

            return $this;
        }

        /**
         * Add a wildcard to this query
         *
         * @return \ElasticSearch\DSL\Query
         * @param $val
         * @param bool|string $field
         */
        public function wildcard($val, $field = false)
        {
            $this->wildcard = ($field)
                ? array($field => $val)
                : $val;

            return $this;
        }

        /**
         * Add a range query
         *
         * @return \ElasticSearch\DSL\RangeQuery
         * @param array $options
         */
        public function range(array $options = [])
        {
            $this->range = new RangeQuery($options);

            return $this->range;
        }

        /**
         * Build the DSL as array
         *
         * @return array
         */
        public function build()
        {
            $built = [];

            if ($this->term) $built['term'] = $this->term;
            elseif ($this->range) $built['range'] = $this->range->build();
            elseif ($this->wildcard) $built['wildcard'] = $this->wildcard;

            return $built;
        }
    }
