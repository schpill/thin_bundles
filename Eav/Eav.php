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

    namespace EavBundle;

    use Closure;
    use Thin\Instance;
    use Thin\Exception;
    use Thin\File;
    use Thin\Arrays;
    use Thin\Inflector;
    use Thin\Route;
    use Thin\Container;
    use Thin\Database\Collection;

    class Eav
    {
        private $db, $table, $timestamps, $results;
        private $wheres = array();
        public static $config = array();

        public function __construct($db, $table, $timestamps = true)
        {
            $this->db = $db;
            $this->table = $table;
            $this->timestamps = $timestamps;
        }

        public static function instance($db, $table, $timestamps = true)
        {
            $key    = sha1(serialize(func_get_args()));
            $has    = Instance::has('Eav', $key);
            if (true === $has) {
                return Instance::get('Eav', $key);
            } else {
                return Instance::make('Eav', $key, with(new self($db, $table, $timestamps)));
            }
        }

        public function save($data, $object = false)
        {
            if (is_object($data) && $data instanceof Container) {
                $data = $data->assoc();
            }

            $id = isAke($data, 'id', null);

            if (strlen($id)) {
                return $this->edit($id, $data, $object);
            } else {
                return $this->add($data, $object);
            }
        }

        private function add($data, $object = false)
        {
            if (!Arrays::is($data)) {
                return $data;
            }

            if (true === $this->timestamps) {
                $data['created_at'] = $data['updated_at'] = time();
            }

            $db = container()->redis();

            $id = $db->incr("eav::ids::$this->db::$this->table");

            foreach ($data as $field => $value) {
                $key = "eav::rows::$this->db::$this->table::$field::$id";
                $db->set($key, json_encode($value));
            }

            return $this->row($id, $object);
        }

        private function edit($id, $data, $object = false)
        {
            if (!Arrays::is($data)) {
                return $data;
            }

            if (true === $this->timestamps) {
                $data['updated_at'] = time();
            }

            $db = container()->redis();

            foreach ($data as $field => $value) {
                $key = "eav::rows::$this->db::$this->table::$field::$id";
                $db->set($key, json_encode($value));
            }

            return $this->row($id, $object);
        }

        public function deleteRow($id)
        {
            $db     = container()->redis();
            $rows   = $db->keys("eav::rows::$this->db::$this->table::*::$id");
            if (count($rows)) {
                foreach ($rows as $row) {
                    $db->del($row);
                }
            }
            return $this;
        }

        public function delete($where = null)
        {
            if (is_null($where)) {
                return $this->exec(true)->delete();
            } else {
                return $this->where($where)->exec(true)->delete();
            }
        }

        public function allSearch($field)
        {
            $db         = container()->redis();
            $rows       = $db->keys("eav::rows::$this->db::$this->table::$field::*");
            $collection = array();
            if (count($rows)) {
                foreach ($rows as $tmp) {
                    $tab = explode('::', $tmp);
                    $id = Arrays::last($tab);
                    $value = $db->get($tmp);

                    $row = array('id' => $id, $field => $value);
                    array_push($collection, $row);
                }
            }

            return $collection;
        }

        public function all($object = false)
        {
            $db         = container()->redis();
            $rows       = $db->keys("eav::rows::$this->db::$this->table::*");
            $collection = array();
            $ever       = array();
            if (count($rows)) {
                foreach ($rows as $tmp) {
                    $tab = explode('::', $tmp);
                    $id = Arrays::last($tab);
                    if (!Arrays::in($id, $ever)) {
                        array_push($ever, $id);
                        array_push($collection, $this->row($id, $object));
                    }
                }
            }
            return true === $object ? new Collection($collection) : $collection;
        }

        public function fetch($object = false)
        {
            $this->results = $this->all($object);
            return $this;
        }

        public function execute($object = false)
        {
            return $this->exec($object);
        }

        public function exec($object = false)
        {
            $collection = array();

            if (count($this->results)) {
                foreach ($this->results as $row) {
                    $id = isAke($row, 'id', false);
                    if (false !== $id) {
                        $item = true === $object ? $this->makeObject($row) : $row;
                        array_push($collection, $item);
                    }
                }
            }

            $this->reset();
            return true === $object ? new Collection($collection) : $collection;
        }

        public function reset()
        {
            $this->results          = null;
            $this->wheres           = array();
            return $this;
        }

        public function update(array $updates, $where = null)
        {
            $res = !empty($where) ? $this->where($where)->exec() : $this->all();
            if (count($res)) {
                if (count($updates)) {
                    foreach ($updates as $key => $newValue) {
                        foreach ($res as $row) {
                            $val = isAke($row, $field, null);
                            if ($val != $newValue) {
                                $row[$field] = $newValue;
                                $this->edit($row['id'], $row);
                            }
                        }
                    }
                }
            }
            return $this;
        }

        public function flushAll()
        {
            return $this->remove();
        }

        public function remove($where = null)
        {
            $res = !empty($where) ? $this->where($where)->exec() : $this->all();
            if (count($res)) {
                foreach ($res as $row) {
                    $this->deleteRow($row['id']);
                }
            }
            return $this;
        }

        public function groupBy($field, $results = array())
        {
            $res = count($results) ? $results : $this->results;
            $groupBys   = array();
            $ever       = array();
            foreach ($res as $id => $tab) {
                $obj = isAke($tab, $field, null);
                if (!Arrays::in($obj, $ever)) {
                    $groupBys[$id]  = $tab;
                    $ever[]         = $obj;
                }
            }
            $this->results = $groupBys;
            $this->order($field);

            return $this;
        }

        public function limit($limit, $offset = 0, $results = array())
        {
            $res            = count($results) ? $results : $this->results;
            $offset         = count($res) < $offset ? count($res) : $offset;
            $this->results  = array_slice($res, $offset, $limit);
            return $this;
        }

        public function sum($field, $results = array())
        {
            $res = count($results) ? $results : $this->results;
            $sum = 0;

            if (count($res)) {
                foreach ($res as $id => $tab) {
                    $val = isAke($tab, $field, 0);
                    $sum += $val;
                }
            }
            $this->reset();
            return $sum;
        }

        public function avg($field, $results = array())
        {
            return ($this->sum($field, $results) / count($res));
        }

        public function min($field, $results = array())
        {
            $res = count($results) ? $results : $this->results;
            $min = 0;
            if (count($res)) {
                $first = true;
                foreach ($res as $id => $tab) {
                    $val = isAke($tab, $field, 0);
                    if (true === $first) {
                        $min = $val;
                    } else {
                        $min = $val < $min ? $val : $min;
                    }
                    $first = false;
                }
            }
            $this->reset();
            return $min;
        }

        public function max($field, $results = array())
        {
            $res = count($results) ? $results : $this->results;
            $max = 0;
            if (count($res)) {
                $first = true;
                foreach ($res as $id => $tab) {
                    $val = isAke($tab, $field, 0);
                    if (true === $first) {
                        $max = $val;
                    } else {
                        $max = $val > $max ? $val : $max;
                    }
                    $first = false;
                }
            }
            $this->reset();
            return $max;
        }

        public function rand($results = array())
        {
            $res = count($results) ? $results : $this->results;
            shuffle($res);
            $this->results = $res;
            return $this;
        }

        public function order($fieldOrder, $orderDirection = 'ASC', $results = array())
        {
            $res = count($results) ? $results : $this->results;

            if (empty($res)) {
                return $this;
            }

            $sortFunc = function($key, $direction) {
                return function ($a, $b) use ($key, $direction) {
                    if ('ASC' == $direction) {
                        return $a[$key] > $b[$key];
                    } else {
                        return $a[$key] < $b[$key];
                    }
                };
            };

            if (Arrays::is($fieldOrder) && !Arrays::is($orderDirection)) {
                $t = array();
                foreach ($fieldOrder as $tmpField) {
                    array_push($t, $orderDirection);
                }
                $orderDirection = $t;
            }

            if (!Arrays::is($fieldOrder) && Arrays::is($orderDirection)) {
                $orderDirection = Arrays::first($orderDirection);
            }

            if (Arrays::is($fieldOrder) && Arrays::is($orderDirection)) {
                for ($i = 0 ; $i < count($fieldOrder) ; $i++) {
                    usort($res, $sortFunc($fieldOrder[$i], $orderDirection[$i]));
                }
            } else {
                usort($res, $sortFunc($fieldOrder, $orderDirection));
            }

            $this->results = $res;
            return $this;
        }

        public function andWhere($condition, $results = array())
        {
            return $this->where($condition, 'AND', $results);
        }

        public function orWhere($condition, $results = array())
        {
            return $this->where($condition, 'OR', $results);
        }

        public function xorWhere($condition, $results = array())
        {
            return $this->where($condition, 'XOR', $results);
        }

        public function _and($condition, $results = array())
        {
            return $this->where($condition, 'AND', $results);
        }

        public function _or($condition, $results = array())
        {
            return $this->where($condition, 'OR', $results);
        }

        public function _xor($condition, $results = array())
        {
            return $this->where($condition, 'XOR', $results);
        }

        public function whereAnd($condition, $results = array())
        {
            return $this->where($condition, 'AND', $results);
        }

        public function whereOr($condition, $results = array())
        {
            return $this->where($condition, 'OR', $results);
        }

        public function whereXor($condition, $results = array())
        {
            return $this->where($condition, 'XOR', $results);
        }

        public function between($field, $min, $max, $object = false)
        {
            return $this->where($field . ' >= ' . $min)->where($field . ' <= ' . $max)->exec($object);
        }

        public function firstOrCreate($tab = array())
        {
            if (count($tab)) {
                foreach ($tab as $key => $value) {
                    $this->where("$key = $value");
                }
                $first = $this->first(true);
                if (!is_null($first)) {
                    return $first;
                }
            }
            return $this->create($tab);
        }

        public function replace($compare = array(), $update = array())
        {
            $instance = $this->firstOrCreate($compare);
            return $instance->hydrate($update)->save();
        }

        public function create($tab = array())
        {
            if (true === $this->timestamps) {
                $tab['created_at'] = isAke($tab, 'created_at', time());
                $tab['updated_at'] = isAke($tab, 'updated_at', time());
            }
            return $this->makeObject($tab);
        }

        public function makeObject($tab = array())
        {
            $o = new Container;
            $o->populate($tab);
            return $this->closures($o);
        }

        private function closures($obj)
        {
            $db = $this;
            $db->results = null;
            $db->wheres = null;

            $save = function () use ($obj, $db) {
                return $db->save($obj);
            };

            $database = function () use ($db) {
                return $db;
            };

            $delete = function () use ($obj, $db) {
                return $db->deleteRow($obj->id);
            };

            $id = function () use ($obj) {
                return $obj->id;
            };

            $exists = function () use ($obj) {
                return isset($obj->id);
            };

            $touch = function () use ($obj) {
                if (!isset($obj->created_at))  $obj->created_at = time();
                $obj->updated_at = time();
                return $obj;
            };

            $duplicate = function () use ($obj) {
                if (isset($obj->id)) unset($obj->id);
                if (isset($obj->created_at)) unset($obj->created_at);
                return $obj->save();
            };

            $hydrate = function ($data = array()) use ($obj) {
                $data = empty($data) ? $_POST : $data;
                if (Arrays::isAssoc($data)) {
                    foreach ($data as $k => $v) {
                        if ('true' == $v) {
                            $v = true;
                        } elseif ('false' == $v) {
                            $v = false;
                        } elseif ('null' == $v) {
                            $v = null;
                        }
                        $obj->$k = $v;
                    }
                }
                return $obj;
            };

            $date = function ($f) use ($obj) {
                return date('Y-m-d H:i:s', $obj->$f);
            };

            $obj->event('save', $save)
            ->event('delete', $delete)
            ->event('date', $date)
            ->event('exists', $exists)
            ->event('id', $id)
            ->event('db', $database)
            ->event('touch', $touch)
            ->event('hydrate', $hydrate)
            ->event('duplicate', $duplicate);

            $settings   = isAke(self::$config, "$this->db.$this->table");
            $functions  = isAke($settings, 'functions');

            if (count($functions)) {
                foreach ($functions as $closureName => $callable) {
                    $closureName    = lcfirst(Inflector::camelize($closureName));
                    $share          = function () use ($obj, $db) {
                        $args[]     = $obj;
                        $args[]     = $db;
                        return call_user_func_array($callable , $args);
                    };
                    $obj->event($closureName, $share);
                }
            }
            return $this->related($obj);
        }

        private function related(Container $obj)
        {
            $fields = array_keys($obj->assoc());
            foreach ($fields as $field) {
                if (endsWith($field, '_id')) {
                    if (is_string($field)) {
                        $value = $obj->$field;
                        if (!is_callable($value)) {
                            $fk = repl('_id', '', $field);
                            $ns = $this->ns;
                            $cb = function() use ($value, $fk, $ns) {
                                $db = jdb($ns, $fk);
                                return $db->find($value);
                            };
                            $obj->event($fk, $cb);

                            $setter = lcfirst(Inflector::camelize("link_$fk"));

                            $cb = function(Container $fkObject) use ($obj, $field, $fk) {
                                $obj->$field = $fkObject->getId();
                                $newCb = function () use ($fkObject) {
                                    return $fkObject;
                                };
                                $obj->event($fk, $newCb);
                                return $obj;
                            };
                            $obj->event($setter, $cb);
                        }
                    }
                }
            }
            return $obj;
        }

        public function find($id, $object = true)
        {
            $file = $this->dir . DS . $id . '.row';
            $row = File::exists($file) ? fgc($file) : '';
            if (strlen($row)) {
                $tab = json_decode($row, true);
                return $object ? $this->makeObject($tab) : $tab;
            }
            return $object ? null : array();
        }

        public function findOneBy($field, $value, $object = false)
        {
            return $this->findBy($field, $value, true, $object);
        }

        public function findBy($field, $value, $one = false, $object = false)
        {
            $res = $this->search("$field = $value");
            if (count($res) && true === $one) {
                return $object ? $this->makeObject(Arrays::first($res)) : Arrays::first($res);
            }
            if (!count($res) && true === $one && true === $object) {
                return null;
            }
            return $this->exec($object);
        }

        public function first($object = false)
        {
            $res = $this->results;
            $this->reset();
            if (true === $object) {
                return count($res) ? $this->makeObject(Arrays::first($res)) : null;
            } else {
                return count($res) ? Arrays::first($res) : array();
            }
        }

        public function only($field)
        {
            $row = $this->first(true);
            return $row instanceof Container ? $row->$field : null;
        }

        public function select($fields, $object = false)
        {
            $collection = array();
            $fields = Arrays::is($fields) ? $fields : array($fields);
            $rows = $this->exec($object);
            if (true === $object) {
                $rows = $rows->rows();
            }
            if (count($rows)) {
                foreach ($rows as $row) {
                    $record = true === $object
                    ? $this->makeObject(
                        array(
                            'id' => $row->id,
                            'created_at' => $row->created_at,
                            'updated_at' => $row->updated_at
                        )
                    )
                    : array();
                    foreach ($fields as $field) {
                        if (true === $object) {
                            $record->$field = $row->$field;
                        } else {
                            $record[$field] = $row[$field];
                        }
                    }
                    array_push($collection, $record);
                }
            }
            return true === $object ? new Collection($collection) : $collection;
        }

        public function last($object = false)
        {
            $res = $this->results;
            $this->reset();
            if (true === $object) {
                return count($res) ? $this->makeObject(Arrays::last($res)) : null;
            } else {
                return count($res) ? Arrays::last($res) : array();
            }
        }

        private function intersect($tab1, $tab2)
        {
            $ids1       = array();
            $ids2       = array();
            $collection = array();

            foreach ($tab1 as $row) {
                $id = isAke($row, 'id', null);
                if (strlen($id)) {
                    array_push($ids1, $id);
                }
            }

            foreach ($tab2 as $row) {
                $id = isAke($row, 'id', null);
                if (strlen($id)) {
                    array_push($ids2, $id);
                }
            }

            $sect = array_intersect($ids1, $ids2);
            if (count($sect)) {
                foreach ($sect as $idRow) {
                    array_push($collection, $this->find($idRow, false));
                }
            }
            return $collection;
        }

        public function query($sql)
        {
            if (strstr($sql, ' && ')) {
                $segs = explode(' && ', $sql);
                foreach ($segs as $seg) {
                    $this->where($seg);
                    $sql = str_replace($seg . ' && ', '', $sql);
                }
            }
            if (strstr($sql, ' || ')) {
                $segs = explode(' || ', $sql);
                foreach ($segs as $seg) {
                    $this->where($seg, 'OR');
                    $sql = str_replace($seg . ' || ', '', $sql);
                }
            }
            if (!empty($sql)) {
                $this->where($sql);
            }
            return $this;
        }

        public function in($ids, $field = null)
        {
            /* polymorphism */
            $ids = !Arrays::is($ids)
            ? strstr($ids, ',')
                ? explode(',', repl(' ', '', $ids))
                : array($ids)
            : $ids;

            $field = is_null($field) ? 'id' : $field;
            return $this->where($field . ' IN (' . implode(',', $ids) . ')');
        }

        public function notIn($ids, $field = null)
        {
            /* polymorphism */
            $ids = !Arrays::is($ids)
            ? strstr($ids, ',')
                ? explode(',', repl(' ', '', $ids))
                : array($ids)
            : $ids;

            $field = is_null($field) ? 'id' : $field;
            return $this->where($field . ' NOT IN (' . implode(',', $ids) . ')');
        }

        public function like($field, $str, $op = 'AND')
        {
            return $this->where("$field LIKE " . $str, $op);
        }

        public function trick(Closure $condition, $op = 'AND', $results = array())
        {
            $data = !count($results) ? $this->all() : $results;
            $res = array();
            if (count($data)) {
                foreach ($data as $row) {
                    $resTrick = $condition($row);
                    if (true === $resTrick) {
                        array_push($res, $row);
                    }
                }
            }
            if (!count($this->wheres)) {
                $this->results = array_values($res);
            } else {
                $values = array_values($this->results);
                switch ($op) {
                    case 'AND':
                        $this->results = $this->intersect($values, array_values($res));
                        break;
                    case 'OR':
                        $this->results = array_merge($values, array_values($res));
                        break;
                    case 'XOR':
                        $this->results = array_merge(
                            array_diff(
                                $values,
                                array_values($res),
                                array_diff(
                                    array_values($res),
                                    $values
                                )
                            )
                        );
                        break;
                }
            }
            $this->wheres[] = $condition;
            return $this;
        }

        public function where($condition, $op = 'AND', $results = array())
        {
            $res = $this->search($condition, $results, false);
            if (!count($this->wheres)) {
                $this->results = array_values($res);
            } else {
                $values = array_values($this->results);
                switch ($op) {
                    case 'AND':
                        $this->results = $this->intersect($values, array_values($res));
                        break;
                    case 'OR':
                        $this->results = array_merge($values, array_values($res));
                        break;
                    case 'XOR':
                        $this->results = array_merge(
                            array_diff(
                                $values,
                                array_values($res),
                                array_diff(
                                    array_values($res),
                                    $values
                                )
                            )
                        );
                        break;
                }
            }
            $this->wheres[] = $condition;
            return $this;
        }

        private function search($condition = null, $results = array(), $populate = true)
        {
            $collection = array();

            $condition  = repl('LIKE START', 'LIKESTART', $condition);
            $condition  = repl('LIKE END', 'LIKEEND', $condition);
            $condition  = repl('NOT LIKE', 'NOTLIKE', $condition);
            $condition  = repl('NOT IN', 'NOTIN', $condition);
            list($field, $op, $value) = explode(' ', $condition, 3);

            $datas = !count($results) ? $this->all() : $results;

            if (empty($condition)) {
                return $datas;
            }

            if(count($datas)) {
                foreach ($datas as $tab) {
                    if (!empty($tab)) {
                        $val = isAke($tab, $field, null);
                        if (strlen($val)) {
                            $val = repl('|', ' ', $val);
                            $check = $this->compare($val, $op, $value);
                        } else {
                            $check = ('null' == $value) ? true : false;
                        }
                        if (true === $check) {
                            array_push($collection, $tab);
                        }
                    }
                }
            }

            if (true === $populate) {
                $this->results = $collection;
            }
            return $collection;
        }

        private function compare($comp, $op, $value)
        {
            $res = false;
            if (isset($comp)) {
                $comp   = Inflector::lower($comp);
                $value  = Inflector::lower($value);
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
                        $value = repl("'", '', $value);
                        $value = repl('%', '', $value);
                        if (strstr($comp, $value)) {
                            $res = true;
                        }
                        break;
                    case 'NOTLIKE':
                        $value = repl("'", '', $value);
                        $value = repl('%', '', $value);
                        if (!strstr($comp, $value)) {
                            $res = true;
                        }
                        break;
                    case 'LIKESTART':
                        $value = repl("'", '', $value);
                        $value = repl('%', '', $value);
                        $res = (substr($comp, 0, strlen($value)) === $value);
                        break;
                    case 'LIKEEND':
                        $value = repl("'", '', $value);
                        $value = repl('%', '', $value);
                        if (!strlen($comp)) {
                            $res = true;
                        }
                        $res = (substr($comp, -strlen($value)) === $value);
                        break;
                    case 'IN':
                        $value = repl('(', '', $value);
                        $value = repl(')', '', $value);
                        $tabValues = explode(',', $value);
                        $res = Arrays::in($comp, $tabValues);
                        break;
                    case 'NOTIN':
                        $value = repl('(', '', $value);
                        $value = repl(')', '', $value);
                        $tabValues = explode(',', $value);
                        $res = !Arrays::in($comp, $tabValues);
                        break;
                }
            }
            return $res;
        }

        public function extend($name, $callable)
        {
            $settings   = isAke(self::$config, "$this->db.$this->table");
            $functions  = isAke($settings, 'functions');

            $functions[$name] = $callable;

            self::$config["$this->db.$this->table"]['functions'] = $functions;
            return $this;
        }

        public function row($id, $object = false)
        {
            $db     = container()->redis();
            $rows   = $db->keys("eav::rows::$this->db::$this->table::*::$id");
            $row    = array();
            if (count($rows)) {
                $row['id'] = $id;
                foreach ($rows as $tmp) {
                    $field = str_replace(
                        array(
                            "eav::rows::$this->db::$this->table::",
                            "::$id",
                        ),
                        '',
                        $tmp
                    );
                    $row[$field] = json_decode($db->get($tmp), true);
                }
                ksort($row);
            }
            return !$object ? $row : $this->makeObject($row);
        }

        /* API static */

        public static function keys($pattern)
        {
            $collection = array();
            $db = new self('core', 'core');
            $pattern = repl('*', '', $pattern);
            return $db->where("key LIKE '$pattern'")->exec(true);
        }

        public static function get($key, $default = null, $object = false)
        {
            static::clean();
            $db = new self('core', 'core');
            $value = $db->where("key = $key")->first(true);
            return $value instanceof Container ? false === $object ? $value->getValue() : $value : $default;
        }

        public static function set($key, $value, $expire = 0)
        {
            $db = new self('core', 'core');
            $exists = self::get($key, null, true);
            if (0 < $expire) $expire += time();
            if ($exists instanceof Container) {
                $exists->setValue($value)->setExpire($expire)->save();
            } else {
                $db->create()->setKey($key)->setValue($value)->setExpire($expire)->save();
            }
            return $db;
        }

        public static function del($key)
        {
            $db = new self('core', 'core');
            $exists = $db->where("key = $key")->first(true);
            if ($exists instanceof Container) {
                $exists->delete();
            }
            return $db;
        }

        public static function incr($key, $by = 1)
        {
            $val = self::get($key);
            if (!strlen($val)) {
                $val = 1;
            } else {
                $val = (int) $val;
                $val += $by;
            }
            self::set($key, $val);
            return $val;
        }

        public static function decr($key, $by = 1)
        {
            $val = self::get($key);
            if (!strlen($val)) {
                $val = 0;
            } else {
                $val = (int) $val;
                $val -= $by;
                $val = 0 > $val ? 0 : $val;
            }
            self::set($key, $val);
            return $val;
        }

        public static function clean()
        {
            $db = new self('core', 'core');
            return $db->where('expire > 0')->where('expire < ' . time())->exec(true)->delete();
        }
    }
