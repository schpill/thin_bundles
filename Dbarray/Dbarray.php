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

    namespace Dbarray;

    use Closure;
    use Thin\Utils;
    use Thin\Exception;
    use Thin\Instance;
    use Thin\File;
    use Thin\Arrays;
    use Thin\Inflector;
    use Thin\Route;
    use Thin\Container;
    use Thin\Database\Collection;

    class Dbarray
    {
        public $dir, $model, $db, $table, $results, $data, $nextId;
        public $wheres = [];
        public $keys   = [];
        public static $config   = [];

        public function __construct($db, $table)
        {
            $path = STORAGE_PATH;

            if (!is_dir($path . DS . 'dbarray')) {
                umask(0000);
                File::mkdir($path . DS . 'dbarray', 0777, true);
            }

            $this->dir  = $path . DS . 'dbarray' . DS . Inflector::lower($db) . DS . Inflector::lower($table);

            if (!is_dir($path . DS . 'dbarray' . DS . Inflector::lower($db))) {
                umask(0000);
                File::mkdir($path . DS . 'dbarray' . DS . Inflector::lower($db), 0777, true);
            }

            if (!is_dir($path . DS . 'dbarray' . DS . Inflector::lower($db) . DS . Inflector::lower($table))) {
                umask(0000);
                File::mkdir($path . DS . 'dbarray' . DS . Inflector::lower($db) . DS . Inflector::lower($table), 0777, true);
            }

            $age = redis()->get(sha1($this->dir));

            if (!strlen($age)) {
                redis()->set(sha1($this->dir), time() - 24 * 3600);
            }

            $this->data();

            $this->db       = $db;
            $this->table    = $table;
        }

        public static function instance($db, $table)
        {
            $args   = func_get_args();
            $key    = sha1(serialize($args));
            $has    = Instance::has('Dbarray', $key);

            if (true === $has) {
                return Instance::get('Dbarray', $key);
            } else {
                return Instance::make('Dbarray', $key, new self($db, $table));
            }
        }

        public function import(array $array, $erase = false)
        {
            if (count($array)) {
                $data = [];

                $i = 1;
                foreach ($array as $row) {
                    if (Arrays::is($row) && Arrays::assoc($row)) {
                        foreach ($row as $key => $value) {
                            $newRow[Inflector::urlize($key, '_')] = $value;
                        }

                        array_push($data, $newRow);

                        $i++;
                    }
                }

                if (count($data)) {
                    $file = $this->dir . DS . 'data.db';

                    if (true === $erase) {
                        File::delete($file);
                    }

                    foreach ($data as $row) {
                        $this->save($row);
                    }
                }
            }

            return $this;
        }

        public function data()
        {
            $file = $this->dir . DS . 'data.db';

            if (!File::exists($file)) {
                File::put($file, serialize([]));
                $this->data = [];
            } else {
                $this->data = unserialize(file_get_contents($file));
            }

            $this->nextId = 1;

            if (count($this->data)) {
                foreach ($this->data as $row) {
                    $id = isAke($row, 'id', 0);
                    $this->nextId = $id + 1;
                }
            }

            return $this;
        }

        public function write()
        {
            $file = $this->dir . DS . 'data.db';

            File::delete($file);
            File::put($file, serialize($this->data));

            return $this;
        }

        public function remove($id)
        {
            $newData = [];

            if (count($this->data)) {
                foreach ($this->data as $row) {
                    $idRow = isAke($row, 'id', false);

                    if ($idRow != $id) {
                        array_push($newData, $row);
                    }
                }
            }

            $this->data = $newData;

            return $this->write();
        }

        public function pk()
        {
            return 'id';
        }

        public function countAll()
        {
            return count($this->all(true));
        }

        public function count()
        {
            $count = count($this->results);
            $this->reset(__function__);

            return $count;
        }

        public function post($save = false)
        {
            return !$save ? $this->create($_POST) : $this->create($_POST)->save();
        }

        public function save($data, $object = true)
        {
            if (is_object($data) && $data instanceof Container) {
                $data = $data->assoc();
            }

            $id = isAke($data, 'id', null);

            redis()->del(sha1($this->dir));

            $this->countQuery($this->getTime());

            if (strlen($id)) {
                return $this->edit($id, $data, $object);
            } else {
                return $this->add($data, $object);
            }
        }

        private function add($data, $object = true)
        {
            if (!Arrays::is($data)) {
                return $data;
            }

            redis()->set(sha1($this->dir), time());

            $hooks = $this->hooks();

            $before = isAke($hooks, 'before_create', false);
            $after  = isAke($hooks, 'after_create', false);

            if (false !== $before) {
                $before();
            }

            $this->lastInsertId = $this->makeId();
            $data['id'] = $this->lastInsertId;
            $data['created_at'] = $data['updated_at'] = time();
            $file  = $this->dir . DS . $this->lastInsertId . '.row';

            foreach ($data as $k => $v) {
                if ($v instanceof Closure) {
                    unset($data[$k]);
                }
            }

            $tuple = $this->tuple($data);

            if (false === $tuple) {
                array_push($this->data, $data);
                $this->write();
            } else {
                $data = $this->find($tuple, false);
            }

            if (false !== $after) {
                $after($data);
            }

            return true === $object ? $this->row($data) : $data;
        }

        private function edit($id, $data, $object = true)
        {
            if (!Arrays::is($data)) {
                return $data;
            }

            $hooks = $this->hooks();

            $before = isAke($hooks, 'before_update', false);
            $after  = isAke($hooks, 'after_update', false);

            if (false !== $before) {
                $before($this->find($id, false));
            }

            $data['id'] = $id;
            $data['updated_at'] = time();

            $old    = $this->find($id, false);
            $new    = array_merge($old, $data);
            $this->deleteRow($id);

            \Dbjson\Dbjson::$queries--;

            $file   = $this->dir . DS . $id . '.row';

            foreach ($data as $k => $v) {
                if ($v instanceof Closure) {
                    unset($data[$k]);
                }
            }

            $tuple = $this->tuple($data);

            if (false === $tuple) {
                array_push($this->data, $new);
                $this->write();
            } else {
                $new = $this->find($tuple, false);
            }

            if (false !== $after) {
                $after($this->row($new));
            }

            redis()->set(sha1($this->dir), time());

            return true === $object ? $this->row($new) : $new;
        }

        public function deleteRow($id)
        {
            redis()->set(sha1($this->dir), time());

            $hooks = $this->hooks();

            $before = isAke($hooks, 'before_delete', false);
            $after  = isAke($hooks, 'after_delete', false);

            if (false !== $before) {
                $before($id);
            }

            $data = $this->find($id, false);

            $this->tuple($data, true);

            $this->remove($id);

            $this->countQuery($this->getTime());

            if (false !== $after) {
                $after($id);
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

        public function all($object = false)
        {
            $collection = [];
            $rows       = $this->data;

            if (count($rows)) {
                foreach ($rows as $row) {
                    if (true === $object) {
                        $data = $this->row($row);
                    } else {
                        $data = $row;
                    }

                    array_push($collection, $data);
                }
            }

            return true === $object ? new Collection($collection) : $collection;
        }

        public function fetch($object = false)
        {
            $this->results = $this->all($object);

            return $this;
        }

        public function full()
        {
            $this->results = $this->all(false);

            return $this;
        }

        public function execute($object = false)
        {
            return $this->exec($object);
        }

        public function exec($object = false)
        {
            $collection = [];

            if (count($this->results)) {
                foreach ($this->results as $row) {
                    $item = true === $object ? $this->row($row) : $row;
                    array_push($collection, $item);
                }
            }

            $this->reset(__function__);

            $this->countQuery($this->getTime());

            return true === $object ? new Collection($collection) : $collection;
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

        public function flush($where = null)
        {
            $res = !empty($where) ? $this->where($where)->exec() : $this->all();

            if (count($res)) {
                foreach ($res as $row) {
                    $this->deleteRow($row['id']);
                }
            }

            return $this;
        }

        public function groupBy($field, $results = [])
        {
            $res        = count($results) ? $results : $this->results;
            $ageChange  = redis()->get(sha1($this->dir));

            $key        = sha1(serialize($res) .
                serialize(func_get_args())) .
            '::groupByArray::' .
            $this->db .
            '::' .
            $this->table;

            $keyAge     = sha1(serialize($res) .
                serialize(func_get_args())) .
            '::groupByArray::' .
            $this->db .
            '::' .
            $this->table .
            '::age';

            $cached     = redis()->get($key);
            $age        = redis()->get($keyAge);

            if (strlen($cached)) {
                if ($age > $ageChange) {
                    $this->results = unserialize($cached);
                    return $this;
                } else {
                    redis()->del($key);
                }
            }

            $groupBys   = [];
            $ever       = [];

            foreach ($res as $id => $tab) {
                $obj = isAke($tab, $field, null);

                if (!Arrays::in($obj, $ever)) {
                    $groupBys[$id]  = $tab;
                    $ever[]         = $obj;
                }
            }

            $this->results = $groupBys;
            $this->order($field);

            redis()->set($key, serialize($this->results));
            redis()->set($keyAge, time());

            return $this;
        }

        public function limit($limit, $offset = 0, $results = [])
        {
            $res            = count($results) ? $results : $this->results;
            $offset         = count($res) < $offset ? count($res) : $offset;
            $this->results  = array_slice($res, $offset, $limit);

            return $this;
        }

        public function sum($field, $results = [])
        {
            $res = count($results) ? $results : $this->results;
            $sum = 0;

            if (count($res)) {
                foreach ($res as $id => $tab) {
                    $val = isAke($tab, $field, 0);
                    $sum += $val;
                }
            }

            $this->reset(__function__);

            return (int) $sum;
        }

        public function avg($field, $results = [])
        {
            return (float) $this->sum($field, $results) / count($results);
        }

        public function min($field, $results = [])
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

            $this->reset(__function__);

            return $min;
        }

        public function max($field, $results = [])
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

            $this->reset(__function__);

            return $max;
        }

        public function rand($results = [])
        {
            $res = count($results) ? $results : $this->results;
            shuffle($res);
            $this->results = $res;

            return $this;
        }

        public function order($fieldOrder, $orderDirection = 'ASC', $results = [])
        {
            $res = count($results) ? $results : $this->results;

            if (empty($res)) {
                return $this;
            }

            $key        = sha1(serialize($res) . serialize(func_get_args())) . '::orderArray::' . $this->db . '::' . $this->table;
            $keyAge     = sha1(serialize($res) . serialize(func_get_args())) . '::orderArray::' . $this->db . '::' . $this->table . '::age';
            $ageChange  = redis()->get(sha1($this->dir));

            $cached     = redis()->get($key);
            $age        = redis()->get($keyAge);

            if (strlen($cached)) {
                if ($age > $ageChange) {
                    $this->results = unserialize($cached);

                    return $this;
                } else {
                    redis()->del($key);
                }
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
                $t = [];

                foreach ($fieldOrder as $tmpField) {
                    array_push($t, $orderDirection);
                }

                $orderDirection = $t;
            }

            if (!Arrays::is($fieldOrder) && Arrays::is($orderDirection)) {
                $orderDirection = Arrays::first($orderDirection);
            }

            if (Arrays::is($fieldOrder) && Arrays::is($orderDirection)) {
                for ($i = 0; $i < count($fieldOrder); $i++) {
                    usort($res, $sortFunc($fieldOrder[$i], $orderDirection[$i]));
                }
            } else {
                usort($res, $sortFunc($fieldOrder, $orderDirection));
            }

            $this->results = $res;

            redis()->set($key, serialize($this->results));
            redis()->set($keyAge, time());

            return $this;
        }

        public function andWhere($condition, $results = [])
        {
            return $this->where($condition, 'AND', $results);
        }

        public function orWhere($condition, $results = [])
        {
            return $this->where($condition, 'OR', $results);
        }

        public function xorWhere($condition, $results = [])
        {
            return $this->where($condition, 'XOR', $results);
        }

        public function _and($condition, $results = [])
        {
            return $this->where($condition, 'AND', $results);
        }

        public function _or($condition, $results = [])
        {
            return $this->where($condition, 'OR', $results);
        }

        public function _xor($condition, $results = [])
        {
            return $this->where($condition, 'XOR', $results);
        }

        public function whereAnd($condition, $results = [])
        {
            return $this->where($condition, 'AND', $results);
        }

        public function whereOr($condition, $results = [])
        {
            return $this->where($condition, 'OR', $results);
        }

        public function whereXor($condition, $results = [])
        {
            return $this->where($condition, 'XOR', $results);
        }

        public function between($field, $min, $max, $object = false)
        {
            return $this->where($field . ' >= ' . $min)->where($field . ' <= ' . $max)->exec($object);
        }

        public function firstOrNew($tab = [])
        {
            return $this->firstOrCreate($tab, false);
        }

        public function firstOrCreate($tab = [], $save = true)
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

            $item = $this->create($tab);

            return !$save ? $item : $item->save();
        }

        public function replace($compare = [], $update = [])
        {
            $instance = $this->firstOrCreate($compare);

            return $instance->hydrate($update)->save();
        }

        public function create($tab = [])
        {
            $tab['created_at'] = isAke($tab, 'created_at', time());
            $tab['updated_at'] = isAke($tab, 'updated_at', time());

            return $this->row($tab);
        }

        public function row($tab = [])
        {
            $o = new Container;
            $o->populate($tab);

            return $this->closures($o);
        }

        public function rows()
        {
            return $this->exec();
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

            $duplicate = function () use ($obj, $db) {
                $obj->copyrow = Utils::token();

                $data = $obj->assoc();

                unset($data['id']);
                unset($data['created_at']);
                unset($data['updated_at']);

                $obj = $db->row($data);

                return $obj->save();
            };

            $hydrate = function ($data = []) use ($obj) {
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
            ->event('hydrate', $hydrate)
            ->event('duplicate', $duplicate);

            $settings   = isAke(self::$config, "$this->db.$this->table");
            $functions  = isAke($settings, 'functions');

            if (count($functions)) {
                foreach ($functions as $closureName => $callable) {
                    $closureName    = lcfirst(Inflector::camelize($closureName));

                    $share          = function () use ($obj, $callable, $db) {
                        $args[]     = $obj;
                        $args[]     = $db;

                        return call_user_func_array($callable, $args);
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
                            $ns = $this->db;

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
            $this->countQuery($this->getTime());

            if (count($this->data)) {
                $find = false;

                foreach ($this->data as $row) {
                    $idRow = isAke($row, 'id', false);

                    if ($id == $idRow) {
                        $find = $row;
                        break;
                    }
                }

                if (false !== $find) {
                    return $object ? $this->row($find) : $find;
                }
            }

            return $object ? null : [];
        }

        public function findOneBy($field, $value, $object = false)
        {
            return $this->findBy($field, $value, true, $object);
        }

        public function findOrFail($id, $object = true)
        {
            if (!is_null($item = $this->find($id, $object))) {
                return $item;
            }

            throw new Exception("Row '$id' in '$this->table' is unknown.");
        }

        public function findBy($field, $value, $one = false, $object = false)
        {
            $res = $this->search("$field = $value");

            if (count($res) && true === $one) {
                return $object ? $this->row(Arrays::first($res)) : Arrays::first($res);
            }

            if (!count($res) && true === $one && true === $object) {
                return null;
            }

            return $this->exec($object);
        }

        public function one($object = true)
        {
            return $this->first($object);
        }

        public function object()
        {
            return $this->first(true);
        }

        public function objects()
        {
            return $this->exec(true);
        }

        public function first($object = false, $reset = true)
        {
            $res = isset($this->results) ? $this->results : $this->all($object);

            if (true === $reset) {
                $this->reset(__function__);
            }

            if (true === $object) {
                return count($res) ? $this->row(Arrays::first($res)) : null;
            } else {
                return count($res) ? Arrays::first($res) : [];
            }
        }

        public function fields()
        {
            $row = $this->first(false, false);

            if (!empty($row)) {
                unset($row['created_at']);
                unset($row['updated_at']);
                ksort($row);

                return array_keys($row);
            }

            return ['id'];
        }

        public function only($field)
        {
            $row = $this->first(true);

            return $row instanceof Container ? $row->$field : null;
        }

        public function select($fields, $object = false)
        {
            $collection = [];
            $fields     = Arrays::is($fields) ? $fields : [$fields];
            $rows       = $this->exec($object);

            if (true === $object) {
                $rows = $rows->rows();
            }

            if (count($rows)) {
                foreach ($rows as $row) {
                    $record = true === $object
                    ? $this->row(
                        [
                            'id' => $row->id,
                            'created_at' => $row->created_at,
                            'updated_at' => $row->updated_at
                        ]
                    )
                    : [];

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
            $this->reset(__function__);

            if (true === $object) {
                return count($res) ? $this->row(Arrays::last($res)) : null;
            } else {
                return count($res) ? Arrays::last($res) : [];
            }
        }

        private function intersect($tab1, $tab2)
        {
            $ids1       = [];
            $ids2       = [];
            $collection = [];

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
                : [$ids]
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
                : [$ids]
            : $ids;

            $field = is_null($field) ? 'id' : $field;

            return $this->where($field . ' NOT IN (' . implode(',', $ids) . ')');
        }

        public function like($field, $str, $op = 'AND')
        {
            return $this->where("$field LIKE " . $str, $op);
        }

        public function likeStart($field, $str, $op = 'AND')
        {
            return $this->where("$field LIKE START " . $str, $op);
        }

        public function likeEnd($field, $str, $op = 'AND')
        {
            return $this->where("$field LIKE END " . $str, $op);
        }

        public function notLike($field, $str, $op = 'AND')
        {
            return $this->where("$field NOT LIKE " . $str, $op);
        }

        public function trick(Closure $condition, $op = 'AND', $results = [])
        {
            $data = !count($results) ? $this->all() : $results;
            $res = [];

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
                        $this->results = $values + $res;
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

        public function where($condition, $op = 'AND', $results = [])
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
                        $this->results = $values + $res;
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

        private function search($condition = null, $results = [], $populate = true)
        {
            $datas = !count($results) ? $this->all() : $results;

            if (empty($condition)) {
                return $datas;
            }

            $ageSearch  = redis()->get(sha1($this->dir . serialize(func_get_args())) . 'ageSearch');
            $dataSearch = redis()->get(sha1($this->dir . serialize(func_get_args())) . 'dataSearch');
            $ageChange  = redis()->get(sha1($this->dir));

            if (strlen($ageSearch) && strlen($dataSearch) && strlen($ageChange)) {

                if ($ageSearch > $ageChange) {
                    $collection = unserialize($dataSearch);

                    if (true === $populate) {
                        $this->results = $collection;
                    }

                    return $collection;
                } else {
                    redis()->del(sha1($this->dir . serialize(func_get_args())) . 'dataSearch');
                    redis()->del(sha1($this->dir . serialize(func_get_args())) . 'ageSearch');
                }
            }

            $collection = [];

            $condition  = repl('LIKE START', 'LIKESTART', $condition);
            $condition  = repl('LIKE END', 'LIKEEND', $condition);
            $condition  = repl('NOT LIKE', 'NOTLIKE', $condition);
            $condition  = repl('NOT IN', 'NOTIN', $condition);

            if (strstr($condition, ' = ')) {
                list($field, $value) = explode(' = ', $condition, 2);
                $op = '=';
            } elseif (strstr($condition, ' < ')) {
                list($field, $value) = explode(' < ', $condition, 2);
                $op = '<';
            } elseif (strstr($condition, ' > ')) {
                list($field, $value) = explode(' > ', $condition, 2);
                $op = '>';
            } elseif (strstr($condition, ' <= ')) {
                list($field, $value) = explode(' <= ', $condition, 2);
                $op = '<=';
            } elseif (strstr($condition, ' >= ')) {
                list($field, $value) = explode(' >= ', $condition, 2);
                $op = '>=';
            } elseif (strstr($condition, ' LIKESTART ')) {
                list($field, $value) = explode(' LIKESTART ', $condition, 2);
                $op = 'LIKESTART';
            } elseif (strstr($condition, ' LIKEEND ')) {
                list($field, $value) = explode(' LIKEEND ', $condition, 2);
                $op = 'LIKEEND';
            } elseif (strstr($condition, ' NOTLIKE ')) {
                list($field, $value) = explode(' NOTLIKE ', $condition, 2);
                $op = 'NOTLIKE';
            } elseif (strstr($condition, ' LIKE ')) {
                list($field, $value) = explode(' LIKE ', $condition, 2);
                $op = 'LIKE';
            } elseif (strstr($condition, ' IN ')) {
                list($field, $value) = explode(' IN ', $condition, 2);
                $op = 'IN';
            } elseif (strstr($condition, ' NOTIN ')) {
                list($field, $value) = explode(' NOTIN ', $condition, 2);
                $op = 'NOTIN';
            } elseif (strstr($condition, ' != ')) {
                list($field, $value) = explode(' != ', $condition, 2);
                $op = '!=';
            } elseif (strstr($condition, ' <> ')) {
                list($field, $value) = explode(' <> ', $condition, 2);
                $op = '<>';
            }

            if (($field == 'created_at' || $field == 'updated_at') && strstr($value, '/')) {
                list($d, $m, $y) = explode('/', $value, 3);
                $value = mktime(23, 59, 59, $m, $d, $y);
            }

            if ($value instanceof Container) {
                $value = $value->id();
                $field = $field . '_id';
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

            redis()->set(sha1($this->dir . serialize(func_get_args())) . 'dataSearch', serialize($collection));
            redis()->set(sha1($this->dir . serialize(func_get_args())) . 'ageSearch', time());

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

        /* API static */

        public static function keys($pattern)
        {
            $collection = [];

            $db         = self::instance('core', 'api');
            $pattern    = repl('*', '', $pattern);

            return $db->like($pattern)->exec(true);
        }

        public static function get($key, $default = null, $object = false)
        {
            self::clean();

            $db     = self::instance('core', 'api');
            $value  = $db->where("key = $key")->first(true);

            return $value instanceof Container ? false === $object ? $value->getValue() : $value : $default;
        }

        public static function set($key, $value, $expire = 0)
        {
            $db     = static::instance('core', 'api');
            $exists = self::get($key, null, true);

            if (0 < $expire) $expire += time();

            if ($exists instanceof Container) {
                return $exists->setValue($value)->setExpire($expire)->save();
            } else {
                return $db->create()->setKey($key)->setValue($value)->setExpire($expire)->save();
            }
        }

        public static function del($key)
        {
            $db = self::instance('core', 'api');
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

        public static function expire($key, $expire = 0)
        {
            $db = self::instance('core', 'api');
            $exists = $db->where("key = $key")->first(true);

            if ($exists instanceof Container) {
                if (0 < $expire) $expire += time();
                $exists->setExpire($expire)->save();

                return true;
            }

            return false;
        }

        public static function clean()
        {
            $db = self::instance('core', 'api');

            return $db->where('expire > 0')->where('expire < ' . time())->exec(true)->delete();
        }

        private static function structure($ns, $table, $fields)
        {
            $dbt = amodel('ama_table');
            $dbf = amodel('ama_field');
            $dbs = amodel('ama_structure');

            $t = $dbt->where('name = ' . $table)->where('ns = ' . $ns)->first(true);

            if (is_null($t)) {
                $t = $dbt->create(['name' => $table, 'ns' => $ns])->save();
            }

            if (!is_null($t)) {
                if (count($fields)) {
                    foreach ($fields as $field) {
                        if ('id' != $field) {
                            $f = $dbf->where('name = ' . $field)->first(true);

                            if (is_null($f)) {
                                $f = $dbf->create()->setName($field)->save();
                            }

                            $s = $dbs
                            ->where('table = ' . $t->getId())
                            ->where('field = ' . $f->getId())
                            ->first(true);

                            if (is_null($s)) {
                                $s = $dbs->create()
                                ->setTable($t->getId())
                                ->setField($f->getId())
                                ->setType('varchar')
                                ->setLength(255)
                                ->setIsIndex(false)
                                ->setCanBeNull(true)
                                ->setDefault(null)
                                ->save();
                            }
                        }
                    }
                }
            }
        }

        public static function tables()
        {
            $dbt = amodel('ama_table');
            $dirs = glob(STORAGE_PATH . DS . 'dbarray' . DS . '*', GLOB_NOSORT);
            $rows = [];

            if (count($dirs)) {
                foreach ($dirs as $dir) {
                    $tmp    = glob($dir . DS . '*', GLOB_NOSORT);
                    $rows   = array_merge($rows, $tmp);
                }
            }

            $tables = [];

            if (count($rows)) {
                foreach ($rows as $row) {
                    $tab    = explode(DS, $row);
                    $index  = Arrays::last($tab);
                    $ns     = $tab[count($tab) - 2];

                    if (!strstr($index, 'ama_')) {
                        $t = $dbt->where('name = ' . $index)->where('ns = ' . $ns)->first(true);

                        if (is_null($t)) {

                            $data = amodel($index, $ns)->fetch()->exec();

                            if (count($data)) {
                                $first = Arrays::first($data);
                                $fields = array_keys($first);
                                $tables[$index]['fields'] = $fields;
                            } else {
                                $fields = [];
                            }

                            self::structure($ns, $index, $fields);
                        }
                    }
                }
            }
            return $tables;
        }

        public function createTable()
        {
            return $this;
        }

        public function dropTable()
        {
            File::rmdir($this->dir);

            return $this;
        }

        public function emptyTable()
        {
            $rows = $this->fetch()->exec();

            if (count($rows)) {
                foreach ($rows as $row) {
                    $this->deleteRow($row['id']);
                }
            }

            return $this;
        }

        public function config($key, $value = null)
        {
            self::configs("$this->db.$this->table", $key, $value);
        }

        public static function configs($entity, $key, $value = null, $cb = null)
        {
            if (!strlen($entity)) {
                throw new Exception("An entity must be provided to use this method.");
            }

            if (!Arrays::exists($entity, static::$config)) {
                self::$config[$entity] = [];
            }

            if (empty($value)) {
                if (!strlen($key)) {
                    throw new Exception("A key must be provided to use this method.");
                }

                return isAke(self::$config[$entity], $key, null);
            }

            if (!strlen($key)) {
                throw new Exception("A key must be provided to use this method.");
            }

            $reverse = strrev($key);
            $last = $reverse{0};

            if ('s' == $last) {
                self::$config[$entity][$key] = $value;
            } else {
                if (!Arrays::exists($key . 's', self::$config[$entity])) {
                    self::$config[$entity][$key . 's'] = [];
                }
                array_push(self::$config[$entity][$key . 's'], $value);
            }

            return !is_callable($cb) ? true : $cb();
        }

        public function export($q = null, $type = 'csv')
        {
            if (!empty($this->wheres)) {
                $datas = $this->results;
            } else {
                if (!empty($q)) {
                    $this->wheres[] = $q;
                    $datas = $this->search($q);
                } else {
                    $datas = $this->all(true);
                }
            }

            if (count($datas)) {
                $fields     = $this->fields();
                $rows = [];
                $rows[] = implode(';', $fields);

                foreach ($datas as $row) {
                    $tmp = [];

                    foreach ($fields as $field) {
                        $value = isAke($row, $field, null);
                        $tmp[] = $value;
                    }
                    $rows[] = implode(';', $tmp);
                }

                $this->$type($rows);
            } else {
                if (count($this->wheres)) {
                    $this->reset(__function__);
                    die('This query has no result.');
                } else {
                    die('This database is empty.');
                }
            }
        }

        private function csv($data)
        {
            $csv    = implode("\n", $data);
            $name   = date('d_m_Y_H_i_s') . '_' . $this->table . '_export.csv';
            $file   = TMP_PUBLIC_PATH . DS . $name;

            File::delete($file);
            File::put($file, $csv);
            Utils::go(repl('ama.php', '', URLSITE) . 'tmp/' . $name);
        }

        public static function __callStatic($fn, $args)
        {
            $method     = Inflector::uncamelize($fn);
            $tab        = explode('_', $method);
            $table      = array_shift($tab);
            $function   = implode('_', $tab);
            $function   = lcfirst(Inflector::camelize($function));
            $instance   = static::instance(SITE_NAME, $table);

            return call_user_func_array([$instance, $function], $args);
        }

        public function __call($fn, $args)
        {
            $fields = $this->fields();

            $method = substr($fn, 0, 2);
            $object = lcfirst(substr($fn, 2));

            if ('is' == $method && strlen($fn) > 2) {
                $field = Inflector::uncamelize($object);
                if (!Arrays::in($field, $fields)) {
                    $field = $field . '_id';
                    $model = Arrays::first($args);
                    if ($model instanceof Container) {
                        $idFk = $model->id();
                    } else {
                        $idFk = $model;
                    }
                    return $this->where("$field = $idFk");
                } else {
                    return $this->where($field . ' = ' . Arrays::first($args));
                }
            }

            $method = substr($fn, 0, 4);
            $object = lcfirst(substr($fn, 4));

            if ('orIs' == $method && strlen($fn) > 4) {
                $field = Inflector::uncamelize($object);
                if (!Arrays::in($field, $fields)) {
                    $field = $field . '_id';
                    $model = Arrays::first($args);
                    if ($model instanceof Container) {
                        $idFk = $model->id();
                    } else {
                        $idFk = $model;
                    }
                    return $this->where("$field = $idFk", 'OR');
                } else {
                    return $this->where($field . ' = ' . Arrays::first($args), 'OR');
                }
            } elseif('like' == $method && strlen($fn) > 4) {
                $field = Inflector::uncamelize($object);
                $op = count($args) == 2 ? Arrays::last($args) : 'AND';

                return $this->like($field, Arrays::first($args), $op);
            }

            $method = substr($fn, 0, 5);
            $object = lcfirst(substr($fn, 5));

            if (strlen($fn) > 5) {
                if ('where' == $method) {
                    $field = Inflector::uncamelize($object);

                    if (!Arrays::in($field, $fields)) {
                        $field = $field . '_id';
                        $model = Arrays::first($args);

                        if ($model instanceof Container) {
                            $idFk = $model->id();
                        } else {
                            $idFk = $model;
                        }

                        return $this->where("$field = $idFk");
                    } else {
                        return $this->where($field . ' ' . Arrays::first($args));
                    }
                } elseif ('xorIs' == $method) {
                    $field = Inflector::uncamelize($object);

                    if (!Arrays::in($field, $fields)) {
                        $field = $field . '_id';
                        $model = Arrays::first($args);

                        if ($model instanceof Container) {
                            $idFk = $model->id();
                        } else {
                            $idFk = $model;
                        }

                        return $this->where("$field = $idFk", 'XOR');
                    } else {
                        return $this->where($field . ' = ' . Arrays::first($args), 'XOR');
                    }
                } elseif ('andIs' == $method) {
                    $field = Inflector::uncamelize($object);

                    if (!Arrays::in($field, $fields)) {
                        $field = $field . '_id';
                        $model = Arrays::first($args);

                        if ($model instanceof Container) {
                            $idFk = $model->id();
                        } else {
                            $idFk = $model;
                        }

                        return $this->where("$field = $idFk");
                    } else {
                        return $this->where($field . ' = ' . Arrays::first($args));
                    }
                }
            }

            $method = substr($fn, 0, 6);
            $object = Inflector::uncamelize(lcfirst(substr($fn, 6)));

            if (strlen($fn) > 6) {
                if ('findBy' == $method) {
                    return $this->findBy($object, Arrays::first($args));
                }
            }

            $method = substr($fn, 0, 7);
            $object = lcfirst(substr($fn, 7));

            if (strlen($fn) > 7) {
                if ('orWhere' == $method) {
                    $field = Inflector::uncamelize($object);

                    if (!Arrays::in($field, $fields)) {
                        $field = $field . '_id';
                        $model = Arrays::first($args);

                        if ($model instanceof Container) {
                            $idFk = $model->id();
                        } else {
                            $idFk = $model;
                        }

                        return $this->where("$field = $idFk", 'OR');
                    } else {
                        return $this->where($field . ' ' . Arrays::first($args), 'OR');
                    }
                } elseif ('orderBy' == $method) {
                    $object = Inflector::uncamelize(lcfirst(substr($fn, 7)));

                    if ($object == 'id') {
                        $object = $this->pk();
                    }

                    if (!Arrays::in($object, $fields)) {
                        $object = Arrays::in($object . '_id', $fields) ? $object . '_id' : $object;
                    }

                    $direction = (count($args)) ? Arrays::first($args) : 'ASC';

                    return $this->order($object, $direction);
                } elseif ('groupBy' == $method) {
                    $object = Inflector::uncamelize(lcfirst(substr($fn, 7)));

                    if ($object == 'id') {
                        $object = $this->pk();
                    }

                    if (!Arrays::in($object, $fields)) {
                        $object = Arrays::in($object . '_id', $fields) ? $object . '_id' : $object;
                    }

                    return $this->groupBy($object);
                }
            }

            $method = substr($fn, 0, 9);
            $object = Inflector::uncamelize(lcfirst(substr($fn, 9)));

            if (strlen($fn) > 9) {
                if ('findOneBy' == $method) {
                    return $this->findOneBy($object, Arrays::first($args));
                }
            }

            $method = substr($fn, 0, 13);
            $object = Inflector::uncamelize(lcfirst(substr($fn, 13)));

            if (strlen($fn) > 13) {
                if ('findObjectsBy' == $method) {
                    return $this->findBy($object, Arrays::first($args), true);
                }
            }

            $method = substr($fn, 0, 15);
            $object = Inflector::uncamelize(lcfirst(substr($fn, 15)));

            if (strlen($fn) > 15) {
                if ('findOneObjectBy' == $method) {
                    return $this->findOneBy($object, Arrays::first($args), true);
                }
            }

            $method = substr($fn, 0, 8);
            $object = lcfirst(substr($fn, 8));

            if (strlen($fn) > 8) {
                if ('xorWhere' == $method) {
                    $field = Inflector::uncamelize($object);

                    if (!Arrays::in($field, $fields)) {
                        $field = $field . '_id';
                        $model = Arrays::first($args);

                        if ($model instanceof Container) {
                            $idFk = $model->id();
                        } else {
                            $idFk = $model;
                        }
                        return $this->where("$field = $idFk", 'XOR');
                    } else {
                        return $this->where($field . ' ' . Arrays::first($args), 'XOR');
                    }
                } elseif('andWhere' == $method) {
                    $field = Inflector::uncamelize($object);

                    if (!Arrays::in($field, $fields)) {
                        $field = $field . '_id';
                        $model = Arrays::first($args);

                        if ($model instanceof Container) {
                            $idFk = $model->id();
                        } else {
                            $idFk = $model;
                        }

                        return $this->where("$field = $idFk");
                    } else {
                        return $this->where($field . ' ' . Arrays::first($args));
                    }
                }
            }

            $field = $fn;
            $fieldFk = $fn . '_id';
            $op = count($args) == 2 ? Inflector::upper(Arrays::last($args)) : 'AND';

            if (Arrays::in($field, $fields)) {
                return $this->where($field . ' = ' . Arrays::first($args), $op);
            } else if (Arrays::in($fieldFk, $fields)) {
                $model = Arrays::first($args);

                if ($model instanceof Container) {
                    $idFk = $model->id();
                } else {
                    $idFk = $model;
                }

                return $this->where("$fieldFk = $idFk", $op);
            }

            throw new Exception("Method '$fn' is unknown.");
        }

        public function backup()
        {
            $file   = 'backup_data_array_' . SITE_NAME . '_' . date('d_m_Y_H_i_s') . '.zip';
            $cmd    = 'cd ' . STORAGE_PATH . ' && zip -r ' . $file . ' dbarray
            lftp -e ' . "'put $file; bye' -u \"admin@XRilqH\",lepenhara dl.free.fr
            rm $file";
            exec($cmd);
        }

        public function indexation($fields)
        {
            /* polymorphism */
            $fields = !Arrays::is($fields)
            ? strstr($fields, ',')
                ? explode(',', repl(' ', '', $fields))
                : [$fields]
            : $fields;

            $ageChange  = redis()->get(sha1($this->dir));
            $ageIndex   = redis()->get("index::array::age::$this->db::$this->table");

            if (!strlen($ageIndex) || $ageIndex < $ageChange) {
                $data = $this->all();
                $indexation = new Indexation($this->table, $fields);

                foreach ($data as $row) {
                    $indexation->handle($row['id']);
                }

                redis()->set("index::array::age::$this->db::$this->table", time());
            }

            return redis()->get("index::array::age::$this->db::$this->table");
        }

        public function fulltext($fields, $query, $strict = false)
        {
            /* polymorphism */
            $fields = !Arrays::is($fields)
            ? strstr($fields, ',')
                ? explode(',', repl(' ', '', $fields))
                : [$fields]
            : $fields;

            $this->indexation($fields);

            $indexation = new Indexation($this->table, $fields);

            return $indexation->search($query, $strict);
        }

        private function tuple($data, $delete = false)
        {
            $id = isAke($data, 'id', 0);
            unset($data['id']);

            $created_at = isAke($data, 'created_at', false);
            $updated_at = isAke($data, 'updated_at', false);

            if (false !== $created_at) unset($data['created_at']);
            if (false !== $updated_at) unset($data['updated_at']);

            $key    = 'row_' . sha1(serialize($data) . $this->dir);
            $tuple  = redis()->get($key);
            $exists = strlen($tuple) > 0;

            if (true === $exists) {
                if (true === $delete) {
                    redis()->del($key);
                    return true;
                } else {
                    return $tuple;
                }
            } else {
                if (false === $delete) {
                    redis()->set($key, $id);
                }
            }

            return false;
        }

        public function reset($f)
        {
            $this->results  = null;
            $this->wheres   = [];

            return $this;
        }

        private function makeId()
        {
            $id = redis()->incr(sha1($this->dir) . 'indexes');

            while ($id < $this->nextId) {
                $id = redis()->incr(sha1($this->dir) . 'indexes');
            }

            return $id;
        }

        private function getTime()
        {
            $time = microtime();
            $time = explode(' ', $time, 2);

            return (Arrays::last($time) + Arrays::first($time));
        }

        private function countQuery($start)
        {
            \Dbjson\Dbjson::$queries++;
            \Dbjson\Dbjson::$duration += $this->getTime() - $start;
        }

        private function hooks()
        {
            $crud = Crud::instance($this);
            return $crud->config();
        }
    }
