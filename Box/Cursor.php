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

    namespace Box;

    use Thin\Inflector;

    class Cursor implements \Countable, \IteratorAggregate
    {
        private $age, $count, $store, $db, $wheres, $cursor, $orders, $selects, $offset, $limit, $position = 0;

        public function __construct(Db $db)
        {
            $this->db       = $db;
            $this->wheres   = $db->wheres;
            $this->orders   = $db->orders;
            $this->selects  = $db->selects;
            $this->offset   = $db->offset;
            $this->limit    = $db->limit;
            $this->store    = $db->store;

            $this->age = $db->getAge();

            unset($this->count);

            $this->cursor();
        }

        public function __destruct()
        {
            $this->reset();
        }

        public function count($return = false)
        {
            if (!isset($this->count) || is_null($this->count)) {
                $this->count = count($this->cursor);
            }

            return $this->count;
        }

        private function getRow($id)
        {
            return $this->store->hget('data', $id);
        }

        public function getNext()
        {
            if (isset($this->cursor[$this->position])) {
                $row = $this->getRow($this->cursor[$this->position]);

                $this->position++;

                return $row;
            }

            return false;
        }

        public function current()
        {
            if (isset($this->cursor[$this->position])) {
                return $this->getRow($this->cursor[$this->position]);
            }

            return false;
        }

        public function getIterator()
        {
            return $this->cursor;
        }

        public function groupBy($field)
        {
            $collection = [];

            while ($row = $this->fetch()) {
                $id     = isAke($row, 'id');
                $val    = isAke($row, $field);

                if ($id && $val) {
                    $add    = ['id' => $id, $field => $val];
                    $collection[] = $add;
                }
            }

            $results = [];

            foreach ($collection as $row) {
                $results[$row['id']] = $row[$field];
            }

            $results = array_unique($results);

            asort($results);

            return array_values($results);
        }

        public function sum($field)
        {
            $collection = [];

            while ($row = $this->fetch()) {
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

            while ($row = $this->fetch()) {
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

            while ($row = $this->fetch()) {
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

            while ($row = $this->fetch()) {
                $id     = isAke($row, 'id');
                $val    = isAke($row, $field);

                $add    = ['id' => $id, $field => $val];

                $collection[] = $add;
            }

            $collection = lib('collection', [$collection]);

            return $collection->max($field);
        }

        public function sortCursor($field, $direction = 'ASC')
        {
            $collection = [];

            while ($row = $this->fetch()) {
                $id     = isAke($row, 'id');
                $val    = isAke($row, $field);

                $add    = ['id' => $id, $field => $val];

                $collection[] = $add;
            }

            $collection = lib('collection', [$collection]);

            if ($direction == 'ASC') {
                $collection = $collection->sortBy($field);
            } else {
                $collection = $collection->sortByDesc($field);
            }

            $ids = [];

            foreach ($collection as $row) {
                $ids[] = $row['id'];
            }

            return $ids;
        }

        public function toArray()
        {
            return array_map(function ($row) {
                return $this->getRow($row);
            }, $this->cursor);
        }

        public function toJson()
        {
            return json_encode($this->toArray());
        }

        public function fetch($object = false)
        {
            $row = $this->getNext();

            if ($row) {
                return $object ? $this->db->model($row) : $row;
            }

            $this->reset();

            return false;
        }

        public function model()
        {
            $row = $this->getNext();

             if ($row) {
                $id = isAke($row, 'id', false);

                return false !== $id ? $this->db->model($row) : false;
            }

            $this->reset();

            return false;
        }

        public function first($object = false)
        {
            $this->position = 0;

            if (isset($this->cursor[$this->position])) {
                $row = $this->getRow($this->cursor[$this->position]);

                $id = isAke($row, 'id', false);

                if (!$id) {
                    return null;
                }

                $this->reset();

                return $object ? $this->db->model($row) : $row;
            }

            return null;
        }

        public function last($object = false)
        {
            $this->position = $this->count - 1;

            if (isset($this->cursor[$this->position])) {
                $row = $this->getRow($this->cursor[$this->position]);

                $id = isAke($row, 'id', false);

                if (!$id) {
                    return null;
                }

                $this->reset();

                return $object ? $this->db->model($row) : $row;
            }

            return null;
        }

        public function cursor()
        {
            if (!isset($this->cursor)) {
                $ids = $this->store->hkeys('ids');

                if (!empty($this->wheres)) {
                    $this->cursor = $this->whereFactor($ids);
                } else {
                    $this->cursor = $ids;
                }

                if (!empty($this->orders)) {
                    foreach ($this->orders as $sortField => $sortDirection) {
                        $this->cursor = $this->sortCursor($sortField, $sortDirection);
                    }
                }

                if (isset($this->limit)) {
                    $offset = 0;

                    if (isset($this->offset)) {
                        $offset = $this->offset;
                    }

                    $this->cursor = array_slice($this->cursor, $offset, $this->limit);
                }
            }

            $this->count();
        }

        public function rand()
        {
            shuffle($this->cursor);

            return $this;
        }

        private function whereFactor($ids)
        {
            $first = true;

            foreach ($this->wheres as $where) {
                $condition  = current($where);
                $op         = end($where);

                $whereCursor = [];

                list($field, $operator, $value) = $condition;

                $whereCursor = $this->makeFieldValues($field, $ids, $operator, $value);

                if (!$first) {
                    if ($op == '&&' || $op == 'AND') {
                        $cursor = array_intersect($cursor, $whereCursor);
                    } elseif ($op == '||' || $op == 'OR') {
                        $cursor = array_merge($cursor, $whereCursor);
                    }
                } else {
                    $first = false;
                    $cursor = $whereCursor;
                }
            }

            return $cursor;
        }

        private function compare($comp, $op, $value)
        {
            $res = false;

            if (strlen($comp) && strlen($op) && strlen($value)) {
                $comp   = Inflector::lower(Inflector::unaccent($comp));
                $value  = Inflector::lower(Inflector::unaccent($value));

                if (is_numeric($comp)) {
                    if (fnmatch('*,*', $comp) || fnmatch('*.*', $comp)) {
                        $comp = floatval($comp);
                    } else {
                        $comp = intval($comp);
                    }
                }

                if (is_numeric($value)) {
                    if (fnmatch('*,*', $value) || fnmatch('*.*', $value)) {
                        $value = floatval($value);
                    } else {
                        $value = intval($value);
                    }
                }

                switch ($op) {
                    case '=':
                        $res = sha1($comp) == sha1($value);
                        break;

                    case '>=':
                        $res = $comp >= $value;
                        break;

                    case '>':
                        $res = $comp > $value;
                        break;

                    case '<':
                        $res = $comp < $value;
                        break;

                    case '<=':
                        $res = $comp <= $value;
                        break;

                    case '<>':
                    case '!=':
                        $res = sha1($comp) != sha1($value);
                        break;

                    case 'LIKE':
                        $value  = str_replace("'", '', $value);
                        $value  = str_replace('%', '*', $value);

                        $res    = fnmatch($value, $comp);

                        break;

                    case 'NOTLIKE':
                        $value  = str_replace("'", '', $value);
                        $value  = str_replace('%', '*', $value);

                        $check  = fnmatch($value, $comp);

                        $res    = !$check;

                        break;

                    case 'LIKESTART':
                        $value  = str_replace("'", '', $value);
                        $value  = str_replace('%', '', $value);
                        $res    = (substr($comp, 0, strlen($value)) === $value);

                        break;

                    case 'LIKEEND':
                        $value = str_replace("'", '', $value);
                        $value = str_replace('%', '', $value);

                        if (!strlen($comp)) {
                            $res = true;
                        }

                        $res = (substr($comp, -strlen($value)) === $value);

                        break;

                    case 'IN':
                        $value      = str_replace('(', '', $value);
                        $value      = str_replace(')', '', $value);
                        $tabValues  = explode(',', $value);
                        $res        = in_array($comp, $tabValues);

                        break;

                    case 'NOTIN':
                        $value      = str_replace('(', '', $value);
                        $value      = str_replace(')', '', $value);
                        $tabValues  = explode(',', $value);
                        $res        = !in_array($comp, $tabValues);

                        break;
                }
            }

            return $res;
        }

        private function makeFieldValues($field, $ids, $operator, $value)
        {
            $key = 'query.' . sha1($field . $operator . $value) . '.' . $this->age;

            $values = $this->store->get($key);

            if (!$values) {
                $values = [];

                foreach ($ids as $id) {
                    $val = $this->store->hget('row.' . $id, $field);

                    $check = $this->compare($val, $operator, $value);

                    if ($check) {
                        $values[] = $id;
                    }
                }

                $this->store->set($key, serialize($values));
            } else {
                $values = unserialize($values);
            }

            return $values;
        }

        public function __call($m, $a)
        {
            return call_user_func_array([$this->db, $m], $a);
        }
    }
