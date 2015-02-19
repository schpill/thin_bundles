<?php
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
