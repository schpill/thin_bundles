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

    namespace Mysqldb;

    use Thin\Config;
    use Thin\Exception;
    use Thin\Arrays;
    use Thin\Inflector;
    use Thin\Utils;
    use Thin\Alias;
    use Thin\File;
    use Thin\Instance;
    use Thin\Database\Collection;
    use Dbredis\Caching;
    use PDO;

    class Db
    {
        private $datas = [];

        public function __construct($db, $table, $config = [])
        {
            $db                 = strtolower($db);
            $table              = strtolower($table);

            $this->db           = $db;
            $this->table        = $table;
            $this->collection   = "$db.$table";

            $host               = Config::get('database.host', isAke($config, 'host', '127.0.0.1'));
            $port               = Config::get('database.port', isAke($config, 'port', 3306));
            $adapter            = Config::get('database.adapter', isAke($config, 'adapter', 'mysql'));
            $driver             = Config::get('database.driver', isAke($config, 'driver', 'pdo_mysql'));
            $username           = Config::get('database.username', isAke($config, 'username', 'root'));
            $password           = Config::get('database.password', isAke($config, 'password', 'root'));

            $dsn                = $adapter . ":dbname=" . $db . ";host=" . $host;
            $this->engine       = new PDO($dsn, $username, $password);
            $this->map();
        }

        public function __destruct()
        {
            $this->datas = [];
        }

        public static function instance($db, $table, $config = [])
        {
            $args   = func_get_args();
            $key    = sha1(serialize($args));
            $has    = Instance::has('DbMysql', $key);

            if (true === $has) {
                return Instance::get('DbMysql', $key);
            } else {
                return Instance::make('DbMysql', $key, new self($db, $table, $config));
            }
        }

        public function create($data = [])
        {
            return $this->model($data);
        }

        public function model($data = [])
        {
            $view   = false;
            $db     = $this->db;
            $table  = $this->table;

            $modelFile = APPLICATION_PATH . DS . 'models' . DS . 'Mysql' . DS . 'models' . DS . Inflector::lower($db) . DS . ucfirst(Inflector::lower($table)) . '.php';

            if (!is_dir(APPLICATION_PATH . DS . 'models' . DS . 'Mysql')) {
                File::mkdir(APPLICATION_PATH . DS . 'models' . DS . 'Mysql');
            }

            if (!is_dir(APPLICATION_PATH . DS . 'models' . DS . 'Mysql' . DS . 'models')) {
                File::mkdir(APPLICATION_PATH . DS . 'models' . DS . 'Mysql' . DS . 'models');
            }

            if (!is_dir(APPLICATION_PATH . DS . 'models' . DS . 'Mysql' . DS . 'models' . DS . Inflector::lower($db))) {
                File::mkdir(APPLICATION_PATH . DS . 'models' . DS . 'Mysql' . DS . 'models' . DS . Inflector::lower($db));
            }

            if (!File::exists($modelFile)) {
                File::put($modelFile, str_replace('##class##', ucfirst(Inflector::lower($db)) . ucfirst(Inflector::lower($table)) . 'SQLModel', File::read(__DIR__ . DS . 'dbModel.tpl')));
            }

            $class = '\\Thin\\' . ucfirst(Inflector::lower($db)) . ucfirst(Inflector::lower($table)) . 'SQLModel';

            if (!class_exists($class)) {
                require_once $modelFile;
            }

            $model = $this;

            if (true === $view) {
                $model = self::instance($db, $table);
            }

            return new $class($model, $data);
        }

        public function save(array $data)
        {
            $id = isAke($data, $this->pk(), false);

            return !$id ? $this->add($data) : $this->edit($id, $data);
        }

        private function edit($id, $data)
        {
            $pk = $this->pk();

            $saveFields = $this->fieldsSave();

            $q = "UPDATE $this->db.$this->table SET ";

            foreach($saveFields as $field) {
                $value = $row->$field;
                $q .= "$this->db.$this->table.$field = " . $this->quote($value) . ', ';
            }

            $q = substr($q, 0, -2) . " WHERE $this->db.$this->table.$pk = " . $this->quote($id);

            $res = $this->engine->prepare($q);
            $res->execute();

            return $this->model($data);
        }

        private function add($data)
        {
            $saveFields = $this->fieldsSave();
            $q = "INSERT INTO $this->db.$this->table (" . implode(', ', $saveFields) . ") VALUES (";

            foreach($saveFields as $field) {
                $value = isAke($data, $field, null);
                $q .= $this->quote($value) . ', ';
            }

            $q = substr($q, 0, -2) . ')';

            $res = $this->engine->prepare($q);
            $res->execute();

            $data[$this->pk()] = $this->engine->lastInsertId();

            return $this->model($data);
        }

        public function delete($id)
        {
            $db = $this->engine;
            $pk = $this->pk();

            if (isset($row->$pk)) {
                $q = "DELETE FROM $this->db.$this->table WHERE $this->db.$this->table.$pk = " . $this->quote($id);
                $res = $db->prepare($q);
                $res->execute();

                return true;
            }

            return false;
        }

        public function fieldsSave()
        {
            $pk         = $this->pk();
            $fields     = $this->fields();
            $collection = [];

            foreach ($fields as $field) {
                if ($pk != $field) {
                    $collection[] = $field;
                }
            }

            return $collection;
        }

        protected function quote($value, $parameterType = PDO::PARAM_STR)
        {
            if (empty($value)) {
                return "NULL";
            }

            $db = $this->engine;

            if (is_string($value)) {
                return $db->quote($value, $parameterType);
            }

            return $value;
        }

        public function query($sql = null, $object = false)
        {
            $db         = $this->engine;
            $sql        = empty($sql) ? $this->makeSql() : $sql;

            $res        = $db->prepare($sql);
            $res->execute();
            $result     = [];

            if (false !== $res) {
                $cols = [];

                while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
                    $cols[] = $object ? $this->model($row) : $row;
                }

                $result = $cols;
            }

            $this->count = count($result);

            return $result;
        }

        private function map()
        {
            $q      = "SHOW COLUMNS FROM $this->table";
            $res    = $this->query($q, false);

            if (empty($res)) {
                throw new Exception("The system cannot access to the table $this->table on $this->db.");
            }

            $conf = $pks = $keys = [];

            foreach ($res as $data) {
                $field                      = $data['Field'];
                $conf[$field]               = [];
                $conf[$field]['type']       = typeSql($data['Type']);
                $conf[$field]['nullable']   = ('yes' == Inflector::lower($data['Null'])) ? true : false;

                if ($data['Key'] == 'PRI') {
                    array_push($pks, $field);
                }

                if (strlen($data['Key']) && $data['Key'] != 'PRI') {
                    array_push($keys, $field);
                }
            }

            $this->pks      = $pks;
            $this->keys     = $keys;
            $this->fields   = $conf;
        }

        public function pk()
        {
            if (!empty($this->pks)) {
                return Arrays::first($this->pks);
            }

            return 'id';
        }

        public function fields()
        {
            return array_keys($this->fields);
        }

        public function __get($key)
        {
            return isAke($this->datas, $key, null);
        }

        public function __set($key, $value)
        {
            $this->datas[$key] = $value;

            return $this;
        }

        public function __unset($key)
        {
            unset($this->datas[$key]);

            return $this;
        }

        public function __isset($key)
        {
            return isset($this->datas[$key]);
        }

        public function cache()
        {
            $has    = Instance::has('MysqlDbCache', sha1($this->db . $this->table));

            if (true === $has) {
                return Instance::get('MysqlDbCache', sha1($this->db . $this->table));
            } else {
                $instance = new Caching($this->collection);

                return Instance::make('MysqlDbCache', sha1($this->db . $this->table), $instance);
            }
        }

        public function findBy($field, $value, $one = false, $object = false)
        {
            if ($field == 'id') {
                $field = $this->pk();
            }

            $q = "SELECT ". $this->db . '.' . $this->table . '.' . implode(', ' . $this->db . '.' . $this->table . '.' , $this->fields()) . "
                FROM $this->db.$this->table
                WHERE $this->db.$this->table.$field = " . $this->quote($value);

            $res = $this->query($q, $object);

            if (true === $one && !empty($res)) {
                return Arrays::first($res);
            }

            return true === $one ? null : $res;
        }

        public function find($id)
        {
            return $this->findBy($this->pk(), $id, true, true);
        }

        private function makeSql()
        {
            $join = $distinct = $groupBy = $order = $limit = '';

            $where = empty($this->wheres) ? '1 = 1' : implode('', $this->wheres);

            if (ake('order', $this->query)) {
                $order = 'ORDER BY ';
                $i = 0;

                foreach ($this->query['order'] as $qo) {
                    list($field, $direction) = $qo;

                    if ($i > 0) {
                        $order .= ', ';
                    }

                    $order .= "$this->db.$this->table.$field $direction";
                    $i++;
                }
            }

            if (ake('limit', $this->query)) {
                list($max, $offset) = $this->query['limit'];
                $limit = "LIMIT $offset, $max";
            }

            if (ake('join', $this->query)) {
                $join = implode(' ', $this->query['join']);
            }

            if (ake('groupBy', $this->query)) {
                $groupBy = 'GROUP BY ' . $this->query['groupBy'];
            }

            if (ake('distinct', $this->query)) {
                $distinct = true === $this->query['distinct'] ? 'DISTINCT' : '';
            }

            $sql = "SELECT $distinct " . $this->db . '.' . $this->table . '.' . implode(', ' . $this->db . '.' . $this->table . '.' , $this->fields()) . "
                FROM $this->db.$this->table $join
                WHERE $where $order $limit $groupBy";

            return $sql;
        }
    }
