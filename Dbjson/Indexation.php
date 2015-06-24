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
    use Thin\Phonetic;
    use Thin\Database\Collection;

    class Indexation
    {
        private $table, $fields, $model;

        public function __construct($db, $table, $fields)
        {
            /* CLI case */
            if (!defined('APPLICATION_ENV')) {
                define('APPLICATION_ENV', 'production');
            }

            /* polymorphism */
            $fields = !Arrays::is($fields)
            ? strstr($fields, ',')
                ? explode(',', repl(' ', '', $fields))
                : [$fields]
            : $fields;

            $this->table    = $table;
            $this->fields   = $fields;

            $this->model = jdb($db, $this->table);
        }

        public function handle($id, $add = true)
        {
            $row = jdb($this->model->db, $this->model->table)->find($id, false);

            if ($row && true === $add) {
                $pipe = $this->model->cache()->pipeline();

                foreach ($this->fields as $field) {
                    $string = isAke($row, $field, false);

                    if (false !== $string) {
                        $keys = $this->keys($string);

                        if (count($keys)) {
                            foreach ($keys as $value) {
                                $pipe->hset('indexes::' . $this->model->db . '_' . APPLICATION_ENV . '::' . $this->model->table . '::' . $value, $id . '::' . $field, 1);
                            }
                        }
                    }
                }

                $pipe->execute();
            }

            if ($row && false === $add) {
                foreach ($this->fields as $field) {
                    $rows = $this->model->cache()->keys('indexes::' . $this->model->db . '_' . APPLICATION_ENV . '::' . $this->model->table . '::*');

                    if (count($rows)) {
                        foreach ($rows as $row) {
                            list($rowDummy, $rowTable, $rowValue) = explode('::', $rows, 3);

                            $subRows = $this->model->cache()->hgetall($row);

                            if (count($subRows)) {
                                foreach ($subRows as $index => $val) {
                                    list($ind, $rowField) = explode('::', $index, 2);

                                    if ($ind == $id && $rowField == $field) {
                                        $this->model->cache()->hdel($row, $index);
                                    }
                                }
                            }
                        }
                    }
                }
            }

            return $this;
        }

        public function search($query, $comparison = 'like')
        {
            $ageChange  = $this->model->cache()->get(
                sha1(
                    jdb(
                        $this->model->db,
                        $this->model->table
                    )->dir
                )
            );

            $keyCache   = 'cache::index::'
            . $this->model->db . '_' . APPLICATION_ENV
            . '::'
            . $this->model->table
            . '::'
            . sha1(serialize($this->fields))
            . '::'
            . sha1($query . $comparison);

            $keyCacheData   = $keyCache . '::data';
            $keyCacheAge    = $keyCache . '::age';

            $age = $this->model->cache()->get($keyCacheAge);

            if (!strlen($age) || $age < $ageChange) {
                $keys = $this->keys($query);

                $collection = [];
                $tuples     = [];

                if (count($keys)) {
                    foreach ($this->fields as $field) {
                        $rows = $this->model->cache()->keys('indexes::' . $this->model->db . '_' . APPLICATION_ENV . '::' . $this->model->table . '::*');

                        if (count($rows)) {
                            foreach ($rows as $row) {
                                list($rowDummy, $rowDb, $rowTable, $rowValue) = explode('::', $row, 3);

                                $subRows = $this->model->cache()->hgetall($row);

                                if (count($subRows)) {
                                    foreach ($subRows as $index => $val) {
                                        list($ind, $rowField) = explode('::', $index, 2);

                                        if ($rowField == $field) {
                                            $dbRow = jdb($this->model->db, $this->model->table)->find($ind, false);

                                            if ($dbRow) {
                                                $compare = isAke($dbRow, $field, false);

                                                if (false !== $compare) {
                                                    foreach ($keys as $compareKey) {
                                                        if ('like' === $comparison) {
                                                            $check = fnmatch("*$compareKey*", $rowValue);
                                                        } elseif ('strict' == $comparison) {
                                                            $check = sha1($compareKey) == sha1($rowValue);
                                                        } elseif ('phonetic' == $comparison) {
                                                            $phonetic   = Phonetic::instance();
                                                            $similarity = $phonetic->similarity($rowValue, $compareKey);

                                                            $check = $similarity <= $phonetic->getTolerance();
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

                $this->model->cache()->set($keyCacheData, serialize($collection));
                $this->model->cache()->set($keyCacheAge, time());
            } else {
                $collection = unserialize($this->model->cache()->get($keyCacheData));
            }

            return new Collection($collection);
        }

        private function keys($str)
        {
            return Inflector::makeIndexes($str);
        }
    }
