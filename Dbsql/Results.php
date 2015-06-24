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

    namespace Dbsql;

    class Results implements \Iterator, \Countable
    {
        private $db, $count, $wheres, $position, $results, $ids = [];

        public function __construct(Db $db)
        {
            $this->db = $db;
            $this->wheres = $db->wheres;
            $this->results = $db->results;
            $this->position = 0;
            $this->take(0);
        }

        public function rewind()
        {
            $this->position = 0;
        }

        public function current()
        {
            return $this->take($this->position);
        }

        public function key()
        {
            return $this->position;
        }

        public function next()
        {
            ++$this->position;
        }

        public function previous()
        {
            --$this->position;
        }

        public function valid()
        {
            return $this->count >= ($this->position + 1);
        }

        public function count()
        {
            return $this->count;
        }

        public function sort($field, $direction = 'ASC')
        {
            $collection = [];

            while ($this->valid()) {
                $row    = $this->current();

                $this->next();

                $id     = isAke($row, 'id');
                $val    = isAke($row, $field);

                $add    = ['id' => $id, $field => $val];

                $collection[] = $add;
            }

            $collection = lib('collection', [$collection]);

            if ($direction == 'ASC') {
                $collection->sortBy($field);
            } else {
                $collection->sortByDesc($field);
            }

            $tab = $collection->toArray();

            $this->results = [];

            foreach ($tab as $row) {
                $this->results[$row['id']] = $row[$field];
            }

            $this->rewind();

            return $this;
        }

        public function groupBy($field)
        {
            $collection = [];

            while ($this->valid()) {
                $row    = $this->current();

                $this->next();

                $id     = isAke($row, 'id');
                $val    = isAke($row, $field);

                $add    = ['id' => $id, $field => $val];

                $collection[] = $add;
            }

            $collection = lib('collection', [$collection]);

            $collection->groupBy($field);

            $tab = $collection->toArray();

            $this->results = [];

            foreach ($tab as $row) {
                $this->results[$row['id']] = $row[$field];
            }

            $this->rewind();

            return $this;
        }

        public function sum($field)
        {
            $collection = [];

            while ($this->valid()) {
                $row    = $this->current();

                $this->next();

                $id     = isAke($row, 'id');
                $val    = isAke($row, $field);

                $add    = ['id' => $id, $field => $val];

                $collection[] = $add;
            }

            $collection = lib('collection', [$collection]);

            return $collection->sum($field);
        }

        public function avg($field)
        {
            $collection = [];

            while ($this->valid()) {
                $row    = $this->current();

                $this->next();

                $id     = isAke($row, 'id');
                $val    = isAke($row, $field);

                $add    = ['id' => $id, $field => $val];

                $collection[] = $add;
            }

            $collection = lib('collection', [$collection]);

            return $collection->avg($field);
        }

        public function min($field)
        {
            $collection = [];

            while ($this->valid()) {
                $row    = $this->current();

                $this->next();

                $id     = isAke($row, 'id');
                $val    = isAke($row, $field);

                $add    = ['id' => $id, $field => $val];

                $collection[] = $add;
            }

            $collection = lib('collection', [$collection]);

            return $collection->min($field);
        }

        public function max($field)
        {
            $collection = [];

            while ($this->valid()) {
                $row    = $this->current();

                $this->next();

                $id     = isAke($row, 'id');
                $val    = isAke($row, $field);

                $add    = ['id' => $id, $field => $val];

                $collection[] = $add;
            }

            $collection = lib('collection', [$collection]);

            return $collection->max($field);
        }

        public function fetch($object = false)
        {
            $row = $this->current();

            $this->next();

            $id = isAke($row, 'id', false);

            if (!$id) {
                return false;
            }

            return $object ? $this->db->model($row) : $row;
        }

        public function model()
        {
            $row = $this->current();

            $this->next();

            $id = isAke($row, 'id', false);

            return false !== $id ? $this->db->model($row) : false;
        }

        public function first($object = false)
        {
            $row = $this->current();

            $this->rewind();

            $id = isAke($row, 'id', false);

            if (!$id) {
                return null;
            }

            return $object ? $this->db->model($row) : $row;
        }

        public function take($offset)
        {
            if (empty($this->wheres)) {
                $this->results = [];

                $ids = empty($this->ids) ? $this->ids = $this->motor()->ids('datas') : $this->ids;

                foreach ($ids as $id) {
                    $this->results[$id] = [];
                }

                unset($ids);
            }

            if (!isset($this->count)) {
                $this->count = count($this->results);
            }

            $return = array_slice(array_keys($this->results), $offset, 1);

            $this->reset();

            return $this->db->motor()->read('datas.' . current($return));
        }

        public function limit($limit, $offset = 0)
        {
            $rows = array_slice(array_keys($this->results), $offset, $limit);

            $this->reset();

            $this->results = [];

            foreach ($rows as $id) {
                $this->results[$id] = true;
            }

            return $this;
        }

        public function __call($m, $a)
        {
            return call_user_func_array([$this->db, $m], $a);
        }
    }
