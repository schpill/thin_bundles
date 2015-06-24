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

    class Stringify
    {
        protected $dsl = array();

        public function __construct(array $dsl)
        {
            $this->dsl = $dsl;
        }

        public function __toString()
        {
            $dsl    = $this->dsl;
            $query  = $dsl['query'];

            $string = "";

            if (Arrays::exists("term", $query)) $string .= $this->transformDSLTermToString($query['term']);

            if (Arrays::exists("wildcard", $query)) $string .= $this->transformDSLTermToString($query['wildcard']);

            if (Arrays::exists("sort", $dsl)) $string .= $this->transformDSLSortToString($dsl['sort']);

            if (Arrays::exists("fields", $dsl)) $string .= $this->transformDSLFieldsToString($dsl['fields']);

            return $string;
        }

        /**
         * A naive transformation of possible term and wildcard arrays in a DSL
         * query
         *
         * @return string
         * @param mixed $dslTerm
         */
        protected function transformDSLTermToString($dslTerm)
        {
            $string = "";

            if (Arrays::is($dslTerm)) {
                $key = key($dslTerm);
                $value = $dslTerm[$key];

                if (is_string($key)) $string .= "$key:";
            } else $value = $dslTerm;
            /**
             * If a specific key is used as key in the array
             * this should translate to searching in a specific field (field:term)
             */
            if (strpos($value, " ") !== false) $string .= '"' . $value . '"';
            else $string .= $value;

            return $string;
        }

        /**
         * Transform search parameters to string
         *
         * @return string
         * @param mixed $dslSort
         */
        protected function transformDSLSortToString($dslSort)
        {
            $string = "";

            if (Arrays::is($dslSort)) {
                foreach ($dslSort as $sort) {
                    if (Arrays::is($sort)) {
                        $field = key($sort);
                        $info = Arrays::first($sort);
                    } else {
                        $field = $sort;
                    }

                    $string .= "&sort=" . $field;

                    if (isset($info)) {
                        if (is_string($info) && $info == "desc") {
                            $string .= ":reverse";
                        } elseif (Arrays::is($info) && Arrays::exists("reverse", $info) && $info['reverse']) {
                            $string .= ":reverse";
                        }
                    }
                }
            }

            return $string;
        }

        /**
         * Transform a selection of fields to return to string form
         *
         * @return string
         * @param mixed $dslFields
         */
        protected function transformDSLFieldsToString($dslFields)
        {
            $string = "";

            if (Arrays::is($dslFields)) $string .= "&fields=" . join(",", $dslFields);

            return $string;
        }
    }
