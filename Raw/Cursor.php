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

    namespace Raw;

    use Thin\Inflector;

    class Cursor implements \Countable, \Iterator
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

        public function getFieldValueById($field, $id)
        {
            return $this->store->hget('row.' . $id, $field);
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

        public function getPrev()
        {
            $this->position--;

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
                if (!empty($this->selects)) {
                    $row = [];
                    $row['id'] = $this->cursor[$this->position];

                    foreach ($this->selects as $field) {
                        $row[$field] = $this->store->hget('row.' . $this->cursor[$this->position], $field);
                    }

                    return $row;
                } else {
                    return $this->getRow($this->cursor[$this->position]);
                }
            }

            return false;
        }

        public function getIterator()
        {
            return $this->cursor;
        }

        private function setCached($key, $value)
        {
            $this->store->set($key . '_' . $this->age, $value);
        }

        private function cached($key)
        {
            $cached =  $this->store->get($key . '_' . $this->age);

            if ($cached) {
                return $cached;
            }

            return null;
        }

        public function groupBy($field)
        {
            $collection = $this->cached('groupby' . $field . '.' . sha1(serialize($this->wheres)));

            if (!$collection) {
                $collection = [];

                foreach ($this->cursor as $id) {
                    $val = $this->store->hget('row.' . $id, $field);

                    if ($val) {
                        $add    = ['id' => $id, $field => $val];
                        $collection[] = $add;
                    }
                }

                $collection = with(new Collection($collection))->groupBy($field)->toArray();

                $this->setCached('groupby' . $field . '.' . sha1(serialize($this->wheres)), $collection);
            }

            return $collection;
        }

        public function sum($field)
        {
            $sum = $this->cached('sum' . $field . '.' . sha1(serialize($this->wheres)));

            if (!$sum) {
                $collection = [];

                foreach ($this->cursor as $id) {
                    $val = $this->store->hget('row.' . $id, $field);

                    if ($val) {
                        $add    = ['id' => $id, $field => $val];
                        $collection[] = $add;
                    }
                }

                $collection = new Collection($collection);

                $sum = $collection->sum($field);

                $this->setCached('sum' . $field . '.' . sha1(serialize($this->wheres)), $sum);
            }

            return $sum;
        }

        public function avg($field)
        {
            $avg = $this->cached('avg' . $field . '.' . sha1(serialize($this->wheres)));

            if (!$avg) {
                $collection = [];

                foreach ($this->cursor as $id) {
                    $val = $this->store->hget('row.' . $id, $field);

                    if ($val) {
                        $add    = ['id' => $id, $field => $val];
                        $collection[] = $add;
                    }
                }

                $collection = new Collection($collection);

                $avg = $collection->avg($field);

                $this->setCached('avg' . $field . '.' . sha1(serialize($this->wheres)), $avg);
            }

            return $avg;
        }

        public function min($field)
        {
            $min = $this->cached('min' . $field . '.' . sha1(serialize($this->wheres)));

            if (!$min) {
                $collection = [];

                foreach ($this->cursor as $id) {
                    $val = $this->store->hget('row.' . $id, $field);

                    if ($val) {
                        $add    = ['id' => $id, $field => $val];
                        $collection[] = $add;
                    }
                }

                $collection = new Collection($collection);

                $min = $collection->min($field);

                $this->setCached('min' . $field . '.' . sha1(serialize($this->wheres)), $min);
            }

            return $min;
        }

        public function max($field)
        {
            $max = $this->cached('max' . $field . '.' . sha1(serialize($this->wheres)));

            if (!$max) {
                $collection = [];

                foreach ($this->cursor as $id) {
                    $val = $this->store->hget('row.' . $id, $field);

                    if ($val) {
                        $add    = ['id' => $id, $field => $val];
                        $collection[] = $add;
                    }
                }

                $collection = new Collection($collection);

                $max = $collection->max($field);

                $this->setCached('max' . $field . '.' . sha1(serialize($this->wheres)), $max);
            }

            return $max;
        }

        public function sortCursor($field, $direction = 'ASC')
        {
            $ids = $this->cached('sort' . $field . '.' . $direction . '.' . sha1(serialize($this->wheres)));

            if (!$ids) {
                $collection = [];

                foreach ($this->cursor as $id) {
                    $val = $this->store->hget('row.' . $id, $field);

                    if ($val) {
                        $add    = ['id' => $id, $field => $val];
                        $collection[] = $add;
                    }
                }

                $collection = new Collection($collection);

                if ($direction == 'ASC') {
                    $collection = $collection->sortBy($field);
                } else {
                    $collection = $collection->sortByDesc($field);
                }

                $ids = [];

                foreach ($collection as $row) {
                    $ids[] = $row['id'];
                }

                $this->setCached('sort' . $field . '.' . $direction . '.' . sha1(serialize($this->wheres)), $ids);
            }

            return $ids;
        }
        
        public function multisort() 
        {
            $args       = func_get_args();
            $collection = clone $this;
            $array      = $collection->data;
            $params     = [];

            if (empty($array)) {
                return $collection;
            }

            foreach ($args as $i => $param) {
                if (is_string($param)) {
                    if (strtolower($param) === 'desc') {
                        ${"param_$i"} = SORT_DESC;
                    } else if (strtolower($param) === 'asc') {
                        ${"param_$i"} = SORT_ASC;
                    } else {
                        ${"param_$i"} = [];
                        
                        foreach($array as $index => $row) {
                            ${"param_$i"}[$index] = is_array($row) ? Inflector::lower($row[$param]) : Inflector::lower($row->$param());
                        }
                    }
                } else {
                    ${"param_$i"} = $args[$i];
                }
                
                $params[] = &${"param_$i"};
            }

            $params[] = &$array;

            call_user_func_array('array_multisort', $params);

            $collection->data = $array;

            return $collection;
        }

        public function toArray()
        {
            if (empty($this->selects)) {
                return array_map(function ($row) {
                    return $this->getRow($row);
                }, $this->cursor);
            } else {
                $fields = $this->selects;

                return array_map(function ($id) use ($fields) {
                    $row = [];
                    $row['id'] = $id;

                    foreach ($fields as $field) {
                        $row[$field] = $this->store->hget('row.' . $id, $field);
                    }

                    return $row;
                }, $this->cursor);
            }
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
                $key = 'ids.' . $this->age;
                $ids = $this->store->get($key);

                if (!$ids) {
                    $ids = $this->store->hkeys('ids');
                    $this->store->set($key, $ids);
                }

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

        public function rand($amount = 1)
        {
            $collection = new Collection($this->cursor);

            $this->cursor = $collection->random($amount)->toArray();

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

        public function rewind()
        {
            $this->position = 0;
        }

        public function key()
        {
            return $this->position;
        }

        public function next()
        {
            ++$this->position;
        }

        public function valid()
        {
            return isset($this->cursor[$this->position]);
        }

        public function update(array $data)
        {
            foreach ($this->cursor as $id) {
                $row = $this->db->model($this->getRow($id));

                foreach ($data as $k => $v) {
                    $row->$k = $v;
                }

                $row->save();
            }

            return $this;
        }

        public function delete()
        {
            foreach ($this->cursor as $id) {
                $row = $this->db->model($this->getRow($id));

                $row->delete();
            }

            return $this;
        }
    }
