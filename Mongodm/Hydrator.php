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

    namespace Mongodm;

    class Hydrator
    {

        public static function hydrate($class, $results, $type = "collection" , $exists = false)
        {

            if (!class_exists($class)) {
                throw new \Exception("class {$class} not exists!");
            } elseif ($type == "collection") {
                $models = array();
                foreach ($results as $result) {
                    $model = self::pack($class, $result , $exists);
                    $models[] = $model;
                }

                return Collection::make($models);
            } else {
                $model = self::pack($class, $results , $exists);

                return $model;
            }

        }

        /**
         * Pack record to a Mongodm instance
         *
         * @param string $class  class
         * @param array  $result result
         * @param bool   $exists
         *
         * @static
         *
         * @return object type
         */
        protected static function pack($class, $result, $exists = false)
        {
            $model = new $class($result, true , $exists);

            return $model;
        }

    }
