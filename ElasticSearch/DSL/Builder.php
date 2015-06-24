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

    use Thin\Arrays;

    class Builder
    {
        protected $dsl      = [];

        private $explain    = null;
        private $from       = null;
        private $size       = null;
        private $fields     = null;
        private $query      = null;
        private $facets     = null;
        private $sort       = null;

        /**
         * Construct DSL object
         *
         * @return \ElasticSearch\DSL\Builder
         * @param array $options
         */
        public function __construct(array $options = [])
        {
            foreach ($options as $key => $value) $this->$key = $value;
        }

        /**
         * Add array clause, can only be one
         *
         * @return \ElasticSearch\DSL\Query
         * @param array $options
         */
        public function query(array $options = [])
        {
            if (!($this->query instanceof Query)) $this->query = new Query($options);

            return $this->query;
        }

        /**
         * Build the DSL as array
         *
         * @throws \ElasticSearch\Exception
         * @return array
         */
        public function build()
        {
            $built = [];

            if ($this->from != null) $built['from'] = $this->from;

            if ($this->size != null) $built['size'] = $this->size;

            if ($this->sort && Arrays::is($this->sort)) $built['sort'] = $this->sort;

            if (!$this->query) throw new \ElasticSearch\Exception("Query must be specified");
            else $built['query'] = $this->query->build();

            return $built;
        }
    }
