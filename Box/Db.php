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

    use Thin\Alias;
    use Thin\Arrays;
    use Thin\Config as Conf;
    use Thin\Utils;
    use Thin\File;
    use Thin\Exception;
    use Thin\Instance;
    use Thin\Inflector;
    use Thin\Container;
    use Thin\Keep;
    use Thin\Light;
    use Thin\Timer;

    class Db
    {
        public $db, $table, $collection, $results, $cnx, $limit, $offset, $cacheClient, $store;
        public $wheres          = [];
        public $selects         = [];
        public $orders          = [];
        public $groupBys        = [];
        public $joinTables      = [];
        public $totalResults    = 0;

        private $useCache = true;

        public static $cache = [];

        public function __construct($db, $table)
        {
            $db                 = strtolower($db);
            $table              = strtolower($table);

            $this->db           = $db;
            $this->table        = $table;
            $this->collection   = "$db.$table";

            $this->store        = lib('redys', ["box.$db.$table"]);

            $this->getAge();
        }

        public function reset()
        {
            $this->results      = null;
            $this->totalResults = 0;
            $this->selects      = [];
            $this->joinTables   = [];
            $this->wheres       = [];
            $this->groupBys     = [];
            $this->orders       = [];

            return $this;
        }

        public function getAge()
        {
            $age = $this->store->get('age');

            if (!$age) {
                $age = strtotime('-1 day');
                $this->setAge($age);
            }

            return $age;
        }

        public function setAge($age = null)
        {
            $age = is_null($age) ? time() : $age;
            $this->store->set('age', (int) $age);

            return $this;
        }

        public function age($format = null)
        {
            $format = is_null($format) ? 'd/m/Y H:i:s' : $format;

            return date($format, $this->getAge());
        }

        public function create($data = [])
        {
            return $this->model($data);
        }

        public static function instance($db, $table)
        {
            $args   = func_get_args();
            $key    = sha1(serialize($args));
            $has    = Instance::has('Box', $key);

            if (true === $has) {
                return Instance::get('Box', $key);
            } else {
                return Instance::make('Box', $key, new self($db, $table));
            }
        }

        public function model($data = [])
        {
            $db     = $this->db;
            $table  = $this->table;

            $modelFile = APPLICATION_PATH . DS . 'models' . DS . 'Box' . DS . 'models' . DS . Inflector::lower($db) . DS . ucfirst(Inflector::lower($table)) . '.php';

            if (!is_dir(APPLICATION_PATH . DS . 'models' . DS . 'Box')) {
                File::mkdir(APPLICATION_PATH . DS . 'models' . DS . 'Box');
            }

            if (!is_dir(APPLICATION_PATH . DS . 'models' . DS . 'Box' . DS . 'models')) {
                File::mkdir(APPLICATION_PATH . DS . 'models' . DS . 'Box' . DS . 'models');
            }

            if (!is_dir(APPLICATION_PATH . DS . 'models' . DS . 'Box' . DS . 'models' . DS . Inflector::lower($db))) {
                File::mkdir(APPLICATION_PATH . DS . 'models' . DS . 'Box' . DS . 'models' . DS . Inflector::lower($db));
            }

            if (!File::exists($modelFile)) {
                File::put($modelFile, str_replace('##class##', ucfirst(Inflector::lower($db)) . ucfirst(Inflector::lower($table)) . 'BoxModel', File::read(__DIR__ . DS . 'dbModel.tpl')));
            }

            $class = '\\Thin\\' . ucfirst(Inflector::lower($db)) . ucfirst(Inflector::lower($table)) . 'BoxModel';

            if (!class_exists($class)) {
                require_once $modelFile;
            }

            $model = $this;

            return new $class($model, $data);
        }

        private function addTuple($id, $keyTuple)
        {
            $this->store->set('tuples.id.' . $id, $keyTuple);
            $this->store->set('tuples.key.' . $keyTuple, $id);

            return $this;
        }

        private function delTuple($id)
        {
            $key = $this->store->get('tuples.id.' . $id);

            if ($key) {
                $this->store->del('tuples.key.' . $keyTuple);
                $this->store->del('tuples.id.' . $id);

                return true;
            }

            return false;
        }

        private function tuple($keyTuple)
        {
            $id = $this->store->get('tuples.key.' . $keyTuple);

            if ($id) {
                return $id;
            }

            return null;
        }

        private function analyze(array $data)
        {
            $clean = [];

            foreach ($data as $k => $v) {
                if (is_numeric($v) && !fnmatch('*phone*', $k) && !fnmatch('*zip*', $k) && $k != 'phone' && $k != 'zip' && $k != 'siret') {
                    if (fnmatch('*.*', $v) || fnmatch('*,*', $v)) {
                        $v = (float) $v;
                    } else {
                        $v = (int) $v;
                    }
                }

                $clean[$k] = $v;
            }

            return $clean;
        }

        public function permute($db, $table)
        {
            $this->db           = $db;
            $this->table        = $table;
            $this->collection   = "$db.$table";

            $this->getAge();

            return $this;
        }

        public function makeId()
        {
            $id = $this->store->incr('id');

            $this->store->hdel('ids', $id);
            $this->store->hset('ids', $id, true);

            return $id;
        }

        public function __destruct()
        {
            $this->reset();
        }

        public function find($id, $object = true)
        {
            if (!is_numeric($id)) {
                return null;
            }

            $id     = (int) $id;
            $obj    = $this->store->hget('data', $id);

            if (!$obj) {
                return null;
            } else {
                $obj = $obj;
            }

            return true === $object ? $this->model($obj) : $obj;
        }

        public function save(array $data)
        {
            $id = isAke($data, 'id', false);

            return !$id ? $this->add($data) : $this->edit($id, $data);
        }

        private function add(array $data)
        {
            $keep = $data;

            unset($keep['id']);
            unset($keep['created_at']);
            unset($keep['updated_at']);
            unset($keep['deleted_at']);

            $keyTuple = sha1($this->db . $this->table . serialize($keep));

            $tuple = $this->tuple($keyTuple);

            if (strlen($tuple)) {
                $o = $this->find($tuple);

                if ($o) {
                    return $o;
                }
            }

            $id = $this->makeId();

            $data['id'] = $id;

            if (!isset($data['created_at'])) {
                $data['created_at'] = (int) time();
            }

            if (!isset($data['updated_at'])) {
                $data['updated_at'] = (int) time();
            }

            $data = $this->analyze($data);

            $this->store->hdel('data', $id);
            $this->store->hset('data', $id, $data);

            $this->populateFields($data, $id);

            $this->addTuple($id, $keyTuple);

            $this->setAge();

            return $this->model($data);
        }

        private function edit($id, array $data)
        {
            $keep = $data;

            unset($keep['id']);
            unset($keep['created_at']);
            unset($keep['updated_at']);
            unset($keep['deleted_at']);

            $keyTuple = sha1($this->db . $this->table . serialize($keep));

            $tuple = $this->tuple($keyTuple);

            if (strlen($tuple)) {
                $o = $this->find($tuple);

                if ($o) {
                    return $o;
                }
            }

            $data['updated_at'] = (int) time();

            $this->store->hdel('data', $id);
            $this->store->hset('data', $id, $this->analyze($data));

            $this->populateFields($data, $id);

            $this->delTuple($id);
            $this->addTuple($id, $keyTuple);

            $this->setAge();

            return $this->find($id);
        }

        public function delete($id)
        {
            $data = $this->store->hget('data', $id);
            $this->store->hdel('ids', $id);

            if ($data) {
                $this->delTuple($id);
                $data = $data;
                $this->store->hdel('data', $id);

                $this->depopulateFields($data, $id);

                $this->setAge();

                return true;
            }

            return false;
        }

        private function populateFields($data, $id)
        {
            $fields = array_keys($data);

            $this->store->del("fields.$id");
            $this->store->set("fields.$id", serialize($fields));

            foreach ($data as $k => $v) {
                $this->store->hdel('row.' . $id, $k);

                if (!is_array($v) && !is_object($v)) {
                    if (is_bool($v)) {
                        $v = $v ? 1 : 0;
                    }
                } else {
                    $v = (array) $v;

                    $v = serialize($v);
                }

                $this->store->hset('row.' . $id, $k, $v);
            }

            return $this;
        }

        private function depopulateFields($data, $id)
        {
            $this->store->del("fields.$id");
            $this->store->del('row.' . $id);

            return $this;
        }

        public function where($condition = [], $op = 'AND')
        {
            $check = isAke($this->wheres, sha1(serialize(func_get_args())), false);

            if (!$check) {
                if (!empty($condition)) {
                    if (!is_array($condition)) {
                        $condition  = str_replace(
                            [' LIKE START ', ' LIKE END ', ' NOT LIKE ', ' NOT IN '],
                            [' LIKESTART ', ' LIKEEND ', ' NOTLIKE ', ' NOTIN '],
                            $condition
                        );

                        if (fnmatch('* = *', $condition)) {
                            list($field, $value) = explode(' = ', $condition, 2);
                            $operand = '=';
                        } elseif (fnmatch('* < *', $condition)) {
                            list($field, $value) = explode(' < ', $condition, 2);
                            $operand = '<';
                        } elseif (fnmatch('* > *', $condition)) {
                            list($field, $value) = explode(' > ', $condition, 2);
                            $operand = '>';
                        } elseif (fnmatch('* <= *', $condition)) {
                            list($field, $value) = explode(' <= ', $condition, 2);
                            $operand = '<=';
                        } elseif (fnmatch('* >= *', $condition)) {
                            list($field, $value) = explode(' >= ', $condition, 2);
                            $operand = '>=';
                        } elseif (fnmatch('* LIKESTART *', $condition)) {
                            list($field, $value) = explode(' LIKESTART ', $condition, 2);
                            $operand = 'LIKESTART';
                        } elseif (fnmatch('* LIKEEND *', $condition)) {
                            list($field, $value) = explode(' LIKEEND ', $condition, 2);
                            $operand = 'LIKEEND';
                        } elseif (fnmatch('* NOTLIKE *', $condition)) {
                            list($field, $value) = explode(' NOTLIKE ', $condition, 2);
                            $operand = 'NOTLIKE';
                        } elseif (fnmatch('* LIKE *', $condition)) {
                            list($field, $value) = explode(' LIKE ', $condition, 2);
                            $operand = 'LIKE';
                        } elseif (fnmatch('* IN *', $condition)) {
                            list($field, $value) = explode(' IN ', $condition, 2);
                            $operand = 'IN';
                        } elseif (fnmatch('* NOTIN *', $condition)) {
                            list($field, $value) = explode(' NOTIN ', $condition, 2);
                            $operand = 'NOTIN';
                        } elseif (fnmatch('* != *', $condition)) {
                            list($field, $value) = explode(' != ', $condition, 2);
                            $operand = '!=';
                        } elseif (fnmatch('* <> *', $condition)) {
                            list($field, $value) = explode(' <> ', $condition, 2);
                            $operand = '<>';
                        }

                        $condition = [$field, $operand, $value];
                    }

                    if (strtoupper($op) == 'AND') {
                        $op = '&&';
                    } elseif (strtoupper($op) == 'OR') {
                        $op = '||';
                    } elseif (strtoupper($op) == 'XOR') {
                        $op = '|';
                    }

                    $this->wheres[sha1(serialize(func_get_args()))] = [$condition, $op];
                }
            }

            return $this;
        }

        public function order($fieldOrder, $orderDirection = 'ASC')
        {
            $this->orders[$fieldOrder] = $orderDirection;

            return $this;
        }

        public function select($what)
        {
            /* polymorphism */
            if (is_string($what)) {
                if (fnmatch('*,*', $what)) {
                    $what = str_replace(' ', '', $what);
                    $what = explode(',', $what);
                }
            }

            if (is_array($what)) {
                foreach ($what as $seg) {
                    if (!in_array($seg, $this->selects)) {
                        $this->selects[] = $seg;
                    }
                }
            } else {
                if (!in_array($what, $this->selects)) {
                    $this->selects[] = $what;
                }
            }

            return $this;
        }

        public function get()
        {
            return new Cursor($this);
        }

        public function limit($limit, $offset = 0)
        {
            if (null !== $limit) {
                if (!is_numeric($limit) || $limit != (int) $limit) {
                    throw new \InvalidArgumentException('The limit is not valid.');
                }

                $limit = (int) $limit;
            }

            if (null !== $offset) {
                if (!is_numeric($offset) || $offset != (int) $offset) {
                    throw new \InvalidArgumentException('The offset is not valid.');
                }

                $offset = (int) $offset;
            }

            $this->limit    = $limit;
            $this->offset   = $offset;

            return $this;
        }

        public function offset($offset = 0)
        {
            if (null !== $offset) {
                if (!is_numeric($offset) || $offset != (int) $offset) {
                    throw new \InvalidArgumentException('The offset is not valid.');
                }

                $offset = (int) $offset;
            }

            $this->offset = $offset;

            return $this;
        }

        public function findFirstBy($field, $value, $object = false)
        {
            return $this->where([$field, '=', $value])->get()->first($object);
        }

        public function findAndModify($where, array $update)
        {
            unset($update['id']);
            $where = is_numeric($where) ? ['id', '=', $where] : $where;

            $cursor = $this->where($where)->get();

            $collection = [];

            if ($cursor->count() > 0) {
                while ($row = $cursor->fetch()) {
                    $id = isAke($row, 'id', 0);

                    if ($id > 0) {
                        $data = array_merge($row, $update);
                        $this->model($data)->save();
                        array_push($collection, $data);
                    }
                }
            }

            return $collection;
        }

        public function refresh()
        {
            return $this->setAge();
        }

        public function flush()
        {
            $cursor = $this->get();

            if ($cursor->count() > 0) {
                while ($row = $cursor->model()) {
                    $row->delete();
                }
            }

            return $this->setAge();
        }

        public function drop()
        {
            $keys = $this->store->keys('*');

            foreach ($keys as $key) {
                $this->store->del($key);
            }

            return $this;
        }

        public function fieldsRow()
        {
            $first  = with(new self($this->db, $this->table))->get()->first();

            if ($first) {
                $fields = array_keys($first);

                unset($fields['id']);
                unset($fields['created_at']);
                unset($fields['updated_at']);
                unset($fields['deleted_at']);

                return $fields;
            } else {
                return [];
            }
        }

        public function timestamp($date)
        {
            return ts($date);
        }

        public function __toString()
        {
            return "$this->db::$this->table";
        }

        public function toObjects(array $rows)
        {
            $collection = [];

            if (!empty($rows)) {
                foreach ($rows as $row) {
                    $collection[] = $this->model($row);
                }
            }

            return $collection;
        }

        private function getTime()
        {
            $time = microtime();
            $time = explode(' ', $time, 2);

            return Arrays::last($time) + current($time);
        }

        public function lock($action = 'write')
        {
            if (true === $this->cacheEnabled) {
                $key = "lock.$action";

                $this->store->set($key, time());
            }

            return $this;
        }

        public function unlock($action = 'write')
        {
            if (true === $this->cacheEnabled) {
                $key = "lock.$action";

                $this->store->del($key);
            }

            return $this;
        }

        public function freeze()
        {
            return $this->lock('read')->lock('write');
        }

        public function unfreeze()
        {
            return $this->unlock('read')->unlock('write');
        }

        public static function __callStatic($fn, $args)
        {
            $method     = Inflector::uncamelize($fn);
            $tab        = explode('_', $method);
            $table      = array_shift($tab);
            $function   = implode('_', $tab);
            $function   = lcfirst(Inflector::camelize($function));
            $instance   = self::instance(SITE_NAME, $table);

            return call_user_func_array([$instance, $function], $args);
        }

        public function post($save = false)
        {
            return !$save ? $this->create($_POST) : $this->create($_POST)->save();
        }

        public function in($ids, $field = null, $op = 'AND', $results = [])
        {
            /* polymorphism */
            $ids = !is_array($ids)
            ? strstr($ids, ',')
                ? explode(',', str_replace(' ', '', $ids))
                : [$ids]
            : $ids;

            $field = is_null($field) ? 'id' : $field;

            return $this->where([$field, 'IN', implode(',', $ids)], $op, $results);
        }

        public function notIn($ids, $field = null, $op = 'AND', $results = [])
        {
            /* polymorphism */
            $ids = !is_array($ids)
            ? strstr($ids, ',')
                ? explode(',', str_replace(' ', '', $ids))
                : [$ids]
            : $ids;

            $field = is_null($field) ? 'id' : $field;

            return $this->where([$field, 'NOT IN', implode(',', $ids)], $op, $results);
        }

        public function like($field, $str, $op = 'AND', $results = [])
        {
            return $this->where([$field, 'LIKE', $str], $op, $results);
        }

        public function likeStart($field, $str, $op = 'AND', $results = [])
        {
            return $this->where([$field, 'LIKE', $str . '%'], $op, $results);
        }

        public function startsWith($field, $str, $op = 'AND', $results = [])
        {
            return $this->where([$field, 'LIKE', $str . '%'], $op, $results);
        }

        public function endWith($field, $str, $op = 'AND', $results = [])
        {
            return $this->where([$field, 'LIKE', '%' . $str], $op, $results);
        }

        public function likeEnd($field, $str, $op = 'AND', $results = [])
        {
            return $this->where([$field, 'LIKE', '%' . $str], $op, $results);
        }

        public function notLike($field, $str, $op = 'AND', $results = [])
        {
            return $this->where([$field, 'NOT LIKE', $str], $op, $results);
        }

        public function pk()
        {
            return 'id';
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
            $cursor = $this->where([$field, '=', $value])->get();

            if ($cursor->count() > 0 && true === $one) {
                return $object ? $this->model(current($res)) : current($res);
            }

            if ($cursor->count() < 1 && true === $one && true === $object) {
                return null;
            }

            return true === $object ? lib('collection', [$res]) : $res;
        }

        public function first($object = false)
        {
            return $this->get()->first($object);
        }

        public function last($object = false)
        {
            return $this->get()->last($object);
        }

        public function count()
        {
            return $this->get()->count();
        }

        public function only($field, $default = null)
        {
            $row = $this->first(true);

            return $row ? $row->$field : $default;
        }

        public function replace($compare = [], $update = [])
        {
            $instance = $this->firstOrCreate($compare);

            return $instance->hydrate($update)->save();
        }

        public function copy($where, array $newArgs)
        {
            $db     = self::instance($this->db, $this->table);
            $cursor = $db->query($where)->get();

            if ($cursor->count() > 0) {
                while ($row = $cursor->fetch()) {
                    unset($row['id']);
                    unset($row['created_at']);
                    unset($row['updated_at']);

                    $db->create(
                        array_merge(
                            $row,
                            $newArgs
                        )
                    )->save();
                }
            }

            return $this;
        }

        public function firstOrNew($tab = [])
        {
            return $this->firstOrCreate($tab, false);
        }

        public function firstOrCreate($tab = [], $save = true)
        {
            if (!empty($tab)) {
                foreach ($tab as $key => $value) {
                    $this->where([$key, '=', $value]);
                }

                $first = $this->cursor()->first(true);

                if ($first) {
                    return $first;
                }
            }

            $item = $this->create($tab);

            return false === $save ? $item : $item->save();
        }

        public function between($field, $min, $max)
        {
            return $this->where([$field, '>=', $min])->where([$field, '<=', $max]);

            return $this;
        }

        public function rand()
        {
            return $this->get()->rand();
        }

        public function random()
        {
            return $this->get()->rand();
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
    }
