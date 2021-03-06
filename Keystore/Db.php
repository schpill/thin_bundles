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

    namespace Keystore;

    use Thin\Alias;
    use Thin\Arrays;
    use Thin\Utils;
    use Thin\File;
    use Thin\Exception;
    use Thin\Instance;
    use Thin\Inflector;

    class Db
    {
        public $db, $table, $collection, $limit, $offset, $token;
        public $wheres          = [];
        public $selects         = [];
        public $orders          = [];
        public $groupBys        = [];
        public $joinTables      = [];
        public $results         = [];
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
            $this->token        = sha1($this->collection . uniqid());

            $this->getAge();
        }

        public static function instance($db, $table)
        {
            $args   = func_get_args();
            $key    = sha1(serialize($args));
            $has    = Instance::has('FastDb', $key);

            if (true === $has) {
                return Instance::get('FastDb', $key);
            } else {
                return Instance::make('FastDb', $key, new self($db, $table));
            }
        }

        public function create($data = [])
        {
            return $this->model($data);
        }

        public function model($data = [])
        {
            $db     = $this->db;
            $table  = $this->table;

            $modelFile = APPLICATION_PATH . DS . 'models' . DS . 'Keystore' . DS . 'models' . DS . Inflector::lower($db) . DS . ucfirst(Inflector::lower($table)) . '.php';

            if (!is_dir(APPLICATION_PATH . DS . 'models' . DS . 'Keystore')) {
                File::mkdir(APPLICATION_PATH . DS . 'models' . DS . 'Keystore');
            }

            if (!is_dir(APPLICATION_PATH . DS . 'models' . DS . 'Keystore' . DS . 'models')) {
                File::mkdir(APPLICATION_PATH . DS . 'models' . DS . 'Keystore' . DS . 'models');
            }

            if (!is_dir(APPLICATION_PATH . DS . 'models' . DS . 'Keystore' . DS . 'models' . DS . Inflector::lower($db))) {
                File::mkdir(APPLICATION_PATH . DS . 'models' . DS . 'Keystore' . DS . 'models' . DS . Inflector::lower($db));
            }

            if (!File::exists($modelFile)) {
                File::put($modelFile, str_replace('##class##', ucfirst(Inflector::lower($db)) . ucfirst(Inflector::lower($table)) . 'ModelKeystore', File::read(__DIR__ . DS . 'dbModel.tpl')));
            }

            $class = '\\Thin\\' . ucfirst(Inflector::lower($db)) . ucfirst(Inflector::lower($table)) . 'ModelKeystore';

            if (!class_exists($class)) {
                require_once $modelFile;
            }

            $model = $this;

            return new $class($model, $data);
        }

        public function __destruct()
        {
            $this->reset();
        }

        public function getAge()
        {
            $age = $this->retrieveAge();

            if (is_null($age)) {
                $age = strtotime('-1 day');
                $this->setAge($age);
            }

            return $age;
        }

        public function age()
        {
            return date('d/m/Y H:i:s', $this->getAge());
        }

        public function setAge($age = null)
        {
            $age = is_null($age) ? time() : $age;
            $this->addAge($age);

            return $this;
        }

        private function addAge($age)
        {
            $db = lib('mysql', 'kvages');

            $db->where('object_database', '=', $this->db)->where('object_table', '=', $this->table)->delete();
            $db->create([
                'object_database' => $this->db,
                'object_table' => $this->table,
                'object_age' => (int) $age
            ]);

            return $this;
        }

        private function retrieveAge()
        {
            $db = lib('mysql', 'kvages');

            $age = $db->where('object_database', '=', $this->db)->where('object_table', '=', $this->table)->first();

            if ($age) {
                return (int) $age->object_age;
            }

            return null;
        }

        public function permute($db, $table)
        {
            $this->db           = $db;
            $this->table        = $table;
            $this->collection   = "$db.$table";

            $this->getAge();

            return $this;
        }

        public function save(array $data, $checkTuple = true)
        {
            $id = isAke($data, 'id', false);

            return !$id ? $this->add($data, $checkTuple) : $this->edit($id, $data, $checkTuple);
        }

        private function insert(array $data)
        {
            $id = $data['id'];

            foreach ($data as $k => $v) {
                if ($k == 'id') {
                    continue;
                }

                $motor = 'string';

                if (fnmatch('*_id', $k)) {
                    $motor = 'int';
                    $v = (int) $v;
                } elseif (is_numeric($v)) {
                    if (fnmatch('*.*', $v) || fnmatch('*.*', $v)) {
                        $motor = 'float';
                        $v = (float) $v;
                    } else {
                        $motor = 'int';
                        $v = (int) $v;
                    }
                } else {
                    if (!is_string($v)) {
                        throw new Exception("An error occured to save this model. Please check the values.");
                    }

                    $length = Inflector::length($v);

                    $motor = 255 < $length ? 'text' : 'string';
                }

                $fieldRow = $motor . '_value';

                $row = lib('mysql', 'kvdatas')
                ->where('object_database', '=', $this->db)
                ->where('object_table', '=', $this->table)
                ->where('object_field', '=', $k)
                ->where('object_id', '=', $id)->first();

                if ($row) {
                    $row->$fieldRow = $v;
                    $row->save();
                } else {
                    lib('mysql', 'kvdatas')->create([
                        'object_database'   => $this->db,
                        'object_table'      => $this->table,
                        'object_field'      => $k,
                        'object_id'         => $id,
                        $fieldRow           => $v
                    ]);
                }
            }
        }

        public function getData($id, $field = null)
        {
            $item = [];
            $item['id'] = $id;

            $rows = lib('mysql', 'kvdatas')
            ->where('object_database', '=', $this->db)
            ->where('object_table', '=', $this->table)
            ->where('object_id', '=', $id)->get();

            if (empty($rows)) {
                return [];
            }

            foreach ($rows as $row) {
                $value = null;

                if ($row->int_value) {
                    $value = (int) $row->int_value;
                } elseif ($row->float_value) {
                    $value = (float) $row->float_value;
                } elseif ($row->string_value) {
                    $value = (string) $row->string_value;
                } elseif ($row->text_value) {
                    $value = (string) $row->text_value;
                }

                if (!empty($field)) {
                    if ($field == $row->object_field) {
                        return $value;
                    }
                }

                $item[$row->object_field] = $value;
            }

            return $item;
        }


        public function delete($id)
        {
            $datas = $this->getData($id);

            $intRows = lib('mysql', 'kvdatas')
            ->where('object_database', '=', $this->db)
            ->where('object_table', '=', $this->table)
            ->where('object_id', '=', $id)->delete();

            unset($datas['id']);
            unset($datas['created_at']);
            unset($datas['updated_at']);
            unset($datas['deleted_at']);

            $keyTuple = sha1($this->db . $this->table . serialize($datas));

            $this->delTuple($keyTuple);

            $this->setAge();

            return true;
        }

        public function find($id, $object = true)
        {
            if (!is_numeric($id)) {
                return null;
            }

            $datas = $this->getData($id);

            $created = isAke($datas, 'created_at', false);

            if (false === $created) {
                return null;
            }

            return true === $object ? $this->model($datas) : $datas;
        }

        public function where($condition = [], $op = 'AND')
        {
            $rows = [];

            $check = isAke($this->wheres, sha1(serialize(func_get_args())), false);

            if (!$check) {
                if (!empty($condition)) {
                    $rows = [];

                    if (!Arrays::is($condition)) {
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
                    } else {
                        list($field, $operand, $value) = $condition;
                    }

                    $this->wheres[sha1(serialize(func_get_args()))] = [$condition, $op]; return $this;

                    if (empty($rows)) {
                        if (fnmatch('*_id', $field) || $field == 'created_at' || $field == 'updated_at' || $field == 'deleted_at' || $field == 'id' || $operand == 'IN' || $operand == 'NOT IN') {
                            $motor = 'int';
                        } elseif($field == 'description') {
                            $motor = 'text';
                        } else {
                            $model = $this->model();
                            $motor = isAke($model->_fields, $field, 'string');
                        }

                        if ($operand = 'IN') {
                            $dbrows = $this->motor($motor)
                            ->where('object_database', '=', $this->db)
                            ->where('object_table', '=', $this->table)
                            ->where('object_field', '=', $field)
                            ->whereIn('object_value', $value)
                            ->get();
                        } elseif ($operand = 'NOT IN') {
                            $dbrows = $this->motor($motor)
                            ->where('object_database', '=', $this->db)
                            ->where('object_table', '=', $this->table)
                            ->where('object_field', '=', $field)
                            ->whereNotIn('object_value', $value)
                            ->get();
                        } else {
                            $dbrows = $this->motor($motor)
                            ->where('object_database', '=', $this->db)
                            ->where('object_table', '=', $this->table)
                            ->where('object_field', '=', $field)
                            ->where('object_value', $operand, $value)
                            ->get();
                        }

                        foreach ($dbrows as $dbrow) {
                            $rows[$dbrow->object_id] = $dbrow->object_value;
                        }
                    }

                    if (empty($this->wheres)) {
                        $this->results = $rows;
                    } else {
                        if (strtoupper($op) == 'AND') {
                            $this->results = $this->intersect($rows, $this->results);
                        } elseif (strtoupper($op) == 'OR') {
                            $this->results = $this->merge($this->results, $rows);
                        }
                    }

                    $this->wheres[sha1(serialize(func_get_args()))] = true;
                }
            }

            return $this;
        }

        private function merge($tab1, $tab2)
        {
            $ids = [];
            $collection = [];

            foreach ($tab1 as $id => $row) {
                if (strlen($id) && !Arrays::in($id, $ids)) {
                    $collection[$id] = $row;
                    array_push($ids, $id);
                }
            }

            foreach ($tab2 as $id => $row) {
                if (strlen($id) && !Arrays::in($id, $ids)) {
                    $collection[$id] = $row;
                    array_push($ids, $id);
                }
            }

            return $collection;
        }

        private function intersect($tab1, $tab2)
        {
            $ids1       = [];
            $ids2       = [];
            $collection = [];

            foreach ($tab1 as $id => $row) {
                if (strlen($id)) {
                    array_push($ids1, $id);
                }
            }

            foreach ($tab2 as $id => $row) {
                if (strlen($id)) {
                    array_push($ids2, $id);
                }
            }

            $sect = array_intersect($ids1, $ids2);

            if (!empty($sect)) {
                foreach ($sect as $idRow) {
                    $collection[$idRow] = $tab1[$idRow];
                }
            }

            return $collection;
        }

        private function compare($comp, $op, $value)
        {
            $res = false;

            if (strlen($comp) && strlen($op) && strlen($value)) {
                $comp   = Inflector::lower(Inflector::unaccent($comp));
                $value  = Inflector::lower(Inflector::unaccent($value));

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
                        $res        = Arrays::in($comp, $tabValues);

                        break;

                    case 'NOTIN':
                        $value      = str_replace('(', '', $value);
                        $value      = str_replace(')', '', $value);
                        $tabValues  = explode(',', $value);
                        $res        = !Arrays::in($comp, $tabValues);

                        break;
                }
            }

            return $res;
        }

        private function add(array $data, $checkTuple = true)
        {
            if ($checkTuple) {
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
            }

            $id = $this->makeId();

            $data['id'] = $id;

            if (!isset($data['created_at'])) {
                $data['created_at'] = time();
            }

            if (!isset($data['updated_at'])) {
                $data['updated_at'] = time();
            }

            $this->insert($data);

            if ($checkTuple) {
                $this->addTuple($id, $keyTuple);
            }

            $this->setAge();

            return $this->model($data);
        }

        public function bulk(array $datas, $checkTuple = false)
        {
            foreach ($datas as $data) {
                $this->save($data, $checkTuple);
            }

            return $this;
        }

        private function edit($id, array $data, $checkTuple = true)
        {
            if ($checkTuple) {
                $keep = $data;

                unset($data['id']);
                unset($data['created_at']);
                unset($data['updated_at']);
                unset($data['deleted_at']);

                $keyTuple = sha1($this->db . $this->table . serialize($data));

                $tuple = $this->tuple($keyTuple);

                if ($tuple) {
                    $o = $this->find($tuple);

                    if ($o) {
                        return $o;
                    }
                }

                $data = $keep;
                unset($keep);
            }

            $this->delete($id);
            $this->insert($data);

            $this->setAge();

            return $this->find($id);
        }

        public function count()
        {
            if (empty($this->wheres)) {
                return (int) lib('mysql', 'kvdatas')
                ->where('object_database', '=', $this->db)
                ->where('object_table', '=', $this->table)
                ->where('object_field', '=', 'created_at')
                ->count();
            }

            $res = $this->exec();
            $totalResults = $this->totalResults;

            $this->reset();

            return $totalResults;
        }

        public function post($save = false)
        {
            return !$save ? $this->create($_POST) : $this->create($_POST)->save();
        }

        public function order($fieldOrder, $orderDirection = 'ASC')
        {
            $this->orders[$fieldOrder] = $orderDirection;

            return $this;
        }

        public function andWhere($condition)
        {
            return $this->where($condition, 'AND');
        }

        public function orWhere($condition)
        {
            return $this->where($condition, 'OR');
        }

        public function xorWhere($condition)
        {
            return $this->where($condition, 'XOR');
        }

        public function _and($condition)
        {
            return $this->where($condition, 'AND');
        }

        public function _or($condition)
        {
            return $this->where($condition, 'OR');
        }

        public function _xor($condition)
        {
            return $this->where($condition, 'XOR');
        }

        public function whereAnd($condition)
        {
            return $this->where($condition, 'AND');
        }

        public function whereOr($condition)
        {
            return $this->where($condition, 'OR');
        }

        public function whereXor($condition)
        {
            return $this->where($condition, 'XOR');
        }

        public function in($ids, $field = null, $op = 'AND')
        {
            /* polymorphism */
            $ids = !Arrays::is($ids)
            ? strstr($ids, ',')
                ? explode(',', str_replace(' ', '', $ids))
                : [$ids]
            : $ids;

            $field = is_null($field) ? 'id' : $field;

            return $this->where([$field, 'IN', implode(',', $ids)], $op);
        }

        public function notIn($ids, $field = null, $op = 'AND')
        {
            /* polymorphism */
            $ids = !Arrays::is($ids)
            ? strstr($ids, ',')
                ? explode(',', str_replace(' ', '', $ids))
                : [$ids]
            : $ids;

            $field = is_null($field) ? 'id' : $field;

            return $this->where([$field, 'NOT IN', implode(',', $ids)], $op);
        }

        public function like($field, $str, $op = 'AND')
        {
            return $this->where([$field, 'LIKE', $str], $op);
        }

        public function likeStart($field, $str, $op = 'AND')
        {
            return $this->where([$field, 'LIKE', $str . '%'], $op);
        }

        public function startsWith($field, $str, $op = 'AND')
        {
            return $this->where([$field, 'LIKE', $str . '%'], $op);
        }

        public function endWith($field, $str, $op = 'AND')
        {
            return $this->where([$field, 'LIKE', '%' . $str], $op);
        }

        public function likeEnd($field, $str, $op = 'AND')
        {
            return $this->where([$field, 'LIKE', '%' . $str], $op);
        }

        public function notLike($field, $str, $op = 'AND')
        {
            return $this->where([$field, 'NOT LIKE', $str], $op);
        }

        public function custom(Closure $condition, $op = 'AND')
        {
            return $this->trick($condition, $op);
        }

        public function between($field, $min, $max)
        {
            return $this->where([$field, '>=', $min])->where([$field, '<=', $max]);

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

                $first = $this->first(true);

                if (!is_null($first)) {
                    return $first;
                }
            }

            $item = $this->create($tab);

            return false === $save ? $item : $item->save();
        }

        public function replace($compare = [], $update = [])
        {
            $instance = $this->firstOrCreate($compare);

            return $instance->hydrate($update)->save();
        }

        /* Ex $db->copy('language = en', ['language' => 'es']); */

        public function copy($where, array $newArgs)
        {
            $db     = self::instance($this->db, $this->table);
            $rows   = $db->query($where)->exec();

            if (!empty($rows)) {
                foreach ($rows as $row) {
                    unset($row['id']);
                    unset($row['created_at']);
                    unset($row['updated_at']);

                    $db->create(array_merge($row, $newArgs))->save();
                }
            }

            return $this;
        }

        public function only($field)
        {
            $row = $this->first(true);

            return $row instanceof Model ? $row->$field : null;
        }

        public function one($object = false, $reset = true)
        {
            return $this->first($object, $reset);
        }

        public function first($object = false, $reset = true)
        {
            $res = $this->exec(false, true);

            if (true === $reset) {
                $this->reset(__function__);
            }

            if (true === $object) {
                return !empty($res) ? $this->model($res) : null;
            } else {
                return !empty($res) ? $res : [];
            }
        }

        public function last($object = false, $reset = true)
        {
            $res = empty($res) ? $this->exec() : $res;

            if (true === $reset) {
                $this->reset(__function__);
            }

            if (true === $object) {
                return !empty($res) ? $this->model(Arrays::last($res)) : null;
            } else {
                return !empty($res) ? Arrays::last($res) : [];
            }
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
            $res = $this->where([$field, '=', $value])->exec();

            if (!empty($res) && true === $one) {
                return $object ? $this->model(Arrays::first($res)) : Arrays::first($res);
            }

            if (!empty($res) && true === $one && true === $object) {
                return null;
            }

            return true === $object ? new Collection($res) : $res;
        }

        public function object()
        {
            return $this->first(true);
        }

        public function objects()
        {
            return $this->exec(true);
        }

        public function all($object = false)
        {
            $this->results = [];

            $ids = $this->motor()->hkeys($this->collection . '.datas');

            foreach ($ids as $id) {
                $this->results[] = ['id' => $id];
            }

            return $this->exec($object);
        }

        public function sort($collection, $fieldOrder, $orderDirection = 'ASC')
        {
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
                    usort($collection, $sortFunc($fieldOrder[$i], $orderDirection[$i]));
                }
            } else {
                usort($collection, $sortFunc($fieldOrder, $orderDirection));
            }

            return $collection;
        }

        public function groupBy($field)
        {
            $this->groupBys[] = $field;

            return $this;
        }

        public function group($collection, $field)
        {
            $groupBys   = [];
            $ever       = [];

            foreach ($collection as $row) {
                $obj = isAke($row, $field, null);

                if (!Arrays::in($obj, $ever)) {
                    $groupBys[] = $row;
                    $ever[]     = $obj;
                }
            }

            $collection = $groupBys;

            return $this->sort($collection, $field);
        }

        public function getHash($object = false, $first = false)
        {
            $object = !$object ? 'false' : 'true';
            $first  = !$first ? 'false' : 'true';

            return sha1(
                serialize($this->selects) .
                serialize($this->wheres) .
                serialize($this->orders) .
                serialize($this->groupBys) .
                $this->offset .
                $this->limit .
                $first .
                $object
            );
        }

        public function exec($object = false, $first = false)
        {
            $hash = $this->getHash($object, $first);

            if (true === $this->useCache && false === $object) {
                $keyData    = 'kvdb.exec.data.' . $this->collection . '.' . $hash;
                $keyAge     = 'kvdb.exec.age.' . $this->collection . '.' . $hash;
                $keyCount   = 'kvdb.exec.count.' . $this->collection . '.' . $hash;

                $ageDb      = $this->getAge();
                $ageQuery   = $this->cache()->get($keyAge);

                if (strlen($ageQuery)) {
                    if ($ageQuery > $ageDb) {
                        $collection     = $this->cache()->get($keyData);
                        $totalResults   = $this->cache()->get($keyCount);

                        $this->reset();

                        $this->totalResults = $totalResults;

                        return $object ? new Collection($collection) : $collection;
                    }
                }
            }

            $ids = [];

            if ($first) {
                $this->limit = 1;
            }

            if (empty($this->wheres)) {
                $res = lib('mysql', 'kvdatas')
                ->where('object_database', '=', $this->db)
                ->where('object_table', '=', $this->table)
                ->where('object_field', '=', 'created_at')->get();

                foreach ($res as $row) {
                    $ids[] = (int) $row->object_id;
                }
            } else {
                $start = true;

                foreach ($this->wheres as $wh) {
                    $tmpIds = [];

                    list($condition, $op) = $wh;
                    list($field, $operand, $value) = $condition;

                    if (fnmatch('*_id', $field) || $field == 'created_at' || $field == 'updated_at' || $field == 'deleted_at' || $field == 'id' || $operand == 'IN' || $operand == 'NOT IN') {
                        $motor = 'int';
                    } elseif ($field == 'description') {
                        $motor = 'text';
                    } else {
                        $model = $this->model();
                        $motor = isAke($model->_fields, $field, 'string');
                    }

                    $dataField = $motor . '_value';

                    if ($operand == 'IN') {
                        $res = lib('mysql', 'kvdatas')
                        ->where('object_database', '=', $this->db)
                        ->where('object_table', '=', $this->table)
                        ->whereIn($dataField, $value)->get();
                    } elseif ($operand == 'NOT IN') {
                        $res = lib('mysql', 'kvdatas')
                        ->where('object_database', '=', $this->db)
                        ->where('object_table', '=', $this->table)
                        ->whereNotIn($dataField, $value)->get();
                    } else {
                        $res = lib('mysql', 'kvdatas')
                        ->where('object_database', '=', $this->db)
                        ->where('object_table', '=', $this->table)
                        ->where($dataField, $operand, $value)->get();
                    }

                    foreach ($res as $row) {
                        $tmpIds[] = (int) $row->object_id;
                    }

                    if ($start) {
                        $ids = $tmpIds;
                    } else {
                        if ($op == 'AND') {
                            $ids = array_intersect($ids, $tmpIds);
                        } elseif ($op == 'OR') {
                            $ids = array_merge($ids, $tmpIds);
                        }
                    }

                    $start = false;
                }
            }

            if (!empty($this->orders)) {
                $tab = $tmpIds = [];

                foreach ($this->orders as $oField => $oDirection) {
                    if (fnmatch('*_id', $oField) || $oField == 'created_at' || $oField == 'updated_at' || $oField == 'deleted_at' || $oField == 'id') {
                        $motor = 'int';
                    } elseif ($field == 'description') {
                        $motor = 'text';
                    } else {
                        $model = $this->model();
                        $motor = isAke($model->_fields, $oField, 'string');
                    }

                    $dataField = $motor . '_value';

                    $res = lib('mysql', 'kvdatas')
                    ->where('object_database', '=', $this->db)
                    ->where('object_table', '=', $this->table)
                    ->where('object_field', '=', $oField)
                    ->whereIn('object_id', $ids)
                    ->orderBy($dataField, $oDirection)
                    ->get();

                    foreach ($res as $row) {
                        $tab[$row->object_id][$oField] = $row->$dataField;
                        $tab[$row->object_id]['id'] = $row->object_id;
                    }
                }

                if (empty($this->orders) > 1) {
                    $tab = $this->sort($tab, array_keys($this->orders), array_values($this->orders));
                }

                foreach ($tab as $row) {
                    $tmpIds[] = $row['id'];
                }

                $ids = $tmpIds;
            } else {
                if (!empty($this->groupBys)) {
                    $tmpIds = [];

                    $groupBy = current($this->groupBys);

                    if (fnmatch('*_id', $groupBy) || $groupBy == 'created_at' || $groupBy == 'updated_at' || $groupBy == 'deleted_at' || $groupBy == 'id') {
                        $motor = 'int';
                    } elseif ($field == 'description') {
                        $motor = 'text';
                    } else {
                        $model = $this->model();
                        $motor = isAke($model->_fields, $groupBy, 'string');
                    }

                    $dataField = $motor . '_value';

                    $res = lib('mysql', 'kvdatas')
                    ->where('object_database', '=', $this->db)
                    ->where('object_table', '=', $this->table)
                    ->where('object_field', '=', $groupBy)
                    ->whereIn('object_id', $ids)
                    ->groupBy($dataField)
                    ->get();

                    foreach ($res as $row) {
                        $tmpIds[] = $row->object_id;
                    }

                    $ids = $tmpIds;
                }
            }

            $totalResults = count($ids);

            if (isset($this->limit)) {
                $ids = array_slice($ids, $this->offset, $this->limit);
            }

            $collection = $this->makeCollection($ids, $object);

            $this->reset();

            $this->totalResults = $totalResults;

            if (true === $this->useCache && false === $object) {
                $this->cache()->set($keyData, $collection);
                $this->cache()->set($keyCount, $totalResults);
                $this->cache()->set($keyAge, time());
            }

            return $object ? new Collection($collection) : $collection;
        }

        private function makeCollection($ids, $object = false)
        {
            $collection = [];

            foreach ($ids as $id) {
                $item = $this->getData($id);
                $collection[] = $object ? $this->model($item) : $item;
            }

            return $collection;
        }

        public function fetch($object = false)
        {
            return $this->all($object);
        }

        public function findAll($object = true)
        {
            return $this->all($object);
        }

        public function getAll($object = false)
        {
            return $this->all($object);
        }

        public function full()
        {
            return $this;
        }

        public function run($object = false)
        {
            return $this->exec($object);
        }

        public function get($object = false)
        {
            return $this->exec($object);
        }

        public function execute($object = false)
        {
            return $this->exec($object);
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

        public function reset($f = null)
        {
            $this->totalResults = 0;
            $this->selects      = [];
            $this->joinTables   = [];
            $this->wheres       = [];
            $this->groupBys     = [];
            $this->orders       = [];
            $this->results      = [];

            return $this;
        }

        public function update($query, array $data)
        {
            $rows = $this->query($query)->exec(true);

            foreach ($rows as $row) {
                foreach ($data as $k => $v) {
                    if ($k != 'id') {
                        $row->$k = $v;
                        $row->save();
                    }
                }
            }

            return $this;
        }

        public function remove($query)
        {
            $rows = $this->query($query)->exec(true);

            if (!empty($rows)) {
                $rows->delete();
            }

            return $this;
        }

        public function pk()
        {
            return 'id';
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

        private function tuple($key)
        {
            $keytuple = lib('mysql', 'kvtuples')
            ->where('keytuple', '=', $key)
            ->first();

            if ($keytuple) {
                return (int) $keytuple->keyid;
            }

            return null;
        }

        private function addTuple($id, $key)
        {
            $db = lib('mysql', 'kvtuples');

            $db->where('keytuple', '=', $key)->delete();

            $db->firstOrCreate([
                'keytuple' => $key,
                'keyid' => (int) $id
            ]);

            return $this;
        }

        private function delTuple($key)
        {
            return lib('mysql', 'kvtuples')
            ->where('keytuple', '=', $key)
            ->delete();
        }

        private function makeId()
        {
            $db = lib('mysql', 'kvids');

            $id = $db
            ->where('object_database', '=', $this->db)
            ->where('object_table', '=', $this->table)
            ->first();

            if (!$id) {
                $db->create([
                    'object_database' => $this->db,
                    'object_table' => $this->table,
                    'object_id' => 1
                ]);

                return 1;
            }

            $val = (int) $id->object_id;

            $val += 1;

            $id->object_id = (int) $val;

            $id->save();

            return $val;
        }

        public function motor($type = 'string')
        {
            $dbTable = str_replace('##type##', $type, 'kv##type##values');

            return lib('mysql', $dbTable);
        }

        public function cache()
        {
            return new Caching($this->collection);
        }

        public function has($foreign, $condition = null)
        {
            if (empty($condition)) {
                return $this->where([$foreign . '_id', '>', 0]);
            } else {
                $ids = [];

                $db = Db::instance($this->db, $foreign);

                $group = $db->group($this->table . '_id');

                if (!empty($group)) {
                    list($op, $num) = $condition;

                    foreach ($group as $row) {
                        $total = (int) $row['total'];

                        $check = $this->compare((int) $total, $op, $num);

                        if ($check) {
                            array_push($ids, (int) $row['value']);
                        }
                    }
                }

                if (!empty($ids)) {
                    return $this->where(['id', 'IN', implode(',', $ids)]);
                }

                return $this->where(['id', '<', 0]);
            }
        }

        public function with($what, $object = false)
        {
            $collection = $ids = $foreigns = $foreignsCo = [];

            if (is_string($what)) {
                if (fnmatch('*,*', $what)) {
                    $what = str_replace(' ', '', $what);
                    $what = explode(',', $what);
                }

                $res = $this->exec($object);
            } elseif (Arrays::is($what)) {
                foreach ($what as $key => $closure) {
                    $what = $key;

                    break;
                }

                if (fnmatch('*,*', $what)) {
                    $what = str_replace(' ', '', $what);
                    $what = explode(',', $what);
                }

                $db     = $this;
                call_user_func_array($closure, [$db]);
                $res    = $db->exec($object);
            }

            if (!empty($res)) {
                foreach ($res as $r) {
                    if (is_object($r)) {
                        $row = $r->assoc();
                    } else {
                        $row = $r;
                    }

                    if (is_string($what)) {
                        $value = isAke($row, $what . '_id', false);

                        if (false !== $value) {
                            if (!Arrays::in($value, $ids)) {
                                array_push($ids, $value);
                            }
                        }
                    } elseif (Arrays::is($what)) {
                        foreach ($what as $fk) {
                            if (!isset($ids[$fk])) {
                                $ids[$fk] = [];
                            }

                            $value = isAke($row, $fk . '_id', false);

                            if (false !== $value) {
                                if (!Arrays::in($value, $ids[$fk])) {
                                    array_push($ids[$fk], $value);
                                }
                            }
                        }
                    }
                }

                if (!empty($ids)) {
                    if (is_string($what)) {
                        $db = Db::instance($this->db, $what);

                        $foreigns = $db->where(['id', 'IN', implode(',', $ids)])->exec($object);

                        if (!empty($foreigns)) {
                            foreach ($foreigns as $foreign) {
                                $id = $object ? $foreign->id : $foreign['id'];
                                $foreignsCo[$id] = $foreign;
                            }
                        }
                    } elseif (Arrays::is($what)) {
                        foreach ($what as $fk) {
                            $idsFk = $ids[$fk];

                            $db = Db::instance($this->db, $fk);

                            $foreigns = $db->where(['id', 'IN', implode(',', $idsFk)])->exec($object);

                            if (!empty($foreigns)) {
                                foreach ($foreigns as $foreign) {
                                    $id = $object ? $foreign->id : $foreign['id'];
                                    $foreignsCo[$fk][$id] = $foreign;
                                }
                            }
                        }
                    }

                    if (!empty($foreignsCo)) {
                        if (is_string($what)) {
                            $whatId = $what . '_id';

                            foreach ($res as $r) {
                                if (is_object($r)) {
                                    $r->$what = $foreignsCo[$r->$whatId];
                                } else {
                                    $r[$what] = $foreignsCo[$r[$whatId]];
                                }

                                array_push($collection, $r);
                            }
                        } elseif (Arrays::is($what)) {
                            foreach ($res as $r) {
                                foreach ($what as $fk) {
                                    $fkId = $fk . '_id';

                                    if (is_object($r)) {
                                        $r->$fk = $foreignsCo[$fk][$r->$fkId];
                                    } else {
                                        $r[$fk] = $foreignsCo[$fk][$r[$fkId]];
                                    }
                                }

                                array_push($collection, $r);
                            }
                        }
                    }
                }
            }

            return $collection;
        }

        public function log($ns = null)
        {
            return $ns ? Log::instance($this->collection . '.' . $ns) : Log::instance($this->collection);
        }

        public function findFirstBy($field, $value, $object = false)
        {
            return $this->where([$field, '=', $value])->first($object);
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
                    array_push($collection, $this->model($row));
                }
            }

            return $collection;
        }

        public function __set($key, $value)
        {
            $this->$key = $value;
        }

        public function __get($key)
        {
            return isset($this->$key) ? $this->$key : null;
        }

        public function __isset($key)
        {
            return isset($this->$key);
        }

        public function __unset($key)
        {
            unset($this->$key);
        }

        public function __call($fn, $args)
        {
            $method = substr($fn, 0, strlen('findLastBy'));
            $object = Inflector::uncamelize(lcfirst(substr($fn, strlen('findLastBy'))));

            if (strlen($fn) > strlen('findLastBy')) {
                if ('findLastBy' == $method) {
                    $obj = count($args) == 2 ? $args[1] : true;

                    if (!is_bool($obj)) {
                        $obj = true;
                    }

                    return $this->where([$object, '=', Arrays::first($args)])->last($obj);
                }
            }

            $method = substr($fn, 0, strlen('findFirstBy'));
            $object = Inflector::uncamelize(lcfirst(substr($fn, strlen('findFirstBy'))));

            if (strlen($fn) > strlen('findFirstBy')) {
                if ('findFirstBy' == $method) {
                    $obj = count($args) == 2 ? $args[1] : true;

                    if (!is_bool($obj)) {
                        $obj = true;
                    }

                    return $this->findFirstBy($object, Arrays::first($args), $obj);
                }
            }

            $method = substr($fn, 0, strlen('findOneBy'));
            $object = Inflector::uncamelize(lcfirst(substr($fn, strlen('findOneBy'))));

            if (strlen($fn) > strlen('findOneBy')) {
                if ('findOneBy' == $method) {
                    $obj = count($args) == 2 ? $args[1] : false;

                    if (!is_bool($obj)) {
                        $obj = false;
                    }

                    return $this->findOneBy($object, Arrays::first($args), $obj);
                }
            }

            $method = substr($fn, 0, strlen('orderBy'));
            $object = Inflector::uncamelize(lcfirst(substr($fn, strlen('orderBy'))));

            if (strlen($fn) > strlen('orderBy')) {
                if ('orderBy' == $method) {
                    $fields = $this->fieldsRow();

                    if (!Arrays::in($object, $fields) && 'id' != $object) {
                        $object = Arrays::in($object . '_id', $fields) ? $object . '_id' : $object;
                    }

                    $direction = !empty($args) ? Arrays::first($args) : 'ASC';

                    return $this->order($object, $direction);
                } elseif ('groupBy' == $method) {
                    $fields = $this->fieldsRow();

                    if (!Arrays::in($object, $fields)) {
                        $object = Arrays::in($object . '_id', $fields) ? $object . '_id' : $object;
                    }

                    return $this->groupBy($object);
                }
            }

            $method = substr($fn, 0, strlen('where'));
            $object = Inflector::uncamelize(lcfirst(substr($fn, strlen('where'))));

            if (strlen($fn) > strlen('where')) {
                if ('where' == $method) {
                    return $this->where([$object, '=', Arrays::first($args)]);
                }
            }

            $method = substr($fn, 0, strlen('sortBy'));
            $object = Inflector::uncamelize(lcfirst(substr($fn, strlen('sortBy'))));

            if (strlen($fn) > strlen('sortBy')) {
                if ('sortBy' == $method) {
                    $fields = $this->fieldsRow();

                    if (!Arrays::in($object, $fields) && 'id' != $object) {
                        $object = Arrays::in($object . '_id', $fields) ? $object . '_id' : $object;
                    }

                    $direction = !empty($args) ? Arrays::first($args) : 'ASC';

                    return $this->order($object, $direction);
                } elseif ('findBy' == $method) {
                    $obj = count($args) == 2 ? $args[1] : false;

                    if (!is_bool($obj)) {
                        $obj = false;
                    }

                    return $this->findBy($object, Arrays::first($args), false, $obj);
                }
            }

            $model = $this->model();
            $scope = lcfirst(Inflector::camelize('scope_' . Inflector::uncamelize($fn)));

            if (method_exists($model, $scope)) {
                return call_user_func_array([$model, $scope], $args);
            }

            throw new Exception("Method '$fn' is unknown.");
        }

        private function facade()
        {
            $facade     = ucfirst($this->db) . ucfirst($this->table);
            $facade2    = false;

            if ($this->db == SITE_NAME) {
                $facade2 = ucfirst($this->table);
            }

            $class = '\\Keystore\\' . $facade;

            if (!class_exists($class)) {
                $code = 'namespace Keystore; class ' . $facade . ' extends Facade { public static $database = "' . $this->db . '"; public static $table = "' . $this->table . '"; }';

                eval($code);

                Alias::facade('Dbk' . $facade, $facade, 'Keystore');
            }

            if (false !== $facade2) {
                $class2 = '\\Keystore\\' . $facade2;

                if (!class_exists($class2)) {
                    $code2 = 'namespace Keystore; class ' . $facade2 . ' extends Facade { public static $database = "' . $this->db . '"; public static $table = "' . $this->table . '"; }';

                    eval($code2);

                    Alias::facade('Dbk' . $facade2, $facade2, 'Keystore');
                }
            }

            return $this;
        }

        public function fieldsRow()
        {
            $first  = Db::instance($this->db, $this->table)->first(true);

            if ($first) {
                $fields = array_keys($first->assoc());
                unset($fields['id']);
                unset($fields['created_at']);
                unset($fields['updated_at']);
                unset($fields['deleted_at']);

                return $fields;
            } else {
                return [];
            }
        }

        public function noCache()
        {
            return $this->inCache(false);
        }

        public function inCache($bool = true)
        {
            $this->useCache = $bool;

            return $this;
        }

        private function getTime()
        {
            $time = microtime();
            $time = explode(' ', $time, 2);

            return Arrays::last($time) + Arrays::first($time);
        }

        public function sum($field)
        {
            $hash = sha1(
                serialize($this->wheres) .
                $field
            );

            $keyData    = 'ksdb.sum.data.' . $this->collection . '.' . $hash;
            $keyAge     = 'ksdb.sum.age.' . $this->collection . '.' . $hash;

            $ageDb      = $this->getAge();
            $ageQuery   = $this->cache()->get($keyAge);

            if (true === $this->useCache) {
                if (strlen($ageQuery)) {
                    if ($ageQuery > $ageDb) {
                        return (int) $this->cache()->get($keyData);
                    }
                }
            }

            $res = $this->exec();
            $sum = 0;

            if (!empty($res)) {
                foreach ($res as $id => $tab) {
                    $val = isAke($tab, $field, 0);
                    $sum += $val;
                }
            }

            $this->reset();

            if (true === $this->useCache) {
                $this->cache()->set($keyData, (int) $sum);
                $this->cache()->set($keyAge, time());
            }

            return (int) $sum;
        }

        public function avg($field)
        {
            $res = $this->exec();

            if (empty($res)) {
                return 0;
            }

            return (float) $this->sum($field, $res) / count($res);
        }

        public function minimum($field, $object = false)
        {
            return $this->min($field, true, $object);
        }

        public function maximum($field, $object = false)
        {
            return $this->max($field, true, $object);
        }

        public function min($field, $returRow = false, $object = false)
        {
            $hash = sha1(
                serialize($this->wheres) .
                $field
            );

            $keyData    = 'ksdb.min.data.' . $this->collection . '.' . $hash;
            $keyAge     = 'ksdb.min.age.' . $this->collection . '.' . $hash;

            $ageDb      = $this->getAge();
            $ageQuery   = $this->cache()->get($keyAge);

            if (true === $this->useCache) {
                if (strlen($ageQuery)) {
                    if ($ageQuery > $ageDb) {
                        $cached = $this->cache()->get($keyData);
                        list($idRow, $min) = unserialize($cached);

                        if (!$returRow) {
                            return (int) $min;
                        } else {
                            $row = $this->find($idRow);

                            return $object ? $row : $row->assoc();
                        }
                    }
                }
            }

            $res    = $this->select($field)->exec();
            $min    = 0;
            $rowId  = 0;

            if (!empty($res)) {
                $first = true;

                foreach ($res as $tab) {
                    $val    = isAke($tab, $field, 0);
                    $idRow  = isAke($tab, 'id');

                    if (true === $first) {
                        $min    = $val;
                        $rowId  = $idRow;
                    } else {
                        $rowId  = $val < $min ? $idRow : $rowId;
                        $min    = $val < $min ? $val : $min;
                    }

                    $first = false;
                }
            }

            $this->reset();

            if (true === $this->useCache) {
                $this->cache()->set($keyData, serialize([$rowId, $min]));
                $this->cache()->set($keyAge, time());
            }

            if (!$returRow) {
                return (int) $min;
            } else {
                $row = $this->find($rowId);

                return $object ? $row : $row->assoc();
            }
        }

        public function max($field, $returRow = false, $object = false)
        {
            $hash = sha1(
                serialize($this->wheres) .
                $field
            );

            $keyData    = 'ksdb.max.data.' . $this->collection . '.' . $hash;
            $keyAge     = 'ksdb.max.age.' . $this->collection . '.' . $hash;

            $ageDb      = $this->getAge();
            $ageQuery   = $this->cache()->get($keyAge);

            if (true === $this->useCache) {
                if (strlen($ageQuery)) {
                    if ($ageQuery > $ageDb) {
                        $cached = $this->cache()->get($keyData);
                        list($idRow, $max) = unserialize($cached);

                        if (!$returRow) {
                            return (int) $max;
                        } else {
                            $row = $this->find($idRow);

                            return $object ? $row : $row->assoc();
                        }
                    }
                }
            }

            $res    = $this->select($field)->exec();
            $max    = 0;
            $rowId  = 0;

            if (!empty($res)) {
                $first = true;

                foreach ($res as $tab) {
                    $val = isAke($tab, $field, 0);
                    $idRow = isAke($tab, 'id');

                    if (true === $first) {
                        $max    = $val;
                        $rowId  = $idRow;
                    } else {
                        $rowId  = $val > $max ? $idRow : $rowId;
                        $max    = $val > $max ? $val : $max;
                    }

                    $first = false;
                }
            }

            $this->reset();

            if (true === $this->useCache) {
                $this->cache()->set($keyData, serialize([$rowId, $max]));
                $this->cache()->set($keyAge, time());
            }

            if (!$returRow) {
                return (int) $max;
            } else {
                $row = $this->find($rowId);

                return $object ? $row : $row->assoc();
            }
        }

        public function rand($object = false)
        {
            $res = $this->exec($object);

            shuffle($res);

            return $object ? new Collection($res) : $res;
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

            if (Arrays::is($what)) {
                foreach ($what as $seg) {
                    if (!Arrays::in($seg, $this->selects)) {
                        $this->selects[] = $seg;
                    }
                }
            } else {
                if (!Arrays::in($what, $this->selects)) {
                    $this->selects[] = $what;
                }
            }

            return $this;
        }

        public function multiQuery(array $queries)
        {
            foreach ($queries as $query) {
                switch (count($query)) {
                    case 4:
                        list($field, $op, $value, $operand) = $query;
                        break;
                    case 3:
                        list($field, $op, $value) = $query;
                        $operand = 'AND';
                        break;
                }

                $this->where([$field, $op, $value], $operand);
            }

            return $this;
        }
    }
