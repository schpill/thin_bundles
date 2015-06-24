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

    use Thin\Inflector;
    use Thin\Arrays;
    use Thin\Database\Collection;

    class Indexation
    {
        private $table, $fields;

        public function __construct($table, $fields)
        {
            /* polymorphism */
            $fields = !Arrays::is($fields)
            ? strstr($fields, ',')
                ? explode(',', repl(' ', '', $fields))
                : [$fields]
            : $fields;

            $this->table    = $table;
            $this->fields   = $fields;
        }

        public function handle($id, $add = true)
        {

            $row = amodel($this->table)->find($id, false);

            if ($row && true === $add) {
                $pipe = redis()->pipeline();

                foreach ($this->fields as $field) {
                    $string = isAke($row, $field, false);

                    if (false !== $string) {
                        $keys = $this->keys($string);

                        if (count($keys)) {
                            foreach ($keys as $value) {
                                $pipe->hset('indexes::array::' . $this->table . '::' . $value, $id . '::' . $field, 1);
                            }
                        }
                    }
                }

                $pipe->execute();
            }

            if ($row && false === $add) {
                foreach ($this->fields as $field) {
                    $rows = redis()->keys('indexes::array::' . $this->table . '::*');

                    if (count($rows)) {
                        foreach ($rows as $row) {
                            list($rowDummy, $typeDummy, $rowTable, $rowValue) = explode('::', $rows, 3);

                            $subRows = redis()->hgetall($row);

                            if (count($subRows)) {
                                foreach ($subRows as $index => $val) {
                                    list($ind, $rowField) = explode('::', $index, 2);

                                    if ($ind == $id && $rowField == $field) {
                                        redis()->hdel($row, $index);
                                    }
                                }
                            }
                        }
                    }
                }
            }

            return $this;
        }

        public function search($query, $strict = false)
        {
            $ageChange  = redis()->get(sha1(amodel($this->table)->dir));
            $keyCache   = 'cache::index::array::' . $this->table . '::' . sha1(serialize($this->fields)) . '::' . sha1($query);

            $keyCacheData   = $keyCache . '::data';
            $keyCacheAge    = $keyCache . '::age';

            $age = redis()->get($keyCacheAge);

            if (!strlen($age) || $age < $ageChange) {
                $keys = $this->keys($query);

                $collection = [];
                $tuples     = [];

                if (count($keys)) {
                    foreach ($this->fields as $field) {
                        $rows = redis()->keys('indexes::' . $this->table . '::*');

                        if (count($rows)) {
                            foreach ($rows as $row) {
                                list($rowDummy, $typeDummy, $rowTable, $rowValue) = explode('::', $row, 3);

                                $subRows = redis()->hgetall($row);

                                if (count($subRows)) {
                                    foreach ($subRows as $index => $val) {
                                        list($ind, $rowField) = explode('::', $index, 2);

                                        if ($rowField == $field) {
                                            $dbRow = amodel($this->table)->find($ind, false);

                                            if ($dbRow) {
                                                $compare = isAke($dbRow, $field, false);

                                                if (false !== $compare) {

                                                    foreach ($keys as $compareKey) {
                                                        if (false === $strict) {
                                                            $check = strstr($rowValue, $compareKey) ? true : false;
                                                        } else {
                                                            $check = sha1($compareKey) == sha1($rowValue);
                                                        }

                                                        if (true === $check && !Arrays::in($ind, $tuples)) {
                                                            array_push($collection, $dbRow);
                                                            array_push($tuples, $ind);
                                                            break;
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }

                redis()->set($keyCacheData, serialize($collection));
                redis()->set($keyCacheAge, time());
            } else {
                $collection = unserialize(redis()->get($keyCacheData));
            }

            return new Collection($collection);
        }

        private function keys($str)
        {
            return Inflector::makeIndexes($str);
        }
    }
