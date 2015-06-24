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
    use ElasticSearch\Exception;

    class RangeQuery
    {
        protected $fieldname    = null;
        protected $from         = null;
        protected $to           = null;
        protected $includeLower = null;
        protected $includeUpper = null;
        protected $boost        = null;

        /**
         * Construct new RangeQuery component
         *
         * @return \ElasticSearch\DSL\RangeQuery
         * @param array $options
         */
        public function __construct(array $options = [])
        {
            $this->fieldname = key($options);
            $values = Arrays::first($options);

            if (Arrays::is($values)) {
                foreach ($values as $key => $val) $this->$key = $val;
            }
        }

        /**
         * Setters
         *
         * @return \ElasticSearch\DSL\RangeQuery
         * @param mixed $value
         */
        public function fieldname($value)
        {
            $this->fieldname = $value;

            return $this;
        }

        /**
         * @param $value
         * @return \ElasticSearch\DSL\RangeQuery $this
         */
        public function from($value)
        {
            $this->from = $value;

            return $this;
        }

        /**
         * @param $value
         * @return \ElasticSearch\DSL\RangeQuery $this
         */
        public function to($value)
        {
            $this->to = $value;

            return $this;
        }

        /**
         * @param $value
         * @return \ElasticSearch\DSL\RangeQuery $this
         */
        public function includeLower($value)
        {
            $this->includeLower = $value;

            return $this;
        }

        /**
         * @param $value
         * @return \ElasticSearch\DSL\RangeQuery $this
         */
        public function includeUpper($value)
        {
            $this->includeUpper = $value;

            return $this;
        }

        /**
         * @param $value
         * @return \ElasticSearch\DSL\RangeQuery $this
         */
        public function boost($value)
        {
            $this->boost = $value;

            return $this;
        }

        /**
         * Build to array
         *
         * @throws \ElasticSearch\Exception
         * @return array
         */
        public function build()
        {
            $built = [];

            if ($this->fieldname) {
                $built[$this->fieldname] = [];

                foreach (array("from","to","includeLower","includeUpper", "boost") as $opt) {
                    if ($this->$opt !== null) $built[$this->fieldname][$opt] = $this->$opt;
                }

                if (count($built[$this->fieldname]) == 0) throw new Exception("Empty RangeQuery cant be created");
            }

            return $built;
        }
    }
