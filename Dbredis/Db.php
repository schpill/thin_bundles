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

    namespace Dbredis;

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
    use MongoBinData;
    use MongoClient as MGC;

    class Db
    {
        public $db, $table, $collection, $results, $cnx, $limit, $offset, $cacheClient;
        public $wheres          = [];
        public $selects         = [];
        public $orders          = [];
        public $groupBys        = [];
        public $joinTables      = [];
        public $totalResults    = 0;

        private $useCache = true;

        public static $cache = [];

        public function __construct($db, $table, $config = [])
        {
            $db                 = strtolower($db);
            $table              = strtolower($table);

            $this->db           = $db;
            $this->table        = $table;
            $this->collection   = "$db.$table";

            if (empty($config)) {
                $host               = Conf::get('mongo.host', '127.0.0.1');
                $port               = Conf::get('mongo.port', 27017);
                $protocol           = Conf::get('mongo.protocol', 'mongodb');
                $auth               = Conf::get('mongo.auth', true);

                if (true === $auth) {
                    $user           = Conf::get('mongo.username', SITE_NAME . '_master');
                    $password       = Conf::get('mongo.password');

                    $this->connect($protocol, $user, $password, $host, $port);
                } else {
                    $this->cnx      = new MGC($protocol . '://' . $host . ':' . $port, ['connect' => true]);
                }
            }

            $this->getAge();
            $this->model()->checkIndices();
        }

        private function connect($protocol, $user, $password, $host, $port, $incr = 0)
        {
            try {
                $this->cnx = new MGC($protocol . '://' . $user . ':' . $password . '@' . $host . ':' . $port, ['connect' => true]);
            } catch (\MongoConnectionException $e) {
                if (APPLICATION_ENV == 'production') {
                    $incr++;

                    if (20 < $incr) {
                        $this->connect($protocol, $user, $password, $host, $port, $incr);
                    } else {
                        dd($e->getMessage());
                    }
                } else {
                    $this->connect($protocol, $user, $password, $host, $port, $incr);
                }
            }
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
            $this->delAge();
            $this->addAge($age);

            return $this;
        }

        private function addAge($age)
        {
            $coll = $this->getCollection($this->db . '.ages');

            return $coll->insert([
                'table' => $this->table,
                'age' => $age
            ]);
        }

        private function delAge()
        {
            $coll = $this->getCollection($this->db . '.ages');

            return $coll->remove(
                ['table' => $this->table],
                ["justOne" => true]
            );
        }

        private function retrieveAge()
        {
            $coll   = $this->getCollection($this->db . '.ages');
            $row    = $coll->findOne([
                'table' => $this->table
            ]);

            if ($row) {
                return isAke($row, 'age', null);
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

        public static function instance($db, $table)
        {
            $args   = func_get_args();
            $key    = sha1(serialize($args));
            $has    = Instance::has('Dbredis', $key);

            if (true === $has) {
                return Instance::get('Dbredis', $key);
            } else {
                return Instance::make('Dbredis', $key, new self($db, $table));
            }
        }

        public function create($data = [])
        {
            if (fnmatch('view_*_*', $this->table)) {
                throw new Exception("It is forbidden to write in db from a view.");
            }

            return $this->model($data);
        }

        public function model($data = [])
        {
            $view = false;

            if (fnmatch('view_*_*', $this->table)) {
                $tab    = explode('_', $this->table);
                $db     = $tab[1];
                $table  = $tab[2];
                $view = true;
            } else {
                $db     = $this->db;
                $table  = $this->table;
            }

            $modelFile = APPLICATION_PATH . DS . 'models' . DS . 'Bigdata' . DS . 'models' . DS . Inflector::lower($db) . DS . ucfirst(Inflector::lower($table)) . '.php';

            if (!is_dir(APPLICATION_PATH . DS . 'models' . DS . 'Bigdata' . DS . 'models' . DS . Inflector::lower($db))) {
                File::mkdir(APPLICATION_PATH . DS . 'models' . DS . 'Bigdata' . DS . 'models' . DS . Inflector::lower($db));
            }

            if (!File::exists($modelFile)) {
                File::put($modelFile, str_replace('##class##', ucfirst(Inflector::lower($db)) . ucfirst(Inflector::lower($table)) . 'Model', File::read(__DIR__ . DS . 'dbModel.tpl')));
            }

            $class = '\\Thin\\' . ucfirst(Inflector::lower($db)) . ucfirst(Inflector::lower($table)) . 'Model';

            if (!class_exists($class)) {
                require_once $modelFile;
            }

            $model = $this;

            if (true === $view) {
                $model = self::instance($db, $table);
            }

            return new $class($model, $data);
        }

        public function repo()
        {
            $view = false;

            if (fnmatch('view_*_*', $this->table)) {
                $tab    = explode('_', $this->table);
                $db     = $tab[1];
                $table  = $tab[2];
                $view = true;
            } else {
                $db     = $this->db;
                $table  = $this->table;
            }

            $repoFile = APPLICATION_PATH . DS . 'models' . DS . 'Bigdata' . DS . 'repositories' . DS . Inflector::lower($db) . DS . ucfirst(Inflector::lower($table)) . '.php';

            if (!File::exists($repoFile)) {
                File::put($repoFile, str_replace('##class##', ucfirst(Inflector::lower($db)) . ucfirst(Inflector::lower($table)) . 'Repository', File::read(__DIR__ . DS . 'dbRepo.tpl')));
            }

            $class = '\\Thin\\' . ucfirst(Inflector::lower($db)) . ucfirst(Inflector::lower($table)) . 'Repository';

            if (!class_exists($class)) {
                require_once $repoFile;
            }

            $model = $this;

            if (true === $view) {
                $model = self::instance($db, $table);
            }

            return new $class($model);
        }

        /* Functions */

        public function save(array $data)
        {
            if (fnmatch('view_*_*', $this->table)) {
                throw new Exception("It is forbidden to write in db from a view.");
            }

            $id = isAke($data, 'id', false);

            return !$id ? $this->add($data) : $this->edit($id, $data);
        }

        public function saveView(array $data)
        {
            $keyTuple = sha1($this->db . $this->table . serialize($data));
            $id = isAke($data, 'id');
            $this->delete($id);

            $db = $this->getCollection();

            $row = $db->insert($data);

            unset($data['_id']);

            $this->addTuple($id, $keyTuple);

            $this->setAge();

            return $this->model($data);
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

            $db = $this->getCollection();

            $data['id'] = $id;

            if (!isset($data['created_at'])) {
                $data['created_at'] = (int) time();
            }

            if (!isset($data['updated_at'])) {
                $data['updated_at'] = (int) time();
            }

            $row = $db->insert($this->analyze($data));

            unset($data['_id']);

            $this->addTuple($id, $keyTuple);

            $this->setAge();

            redis()->set('must.backup', 1);

            return $this->model($data);
        }

        private function analyze(array $data)
        {
            $clean = [];

            foreach ($data as $k => $v) {
                if (is_numeric($v) && !fnmatch('*phone*', $k) && !fnmatch('*zip*', $k) && !fnmatch('*cellular*', $k) && $k != 'phone' && $k != 'zip' && $k != 'siret' && $k != 'cellular') {
                    if (fnmatch('*.*', $v) || fnmatch('*,*', $v)) {
                        $v = (float) $v;
                    } else {
                        $v = new \MongoInt32($v);
                    }
                }

                $clean[$k] = $v;
            }

            return $clean;
        }

        public function bulk(array $datas, $checkTuple = false)
        {
            foreach ($datas as $data) {
                if ($checkTuple) {
                    $this->add($data);
                } else {
                    $this->insert($data);
                }
            }

            return $this;
        }

        public function insert($data)
        {
            $id = $this->makeId();

            $db = $this->getCollection();

            $data['id'] = $id;

            if (!isset($data['created_at'])) {
                $data['created_at'] = time();
            }

            if (!isset($data['updated_at'])) {
                $data['updated_at'] = time();
            }

            $row = $db->insert($this->analyze($data));

            redis()->set('must.backup', 1);

            return $this->model($data);
        }

        private function edit($id, array $data)
        {
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

            $data['updated_at'] = time();

            $old = $this->find($id);

            if ($old) {
                $old = $old->assoc();
            } else {
                $old = [];
            }

            $unset = [];

            foreach (array_keys($old) as $f) {
                if (!isset($data[$f])) {
                    $unset[] = $f;
                }
            }

            unset($data['id']);

            $new = $data;

            $this->delTuple($id);
            $this->addTuple($id, $keyTuple);

            $db = $this->getCollection();

            if (!empty($unset)) {
                $row = $db->update(['id' => $id], ['$set' => $new]);

                foreach ($unset as $fu) {
                    $row = $db->update(
                        ['id' => $id],
                        ['$unset' => [$fu => '']]
                    );
                }
            } else {
                $row = $db->update(
                    ['id' => $id],
                    ['$set' => $this->analyze($new)]
                );
            }

            unset($data['_id']);

            $this->setAge();

            return $this->find($id);
        }

        public function update($query, $update)
        {
            $db = $this->getCollection();

            $db->update(
                $query,
                ['$set' => $update],
                ['multi' => true]
            );

            redis()->set('must.backup', 1);

            return $this;
        }

        public function remove($query)
        {
            $db = $this->getCollection();

            $db->remove($query);

            redis()->set('must.backup', 1);

            return $this;
        }

        public function delete($id)
        {
            $db = $this->getCollection();

            $db->remove(['id' => $id], ["justOne" => true]);

            $this->delTuple($id);

            $this->setAge();

            redis()->set('must.backup', 1);

            return true;
        }

        public function find($id, $object = true)
        {
            if (!is_numeric($id)) {
                return null;
            }

            $id     = (int) $id;
            $db     = $this->getCollection();
            $obj    = $db->findOne(['id' => $id]);

            if (!is_array($obj)) {
                return null;
            }

            unset($obj['_id']);

            return true === $object ? $this->model($obj) : $obj;
        }

        private function getDbOperand($op)
        {
            switch($op) {
                case "!=":
                case "<>":
                    return '$ne';
                case "<=":
                    return '$lte';
                case ">=":
                    return '$gte';
                case "<":
                    return '$lt';
                case ">":
                    return '$gt';
                case "=":
                case "=i":
                default:
                    return true;
            }
        }

        public function getHash($object = false, $count = false, $first = false, $huge = false)
        {
            $object = !$object  ? 'false' : 'true';
            $count  = !$count   ? 'false' : 'true';
            $first  = !$first   ? 'false' : 'true';
            $huge   = !$huge    ? 'false' : 'true';

            return sha1(
                serialize($this->selects) .
                serialize($this->wheres) .
                serialize($this->orders) .
                serialize($this->groupBys) .
                $this->offset .
                $this->limit .
                $count .
                $first .
                $huge .
                $object
            );
        }

        public function prepare(array $q, $res = false)
        {
            $filter = ['$or' => [], '$and' => []];

            if (!empty($q)) {
                foreach ($q as $wh) {
                    $addFilter = true;

                    if ($res) {
                        list($field, $operand, $value) = current($wh);
                        $condition = [$field, $operand, $value];
                        $op = end($wh);

                        if ($op == '&&') {
                            $op = 'AND';
                        } elseif ($op == '||') {
                            $op = 'OR';
                        }
                    } else {
                        if (4 == count($wh)) {
                            list($field, $operand, $value, $op) = $wh;
                            $condition = [$field, $operand, $value];
                        } elseif (3 == count($wh)) {
                            $op = 'AND';
                            list($field, $operand, $value) = $wh;
                            $condition = [$field, $operand, $value];
                        }
                    }

                    if (is_string($condition)) {
                        $condition = $this->normalizeCondition($condition);
                    }

                    if (count($condition) == 3) {
                        list($field, $operand, $value) = $condition;
                    } elseif (count($condition) == 1) {
                        $operand    = '=';
                        $field      = current(array_keys($condition));
                        $value      = current(array_values($condition));
                    }

                    if (!fnmatch('*LIKE*', $operand) && !fnmatch('*IN*', $operand)) {
                        if (fnmatch('IS*', $operand)) {
                            if (!fnmatch('*NOT', $operand)) {
                                $query = [$field => null];
                            } else {
                                $query = [$field => ['$ne' => null]];
                            }
                        } else {
                            $dbOperand = $this->getDbOperand($operand);

                            if (is_string($dbOperand)) {
                                if (fnmatch('*t*', $dbOperand)) {
                                    $value = !fnmatch('*.*', $value) && !fnmatch('*.*', $value) ? (int) $value : (float) $value;
                                }

                                $query = [$field => [$dbOperand => $value]];
                            } else {
                                if ('=' == $operand) {
                                    $query = [$field => $value];
                                } elseif ('=i' == $operand) {
                                    $query = [$field => new \MongoRegex('/^' . $value . '$/i')];
                                } elseif ('EXISTS' == $operand) {
                                    if (!is_bool($value)) {
                                        $value = 'true' ? true : false;
                                    }

                                    $query = [$field => ['$exists' => $value]];
                                } elseif ('ALL' == $operand && is_array($value)) {
                                    $query = [$field => ['$all' => array_values($value)]];
                                } elseif ('SIZE' == $operand) {
                                    $query = [$field => ['$size' => $value]];
                                } elseif ('TYPE' == $operand) {
                                    $query = [$field => ['$type' => $this->resolveType($value)]];
                                } elseif ('WORD' == $operand) {
                                    $filter['$text'] = ['$search' => $value];
                                    $addFilter  = false;
                                } elseif ('WORDS' == $operand) {
                                    $filter['$text'] = ['$search' => "\"$value\""];
                                    $addFilter  = false;
                                } elseif ('SENTENCE' == $operand) {
                                    $filter['$text'] = ['$search' => "\"$value\""];
                                    $addFilter  = false;
                                } elseif ('NOR' == $operand) {
                                    $filter['$nor'] = $value;
                                    $addFilter  = false;
                                } elseif ('NOT' == $operand) {
                                    $filter['$not'] = $value;
                                    $addFilter  = false;
                                } elseif ('WHERE' == $operand) {
                                    $filter['$where'] = $value;
                                    $addFilter  = false;
                                } elseif ('MOD' == $operand) {
                                    $divisor    = current($value);
                                    $remainder  = end($value);
                                    $query      = [$field => ['$mod' => [(int) $divisor, (int) $remainder]]];
                                } elseif ('CENTER' == $operand) {
                                    $longitude  = current($value);
                                    $latitude   = $value[1];
                                    $radius     = end($value);
                                    $query      = [$field => ['$geoWithin' => ['$center' => [[$longitude, $latitude], $radius]]]];
                                } elseif ('CENTERSPHERE' == $operand) {
                                    $longitude  = current($value);
                                    $latitude   = $value[1];
                                    $radius     = end($value);
                                    $query      = [$field => ['$geoWithin' => ['$centerSphere' => [[$longitude, $latitude], $radius]]]];
                                } elseif ('BETWEEN' == $operand) {
                                    $min    = current($value);
                                    $max    = end($value);
                                    $query  = [$field => ['$gt' => $min, '$lt' => $max]];
                                } elseif ('BOX' == $operand) {
                                    $left   = current($value);
                                    $right  = end($value);
                                    $query  = [$field => ['$geoWithin' => ['$box' => [$left, $right]]]];
                                } elseif ('POLYGON' == $operand) {
                                    $query  = [$field => ['$geoWithin' => ['$polygon' => $value]]];
                                }
                            }
                        }
                    } else {
                        if (fnmatch('*LIKE*', $operand) && !fnmatch('*NOT*', $operand)) {
                            $pattern = str_replace('%', '.*', $value);
                            $query = [$field => new \MongoRegex('/^' . $pattern . '/imxsu')];
                        } elseif (fnmatch('*LIKE*', $operand) && fnmatch('*NOT*', $operand)) {
                            $pattern = str_replace('%', '.*', $value);
                            $query = [
                                $field => [
                                    '$not' => new \MongoRegex('/^' . $pattern . '/imxsu')
                                ]
                            ];

                        } elseif (fnmatch('*IN*', $operand) && !fnmatch('*NOT*', $operand)) {
                            if (!is_array($value)) {
                                $value = str_replace('(', '', $value);
                                $value = str_replace(')', '', $value);
                                $value = str_replace(' ,', ',', $value);
                                $value = str_replace(', ', ',', $value);

                                $values = explode(',', $value);

                                $t = [];

                                foreach ($values as $v) {
                                    $t[] = is_numeric($v) ? (int) $v : $v;
                                }

                                $value = $t;
                            }

                            $query = [$field => ['$in' => $value]];
                        } elseif (fnmatch('*IN*', $operand) && fnmatch('*NOT*', $operand)) {
                            if (!is_array($value)) {
                                $value = str_replace('(', '', $value);
                                $value = str_replace(')', '', $value);
                                $value = str_replace(' ,', ',', $value);
                                $value = str_replace(', ', ',', $value);

                                $values = explode(',', $value);

                                $t = [];

                                foreach ($values as $v) {
                                    $t[] = is_numeric($v) ? (int) $v : $v;
                                }

                                $value = $t;
                            }

                            $query = [$field => ['$nin' => $value]];
                        }
                    }

                    if (true === $addFilter) {
                        if ('&&' == $op || 'AND' == $op) {
                            array_push($filter['$and'], $query);
                        } elseif ('||' == $op || 'OR' == $op) {
                            array_push($filter['$or'], $query);
                        }
                    }
                }
            }

            if (empty($filter['$and'])) {
                unset($filter['$and']);
            } else {
                if (empty($filter['$or'])) {
                    $newfilter = [];

                    foreach ($filter['$and'] as $rowFilter) {
                        foreach ($rowFilter as $k => $v) {
                            $newfilter[$k] = $v;
                        }
                    }

                    return $newfilter;
                } else {
                    $filter['$or'][] = ['$and' => $filter['$and']];
                    unset($filter['$and']);
                }
            }

            if (empty($filter['$or'])) {
                unset($filter['$or']);
            }

            return $filter;
        }

        public function _exec($object = false, $count = false, $first = false)
        {
            $hash = $this->getHash($object, $count, $first);

            return lib('utils')->until('exec.' . $hash, function ($self, $object, $count, $first) {
                $collection = [];

                $self->model()->checkIndices();

                $filter = ['$or' => [], '$and' => []];

                if (!empty($self->wheres)) {
                    foreach ($self->wheres as $wh) {
                        $addFilter = true;

                        list($condition, $op) = $wh;

                        if (is_string($condition)) {
                            $condition = $self->normalizeCondition($condition);
                        }

                        if (count($condition) == 3) {
                            list($field, $operand, $value) = $condition;
                        } elseif (count($condition) == 1) {
                            $operand    = '=';
                            $field      = current(array_keys($condition));
                            $value      = current(array_values($condition));
                        }

                        if (!fnmatch('*LIKE*', $operand) && !fnmatch('*IN*', $operand)) {
                            if (fnmatch('IS*', $operand)) {
                                if (!fnmatch('*NOT', $operand)) {
                                    $query = [$field => null];
                                } else {
                                    $query = [$field => ['$ne' => null]];
                                }
                            } else {
                                $dbOperand = $self->getDbOperand($operand);

                                if (is_string($dbOperand)) {
                                    if (fnmatch('*t*', $dbOperand)) {
                                        $value = !fnmatch('*.*', $value) && !fnmatch('*.*', $value) ? (int) $value : (float) $value;
                                    }

                                    $query = [$field => [$dbOperand => $value]];
                                } else {
                                    if ('=' == $operand) {
                                        $query = [$field => $value];
                                    } elseif ('=i' == $operand) {
                                        $query = [$field => new \MongoRegex('/^' . $value . '$/i')];
                                    } elseif ('EXISTS' == $operand) {
                                        if (!is_bool($value)) {
                                            $value = 'true' ? true : false;
                                        }

                                        $query = [$field => ['$exists' => $value]];
                                    } elseif ('ALL' == $operand && is_array($value)) {
                                        $query = [$field => ['$all' => array_values($value)]];
                                    } elseif ('SIZE' == $operand) {
                                        $query = [$field => ['$size' => $value]];
                                    } elseif ('TYPE' == $operand) {
                                        $query = [$field => ['$type' => $self->resolveType($value)]];
                                    } elseif ('WORD' == $operand) {
                                        $filter['$text'] = ['$search' => $value];
                                        $addFilter = false;
                                    } elseif ('WORDS' == $operand) {
                                        $filter['$text'] = ['$search' => "\"$value\""];
                                        $addFilter = false;
                                    } elseif ('SENTENCE' == $operand) {
                                        $filter['$text'] = ['$search' => "\"$value\""];
                                        $addFilter = false;
                                    } elseif ('NOR' == $operand) {
                                        $filter['$nor'] = $value;
                                        $addFilter = false;
                                    } elseif ('NOT' == $operand) {
                                        $filter['$not'] = $value;
                                        $addFilter = false;
                                    } elseif ('WHERE' == $operand) {
                                        $filter['$where'] = $value;
                                        $addFilter = false;
                                    } elseif ('MOD' == $operand) {
                                        $divisor    = current($value);
                                        $remainder  = end($value);
                                        $query      = [$field => ['$mod' => [(int) $divisor, (int) $remainder]]];
                                    } elseif ('CENTER' == $operand) {
                                        $longitude  = current($value);
                                        $latitude   = $value[1];
                                        $radius     = end($value);
                                        $query      = [$field => ['$geoWithin' => ['$center' => [[$longitude, $latitude], $radius]]]];
                                    } elseif ('CENTERSPHERE' == $operand) {
                                        $longitude  = current($value);
                                        $latitude   = $value[1];
                                        $radius     = end($value);
                                        $query      = [$field => ['$geoWithin' => ['$centerSphere' => [[$longitude, $latitude], $radius]]]];
                                    } elseif ('BETWEEN' == $operand) {
                                        $min    = current($value);
                                        $max    = end($value);
                                        $query  = [$field => ['$gt' => $min, '$lt' => $max]];
                                    } elseif ('BOX' == $operand) {
                                        $left   = current($value);
                                        $right  = end($value);
                                        $query  = [$field => ['$geoWithin' => ['$box' => [$left, $right]]]];
                                    } elseif ('POLYGON' == $operand) {
                                        $query  = [$field => ['$geoWithin' => ['$polygon' => $value]]];
                                    }
                                }
                            }
                        } else {
                            if (fnmatch('*LIKE*', $operand) && !fnmatch('*NOT*', $operand)) {
                                $pattern = str_replace('%', '.*', $value);
                                $query = [$field => new \MongoRegex('/^' . $pattern . '/imxsu')];
                            } elseif (fnmatch('*LIKE*', $operand) && fnmatch('*NOT*', $operand)) {
                                $pattern = str_replace('%', '.*', $value);
                                $query = [
                                    $field => [
                                        '$not' => new \MongoRegex('/^' . $pattern . '/imxsu')
                                    ]
                                ];

                            } elseif (fnmatch('*IN*', $operand) && !fnmatch('*NOT*', $operand)) {
                                if (!is_array($value)) {
                                    $value = str_replace('(', '', $value);
                                    $value = str_replace(')', '', $value);
                                    $value = str_replace(' ,', ',', $value);
                                    $value = str_replace(', ', ',', $value);

                                    $values = explode(',', $value);

                                    $t = [];

                                    foreach ($values as $v) {
                                        $t[] = is_numeric($v) ? (int) $v : $v;
                                    }

                                    $value = $t;
                                }

                                $query = [$field => ['$in' => $value]];
                            } elseif (fnmatch('*IN*', $operand) && fnmatch('*NOT*', $operand)) {
                                if (!is_array($value)) {
                                    $value = str_replace('(', '', $value);
                                    $value = str_replace(')', '', $value);
                                    $value = str_replace(' ,', ',', $value);
                                    $value = str_replace(', ', ',', $value);

                                    $values = explode(',', $value);

                                    $t = [];

                                    foreach ($values as $v) {
                                        $t[] = is_numeric($v) ? (int) $v : $v;
                                    }

                                    $value = $t;
                                }

                                $query = [$field => ['$nin' => $value]];
                            }
                        }

                        if (true === $addFilter) {
                            if ('&&' == $op) {
                                array_push($filter['$and'], $query);
                            } elseif ('||' == $op) {
                                array_push($filter['$or'], $query);
                            }
                        }
                    }
                }

                if (empty($filter['$and'])) {
                    unset($filter['$and']);
                } else {
                    $filter['$or'][] = ['$and' => $filter['$and']];
                    unset($filter['$and']);
                }

                if (empty($filter['$or'])) {
                    unset($filter['$or']);
                }

                $db = $self->getCollection();
                $db->ensureIndex(['id' => 1]);

                if (empty($self->selects)) {
                    $results = new Cursor($db->find($filter));
                } else {
                    $fields = [];

                    foreach ($self->selects as $f) {
                        $fields[$f] = true;
                    }

                    $hasId = isAke($fields, 'id', false);

                    if (false === $hasId) {
                        $fields['id'] = true;
                    }

                    $results = new Cursor($db->find($filter, $fields));
                }

                if (true === $count) {
                    return $results->count();
                }

                if (!empty($self->orders)) {
                    $results = $results->sort($self->orders);
                }

                if (isset($self->offset)) {
                    $results = $results->skip($self->offset);
                }

                if (isset($self->limit)) {
                    $results = $results->limit($self->limit);
                }

                if (true === $first) {
                    $item = [];

                    if (!empty($results)) {
                        foreach ($results as $row) {
                            unset($row['_id']);
                            $exists = isAke($row, 'id', false);

                            if (false === $exists) {
                                continue;
                            }

                            $item = $row;
                            break;
                        }
                    }

                    return $item;
                }

                if (!empty($self->groupBys)) {
                    foreach ($self->groupBys as $fieldGB) {
                        $groupByTab = [];
                        $ever       = [];

                        foreach ($results as $tab) {
                            $exists = isAke($tab, 'id', false);

                            if (false === $exists) {
                                continue;
                            }

                            $obj = isAke($tab, $fieldGB, null);

                            if (!in_array($obj, $ever)) {
                                $groupByTab[]   = $tab;
                                $ever[]         = $obj;
                            }
                        }

                        $results = count($groupByTab) > 1 ? $self->_order($field, 'ASC', $groupByTab) : $groupByTab;
                    }
                }

                if (!empty($self->joinTables)) {
                    $joinCollection = [];

                    foreach ($self->wheres as $wh) {
                        list($condition, $op) = $wh;
                        list($field, $operand, $value) = $condition;

                        if (fnmatch('*.*', $field)) {
                            if (fnmatch('*.*.*', $field)) {
                                list($fKDb, $fkTable, $fkField) = explode('.', $field, 3);
                            } else {
                                list($fkTable, $fkField) = explode('.', $field, 2);
                                $fKDb = $self->db;
                            }

                            $joinField = $self->joinTables[$field];

                            foreach ($results as $tab) {
                                $exists = isAke($tab, 'id', false);

                                if (false === $exists) {
                                    continue;
                                }

                                $joinFieldValue = isAke($tab, $joinField, false);

                                if (false !== $joinFieldValue) {
                                    $fkRow = Db::instance($fkTable, $fKDb)->find($joinFieldValue);

                                    if ($fkRow) {
                                        $fkTab = $fkRow->assoc();
                                        $fkFieldValue = isAke($fkTab, $fkField, false);

                                        if (false !== $fkFieldValue) {
                                            if (is_array($fkFieldValue)) {
                                                if ($operand != '!=' && $operand != '<>' && !fnmatch('*NOT*', $operand)) {
                                                    $check = in_array($value, $fkFieldValue);
                                                } else {
                                                    $check = !in_array($value, $fkFieldValue);
                                                }
                                            } else {
                                                if (strlen($fkFieldValue)) {
                                                    if ($value == 'null') {
                                                        $check = false;

                                                        if ($operand == 'IS' || $operand == '=') {
                                                            $check = false;
                                                        } elseif ($operand == 'ISNOT' || $operand == '!=' || $operand == '<>') {
                                                            $check = true;
                                                        }
                                                    } else {
                                                        $fkFieldValue   = str_replace('|', ' ', $fkFieldValue);
                                                        $check          = $self->compare($fkFieldValue, $operand, $value);
                                                    }
                                                } else {
                                                    $check = false;

                                                    if ($value == 'null') {
                                                        if ($operand == 'IS' || $operand == '=') {
                                                            $check = true;
                                                        } elseif ($operand == 'ISNOT' || $operand == '!=' || $operand == '<>') {
                                                            $check = false;
                                                        }
                                                    }
                                                }
                                            }

                                            if (true === $check) {
                                                array_push($joinCollection, $tab);
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }

                    $results = $joinCollection;
                }

                if (!empty($results)) {
                    foreach ($results as $row) {
                        unset($row['_id']);
                        $exists = isAke($row, 'id', false);

                        if (false === $exists) {
                            continue;
                        }

                        $item = true === $object ? $self->model($row) : $row;

                        array_push($collection, $item);
                    }
                }

                $self->reset();

                return true === $object ? new Collection($collection) : $collection;
            }, $this->getAge(), [$this, $object, $count, $first]);
        }

        public function huge($object = false, $count = false, $first = false)
        {
            return $this->exec($object, $count, $first, true);
        }

        public function exec($object = false, $count = false, $first = false, $huge = false)
        {
            $collection = $huge ? new Huge($this) : [];

            $hash = $this->getHash($object, $count, $first);

            if (true === $this->useCache) {
                $keyData    = 'dbr.exec.data.' . $this->collection . '.' . $hash;
                $keyAge     = 'dbr.exec.age.' . $this->collection . '.' . $hash;

                $ageDb      = $this->getAge();
                $ageQuery   = $this->cache()->get($keyAge);

                if (strlen($ageQuery)) {
                    if ($ageQuery > $ageDb) {
                        $collection = unserialize($this->cache()->get($keyData));

                        $this->reset();

                        return true === $object ? new Collection($this->makeModels($collection)) : $collection;
                    }
                }
            }

            $this->model()->checkIndices();

            $filter = ['$or' => [], '$and' => []];

            if (!empty($this->wheres)) {
                foreach ($this->wheres as $wh) {
                    $addFilter = true;

                    list($condition, $op) = $wh;

                    if (is_string($condition)) {
                        $condition = $this->normalizeCondition($condition);
                    }

                    if (count($condition) == 3) {
                        list($field, $operand, $value) = $condition;
                    } elseif (count($condition) == 1) {
                        $operand    = '=';
                        $field      = current(array_keys($condition));
                        $value      = current(array_values($condition));
                    }

                    if (!fnmatch('*LIKE*', $operand) && !fnmatch('*IN*', $operand)) {
                        if (fnmatch('IS*', $operand)) {
                            if (!fnmatch('*NOT', $operand)) {
                                $query = [$field => null];
                            } else {
                                $query = [$field => ['$ne' => null]];
                            }
                        } else {
                            $dbOperand = $this->getDbOperand($operand);

                            if (is_string($dbOperand)) {
                                if (fnmatch('*t*', $dbOperand)) {
                                    $value = !fnmatch('*.*', $value) && !fnmatch('*.*', $value) ? (int) $value : (float) $value;
                                }

                                $query = [$field => [$dbOperand => $value]];
                            } else {
                                if ('=' == $operand) {
                                    $query = [$field => $value];
                                } elseif ('=i' == $operand) {
                                    $query = [$field => new \MongoRegex('/^' . $value . '$/i')];
                                } elseif ('EXISTS' == $operand) {
                                    if (!is_bool($value)) {
                                        $value = 'true' ? true : false;
                                    }

                                    $query = [$field => ['$exists' => $value]];
                                } elseif ('ALL' == $operand && is_array($value)) {
                                    $query = [$field => ['$all' => array_values($value)]];
                                } elseif ('SIZE' == $operand) {
                                    $query = [$field => ['$size' => $value]];
                                } elseif ('TYPE' == $operand) {
                                    $query = [$field => ['$type' => $this->resolveType($value)]];
                                } elseif ('WORD' == $operand) {
                                    $filter['$text'] = ['$search' => $value];
                                    $addFilter = false;
                                } elseif ('WORDS' == $operand) {
                                    $filter['$text'] = ['$search' => "\"$value\""];
                                    $addFilter = false;
                                } elseif ('SENTENCE' == $operand) {
                                    $filter['$text'] = ['$search' => "\"$value\""];
                                    $addFilter = false;
                                } elseif ('NOR' == $operand) {
                                    $filter['$nor'] = $value;
                                    $addFilter = false;
                                } elseif ('NOT' == $operand) {
                                    $filter['$not'] = $value;
                                    $addFilter = false;
                                } elseif ('WHERE' == $operand) {
                                    $filter['$where'] = $value;
                                    $addFilter = false;
                                } elseif ('MOD' == $operand) {
                                    $divisor    = current($value);
                                    $remainder  = end($value);
                                    $query      = [$field => ['$mod' => [(int) $divisor, (int) $remainder]]];
                                } elseif ('CENTER' == $operand) {
                                    $longitude  = current($value);
                                    $latitude   = $value[1];
                                    $radius     = end($value);
                                    $query      = [$field => ['$geoWithin' => ['$center' => [[$longitude, $latitude], $radius]]]];
                                } elseif ('CENTERSPHERE' == $operand) {
                                    $longitude  = current($value);
                                    $latitude   = $value[1];
                                    $radius     = end($value);
                                    $query      = [$field => ['$geoWithin' => ['$centerSphere' => [[$longitude, $latitude], $radius]]]];
                                } elseif ('BOX' == $operand) {
                                    $left   = current($value);
                                    $right  = end($value);
                                    $query  = [$field => ['$geoWithin' => ['$box' => [$left, $right]]]];
                                } elseif ('BETWEEN' == $operand) {
                                    $min    = current($value);
                                    $max    = end($value);
                                    $query  = [$field => ['$gt' => $min, '$lt' => $max]];
                                } elseif ('POLYGON' == $operand) {
                                    $query  = [$field => ['$geoWithin' => ['$polygon' => $value]]];
                                }
                            }
                        }
                    } else {
                        if (fnmatch('*LIKE*', $operand) && !fnmatch('*NOT*', $operand)) {
                            $pattern = str_replace('%', '.*', $value);
                            $query = [$field => new \MongoRegex('/^' . $pattern . '/imxsu')];
                        } elseif (fnmatch('*LIKE*', $operand) && fnmatch('*NOT*', $operand)) {
                            $pattern = str_replace('%', '.*', $value);
                            $query = [
                                $field => [
                                    '$not' => new \MongoRegex('/^' . $pattern . '/imxsu')
                                ]
                            ];

                        } elseif (fnmatch('*IN*', $operand) && !fnmatch('*NOT*', $operand)) {
                            if (!is_array($value)) {
                                $value = str_replace('(', '', $value);
                                $value = str_replace(')', '', $value);
                                $value = str_replace(' ,', ',', $value);
                                $value = str_replace(', ', ',', $value);

                                $values = explode(',', $value);

                                $t = [];

                                foreach ($values as $v) {
                                    $t[] = is_numeric($v) ? (int) $v : $v;
                                }

                                $value = $t;
                            }

                            $query = [$field => ['$in' => $value]];
                        } elseif (fnmatch('*IN*', $operand) && fnmatch('*NOT*', $operand)) {
                            if (!is_array($value)) {
                                $value = str_replace('(', '', $value);
                                $value = str_replace(')', '', $value);
                                $value = str_replace(' ,', ',', $value);
                                $value = str_replace(', ', ',', $value);

                                $values = explode(',', $value);

                                $t = [];

                                foreach ($values as $v) {
                                    $t[] = is_numeric($v) ? (int) $v : $v;
                                }

                                $value = $t;
                            }

                            $query = [$field => ['$nin' => $value]];
                        }
                    }

                    if (true === $addFilter) {
                        if ('&&' == $op) {
                            array_push($filter['$and'], $query);
                        } elseif ('||' == $op) {
                            array_push($filter['$or'], $query);
                        }
                    }
                }
            }

            if (empty($filter['$and'])) {
                unset($filter['$and']);
            } else {
                $filter['$or'][] = ['$and' => $filter['$and']];
                unset($filter['$and']);
            }

            if (empty($filter['$or'])) {
                unset($filter['$or']);
            }

            $db = $this->getCollection();
            $db->ensureIndex(['id' => 1]);

            if (empty($this->selects)) {
                $results = new Cursor($db->find($filter));
            } else {
                $fields = [];

                foreach ($this->selects as $f) {
                    $fields[$f] = true;
                }

                $hasId = isAke($fields, 'id', false);

                if (false === $hasId) {
                    $fields['id'] = true;
                }

                $results = new Cursor($db->find($filter, $fields));
            }

            if (true === $count) {
                if (true === $this->useCache && false === $object) {
                    $this->cache()->set($keyData, serialize($results->count()));
                    $this->cache()->set($keyAge, time());
                }

                return $results->count();
            }

            if (!empty($this->orders)) {
                $results = $results->sort($this->orders);
            }

            if (isset($this->offset)) {
                $results = $results->skip($this->offset);
            }

            if (isset($this->limit)) {
                $results = $results->limit($this->limit);
            }

            if (true === $first) {
                $item = [];

                if (!empty($results)) {
                    foreach ($results as $row) {
                        unset($row['_id']);
                        $exists = isAke($row, 'id', false);

                        if (false === $exists) {
                            continue;
                        }

                        $item = $row;
                        break;
                    }
                }

                if (true === $this->useCache && false === $object) {
                    $this->cache()->set($keyData, serialize($item));
                    $this->cache()->set($keyAge, time());
                }

                return $item;
            }

            if (!empty($this->groupBys)) {
                foreach ($this->groupBys as $fieldGB) {
                    $groupByTab = [];
                    $ever       = [];

                    foreach ($results as $tab) {
                        $exists = isAke($tab, 'id', false);

                        if (false === $exists) {
                            continue;
                        }

                        $obj = isAke($tab, $fieldGB, null);

                        if (!in_array($obj, $ever)) {
                            $groupByTab[]   = $tab;
                            $ever[]         = $obj;
                        }
                    }

                    $results = count($groupByTab) > 1 ? $this->_order($field, 'ASC', $groupByTab) : $groupByTab;
                }
            }

            if (!empty($this->joinTables)) {
                $joinCollection = [];

                foreach ($this->wheres as $wh) {
                    list($condition, $op) = $wh;
                    list($field, $operand, $value) = $condition;

                    if (fnmatch('*.*', $field)) {
                        if (fnmatch('*.*.*', $field)) {
                            list($fKDb, $fkTable, $fkField) = explode('.', $field, 3);
                        } else {
                            list($fkTable, $fkField) = explode('.', $field, 2);
                            $fKDb = $this->db;
                        }

                        $joinField = $this->joinTables[$field];

                        foreach ($results as $tab) {
                            $exists = isAke($tab, 'id', false);

                            if (false === $exists) {
                                continue;
                            }

                            $joinFieldValue = isAke($tab, $joinField, false);

                            if (false !== $joinFieldValue) {
                                $fkRow = self::instance($fkTable, $fKDb)->find($joinFieldValue);

                                if ($fkRow) {
                                    $fkTab = $fkRow->assoc();
                                    $fkFieldValue = isAke($fkTab, $fkField, false);

                                    if (false !== $fkFieldValue) {
                                        if (is_array($fkFieldValue)) {
                                            if ($operand != '!=' && $operand != '<>' && !fnmatch('*NOT*', $operand)) {
                                                $check = in_array($value, $fkFieldValue);
                                            } else {
                                                $check = !in_array($value, $fkFieldValue);
                                            }
                                        } else {
                                            if (strlen($fkFieldValue)) {
                                                if ($value == 'null') {
                                                    $check = false;

                                                    if ($operand == 'IS' || $operand == '=') {
                                                        $check = false;
                                                    } elseif ($operand == 'ISNOT' || $operand == '!=' || $operand == '<>') {
                                                        $check = true;
                                                    }
                                                } else {
                                                    $fkFieldValue   = str_replace('|', ' ', $fkFieldValue);
                                                    $check          = $this->compare($fkFieldValue, $operand, $value);
                                                }
                                            } else {
                                                $check = false;

                                                if ($value == 'null') {
                                                    if ($operand == 'IS' || $operand == '=') {
                                                        $check = true;
                                                    } elseif ($operand == 'ISNOT' || $operand == '!=' || $operand == '<>') {
                                                        $check = false;
                                                    }
                                                }
                                            }
                                        }

                                        if (true === $check) {
                                            array_push($joinCollection, $tab);
                                        }
                                    }
                                }
                            }
                        }
                    }
                }

                $results = $joinCollection;
            }

            if (!empty($results)) {
                $ind = 0;

                foreach ($results as $row) {
                    unset($row['_id']);
                    $row    = $this->cleanInt($row);
                    $exists = isAke($row, 'id', false);

                    if (false === $exists) {
                        continue;
                    }

                    if (is_array($collection)) {
                        $collection[] = $row;
                    } else {
                        $collection->push($ind, $row);
                        $ind++;
                    }
                }
            }

            if (true === $this->useCache) {
                $this->cache()->set($keyData, serialize($collection));
                $this->cache()->set($keyAge, time());
            }

            $this->reset();

            return true === $object ? new Collection($this->makeModels($collection)) : $collection;
        }

        public function makeModels($rows)
        {
            $collection = [];

            foreach ($rows as $row) {
                $collection[] = $this->model($row);
            }

            return $collection;
        }

        private function cleanInt($row)
        {
            $newrow = [];

            foreach ($row as $k => $v) {
                if (is_numeric($v)) {
                    if (!fnmatch('*.*', $v) && !fnmatch('*,*', $v)) {
                        $v = (int) $v;
                    } else {
                        $v = (double) $v;
                    }
                }

                $newrow[$k] = $v;
            }

            return $newrow;
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

        public function resolveType($type)
        {
            if (is_numeric($type)) {
                return (int) $type;
            }

            $type = Inflector::upper($type);

            static $types = array(
                'DOUBLE' => 1, 'STRING' => 2, 'OBJECT' => 3, 'ARRAY' => 4,
                'BINARY' => 5, 'ID' => 8, 'BOOL' => 8, 'BOOLEAN' => 8,
                'DATE' => 9, 'NULL' => 10, 'REGEX' => 11, 'JAVASCRIPT' => 13,
                'CODE' => 13, 'SYMBOL' => 14, 'JAVASCRIPT_SCOPE' => 15,
                'CODE_SCOPE' => 15, 'INT32' => 16, 'TS' => 17, 'TIMESTAMP' => 17,
                'INT64' => 18, 'MIN' => -1, 'MAX' => 127,
            );

            if (!isset($types[$type])) {
                throw new \InvalidArgumentException('Type "' . $type . '" could not be resolved');
            }

            return $types[$type];
        }

        public function _order($fieldOrder, $orderDirection = 'ASC', $results = [])
        {
            if (empty($results)) {
                return $this;
            }

            $sortFunc = function($key, $direction) {
                return function ($a, $b) use ($key, $direction) {
                    if (!isset($a[$key]) || !isset($b[$key])) {
                        return false;
                    }

                    if ('ASC' == $direction) {
                        return $a[$key] > $b[$key];
                    } else {
                        return $a[$key] < $b[$key];
                    }
                };
            };

            if (is_array($fieldOrder) && !is_array($orderDirection)) {
                $t = [];

                foreach ($fieldOrder as $tmpField) {
                    array_push($t, $orderDirection);
                }

                $orderDirection = $t;
            }

            if (!is_array($fieldOrder) && is_array($orderDirection)) {
                $orderDirection = current($orderDirection);
            }

            if (is_array($fieldOrder) && is_array($orderDirection)) {
                for ($i = 0 ; $i < count($fieldOrder) ; $i++) {
                    usort($results, $sortFunc($fieldOrder[$i], $orderDirection[$i]));
                }
            } else {
                usort($results, $sortFunc($fieldOrder, $orderDirection));
            }

            return $results;
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

        private function normalizeCondition($condition)
        {
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

            return [$field, $operand, $value];
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

        public function all($object = false)
        {
            $collection = [];

            $keyData    = 'rdb.all.data.' . $this->collection;
            $keyAge     = 'rdb.all.age.' . $this->collection;

            $ageDb      = $this->getAge();
            $ageQuery   = $this->cache()->get($keyAge);

            if (true === $this->useCache && false === $object) {
                if (strlen($ageQuery)) {
                    if ($ageQuery > $ageDb) {
                        $collection = unserialize($this->cache()->get($keyData));

                        $this->reset();

                        return true === $object ? new Collection($collection) : $collection;
                    }
                }
            }

            $db = $this->getCollection();

            $datas = $db->find();

            foreach ($datas as $row) {
                unset($row['_id']);

                $row = true === $object ? $this->model($row) : $row;

                array_push($collection, $row);
            }

            if (true === $this->useCache && false === $object) {
                $this->cache()->set($keyData, serialize($collection));
                $this->cache()->set($keyAge, time());
            }

            $this->reset();

            return true === $object ? new Collection($collection) : $collection;
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

        public function groupBy($field)
        {
            $this->groupBys[] = $field;

            return $this;
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

            $this->offset   = $offset;

            return $this;
        }

        public function sum($field, $res = [])
        {
            $hash = sha1(serialize($this->wheres) . serialize($this->orders)  . serialize($this->groupBys) . $this->offset . $this->limit . $field);

            $keyData    = 'rdb.sum.data.' . $this->collection . '.' . $hash;
            $keyAge     = 'rdb.sum.age.' . $this->collection . '.' . $hash;

            $ageDb      = $this->getAge();
            $ageQuery   = $this->cache()->get($keyAge);

            if (true === $this->useCache) {
                if (strlen($ageQuery)) {
                    if ($ageQuery > $ageDb) {
                        return (int) $this->cache()->get($keyData);
                    }
                }
            }

            $res = empty($res) ? $this->exec() : $res;
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

        public function avg($field, $res = [])
        {
            $res = empty($res) ? $this->exec() : $res;

            if (empty($res)) {
                return 0;
            }

            return (float) $this->sum($field, $res) / count($res);
        }

        public function minimum($field, $object = false)
        {
            return $this->min($field, $this->select($field)->exec(), true, $object);
        }

        public function maximum($field, $object = false)
        {
            return $this->max($field, $this->select($field)->exec(), true, $object);
        }

        public function min($field, $res = [], $returRow = false, $object = false)
        {
            $hash = sha1(
                serialize($this->wheres) .
                serialize($this->orders)  .
                serialize($this->groupBys) .
                $this->offset .
                $this->limit .
                $field
            );

            $keyData    = 'rdb.min.data.' . $this->collection . '.' . $hash;
            $keyAge     = 'rdb.min.age.' . $this->collection . '.' . $hash;

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

            $res    = empty($res) ? $this->select($field)->exec() : $res;
            $min    = 0;
            $rowId  = 0;

            if (!empty($res)) {
                $first = true;

                foreach ($res as $tab) {
                    $val = isAke($tab, $field, 0);
                    $idRow = isAke($tab, 'id');

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

        public function max($field, $res = [], $returRow = false, $object = false)
        {
            $hash = sha1(serialize($this->wheres) . serialize($this->orders)  . serialize($this->groupBys) . $this->offset . $this->limit . $field);

            $keyData    = 'rdb.max.data.' . $this->collection . '.' . $hash;
            $keyAge     = 'rdb.max.age.' . $this->collection . '.' . $hash;

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

            $res    = empty($res) ? $this->select($field)->exec() : $res;
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

        public function rand($res = [])
        {
            $res = empty($res) ? $this->exec() : $res;

            shuffle($res);

            return new Collection($res);
        }

        public function sort(Closure $sortFunc, $res = [])
        {
            $res = empty($res) ? $this->exec() : $res;

            if (empty($res)) {
                return $res;
            }

            usort($res, $sortFunc);

            return $res;
        }

        public function order($fieldOrder, $orderDirection = 'ASC')
        {
            $val = $orderDirection == 'ASC' ? 1 : -1;
            $this->orders[$fieldOrder] = $val;

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

                $first = $this->cursor()->first(true);

                if ($first) {
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

        public function only($field, $default = null)
        {
            $row = $this->first(true);

            return $row instanceof Model ? $row->$field : $default;
        }

        public function destroy($what)
        {
            /* polymorphism */
            if (func_num_args() == 1) {
                if (is_string($what)) {
                    if (fnmatch('*,*', $what)) {
                        $what = str_replace(' ', '', $what);
                        $what = explode(',', $what);
                    }
                }
            } else {
                $what = func_get_args();
            }

            if (is_array($what)) {
                foreach ($what as $seg) {
                    $obj = $this->find((int) $seg);

                    if ($obj) {
                        $obj->delete();
                    }
                }
            }

            return $this;
        }

        public function select($what)
        {
            /* polymorphism */
            if (func_num_args() == 1) {
                if (is_string($what)) {
                    if (fnmatch('*,*', $what)) {
                        $what = str_replace(' ', '', $what);
                        $what = explode(',', $what);
                    }
                }
            } else {
                $what = func_get_args();
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

        public function one($object = false, $reset = true)
        {
            return $this->first($object, $reset);
        }

        public function first($object = false, $reset = true)
        {
            if ($this->useCache === false) {
                $inCache = false;
            } else {
                $inCache = !empty($this->wheres) ? true : false;
            }

            $res = $this->inCache($inCache)->exec(false, false, true);

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
                return !empty($res) ? $this->model(end($res)) : null;
            } else {
                return !empty($res) ? end($res) : [];
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
                return $object ? $this->model(current($res)) : current($res);
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

        public function none()
        {
            return new None($this);
        }

        public function reset($f = null)
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

        public function pk()
        {
            return 'id';
        }

        public function countAll()
        {
            return count($this->all(true));
        }

        public function count($res = [])
        {
            if (empty($res)) {
                if ($this->useCache === false) {
                    $inCache = false;
                } else {
                    $inCache = !empty($this->wheres) ? true : false;
                }

                $count = (int) $this->inCache($inCache)->exec(false, true);
                $this->reset();

                return $count;
            } else {
                return count($res);
            }
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

        public function custom(Closure $condition, $op = 'AND', $results = [])
        {
            return $this->trick($condition, $op, $results);
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

            if (!empty($sect)) {
                foreach ($sect as $idRow) {
                    array_push($collection, $this->find($idRow, false));
                }
            }

            return $collection;
        }

        public function trick(Closure $condition, $op = 'AND', $results = [])
        {
            $data = empty($results) ? $this->all() : $results;
            $res = [];

            if (!empty($data)) {
                foreach ($data as $row) {
                    $resTrick = $condition($row);

                    if (true === $resTrick) {
                        array_push($res, $row);
                    }
                }
            }

            if (empty($this->wheres)) {
                $res = array_values($res);
            } else {
                $values = array_values($res);

                switch ($op) {
                    case 'AND':
                        $res = $this->intersect($values, array_values($res));
                        break;
                    case 'OR':
                        $res = $values + $res;
                        break;
                    case 'XOR':
                        $res = array_merge(
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

            $this->wheres[] = true;

            return $res;
        }

        public function getOdm($db = null)
        {
            $db = is_null($db) ? SITE_NAME : $db;

            return $this->cnx->selectDB($db);
        }

        public function getCollection($collection = null, $db = null)
        {
            $collection = is_null($collection) ? $this->collection : $collection;
            $db         = is_null($db) ? SITE_NAME : $db;

            $odm = $this->getOdm($db);

            return $odm->selectCollection($collection);
        }

        private function tuple($tuple)
        {
            $tupler = $this->getCollection($this->db . '.tuples');
            $tupler->ensureIndex(['table' => 1]);

            $has = $tupler->findOne(['table' => $this->table, 'key' => $tuple]);

            if ($has) {
                return isAke($has, 'table_id', null);
            }

            return null;
        }

        private function addTuple($id, $tuple)
        {
            $tupler = $this->getCollection($this->db . '.tuples');

            return $tupler->insert(['table' => $this->table, 'key' => $tuple, 'table_id' => $id]);
        }

        private function delTuple($id)
        {
            $tupler = $this->getCollection($this->db . '.tuples');
            $tupler->ensureIndex(['table' => 1]);

            return $tupler->remove(['table' => $this->table, 'table_id' => $id], ["justOne" => true]);
        }

        private function makeId()
        {
            $counter    = $this->getCollection($this->db . '.counters');
            $last       = $counter->findOne(['table' => $this->table]);

            if ($last) {
                $id     = (int) (isAke($last, 'count', 0) + 1);
                $update = $counter->update(['table' => $this->table], ['$set' => ['count' => $id]]);
            } else {
                $id     = 1;
                $new    = $counter->insert(['table' => $this->table, 'count' => $id]);
            }

            return $id;
        }

        public function cache()
        {
            if (is_null($this->clientCache)) {
                $this->clientCache =  lib('redys', [$this->collection]);
            }

            return $this->clientCache;
        }

        public function clearCache()
        {
            $keys = $this->cache()->keys('*');

            if (!empty($keys)) {
                foreach ($keys as $key) {
                    $this->cache()->del(str_replace($this->collection . '.', '', $key));
                }
            }

            return $this;
        }

        public function getCache($ns = null)
        {
            if (is_null($ns)) {
                return lib('redys', [$this->collection]);
            } else {
                return lib('redys', [$ns]);
            }
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

                    return $this->where([$object, '=', current($args)])->last($obj);
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

                    return $this->findFirstBy($object, current($args), $obj);
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

                    return $this->findOneBy($object, current($args), $obj);
                }
            }

            $method = substr($fn, 0, strlen('orderBy'));
            $object = Inflector::uncamelize(lcfirst(substr($fn, strlen('orderBy'))));

            if (strlen($fn) > strlen('orderBy')) {
                if ('orderBy' == $method) {
                    $fields = $this->fieldsRow();

                    if (!in_array($object, $fields) && 'id' != $object) {
                        $object = in_array($object . '_id', $fields) ? $object . '_id' : $object;
                    }

                    $direction = !empty($args) ? current($args) : 'ASC';

                    return $this->order($object, $direction);
                } elseif ('groupBy' == $method) {
                    $fields = $this->fieldsRow();

                    if (!in_array($object, $fields)) {
                        $object = in_array($object . '_id', $fields) ? $object . '_id' : $object;
                    }

                    return $this->groupBy($object);
                }
            }

            $method = substr($fn, 0, strlen('where'));
            $object = Inflector::uncamelize(lcfirst(substr($fn, strlen('where'))));

            if (strlen($fn) > strlen('where')) {
                if ('where' == $method) {
                    return $this->where([$object, '=', current($args)]);
                }
            }

            $method = substr($fn, 0, strlen('sortBy'));
            $object = Inflector::uncamelize(lcfirst(substr($fn, strlen('sortBy'))));

            if (strlen($fn) > strlen('sortBy')) {
                if ('sortBy' == $method) {
                    $fields = $this->fieldsRow();

                    if (!in_array($object, $fields) && 'id' != $object) {
                        $object = in_array($object . '_id', $fields) ? $object . '_id' : $object;
                    }

                    $direction = !empty($args) ? current($args) : 'ASC';

                    return $this->order($object, $direction);
                } elseif ('findBy' == $method) {
                    $obj = count($args) == 2 ? $args[1] : false;

                    if (!is_bool($obj)) {
                        $obj = false;
                    }

                    return $this->findBy($object, current($args), false, $obj);
                }
            }

            $model = $this->model();
            $scope = lcfirst(Inflector::camelize('scope_' . Inflector::uncamelize($fn)));

            if (method_exists($model, $scope)) {
                return call_user_func_array([$model, $scope], $args);
            }

            throw new Exception("Method '$fn' is unknown.");
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

        private function getTime()
        {
            $time = microtime();
            $time = explode(' ', $time, 2);

            return end($time) + current($time);
        }

        public function lock($action = 'write')
        {
            if (true === $this->cacheEnabled) {
                $key = "lock::$this->db::$this->table::$action";

                $this->cache()->set($key, time());
            }

            return $this;
        }

        public function unlock($action = 'write')
        {
            if (true === $this->cacheEnabled) {
                $key = "lock::$this->db::$this->table::$action";

                $this->cache()->del($key);
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

        public function join($model, $fieldJoin = null)
        {
            $fields = $this->fieldsRow();

            $fieldJoin = is_null($fieldJoin) ? $model . '_id' : $fieldJoin;

            if (!in_array($fieldJoin, $fields)) {
                throw new Exception("'$fieldJoin' unknown in $this->table model. This join is not possible.");
            }

            $this->joinTables[$model] = $fieldJoin;

            return $this;
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

        private function facade()
        {
            $facade     = ucfirst($this->db) . ucfirst($this->table);
            $facade2    = false;

            if ($this->db == SITE_NAME) {
                $facade2 = ucfirst($this->table);
            }

            $class = '\\Dbredis\\' . $facade;

            if (!class_exists($class)) {
                $code = 'namespace Dbredis; class ' . $facade . ' extends Facade { public static $database = "' . $this->db . '"; public static $table = "' . $this->table . '"; }';

                eval($code);

                Alias::facade('Dbr' . $facade, $facade, 'Dbredis');
            }

            if (false !== $facade2) {
                $class2 = '\\Dbredis\\' . $facade2;

                if (!class_exists($class2)) {
                    $code2 = 'namespace Dbredis; class ' . $facade2 . ' extends Facade { public static $database = "' . $this->db . '"; public static $table = "' . $this->table . '"; }';

                    eval($code2);

                    Alias::facade('Dbr' . $facade2, $facade2, 'Dbredis');
                }
            }

            return $this;
        }

        public function fieldsRow()
        {
            $first  = with(new self($this->db, $this->table))->full()->first(true);

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

        public function view($what)
        {
            return View::make($this, $what);
        }

        public static function data($data, $type = null)
        {
            $type === null && $type = MongoBinData::BYTE_ARRAY;

            return new MongoBinData($data, $type);
        }

        public function transaction()
        {
            return new Transaction($this);
        }

        public function drop()
        {
            $db = $this->getCollection();

            $odm = $this->getOdm($this->db);

            $counter    = $odm->selectCollection($this->db . '.counters');
            $tupler     = $odm->selectCollection($this->db . '.tuples');
            $ager       = $odm->selectCollection($this->db . '.ages');

            $counter->remove(['table' => $this->table], ["justOne" => true]);
            $ager->remove(['table' => $this->table], ["justOne" => true]);
            $tupler->remove(['table' => $this->table]);

            $db->drop();

            return $this;
        }

        public function flush()
        {
            $db = $this->getCollection();

            $odm = $this->getOdm($this->db);

            $counter    = $odm->selectCollection($this->db . '.counters');
            $tupler     = $odm->selectCollection($this->db . '.tuples');
            $ager       = $odm->selectCollection($this->db . '.ages');

            $counter->remove(['table' => $this->table], ["justOne" => true]);
            $tupler->remove(['table' => $this->table]);

            $db->remove(['id' => ['$gt' => 0]]);

            return $this->refresh();
        }

        public function refresh()
        {
            $ager = $this->getCollection($this->db . '.ages');

            $ager->update(['table' => $this->table], ['$set' => ['age' => time()]]);

            return $this;
        }

        public function findAndModify($where, array $update)
        {
            unset($update['id']);
            $where = is_numeric($where) ? ['id', '=', $where] : $where;

            $rows = $this->where($where)->exec();

            $collection = [];

            if (!empty($rows)) {
                foreach ($rows as $row) {
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

        public function reduce($map, $reduce, $query = null, $queryOut = [])
        {
            $db     = $this->cnx->selectDB(SITE_NAME);
            $query  = is_null($query)   ? ['id' => ['$gt' => 0]]    : $query;
            $out    = $this->collection . '.fnRes';

            $map    = new \MongoCode($map);
            $reduce = new \MongoCode($reduce);

            // $map    = new \MongoCode("function() { emit(this.$fk, 1); }");
            // $reduce = new \MongoCode("function(k, vals) { ".
            //     "var sum = 0;".
            //     "for (var i in vals) {".
            //         "sum += vals[i];".
            //     "}".
            //     "return sum; }");

            $commandResults = $db->command([
                'mapreduce' => $this->collection,
                'map'       => $map,
                'reduce'    => $reduce,
                'query'     => $query,
                'out'       => $out
            ]);

            return $db->selectCollection($commandResults['result'])->find($queryOut);
        }

        public function aggregate($op, $field)
        {
            set_time_limit(0);

            $ops = [
                [
                    '$group' => [
                        "_id" => ["_id" => '$' . $this->collection],
                        "$op" => ['$' . $op => '$' . $field],
                    ],
                ]
            ];

            $results = $this->getCollection()->aggregate($ops);

            $result = isake($results, 'result', []);

            if (!empty($result)) {
                $row = current($result);

                $val = isAke($row, $op, false);

                if (false !== $val) {
                    return $val;
                }
            }

            return null;
        }

        public function groupByField($field)
        {
            set_time_limit(0);

            $ops = [
                [
                    '$group'    => [
                        "_id"   => [$field => '$' . $field],
                        "count" => ['$sum' => 1],
                    ],
                ]
            ];

            $results = $this->getCollection()->aggregate($ops);

            $results =  isAke($results, 'result', []);

            $collection = [];

            foreach ($results as $result) {
                $collection[] = ['reseller_id' => current($result['_id']), 'count' => $result['count']];
            }

            return $collection;
        }

        public function foreign($collection, $condition)
        {
            $db         = $this->cnx->selectDB(SITE_NAME);
            $collection = !fnmatch('*.*', $collection) ? SITE_NAME . '.' . $collection : $collection;
            $coll       = $db->selectCollection($collection);

            list($dbFk, $tableFk) = explode('.', $collection, 2);

            $fk = $tableFk . '_id';

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
            } else {
                list($field, $operand, $value) = $condition;
            }

            if (!fnmatch('*LIKE*', $operand) && !fnmatch('*IN*', $operand)) {
                if (fnmatch('*IS*', $operand)) {
                    if (!fnmatch('*NOT*', $operand)) {
                        $query = [$field => null];
                    } else {
                        $query = [$field => ['$ne' => null]];
                    }
                } else {
                    $dbOperand = $this->getDbOperand($operand);

                    if (is_string($dbOperand)) {
                        if (fnmatch('*t*', $dbOperand)) {
                            $value = !fnmatch('*.*', $value) && !fnmatch('*.*', $value) ? (int) $value : (float) $value;
                        }

                        $query = [$field => [$dbOperand => $value]];
                    } else {
                        $query = [$field => $value];
                    }
                }
            } else {
                if (fnmatch('*LIKE*', $operand) && !fnmatch('*NOT*', $operand)) {
                    $pattern = str_replace('%', '.*', $value);
                    $query = [$field => new \MongoRegex('/^' . $pattern . '/imxsu')];
                } elseif (fnmatch('*LIKE*', $operand) && fnmatch('*NOT*', $operand)) {
                    $pattern = str_replace('%', '.*', $value);
                    $query = [
                        $field => [
                            '$not' => new \MongoRegex('/^' . $pattern . '/imxsu')
                        ]
                    ];

                } elseif (fnmatch('*IN*', $operand) && !fnmatch('*NOT*', $operand)) {
                    if (!is_array($value)) {
                        $value = str_replace('(', '', $value);
                        $value = str_replace(')', '', $value);
                        $value = str_replace(' ,', ',', $value);
                        $value = str_replace(', ', ',', $value);

                        $values = explode(',', $value);

                        $t = [];

                        foreach ($values as $v) {
                            $t[] = (int) $v;
                        }

                        $value = $t;
                    }

                    $query = [$field => ['$in' => $value]];
                } elseif (fnmatch('*IN*', $operand) && fnmatch('*NOT*', $operand)) {
                    if (!is_array($value)) {
                        $value = str_replace('(', '', $value);
                        $value = str_replace(')', '', $value);
                        $value = str_replace(' ,', ',', $value);
                        $value = str_replace(', ', ',', $value);

                        $values = explode(',', $value);

                        $t = [];

                        foreach ($values as $v) {
                            $t[] = (int) $v;
                        }

                        $value = $t;
                    }

                    $query = [$field => ['$nin' => $value]];
                } elseif ('EXISTS' == $operand) {
                    if (!is_bool($value)) {
                        $value = 'true' ? true : false;
                    }

                    $query = [$field => ['$exists' => $value]];
                } elseif ('ALL' == $operand) {
                    $query = [$field => ['$all' => array_values($value)]];
                } elseif ('SIZE' == $operand) {
                    $query = [$field => ['$size' => $value]];
                } elseif ('TYPE' == $operand) {
                    $query = [$field => ['$type' => $this->resolveType($value)]];
                } elseif ('WORD' == $operand) {
                    $query['$text'] = ['$search' => $value];
                } elseif ('WORDS' == $operand) {
                    $query['$text'] = ['$search' => "\"$value\""];
                } elseif ('SENTENCE' == $operand) {
                    $query['$text'] = ['$search' => "\"$value\""];
                } elseif ('NOR' == $operand) {
                    $query['$nor'] = $value;
                } elseif ('NOT' == $operand) {
                    $query['$not'] = $value;
                } elseif ('WHERE' == $operand) {
                    $query['$where'] = $value;
                }
            }

            $coll->ensureIndex(['id' => 1]);

            $results = new Cursor($coll->find($query, ['id' => true]));

            if ($results->count() == 0) {
                return $this->none();
            }

            $ids = [];

            foreach ($results as $row) {
                $ids[] = $row['id'];
            }

            return $this->where(['id', 'IN', implode(',', $ids)]);
        }

        public function rqlForeign($collection, $query)
        {
            $db         = $this->cnx->selectDB(SITE_NAME);
            $collection = !fnmatch('*.*', $collection) ? SITE_NAME . '.' . $collection : $collection;
            $coll       = $db->selectCollection($collection);

            list($dbFk, $tableFk) = explode('.', $collection, 2);

            $fk = $tableFk . '_id';

            $coll->ensureIndex([$fk => 1]);

            $results = new Cursor($coll->find($query, [$fk => true]));

            if ($results->count() == 0) {
                return $this->none();
            }

            $ids = [];

            foreach ($results as $row) {
                $ids[] = $row[$fk];
            }

            return $this->where([$fk, 'IN', implode(',', $ids)]);
        }

        public function rql($query)
        {
            $db     = $this->cnx->selectDB(SITE_NAME);
            $coll   = $db->selectCollection($this->collection);

            $coll->ensureIndex(['id' => 1]);

            $results = new Cursor($coll->find($query, ['id' => true]));

            if ($results->count() == 0) {
                return $this->none();
            }

            $ids = [];

            foreach ($results as $row) {
                $ids[] = $row['id'];
            }

            return $this->where(['id', 'IN', implode(',', $ids)]);
        }

        public function segment(array $query)
        {
            $db     = $this->cnx->selectDB(SITE_NAME);
            $coll   = $db->selectCollection($this->collection);
            $hash   = sha1(serialize($query));
            $kAge   = 'segment.age.' . $hash;
            $kData  = 'segment.data.' . $hash;

            $age    = $this->getAge();

            $ageC   = $this->cache()->get($kAge, 0);

            if ($ageC > 0 && $ageC > $age) {
                $ids = unserialize($this->cache()->get($kData, []));
            } else {
                $ids = [];
                $coll->ensureIndex(['id' => 1]);

                $results = new Cursor($coll->find($query, ['id' => true]));

                if ($results->count() > 0) {
                    foreach ($results as $row) {
                        $ids[] = $row['id'];
                    }
                }

                $this->cache()->set($kAge, time());
                $this->cache()->set($kData, serialize($ids));
            }

            $q = $this;

            if (!empty($ids)) {
                $q = $q->where(['id', 'IN', implode(',', $ids)]);
            }

            return $q;
        }

        public static function importJson($table)
        {
            $data   = jmodel($table)->full()->orderById()->exec();
            $db     = self::instance(SITE_NAME, $table);

            foreach ($data as $row) {
                $insert = $db->addWithId($db->treatCast($row));
            }

            dd($insert);
        }

        public function treatCast($tab)
        {
            if (!empty($tab)) {
                foreach ($tab as $k => $v) {
                    if (fnmatch('*_id', $k) && !empty($v)) {
                        if (is_numeric($v)) {
                            $tab[$k] = (int) $v;
                        }
                    }
                }
            }

            return $tab;
        }

        public function addWithId(array $data)
        {
            $keep = $data;

            unset($keep['id']);
            unset($keep['created_at']);
            unset($keep['updated_at']);
            unset($keep['deleted_at']);

            $keyTuple = sha1($this->db . $this->table . serialize($keep));

            $tuple = $this->tuple($keyTuple);

            $id = isAke($data, 'id', false);

            if (false !== $id) {
                if (is_numeric($id)) {
                    $id = (int) $id;

                    if (strlen($tuple)) {
                        $this->delTuple($id);
                    }

                    $db = $this->getCollection();

                    $row = $db->insert($data);

                    unset($data['_id']);

                    $this->addTuple($id, $keyTuple);

                    $this->setAge();

                    $counter    = $this->getCollection($this->db . '.counters');
                    $last       = $counter->findOne(['table' => $this->table]);

                    if ($last) {
                        $update = $counter->update(['table' => $this->table], ['$set' => ['count' => $id]]);
                    } else {
                        $new    = $counter->insert(['table' => $this->table, 'count' => $id]);
                    }

                    return $this->model($data);
                }
            }
        }

        public function distinct($field, $query = null)
        {
            $db         = $this->cnx->selectDB(SITE_NAME);
            $coll       = $db->selectCollection($this->collection);

            if (is_null($query)) {
                $res = $coll->distinct($field);
            } else {
                $res = $coll->distinct($field, $query);
            }

            sort($res);

            return $res;
        }

        public function group($field, $query = null, $returnRows = false)
        {
            $hash       = sha1(serialize(func_get_args()));
            $kAge       = 'group.age.' . $hash;
            $kData      = 'group.data.' . $hash;

            $age        = $this->getAge();

            $ageC       = $this->cache()->get($kAge, 0);

            if ($ageC > 0 && $ageC > $age) {
                return unserialize($this->cache()->get($kData, []));
            }

            $db         = $this->cnx->selectDB(SITE_NAME);
            $coll       = $db->selectCollection($this->collection);
            $keys       = [$field => true];
            $initial    = ["rows" => []];
            $reduce     = new \MongoCode("function (obj, prev) { prev.rows.push(obj); }");

            if (is_null($query)) {
                $group = $coll->group($keys, $initial, $reduce);
            } else {
                $group = $coll->group($keys, $initial, $reduce, ['condition' => $query]);
            }

            $collection = [];

            $results = $group['retval'];

            if (!empty($results)) {
                foreach ($results as $row) {
                    $rows = isAke($row, 'rows', []);

                    if ($returnRows) {
                        if (!empty($rows)) {
                            foreach ($rows as $item) {
                                unset($item['_id']);
                                $exists = isAke($item, 'id', false);

                                if (false === $exists) {
                                    continue;
                                }

                                array_push($collection, $item);
                            }
                        }
                    } else {
                        $collection[$row[$field]] = count($rows);
                    }
                }
            }

            if (!$returnRows) {
                asort($collection);
                $collection = array_reverse($collection);
            }

            $this->cache()->set($kData, serialize($collection));
            $this->cache()->set($kAge, time());

            return $collection;
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

                return $this->none();
            }
        }

        public function take($what, $where = null, $object = false)
        {
            return with(new Take($this))->take($what, $where, $object);
        }

        public function with($what, $object = false)
        {
            $collection = $ids = $foreigns = $foreignsCo = [];

            if (is_string($what)) {
                if (fnmatch('*,*', $what)) {
                    $what = explode(',', str_replace(' ', '', $what));
                }

                $res = $this->exec($object);
            } elseif (is_array($what)) {
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
                            if (!in_array($value, $ids)) {
                                array_push($ids, $value);
                            }
                        }
                    } elseif (is_array($what)) {
                        foreach ($what as $fk) {
                            if (!isset($ids[$fk])) {
                                $ids[$fk] = [];
                            }

                            $value = isAke($row, $fk . '_id', false);

                            if (false !== $value) {
                                if (!in_array($value, $ids[$fk])) {
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
                                $id = $object ? (int) $foreign->id : (int) $foreign['id'];
                                $foreignsCo[$id] = $foreign;
                            }
                        }
                    } elseif (is_array($what)) {
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
                                    if (isset($r->$whatId)) {
                                        if (isset($foreignsCo[$r->$whatId])) {
                                            $r->$what = $foreignsCo[$r->$whatId];
                                        }
                                    }
                                } else {
                                    if (isset($r[$whatId])) {
                                        if (isset($foreignsCo[$r[$whatId]])) {
                                            $r[$what] = $foreignsCo[$r[$whatId]];
                                        }
                                    }
                                }

                                array_push($collection, $r);
                            }
                        } elseif (is_array($what)) {
                            foreach ($res as $r) {
                                foreach ($what as $fk) {
                                    $fkId = $fk . '_id';

                                    if (is_object($r)) {
                                        if (isset($r->$fkId)) {
                                            if (isset($foreignsCo[$fk])) {
                                                if (isset($foreignsCo[$fk][$r->$fkId])) {
                                                    $r->$fk = $foreignsCo[$fk][$r->$fkId];
                                                }
                                            }
                                        }
                                    } else {
                                        if (isset($r[$fkId])) {
                                            if (isset($foreignsCo[$fk])) {
                                                if (isset($foreignsCo[$fk][$r[$fkId]])) {
                                                    $r[$fk] = $foreignsCo[$fk][$r[$fkId]];
                                                }
                                            }
                                        }
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

        public function multiQuery(array $queries)
        {
            foreach ($queries as $query) {
                $count = count($query);

                switch ($count) {
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

        public function backup($database = null)
        {
            set_time_limit(0);

            $database = is_null($database) ? SITE_NAME : $database;

            $path = STORAGE_PATH . DS . 'backup';

            if (!is_dir($path)) {
                File::mkdir($path);
            }

            $now    = time();
            $next   = $now + 900; /* Toutes les 15 minutes */

            $collection = [];

            $db = $this->getOdm();

            $backup = Light::Backup();

            $collections = $db->getCollectionNames();

            $save = false;

            foreach ($collections as $coll) {
                list($collDb, $collTable) = explode('.', $coll, 2);

                if ($collDb != $database) {
                    continue;
                }

                $row = $backup->where(['collection', '=', $coll])->first(true);

                if (!$row) {
                    $row = $backup->firstOrCreate([
                        'collection' => $coll
                    ])->setNext($now)->save();
                }

                if ($now > $row->next) {
                    $datas = $db->selectCollection($coll)->find();

                    if (!empty($datas)) {
                        $file = STORAGE_PATH . DS . 'backup' . DS . str_replace('.', '_', $coll) . '.php';
                        File::delete($file);
                        File::put($file, '<?php' . "\n" . '$datas = [];' . "\n");

                        foreach ($datas as $data) {
                            unset($data['_id']);
                            $json = json_encode($data);
                            File::append($file, '$datas[] = ' . var_export($data, 1) . ';' . "\n");
                        }

                        File::append($file, 'return $datas;');

                        $row->setNext($next)->save();

                        $save = true;
                    }
                }
            }

            if (true === $save) {
                $cmd = "cd /tmp && zip -r backup_database_" . date('Ymd-His') . ".zip " . $path;
                exec($cmd);
            }

            dd(Timer::get());
        }

        public function restore($database = null)
        {
            set_time_limit(0);

            $database = is_null($database) ? SITE_NAME : $database;

            $files = glob(STORAGE_PATH . DS . 'backup' . DS . '*.php', GLOB_NOSORT);

            foreach ($files as $file) {
                $seg                = str_replace('.php', '', end(explode('/', $file)));
                list($db, $table)   = explode('_', $seg, 2);

                if ($db != $database) {
                    continue;
                }

                $datas = include($file);

                if (!empty($datas)) {
                    $first  = current($datas);
                    $id     = isAke($first, 'id', false);

                    if (false !== $id) {
                        $i = self::instance($db, $table);

                        foreach ($datas as $row) {
                            $obj = $i->model($row);

                            $obj->restore();
                        }
                    }
                }
            }

            dd(Timer::get());
        }

        public function native($query = [], $select = [])
        {
            $db     = $this->cnx->selectDB(SITE_NAME);
            $coll   = $db->selectCollection($this->collection);

            $coll->ensureIndex(['id' => 1]);

            $query = $this->prepare($query);

            return new Cursor($coll->find($query, $select), $this);
        }

        public function orm()
        {
            return new Orm($this);
        }

        public function seeds($database = null)
        {
            set_time_limit(0);

            $database = is_null($database) ? SITE_NAME : $database;

            $path = STORAGE_PATH . DS . 'seeds';

            if (!is_dir($path)) {
                File::mkdir($path);
            }

            $now    = time();

            $db = $this->getOdm();

            $backup = self::instance('core', 'backup');

            $collections = $db->getCollectionNames();

            $key = array_search('zelift.ages', $collections);

            if (strlen($key)) {
                unset($collections[$key]);
            }

            $key = array_search('zelift.counters', $collections);

            if (strlen($key)) {
                unset($collections[$key]);
            }

            $key = array_search('zelift.tuples', $collections);

            if (strlen($key)) {
                unset($collections[$key]);
            }

            $key = array_search('zelift.caching', $collections);

            if (strlen($key)) {
                unset($collections[$key]);
            }

            $key = array_search('zelift.ages', $collections);

            if (strlen($key)) {
                unset($collections[$key]);
            }

            foreach ($collections as $coll) {
                list($collDb, $collTable) = explode('.', $coll, 2);

                if ($collDb != $database) {
                    continue;
                }

                $row = $backup->where(['collection', '=', $coll])->first(true);

                if (!$row) {
                    $row = $backup->firstOrCreate([
                        'collection' => $coll
                    ])->setWhen($now)->save();
                }

                $file = STORAGE_PATH . DS . 'seeds' . DS . str_replace('.', '_', $coll) . '.php';

                $model = self::instance($collDb, $collTable);

                $last = $model->where(['id', '>', 0])->select('updated_at')->order('updated_at', 'DESC')->first(true);

                if ($last) {
                    if ($last->updated_at > $row->when || !File::exists($file)) {
                        if (!File::exists($file)) {
                            $datas = $model->select('id')->get();
                        } else {
                            $datas = $model->where(['updated_at', '>=', (int) $now])->select('id')->get();
                            File::delete($file);
                        }

                        if (!empty($datas)) {
                            File::put($file, '<?php' . "\nnamespace Thin;\n\n");

                            $code = '';
                            $code .= '$db = rdb("' . $collDb . '", "' . $collTable . '");' . "\n\n\n";

                            File::append($file, $code);

                            foreach ($datas as $rowData) {
                                $data = $model->find((int) $rowData['id'], false);

                                $codeRow = '// *** ligne ' . $data['id'] . ' ***' . "\n";
                                $codeRow .= '$row = $db->find(' . $data['id'] . ');' . "\n\n";
                                $codeRow .= 'if (!$row) {' . "\n";
                                $codeRow .= "\t" . '$row = $db->addWithId(["id" => ' . $data['id'] . ']);' . "\n";
                                $codeRow .= '}' . "\n\n";

                                unset($data['id']);

                                $codeRow .= '$data = ' . var_export($data, 1) . ';' . "\n";
                                $codeRow .= '$row->hydrate($data)->save();' . "\n";
                                $codeRow .= '// *** fin ligne ' . $data['id'] . ' ***' . "\n\n";

                                File::append($file, $codeRow);
                            }

                            $row->setWhen($now)->save();
                        }
                    }
                }
            }

            dd(Timer::get());
        }

        public function makeBackup($database = null)
        {
            set_time_limit(0);

            $database = is_null($database) ? SITE_NAME : $database;

            $db = $this->getOdm();

            $collections = $db->getCollectionNames();

            $key = array_search('zelift.ages', $collections);

            if (strlen($key)) {
                unset($collections[$key]);
            }

            $key = array_search('zelift.counters', $collections);

            if (strlen($key)) {
                unset($collections[$key]);
            }

            $key = array_search('zelift.tuples', $collections);

            if (strlen($key)) {
                unset($collections[$key]);
            }

            $key = array_search('zelift.caching', $collections);

            if (strlen($key)) {
                unset($collections[$key]);
            }

            $key = array_search('zelift.ages', $collections);

            if (strlen($key)) {
                unset($collections[$key]);
            }

            $i = 0;

            foreach ($collections as $coll) {
                $path = \Thin\Config::get('application.backup_dir');

                if (!is_dir($path)) {
                    return false;
                }

                list($collDb, $collTable) = explode('.', $coll, 2);

                if ($collDb != $database) {
                    continue;
                }

                $path = $path . DS . $collDb;

                if (!is_dir($path)) {
                    File::mkdir($path);
                }

                $path = $path . DS . $collTable;

                if (!is_dir($path)) {
                    File::mkdir($path);
                }

                $model = self::instance($collDb, $collTable);

                $cursor = $model->cursor();

                while ($row = $cursor->fetch()) {
                    if (isset($row['id'])) {
                        $file = $path . DS . $row['id'] . '.json';
                        File::put($file, json_encode($row));
                        $i++;
                    }
                }
            }

            $now = date("d_m_Y_H_i_s");

            $path = \Thin\Config::get('application.backup_dir', false);
            $user = \Thin\Config::get('redis.ftp.backup.user', false);
            $password = \Thin\Config::get('redis.ftp.backup.password', false);
            $host = \Thin\Config::get('redis.ftp.backup.host', false);

            if (false !== $path && false !== $user && false !== $password && false !== $host) {
                $cmd = "cd $path && tar cfvz zelift_$now.tar.gz $path
lftp -e 'put $path/zelift_$now.tar.gz; bye' -u \"$user\",$password $host
rm zelift_$now.tar.gz
echo 'done'";

                passthru($cmd);
            }

            vd($i);
            dd(Timer::get());
        }

        public function cursor()
        {
            return new Results($this);
        }

        public function listTables()
        {
            $db = $this->getOdm();

            $collections = $db->getCollectionNames();

            $key = array_search('zelift.ages', $collections);

            if (strlen($key)) {
                unset($collections[$key]);
            }

            $key = array_search('zelift.counters', $collections);

            if (strlen($key)) {
                unset($collections[$key]);
            }

            $key = array_search('zelift.tuples', $collections);

            if (strlen($key)) {
                unset($collections[$key]);
            }

            $key = array_search('zelift.caching', $collections);

            if (strlen($key)) {
                unset($collections[$key]);
            }

            $key = array_search('zelift.ages', $collections);

            if (strlen($key)) {
                unset($collections[$key]);
            }

            $zelift = [];

            foreach ($collections as $coll) {
                if (fnmatch(SITE_NAME . '.*', $coll)) {
                    $zelift[] = $coll;
                }
            }

            asort($zelift);

            return array_values($zelift);
        }

        public function chain($tab)
        {
            foreach ($tab as $k => $v) {
                $this->where([$k, '=', $v]);
            }

            return $this;
        }
    }
