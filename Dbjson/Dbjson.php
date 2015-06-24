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

    use Closure;
    use Thin\Utils;
    use Thin\Alias;
    use Thin\App;
    use Thin\Exception;
    use Thin\Instance;
    use Thin\File;
    use Thin\Arrays;
    use Thin\Arr;
    use Thin\Inflector;
    use Thin\Route;
    use Thin\Container;
    use Thin\Event;
    use Thin\Timer;
    use Thin\Database\Collection;
    use Thin\Config as AppConfig;
    use Elasticsearch\Common\Exceptions\Missing404Exception as E404;

    class Dbjson
    {
        public $dir, $model, $db, $table, $results, $view, $esEnabled, $session, $env;

        public $wheres              = [];
        public $take                = [];
        public $keys                = [];
        public $isView              = false;
        public $forceEs             = false;
        public $cacheEnabled        = true;
        public $count               = 0;
        public static $config       = [];
        public static $queries      = 0;
        public static $duration     = 0;

        private $joinTable          = [];
        private $hooks              = [];
        private $fileHandle;

        public function __construct($db, $table)
        {
            /* CLI case */
            defined('APPLICATION_ENV') || define('APPLICATION_ENV', 'production');

            $this->esEnabled    = Config::get('es.enabled', true);

            $this->db           = $db;
            $this->table        = $table;

            $this->setEnv();

            $path = $this->dirStore();

            if (!is_dir($path . DS . 'dbjson')) {
                umask(0000);

                File::mkdir($path . DS . 'dbjson', 0777, true);
            }

            if (!is_dir($path . DS . 'dbjson' . DS . Inflector::lower($this->db . '_' . $this->getEnv()))) {
                umask(0000);

                File::mkdir($path . DS . 'dbjson' . DS . Inflector::lower($this->db . '_' . $this->getEnv()), 0777, true);
            }

            $this->dir  = $path . DS . 'dbjson' . DS . Inflector::lower($this->db . '_' . $this->getEnv()) . DS . Inflector::lower($this->table);

            if (!is_dir($this->dir)) {
                umask(0000);

                File::mkdir($this->dir, 0777, true);
            }

            if (true === $this->cacheEnabled) {
                $this->getAge();
            }

            $this->facade();

            if (false === CLI) $this->session = Session::instance($this);
        }

        public function __destruct()
        {
            $this->reset();
        }

        public function getAge()
        {
            $key = 'dbjson.age.' . $this->db . '.' . $this->table;
            $age = $this->cache()->get($key);

            if (!strlen($age)) {
                $age = strtotime('-1 day');
                $this->setage($age);
            }

            return $age;
        }

        public function setage($age = null)
        {
            $key = 'dbjson.age.' . $this->db . '.' . $this->table;
            $age = is_null($age) ? time() : $age;

            $this->cache()->set($key, $age);

            return $this;
        }

        public function getEnv()
        {
            return !isset($this->env) ? APPLICATION_ENV : $this->env;
        }

        public function setEnv($env = null)
        {
            $env = is_null($env) ? APPLICATION_ENV : $env;
            $this->env = $env;

            return $this;
        }

        public static function instance($db, $table)
        {
            $args   = func_get_args();
            $key    = sha1(serialize($args));
            $has    = Instance::has('Dbjson', $key);

            if (true === $has) {
                return Instance::get('Dbjson', $key);
            } else {
                return Instance::make('Dbjson', $key, new self($db, $table));
            }
        }

        public function session()
        {
            return $this->session;
        }

        public function import(array $array)
        {
            if (!empty($array)) {
                $data = [];

                foreach ($array as $row) {
                    if (Arrays::is($row) && Arrays::isAssoc($row)) {
                        foreach ($row as $key => $value) {
                            $newRow[Inflector::urlize($key, '_')] = str_replace('[NL]', "\n", $value);
                        }

                        array_push($data, $newRow);
                    }
                }

                if (!empty($data)) {
                    foreach ($data as $row) {
                        $this->save($row);
                    }
                }
            }

            return $this;
        }

        public function importCsvData($csv, $delimiter = '|||')
        {
            $rows   = explode("\n", $csv);
            $fields = explode($delimiter, Arrays::first($rows));
            array_shift($rows);

            $collection = [];

            foreach ($rows as $row) {
                $newRow     = [];
                $fieldsRow  = explode($delimiter, $row);

                $i = 0;

                foreach ($fieldsRow as $fieldRow) {
                    $newRow[$fields[$i]] = $fieldRow;
                    $i++;
                }

                array_push($collection, $newRow);
            }

            return $this->import($collection);
        }

        public function importCsvFile($csv, $delimiter = '|||')
        {
            return $this->importCsvData(File::read($csv), $delimiter);
        }

        public function importCsvUpload($field, $delimiter = '|||')
        {
            if (!empty($_FILES)) {
                $fileupload         = $_FILES[$field]['tmp_name'];
                $fileuploadName     = $_FILES[$field]['name'];

                if (strlen($fileuploadName)) {
                    return $this->importCsvFile($fileupload, $delimiter);
                }
            }

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

        public function count()
        {
            $count = count($this->results);
            $this->reset();

            return $count;
        }

        public function post($save = false)
        {
            return !$save ? $this->create($_POST) : $this->create($_POST)->save();
        }

        public function save($data, $object = true)
        {
            $start = $this->getTime();

            if (true === $this->cacheEnabled) {
                $key = "lock::$this->db::$this->table::write";

                $lock = strlen($this->cache()->get($key)) > 0 ? true : false;

                if (true === $lock) {
                    throw new Exception("This table $this->db $this->table is locked to write.");
                }
            }

            if (is_object($data) && $data instanceof Container) {
                $data = $data->assoc();
            }

            $id = isAke($data, 'id', null);

            if (true === $this->cacheEnabled) {
                $this->cache()->del(sha1($this->dir));
            }

            $this->countQuery($start);

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

            $hooks = $this->hooks();

            $before     = isAke($hooks, 'before_create', false);
            $after      = isAke($hooks, 'after_create', false);
            $revision   = isAke($hooks, 'keep_revision', false);
            $indices    = isAke($hooks, 'indices', []);
            $uniques    = isAke($indices, 'uniques', []);

            if (!empty($uniques)) {
                foreach ($uniques as $unique) {
                    $valIndex = isAke($data, $unique, false);

                    if (false === $valIndex) {
                        throw new Exception("The field '$unique' is an unique index of this table '$this->table'. It is required to occure.");
                    }

                    $checkDb    = self::instance($this->db, $this->table);
                    $count      = $checkDb->where([$unique, '=', $valIndex])->count();

                    if ($count > 0) {
                        throw new Exception("The field '$unique' is an unique index of this table '$this->table'. Its value exists ever in this table.");
                    }
                }
            }

            /* check required fields and their lengthes */
            $crud = Crud::instance($this);
            $crudFields = $crud->fields();

            foreach ($crudFields as $field) {
                $required   = $this->fieldConfig($field, 'required', false);
                $length     = $this->fieldConfig($field, 'length', false);
                $default    = $this->fieldConfig($field, 'default', null);

                if (!empty($default)) {
                    $valField   = isAke($data, $field, '');

                    if (!strlen($valField)) {
                        $data[$field] = $default;
                    }
                }

                $valField   = isAke($data, $field, $default);

                if (true === $required) {
                    if (Arrays::is($valField)) {
                        if (empty($valField)) {
                            throw new Exception("The field '$field' is required field of this table '$this->table'.");
                        }
                    } else {
                        if (!strlen($valField)) {
                            throw new Exception("The field '$field' is required field of this table '$this->table'.");
                        } else {
                            if (false !== $length && is_string($valField)) {
                                if (strlen($valField) > $length) {
                                    throw new Exception("The field '$field' is too large. Max length is $length.");
                                }
                            }
                        }
                    }
                } else {
                    if (false !== $length && is_string($valField)) {
                        if (strlen($valField) > $length) {
                            throw new Exception("The field '$field' is too large. Max length is $length.");
                        }
                    }
                }
            }

            if (false !== $before) {
                $backup = $data;
                $data   = $before($data);

                $data   = empty($data) ? $backup : $data;
                Event::run("$this->db.$this->table.before.create");
            }

            if (true === $this->cacheEnabled) {
                $this->cache()->set(sha1($this->dir), time());
            }

            $data['created_at'] = $data['updated_at'] = time();
            $this->lastInsertId = $this->makeId();
            $data['id']         = $this->lastInsertId;

            $tuple = $this->tuple($data);

            foreach ($data as $k => $v) {
                if ($v instanceof Closure) {
                    unset($data[$k]);
                }
            }

            if (false === $tuple) {
                $file   = $this->dir . DS . $this->lastInsertId . '.row';
                $this->writeFile($file, json_encode($data));
            } else {
                $file   = $this->dir . DS . $tuple . '.row';
                $data   = json_decode($this->readFile($file), true);
            }

            if (false !== $after) {
                $backup = $data;
                $data   = $after($this->row($data));

                $data   = empty($data) ? $backup : $data;

                if (true === Event::listeners("$this->db.$this->table.after.create")) {
                    $data = Event::run("$this->db.$this->table.after.create", [$this->row($data)]);
                }
            }

            if (true === $revision) {
                if (true === $this->cacheEnabled) {
                    $revisionId     = $this->cache()->incr("revisions::$this->db::$this->table::" . $data['id']);

                    $rowRevision    = jdb('system', 'revisionrecord')
                    ->create()
                    ->setDatabase($this->db)
                    ->setTable($this->table)
                    ->setNumber($revisionId)
                    ->setId($data['id'])
                    ->setData($new)
                    ->save();
                }
            }

            return true === $object ? $this->row($data) : $data;
        }

        private function edit($id, $data, $object = true)
        {
            if (!Arrays::is($data)) {
                return $data;
            }

            $hooks = $this->hooks();

            $before     = isAke($hooks, 'before_update', false);
            $after      = isAke($hooks, 'after_update', false);
            $revision   = isAke($hooks, 'keep_revision', false);
            $indices    = isAke($hooks, 'indices', []);
            $uniques    = isAke($indices, 'uniques', []);

            if (!empty($uniques)) {
                foreach ($uniques as $unique) {
                    $valIndex = isAke($data, $unique, false);

                    if (false === $valIndex) {
                        throw new Exception("The field '$unique' is an unique index of this table '$this->table'. It is required to occured.");
                    }

                    $checkDb    = self::instance($this->db, $this->table);
                    $count      = $checkDb->where([$unique, '=', $valIndex])->where(['id', '!=', $id])->count();

                    if ($count > 0) {
                        throw new Exception("The field '$unique' is an unique index of this table '$this->table'. Its value exists ever in this table.");
                    }
                }
            }

            if (false !== $before) {
                $backup = $data;
                $data   = $before($data);

                $data   = empty($data) ? $backup : $data;
                Event::run("$this->db.$this->table.before.update", [$data]);
            }

            $data['id'] = $id;
            $data['updated_at'] = time();

            /* check required fields and their lengthes */

            $crud = Crud::instance($this);
            $crudFields = $crud->fields();

            foreach ($crudFields as $field) {
                $required   = $this->fieldConfig($field, 'required', false);
                $length     = $this->fieldConfig($field, 'length', false);
                $default    = $this->fieldConfig($field, 'default', null);

                if (!empty($default)) {
                    $valField   = isAke($data, $field, '');

                    if (!strlen($valField)) {
                        $data[$field] = $default;
                    }
                }

                $valField   = isAke($data, $field, null);

                if (true === $required) {
                    if (Arrays::is($valField)) {
                        if (empty($valField)) {
                            throw new Exception("The field '$field' is required field of this table '$this->table'.");
                        }
                    } else {
                        if (!strlen($valField)) {
                            throw new Exception("The field '$field' is required field of this table '$this->table'.");
                        } else {
                            if (false !== $length && is_string($valField)) {
                                if (strlen($valField) > $length) {
                                    throw new Exception("The field '$field' is too large. Max length is $length.");
                                }
                            }
                        }
                    }
                } else {
                    if (false !== $length && is_string($valField)) {
                        if (strlen($valField) > $length) {
                            throw new Exception("The field '$field' is too large. Max length is $length.");
                        }
                    }
                }
            }

            $old = $this->find($id)->assoc();

            if (false === CLI && isset($this->session)) {
                $keySession = "previous::$id";

                $this->session->set($keySession, $old);
            }

            $new = array_merge($old, $data);
            $this->deleteRow($id);

            self::$queries--;

            $file = $this->dir . DS . $id . '.row';

            foreach ($data as $k => $v) {
                if ($v instanceof Closure) {
                    unset($data[$k]);
                }
            }

            $tuple = $this->tuple($data);

            if (false === $tuple) {
                $this->writeFile($file, json_encode($new));
            } else {
                $file   = $this->dir . DS . $tuple . '.row';
                $new    = json_decode($this->readFile($file), true);
            }

            if (true === $this->cacheEnabled) {
                $this->cache()->set(sha1($this->dir), time());
            }

            if (false !== $after) {
                $backup = $new;
                $new    = $after($new);

                $new   = empty($new) ? $backup : $new;

                if (true === Event::listeners("$this->db.$this->table.after.update")) {
                    $new = Event::run("$this->db.$this->table.after.update", [$new]);
                }
            }

            if (true === $revision) {
                if (true === $this->cacheEnabled) {
                    $revisionId     = $this->cache()->incr("revisions::$this->db::$this->table::" . $new['id']);

                    $rowRevision    = jdb('system', 'revisionrecord')
                    ->create()
                    ->setDatabase($this->db)
                    ->setTable($this->table)
                    ->setNumber($revisionId)
                    ->setId($new['id'])
                    ->setData($new)
                    ->save();
                }
            }

            return true === $object ? $this->row($new) : $new;
        }

        public function deleteRow($id)
        {
            $start = $this->getTime();

            if (true === $this->cacheEnabled) {
                $key = "lock::$this->db::$this->table::write";

                $lock = strlen($this->cache()->get($key)) > 0 ? true : false;

                if (true === $lock) {
                    throw new Exception("This table $this->db $this->table is locked to write.");
                }
            }

            $hooks = $this->hooks();

            $before = isAke($hooks, 'before_delete', false);
            $after  = isAke($hooks, 'after_delete', false);

            if (false !== $before) {
                $before($id);
                Event::run("$this->db.$this->table.before.delete", [$id]);
            }

            if (true === $this->cacheEnabled) {
                $indexation = new Indexation($this->db, $this->table, $this->fields());
                $indexation->handle($id, false);

                $this->cache()->set(sha1($this->dir), time());
            }

            $file  = $this->dir . DS . $id . '.row';

            $old = $this->find($id)->assoc();

            if (false === CLI && isset($this->session)) {
                $keySession = "previous::$id";

                $this->session->set($keySession, $old);
            }

            $this->tuple($old, true);

            $this->deleteFile($file);

            if (false !== $after) {
                $after($id);
                Event::run("$this->db.$this->table.after.delete", [$id]);
            }

            $this->countQuery($start);

            return $this;
        }

        private function tuple($data, $delete = false)
        {
            $id = isAke($data, 'id', 0);
            unset($data['id']);

            $created_at = isAke($data, 'created_at', false);
            $updated_at = isAke($data, 'updated_at', false);

            if (false !== $created_at) unset($data['created_at']);
            if (false !== $updated_at) unset($data['updated_at']);

            $key = 'row_' . sha1(serialize($data) . $this->db . $this->table . $this->getEnv());

            if (true === $this->cacheEnabled) {
                $tuple = $this->cache()->get($key);
            } else {
                $tuple = null;
            }

            $exists = strlen($tuple) > 0;

            if (true === $exists) {
                if (true === $delete) {
                    if (true === $this->cacheEnabled) {
                        $this->cache()->del($key);
                    }

                    return true;
                } else {
                    return $tuple;
                }
            } else {
                if (false === $delete) {
                    if (true === $this->cacheEnabled) {
                        $this->cache()->set($key, $id);
                    }
                }
            }

            return false;
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
            if (true === $this->cacheEnabled) {
                $key = "lock::$this->db::$this->table::read";

                $lock = strlen($this->cache()->get($key)) > 0 ? true : false;

                if (true === $lock) {
                    throw new Exception("This table $this->db $this->table is locked to read.");
                }

                if (!$this->isView) {
                    $ageAll     = $this->cache()->get(sha1($this->dir) . 'ageAll');
                    $dataAll    = $this->cache()->get(sha1($this->dir) . 'dataAll');
                } else {
                    $ageAll     = $this->cache()->get(sha1($this->dir) . 'age::view::' . $this->isView);
                    $dataAll    = $this->cache()->get(sha1($this->dir) . 'data::view::' . $this->isView);
                }

                $ageChange  = $this->cache()->get(sha1($this->dir));

                if (strlen($ageAll) && strlen($dataAll) && strlen($ageChange)) {

                    if ($ageAll > $ageChange) {
                        $collection = unserialize($dataAll);

                        $this->count = count($collection);

                        return true === $object ? new Collection($collection, "$this->db::$this->table") : $collection;
                    } else {
                        if (!$this->isView) {
                            $this->cache()->del(sha1($this->dir) . 'ageAll');
                            $this->cache()->del(sha1($this->dir) . 'dataAll');
                            $this->cache()->del(sha1($this->dir) . 'countAll');
                            $this->cache()->del("collection::$this->db::$this->table");
                        } else {
                            $this->cache()->del(sha1($this->dir) . 'age::view::' . $this->isView);
                            $this->cache()->del(sha1($this->dir) . 'data::view::' . $this->isView);
                            $this->cache()->del(sha1($this->dir) . 'count::view::' . $this->isView);
                            $this->cache()->del("collection::$this->db::$this->table::$this->isView");
                        }
                    }
                }
            }

            $hooks          = $this->hooks();
            $customFields   = isAke($hooks, 'custom_fields', false);

            $collection = $datas = $rows = [];

            if (true === $this->cacheEnabled) {
                $key = 'jsonDB::data::' . $this->db . '_' . $this->getEnv() . '::' . $this->table;

                if (!$this->isView) {
                    $datas = $this->cache()->hgetall($key);
                }

                if (!empty($datas) && strlen($ageAll)) {
                    foreach ($datas as $data) {
                        $data = json_decode($data, true);

                        if (true === $customFields) {
                            $data = array_merge($data, customFields($this->table, $data['id']));
                        }

                        if (true === $object) {
                            $data = $this->row($data);
                        }

                        array_push($collection, $data);
                    }
                } else {
                    if (empty($rows)) {
                        $rows = !$this->isView ? $this->glob() : unserialize($this->cache()->get($this->view));
                    }

                    if (!empty($rows)) {
                        foreach ($rows as $row) {
                            $data = json_decode($this->readFile($row), true);

                            if (true === $customFields) {
                                $data = array_merge($data, customFields($this->table, $data['id']));
                            }

                            if (true === $object) {
                                $data = $this->row($data);
                            }

                            array_push($collection, $data);
                        }
                    }
                }
            } else {
                $rows = $this->glob();

                if (!empty($rows)) {
                    foreach ($rows as $row) {
                        $data = json_decode($this->readFile($row), true);

                        if (true === $customFields) {
                            $data = array_merge($data, customFields($this->table, $data['id']));
                        }

                        if (true === $object) {
                            $data = $this->row($data);
                        }

                        array_push($collection, $data);
                    }
                }
            }

            $this->count = count($collection);

            if (true === $this->cacheEnabled) {
                if (!$this->isView) {
                    $this->cache()->set(sha1($this->dir) . 'dataAll', serialize($collection));
                    $this->cache()->set(sha1($this->dir) . 'countAll', count($collection));
                    $this->cache()->set(sha1($this->dir) . 'ageAll', time());
                } else {
                    $this->cache()->set(sha1($this->dir) . 'data::view::' . $this->isView, serialize($collection));
                    $this->cache()->set(sha1($this->dir) . 'count::view::' . $this->isView, count($collection));
                    $this->cache()->set(sha1($this->dir) . 'age::view::' . $this->isView, time());
                }

                if (!$this->isView) {
                    return true === $object
                        ? new Collection($collection, "$this->db::$this->table")
                        : $collection;
                } else {
                    return true === $object
                        ? new Collection($collection, "$this->db::$this->table::$this->isView")
                        : $collection;
                }
            } else {
                return true === $object ? new Collection($collection) : $collection;
            }
        }

        public function fetch($object = false)
        {
            $this->results = $this->all($object);

            return $this;
        }

        public function findAll($object = true)
        {
            $this->results = $this->all($object);

            return $this;
        }

        public function getAll($object = false)
        {
            $this->results = $this->all($object);

            return $this;
        }

        public function full()
        {
            $this->results = $this->all(false);

            return $this;
        }

        public function run($object = false)
        {
            return $this->exec($object);
        }

        public function execute($object = false)
        {
            return $this->exec($object);
        }

        public function exec($object = false)
        {
            $collection = [];

            $hooks  = $this->hooks();
            $before = isAke($hooks, 'before_list', false);
            $after  = isAke($hooks, 'after_list', false);

            if (false !== $before) {
                $backup         = $this->results;
                $this->results  = $before($this->results);

                if (true === Event::listeners("$this->db.$this->table.before.list")) {
                    $this->results = Event::run("$this->db.$this->table.before.list", [$this->results]);
                }

                if (!Arrays::is($this->results)) {
                    $this->results = $backup;

                    unset($backup);
                }
            }

            if (!empty($this->results) && true === $object) {
                foreach ($this->results as $row) {
                    array_push($collection, $this->row($row));
                }
            } else {
                $collection = $this->results;
            }

            $this->reset();

            $this->countQuery($this->getTime());

            if (false !== $after) {
                $backup     = $collection;
                $collection = $after($collection);

                if (true === Event::listeners("$this->db.$this->table.before.list")) {
                    $collection = Event::run("$this->db.$this->table.after.list", [$collection]);
                }

                if (!Arrays::is($collection)) {
                    $collection = $backup;

                    unset($backup);
                }
            }

            if (true === Event::listeners("$this->db.$this->table.put.in.cache")) {
                Event::run("$this->db.$this->table.put.in.cache", [$collection]);
            }

            return true === $object ? new Collection($collection) : $collection;
        }

        public function update(array $updates, $where = null)
        {
            $res = !empty($where) ? $this->where($where)->exec() : $this->all();

            if (!empty($res)) {
                if (!empty($updates)) {
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

            if (!empty($res)) {
                foreach ($res as $row) {
                    $this->deleteRow($row['id']);
                }
            }

            return $this;
        }

        public function groupBy($field, $results = [])
        {
            $res = !empty($results) ? $results : $this->results;

            if (true === $this->cacheEnabled) {
                $ageChange  = $this->cache()->get(sha1($this->dir));

                $key        = sha1(serialize($res) .
                    serialize(func_get_args())
                ) .
                '::groupBy::' .
                $this->db .
                '::' .
                $this->table;

                $keyAge     = sha1(serialize($res) .
                    serialize(func_get_args())
                ) .
                '::groupBy::' .
                $this->db .
                '::' .
                $this->table .
                '::age';

                $cached     = $this->cache()->get($key);
                $age        = $this->cache()->get($keyAge);

                if (strlen($cached)) {
                    if ($age > $ageChange) {
                        $this->results = unserialize($cached);

                        return $this;
                    } else {
                        $this->cache()->del($key);
                    }
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

            if (true === $this->cacheEnabled) {
                $this->cache()->set($key, serialize($this->results));
                $this->cache()->set($keyAge, time());
            }

            return $this;
        }

        public function limit($limit, $offset = 0, $results = [])
        {
            $res            = !empty($results) ? $results : $this->results;
            $offset         = count($res) < $offset ? count($res) : $offset;
            $this->results  = array_slice($res, $offset, $limit);

            return $this;
        }

        public function sum($field, $results = [])
        {
            $res = !empty($results) ? $results : $this->results;
            $sum = 0;

            if (!empty($res)) {
                foreach ($res as $id => $tab) {
                    $val = isAke($tab, $field, 0);
                    $sum += $val;
                }
            }

            $this->reset();

            return (int) $sum;
        }

        public function avg($field, $results = [])
        {
            $res = !empty($results) ? $results : $this->results;

            return (float) $this->sum($field, $res) / count($res);
        }

        public function min($field, $results = [])
        {
            $res = !empty($results) ? $results : $this->results;
            $min = 0;

            if (!empty($res)) {
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

        public function max($field, $results = [])
        {
            $res = !empty($results) ? $results : $this->results;
            $max = 0;

            if (!empty($res)) {
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

        public function rand($results = [])
        {
            $res = !empty($results) ? $results : $this->results;
            shuffle($res);
            $this->results = $res;

            return $this;
        }

        public function sort(Closure $sortFunc, $results = [])
        {
            $res = !empty($results) ? $results : $this->results;

            if (empty($res)) {
                return $this;
            }

            if (true === $this->cacheEnabled) {
                $key        = sha1(serialize(func_get_args()) . serialize($this->wheres)) . '::order::' . $this->db . '_' . $this->getEnv() . '::' . $this->table;
                $keyAge     = sha1(serialize(func_get_args()) . serialize($this->wheres)) . '::order::' . $this->db . '_' . $this->getEnv() . '::' . $this->table . '::age';
                $ageChange  = $this->cache()->get(sha1($this->dir));

                $cached     = $this->cache()->get($key);
                $age        = $this->cache()->get($keyAge);

                if (strlen($cached)) {
                    if ($age > $ageChange) {
                        $this->results = unserialize($cached);

                        return $this;
                    } else {
                        $this->cache()->del($key);
                    }
                }
            }

            usort($res, $sortFunc);

            $this->results = $res;

            if (true === $this->cacheEnabled) {
                $this->cache()->set($key, serialize($this->results));
                $this->cache()->set($keyAge, time());
            }

            return $this;
        }

        public function order($fieldOrder, $orderDirection = 'ASC', $results = [])
        {
            $res = !empty($results) ? $results : $this->results;

            if (empty($res)) {
                return $this;
            }

            if (true === $this->cacheEnabled) {
                $key        = sha1(serialize(func_get_args()) . serialize($this->wheres)) . '::order::' . $this->db . '_' . $this->getEnv() . '::' . $this->table;
                $keyAge     = sha1(serialize(func_get_args()) . serialize($this->wheres)) . '::order::' . $this->db . '_' . $this->getEnv() . '::' . $this->table . '::age';
                $ageChange  = $this->cache()->get(sha1($this->dir));

                $cached     = $this->cache()->get($key);
                $age        = $this->cache()->get($keyAge);

                if (strlen($cached)) {
                    if ($age > $ageChange) {
                        $this->results = unserialize($cached);

                        return $this;
                    } else {
                        $this->cache()->del($key);
                    }
                }
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
                for ($i = 0 ; $i < count($fieldOrder) ; $i++) {
                    usort($res, $sortFunc($fieldOrder[$i], $orderDirection[$i]));
                }
            } else {
                usort($res, $sortFunc($fieldOrder, $orderDirection));
            }

            $this->results = $res;

            if (true === $this->cacheEnabled) {
                $this->cache()->set($key, serialize($this->results));
                $this->cache()->set($keyAge, time());
            }

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
            $db     = new self($this->db, $this->table);
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

        public function create($tab = [])
        {
            $tab['created_at'] = isAke($tab, 'created_at', time());
            $tab['updated_at'] = isAke($tab, 'updated_at', time());

            return $this->row($tab);
        }

        public function row($tab = [])
        {
            $id = isAke($tab, 'id', false);

            if (false !== $id) {
                if (!is_numeric($id)) {
                    throw new Exception("the id must be numeric.");
                } else {
                    $tab['id'] = (int) $id;
                }
            }

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
            $db->results    = null;
            $db->wheres     = null;

            $save = function () use ($obj, $db) {
                return $db->save($obj);
            };

            $database = function () use ($db) {
                return $db;
            };

            $delete = function () use ($obj, $db) {
                return $db->deleteRow($obj->id);
            };

            $deleteSoft = function () use ($obj, $db) {
                $obj->deleted_at = time();

                return $db->save($obj);
            };

            $id = function () use ($obj) {
                return isset($obj->id) ? $obj->id : null;
            };

            $exists = function () use ($obj) {
                return isset($obj->id);
            };

            $touch = function () use ($obj) {
                if (!isset($obj->created_at))  $obj->created_at = time();

                $obj->updated_at = time();

                return $obj;
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

            $date = function ($field, $format = 'Y-m-d H:i:s') use ($obj) {
                return date($format, $obj->$field);
            };

            $string = function () use ($obj) {
                if (isset($obj->name)) {
                    return $obj->name;
                }

                return $obj->id;
            };

            $model = function () use ($db, $obj) {
                return Model::instance($db, $obj);
            };

            $obj->event('save', $save)
            ->event('delete', $delete)
            ->event('deletesoft', $deleteSoft)
            ->event('date', $date)
            ->event('exists', $exists)
            ->event('id', $id)
            ->event('db', $database)
            ->event('touch', $touch)
            ->event('hydrate', $hydrate)
            ->event('string', $string)
            ->event('model', $model)
            ->event('duplicate', $duplicate);

            $settings   = isAke(self::$config, "$this->db.$this->table");
            $functions  = isAke($settings, 'functions');

            if (!empty($functions)) {
                foreach ($functions as $closureName => $callable) {
                    $closureName    = lcfirst(Inflector::camelize($closureName));

                    if (Arrays::is($callable)) {
                        list($callable, $callableArgs) = $callable;
                    } else {
                        $callableArgs = [];
                    }

                    $share          = function () use ($obj, $callable, $db, $callableArgs) {
                        $args       = [];

                        if (!empty($callableArgs)) {
                            $args[] = $callableArgs;
                        }

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
                if (fnmatch('*_id', $field)) {
                    if (is_string($field)) {
                        $value = $obj->$field;

                        if (!is_callable($value)) {
                            $fk = str_replace('_id', '', $field);
                            $ns = $this->db;

                            $cb = function() use ($value, $fk, $ns) {
                                $db = jdb($ns, $fk);

                                return $db->find($value);
                            };

                            $obj->event($fk, $cb);

                            $setter = lcfirst(Inflector::camelize("link_$fk"));

                            $cb = function(Container $fkObject) use ($obj, $field, $fk) {
                                $obj->$field = $fkObject->id;

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
            $start = $this->getTime();

            if (!is_numeric($id)) {
                return $object ? null : [];
            }

            if ($id <= 0) {
                return $object ? null : [];
            }

            $key = "lock::$this->db::$this->table::read";

            $hooks      = $this->hooks();
            $before     = isAke($hooks, 'before_read', false);
            $after      = isAke($hooks, 'after_read', false);

            if (true === $this->cacheEnabled) {
                $lock = strlen($this->cache()->get($key)) > 0 ? true : false;

                if (true === $lock) {
                    throw new Exception("This table $this->db $this->table is locked to read.");
                }
            }

            $file   = $this->dir . DS . $id . '.row';
            $row    = $this->readFile($file);

            $customFields   = isAke($hooks, 'custom_fields', false);

            if (false !== $before) {
                $before($id);
                Event::run("$this->db.$this->table.before.read", [$id]);
            }

            if (strlen($row)) {
                $tab = json_decode($row, true);

                if (true === $customFields) {
                    $tab = array_merge($tab, customFields($this->table, $tab['id']));
                }

                $data = $object ? $this->row($tab) : $tab;

                if (false !== $after) {
                    $backup = $data;
                    $data   = $after($data);
                    $data   = empty($data) ? $backup : $data;

                    if (true === Event::listeners("$this->db.$this->table.after.read")) {
                        $data = Event::run("$this->db.$this->table.after.read", [$data]);
                    }
                }

                return $data;
            }

            $this->countQuery($start);

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
            $res = $this->search([$field, '=', $value]);

            if (!empty($res) && true === $one) {
                return $object ? $this->row(Arrays::first($res)) : Arrays::first($res);
            }

            if (!empty($res) && true === $one && true === $object) {
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
            $res = isset($this->results) ? $this->results : $this->all();

            if (true === $reset) {
                $this->reset();
            }

            if (true === $object) {
                return !empty($res) ? $this->row(Arrays::first($res)) : null;
            } else {
                return !empty($res) ? Arrays::first($res) : [];
            }
        }

        public function last($object = false, $reset = true)
        {
            $res = isset($this->results) ? $this->results : $this->all();

            if (true === $reset) {
                $this->reset();
            }

            if (true === $object) {
                return !empty($res) ? $this->row(Arrays::last($res)) : null;
            } else {
                return !empty($res) ? Arrays::last($res) : [];
            }
        }

        public function fields()
        {
            $crud = Crud::instance($this);

            return $crud->fields();
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

            if (!empty($rows)) {
                foreach ($rows as $row) {
                    $record = true === $object
                    ? $this->row(
                        [
                            'id'         => (int) $row->id,
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

        public function in($ids, $field = null, $op = 'AND', $results = [])
        {
            /* polymorphism */
            $ids = !Arrays::is($ids)
            ? strstr($ids, ',')
                ? explode(',', str_replace(' ', '', $ids))
                : [$ids]
            : $ids;

            $field = is_null($field) ? 'id' : $field;

            return $this->where([$field, 'IN', '(' . implode(',', $ids) . ')'], $op, $results);
        }

        public function notIn($ids, $field = null, $op = 'AND', $results = [])
        {
            /* polymorphism */
            $ids = !Arrays::is($ids)
            ? strstr($ids, ',')
                ? explode(',', str_replace(' ', '', $ids))
                : [$ids]
            : $ids;

            $field = is_null($field) ? 'id' : $field;

            return $this->where([$field, 'NOT IN', '(' . implode(',', $ids) . ')'], $op, $results);
        }

        public function like($field, $str, $op = 'AND', $results = [])
        {
            return $this->where([$field, 'LIKE', $str], $op, $results);
        }

        public function likeStart($field, $str, $op = 'AND', $results = [])
        {
            return $this->where([$field, 'LIKE START', $str], $op, $results);
        }

        public function likeEnd($field, $str, $op = 'AND', $results = [])
        {
            return $this->where([$field, 'LIKE END', $str], $op, $results);
        }

        public function notLike($field, $str, $op = 'AND', $results = [])
        {
            return $this->where([$field, 'NOT LIKE', $str], $op, $results);
        }

        public function custom(Closure $condition, $op = 'AND', $results = [])
        {
            return $this->trick($condition, $op, $results);
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

            $this->wheres[] = true;

            return $this;
        }

        public function where($condition, $op = 'AND', $results = [])
        {
            /* force data to reduce process time */
            // $results = empty($results) && !empty($this->wheres) && 'AND' == $op ? $this->results : $results;

            $res = $this->search($condition, $results, false);

            if (empty($this->wheres)) {
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

        private function esSearch($field, $op, $value, $results = [], $populate = true)
        {
            if (true === $this->cacheEnabled) {
                $ageSearch  = $this->cache()->get(sha1(serialize(func_get_args())) . '::' . $this->db . '_' . $this->getEnv() . '::' . $this->table . '::age::ES::Search');
                $dataSearch = $this->cache()->get(sha1(serialize(func_get_args())) . '::' . $this->db . '_' . $this->getEnv() . '::' . $this->table . '::data::ES::Search');
                $ageChange  = $this->cache()->get(sha1($this->dir));

                if (strlen($ageSearch) && strlen($dataSearch) && strlen($ageChange)) {
                    if ($ageSearch > $ageChange) {
                        $collection = unserialize($dataSearch);

                        if (true === $populate) {
                            $this->results = $collection;
                        }

                        return $collection;
                    } else {
                        $this->cache()->del(sha1(serialize(func_get_args())) . '::' . $this->db . '_' . $this->getEnv() . '::' . $this->table . '::age::ES::Search');
                        $this->cache()->del(sha1(serialize(func_get_args())) . '::' . $this->db . '_' . $this->getEnv() . '::' . $this->table . '::data::ES::Search');
                    }
                }
            }

            if (true === $this->cacheEnabled) {
                $size = $this->cache()->get('jsonDB::es::count::' . $this->db . '_' . $this->getEnv() . '::' . $this->table);

                if (!strlen($size)) {
                    $size = count($this->glob());
                    $this->cache()->set('jsonDB::es::count::' . $this->db . '_' . $this->getEnv() . '::' . $this->table, $size);
                    $this->cache()->set(sha1($this->dir) . 'countAll', $size);
                }
            } else {
                $size = count($this->glob());
            }

            $collection = [];

            $crud           = Crud::instance($this);
            $config         = $crud->config();
            $configFields   = isAke($config, 'fields', []);
            $type           = isAke($configFields, 'form_type', 'text');

            if (($field == 'created_at' || $field == 'updated_at' || 'date' == $type) && fnmatch('*/*/*', $value)) {
                list($d, $m, $y)    = explode('/', $value, 3);
                $value              = mktime(23, 59, 59, $m, $d, $y);
            }

            if ($value instanceof Container) {
                $value = $value->id;
                $field = $field . '_id';
            }

            $join = false;

            /* join query */
            if (strstr($field, '.')) {
                list($tmpModel, $tmpField) = explode('.', $field, 2);

                if ($tmpModel == $this->table) {
                    $field = $tmpField;
                } else {
                    $foreignField   = isAke($this->joinTable, $tmpModel, $tmpModel . '_id');
                    $field          = $foreignField;
                    $join           = true;
                    $joinValue      = $value;
                    $value          = '';
                }
            }

            if (is_numeric($value) && Arrays::in($op, ['<', '>', '<=', '>='])) {
                switch ($op) {
                    case '<':
                        $operand = 'lt';
                        $filter = [$operand => $value];
                        break;
                    case '>':
                        $operand = 'gt';
                        $filter = [$operand => $value];
                        break;
                    case '<=':
                        $operand = 'lte';
                        $filter = [$operand => $value];
                        break;
                    case '>=':
                        $operand = 'gte';
                        $filter = [$operand => $value];
                        break;
                }

                try {
                    $results = es()->search([
                        'index' => $this->db . '_' . $this->getEnv(),
                        'type' => $this->table,
                        'size' => $size,
                        'body' => [
                            'query' => [
                                'range' => [
                                    $field => $filter
                                ]
                            ]
                        ]
                    ]);
                } catch (E404 $e) {
                    $results = [];
                }
            } elseif (Arrays::in($op, ['<>', '!=', 'NOTLIKE'])) {
                $operand = !fnmatch('*LIKE*', $op) ? 'match' : 'wildcard';

                if (fnmatch('*LIKE*', $op) && fnmatch('%', $value)) {
                    $vSearch = str_replace('%', '*', $value);
                } else {
                    $vSearch = 'match' == $operand ? $value : "*$value*";
                }

                try {
                    $results = es()->search([
                        'index' => $this->db . '_' . $this->getEnv(),
                        'type' => $this->table,
                        'size' => $size,
                        'body' => [
                            'query' => [
                                'bool' => [
                                    'must_not' => [
                                        $operand => [
                                            $field => str_replace(['%', "'", '"'], '', $vSearch)
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]);
                } catch (E404 $e) {
                    $results = [];
                }
            } else {
                $operand = !fnmatch('*LIKE*', $op) ? 'match' : 'wildcard';

                if (fnmatch('*LIKE*', $op) && fnmatch('*%*', $value)) {
                    $vSearch = str_replace('%', '*', $value);
                } else {
                    $vSearch = 'match' == $operand ? $value : "*$value*";
                }

                try {
                    $results = es()->search([
                        'index' => $this->db . '_' . $this->getEnv(),
                        'type'  => $this->table,
                        'size'  => $size,
                        'body'  => [
                            'query'         => [
                                $operand    => [
                                    $field  => str_replace(['%', "'", '"'], '', $vSearch)
                                ]
                            ]
                        ]
                    ]);
                } catch (E404 $e) {
                    $results = [];
                }
            }

            $hits   = isAke($results, 'hits', []);
            $total  = isAke($hits, 'total', 0);

            if ($total == 0) {
                if (true === $populate) {
                    $this->results = $collection;
                }

                if (true === $this->cacheEnabled) {
                    $this->cache()->set(sha1(serialize(func_get_args())) . '::' . $this->db . '_' . $this->getEnv() . '::' . $this->table . '::data::ES::Search', serialize($collection));
                    $this->cache()->set(sha1(serialize(func_get_args())) . '::' . $this->db . '_' . $this->getEnv() . '::' . $this->table . '::age::ES::Search', time());
                }

                return $collection;
            }

            $rows   = isAke($hits, 'hits', []);
            $keyRow = 'jsonDB::data::' . $this->db . '_' . $this->getEnv() . '::' . $this->table;

            if (!empty($rows)) {
                foreach ($rows as $row) {
                    $data = isAke($row, '_source', []);

                    if (!empty($data)) {
                        $val    = isAke($data, $field, null);
                        $id     = isAke($data, 'id', false);

                        if (true === $join) {
                            $value  = $joinValue;
                            $tmpRow = jdb($this->db, $tmpModel)->find($val);

                            if ($tmpRow) {
                                $tmpTab = $tmpRow->assoc();
                                $val = isAke($tmpTab, $tmpField, null);
                            } else {
                                $val = null;
                            }
                        }

                        if (Arrays::is($val)) {
                            if ($op != '!=' && $op != '<>') {
                                $check = Arrays::in($value, $val);
                            } else {
                                $check = !Arrays::in($value, $val);
                            }
                        } else {
                            if (strlen($val)) {
                                if ($value == 'null') {
                                    $check = false;

                                    if ($op == 'IS' || $op == '=') {
                                        $check = false;
                                    } elseif ($op == 'ISNOT' || $op == '!=' || $op == '<>') {
                                        $check = true;
                                    }
                                } else {
                                    $val    = str_replace('|', ' ', $val);
                                    $check  = $this->compare($val, $op, $value);
                                }
                            } else {
                                $check = false;

                                if ($value == 'null') {
                                    if ($op == 'IS' || $op == '=') {
                                        $check = true;
                                    } elseif ($op == 'ISNOT' || $op == '!=' || $op == '<>') {
                                        $check = false;
                                    }
                                }
                            }
                        }

                        if (true === $check && false !== $id) {
                            if (true === $this->cacheEnabled) {
                                $rowValue = $this->cache()->hget($keyRow, $id);

                                if (!strlen($rowValue)) {
                                    $rowValue = $this->readFile($this->dir . DS . $id . '.row');
                                    $this->cache()->hset($keyRow, $id, $rowValue);
                                }

                                array_push(
                                    $collection,
                                    json_decode(
                                        $rowValue,
                                        true
                                    )
                                );
                            } else {
                                array_push(
                                    $collection,
                                    json_decode(
                                        $this->readFile($this->dir . DS . $id . '.row'),
                                        true
                                    )
                                );
                            }
                        }
                    }
                }
            }

            if (true === $populate) {
                $this->results = $collection;
            }

            if (true === $this->cacheEnabled) {
                $this->cache()->set(sha1(serialize(func_get_args())) . '::' . $this->db . '_' . $this->getEnv() . '::' . $this->table . '::data::ES::Search', serialize($collection));
                $this->cache()->set(sha1(serialize(func_get_args())) . '::' . $this->db . '_' . $this->getEnv() . '::' . $this->table . '::age::ES::Search', time());
            }

            return $collection;
        }

        private function search($condition = null, $results = [], $populate = true)
        {
            if (empty($condition)) {
                $datas = empty($results) ? $this->all() : $results;

                if (true === $populate) {
                    $this->results = $datas;
                }

                return $datas;
            }

            if (!Arrays::is($condition)) {
                $condition  = str_replace(
                    [' LIKE START ', ' LIKE END ', ' NOT LIKE ', ' NOT IN '],
                    [' LIKESTART ', ' LIKEEND ', ' NOTLIKE ', ' NOTIN '],
                    $condition
                );

                if (fnmatch('* = *', $condition)) {
                    list($field, $value) = explode(' = ', $condition, 2);
                    $op = '=';
                } elseif (fnmatch('* < *', $condition)) {
                    list($field, $value) = explode(' < ', $condition, 2);
                    $op = '<';
                } elseif (fnmatch('* > *', $condition)) {
                    list($field, $value) = explode(' > ', $condition, 2);
                    $op = '>';
                } elseif (fnmatch('* <= *', $condition)) {
                    list($field, $value) = explode(' <= ', $condition, 2);
                    $op = '<=';
                } elseif (fnmatch('* >= *', $condition)) {
                    list($field, $value) = explode(' >= ', $condition, 2);
                    $op = '>=';
                } elseif (fnmatch('* LIKESTART *', $condition)) {
                    list($field, $value) = explode(' LIKESTART ', $condition, 2);
                    $op = 'LIKESTART';
                } elseif (fnmatch('* LIKEEND *', $condition)) {
                    list($field, $value) = explode(' LIKEEND ', $condition, 2);
                    $op = 'LIKEEND';
                } elseif (fnmatch('* NOTLIKE *', $condition)) {
                    list($field, $value) = explode(' NOTLIKE ', $condition, 2);
                    $op = 'NOTLIKE';
                } elseif (fnmatch('* LIKE *', $condition)) {
                    list($field, $value) = explode(' LIKE ', $condition, 2);
                    $op = 'LIKE';
                } elseif (fnmatch('* IN *', $condition)) {
                    list($field, $value) = explode(' IN ', $condition, 2);
                    $op = 'IN';
                } elseif (fnmatch('* NOTIN *', $condition)) {
                    list($field, $value) = explode(' NOTIN ', $condition, 2);
                    $op = 'NOTIN';
                } elseif (fnmatch('* != *', $condition)) {
                    list($field, $value) = explode(' != ', $condition, 2);
                    $op = '!=';
                } elseif (fnmatch('* <> *', $condition)) {
                    list($field, $value) = explode(' <> ', $condition, 2);
                    $op = '<>';
                }
            } else {
                list($field, $op, $value) = $condition;

                $op = str_replace(' ', '', $op);
            }

            $continue   = true;
            $collection = [];

            if ($field == 'id' || fnmatch('*_id', $field)) {
                if (!is_numeric($value)) {
                    $continue = false;
                }

                if ($value <= 0) {
                    $continue = false;
                }
            }

            if (false === $continue) {
                if (true === $populate) {
                    $this->results = $collection;
                }

                if (true === $this->cacheEnabled) {
                    $this->cache()->set(sha1($this->dir . serialize(func_get_args())) . 'dataSearch', serialize($collection));
                    $this->cache()->set(sha1($this->dir . serialize(func_get_args())) . 'ageSearch', time());
                }

                return $collection;
            }

            $count = $this->cache()->get(sha1($this->dir) . 'countAll');
            $count = !strlen($count) ? 0 : $count;

            $es = $this->inConfig('es_search', true);

            $esSearch = true;

            if (is_numeric($value) && Arrays::in($op, ['=', '!=', '<>']) && $count < 1000) {
                $esSearch = false;
            }

            if (true === $es && true === $this->cacheEnabled && true === $this->esEnabled && true === $esSearch && (1000 <= $count || fnmatch('*LIKE*', $op)) || true === $this->forceEs) {
                return $this->esSearch($field, $op, $value, $results, $populate);
            }

            if (true === $this->cacheEnabled) {
                $ageSearch  = $this->cache()->get(sha1($this->dir . serialize(func_get_args())) . 'ageSearch');
                $dataSearch = $this->cache()->get(sha1($this->dir . serialize(func_get_args())) . 'dataSearch');
                $ageChange  = $this->cache()->get(sha1($this->dir));

                if (strlen($ageSearch) && strlen($dataSearch) && strlen($ageChange)) {
                    if ($ageSearch > $ageChange) {
                        $collection = unserialize($dataSearch);

                        if (true === $populate) {
                            $this->results = $collection;
                        }

                        return $collection;
                    } else {
                        $this->cache()->del(sha1($this->dir . serialize(func_get_args())) . 'dataSearch');
                        $this->cache()->del(sha1($this->dir . serialize(func_get_args())) . 'ageSearch');
                    }
                }
            }

            $ids = empty($results) ? $this->ids() : $results;

            $crud           = Crud::instance($this);
            $config         = $crud->config();
            $configFields   = isAke($config, 'fields', []);
            $type           = isAke($configFields, 'form_type', 'text');

            $hooks          = $this->hooks();
            $customFields   = isAke($hooks, 'custom_fields', false);

            if (($field == 'created_at' || $field == 'updated_at' || 'date' == $type) && fnmatch('*/*/*', $value)) {
                list($d, $m, $y) = explode('/', $value, 3);
                $value = mktime(23, 59, 59, $m, $d, $y);
            }

            if ($value instanceof Container) {
                $value = $value->id;
                $field = $field . '_id';
            }

            if (count($ids)) {
                foreach ($ids as $id) {
                    $data = $this->cache()->hget('jsonDB::data::' . $this->db . '_' . $this->getEnv() . '::' . $this->table, $id);

                    if (!strlen($data)) {
                        $data = $this->readFile($this->dir . DS . $id . '.row');
                        $this->cache()->hset('jsonDB::data::' . $this->db . '_' . $this->getEnv() . '::' . $this->table, $id, $data);
                        $tab = json_decode($data, true);

                        if (true === $customFields) {
                            $tab = array_merge($tab, customFields($this->table, $tab['id']));
                        }
                    } else {
                        $tab = json_decode($data, true);

                        if (true === $customFields) {
                            $tab = array_merge($tab, customFields($this->table, $tab['id']));
                        }
                    }

                    if (!empty($tab)) {
                        /* join query */
                        if (strstr($field, '.')) {
                            list($tmpModel, $tmpField) = explode('.', $field, 2);

                            if ($tmpModel == $this->table) {
                                $val = isAke($tab, $tmpField, null);
                            } else {
                                $tmpId  = isAke(
                                    $tab,
                                    isAke(
                                        $this->joinTable,
                                        $tmpModel,
                                        $tmpModel . '_id'
                                    ),
                                    null
                                );

                                $tmpRow = jdb($this->db, $tmpModel)->find($tmpId);

                                if ($tmpRow) {
                                    $tmpTab = $tmpRow->assoc();
                                    $val = isAke($tmpTab, $tmpField, null);
                                } else {
                                    $val = null;
                                }
                            }
                        } else {
                            $val = isAke($tab, $field, null);
                        }

                        if (Arrays::is($val)) {
                            if ($op != '!=' && $op != '<>' && !fnmatch('*NOT*', $op)) {
                                $check = Arrays::in($value, $val);
                            } else {
                                $check = !Arrays::in($value, $val);
                            }
                        } else {
                            if (strlen($val)) {
                                if ($value == 'null') {
                                    $check = false;

                                    if ($op == 'IS' || $op == '=') {
                                        $check = false;
                                    } elseif ($op == 'ISNOT' || $op == '!=' || $op == '<>') {
                                        $check = true;
                                    }
                                } else {
                                    $val    = str_replace('|', ' ', $val);
                                    $check  = $this->compare($val, $op, $value);
                                }
                            } else {
                                $check = false;

                                if ($value == 'null') {
                                    if ($op == 'IS' || $op == '=') {
                                        $check = true;
                                    } elseif ($op == 'ISNOT' || $op == '!=' || $op == '<>') {
                                        $check = false;
                                    }
                                }
                            }
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

            if (true === $this->cacheEnabled) {
                $this->cache()->set(sha1($this->dir . serialize(func_get_args())) . 'dataSearch', serialize($collection));
                $this->cache()->set(sha1($this->dir . serialize(func_get_args())) . 'ageSearch', time());
            }

            return $collection;
        }

        private function ids()
        {
            return !$this->isView ? $this->globIds() : unserialize($this->cache()->get($this->view));

            $key = 'jsonDB::data::' . $this->db . '_' . $this->getEnv() . '::' . $this->table;
            $ids = $this->cache()->hkeys($key);

            if (empty($ids) || !$this->cacheEnabled) {
                $ids = !$this->isView ? $this->globIds() : unserialize($this->cache()->get($this->view));
            }

            return $ids;
        }

        private function allSearch($field)
        {
            $key    = 'jsonDB::ids::' . $this->db . '_' . $this->getEnv() . '::' . $this->table;
            $keyRow = 'jsonDB::data::' . $this->db . '_' . $this->getEnv() . '::' . $this->table;

            $ids = $this->cache()->hgetall($key);

            $hooks          = $this->hooks();
            $customFields   = isAke($hooks, 'custom_fields', false);

            if (empty($ids)) {
                $rows = !$this->isView ? $this->glob() : unserialize($this->cache()->get($this->view));
                $collection = [];

                if (!empty($rows)) {
                    foreach ($rows as $row) {
                        $data = json_decode($this->readFile($row), true);

                        if (true === $customFields) {
                            $data = array_merge($data, customFields($this->table, $data['id']));
                        }

                        $val = isAke($data, $field, null);

                        array_push($collection, [$field => $val]);
                    }
                }

                return $collection;
            } else {
                $collection = [];

                foreach ($ids as $id => $dummy) {
                    $data = json_decode($this->cache()->hget($keyRow, $id), true);

                    if (true === $customFields) {
                        $data = array_merge($data, customFields($this->table, $data['id']));
                    }

                    $val = isAke($data, $field, null);

                    array_push($collection, [$field => $val]);
                }

                return $collection;
            }
        }

        private function searchHash($condition = null, $results = [], $populate = true)
        {
            $ageSearch  = $this->cache()->get(sha1($this->dir . serialize(func_get_args())) . 'ageSearch');
            $dataSearch = $this->cache()->get(sha1($this->dir . serialize(func_get_args())) . 'dataSearch');
            $ageChange  = $this->cache()->get(sha1($this->dir));

            if (strlen($ageSearch) && strlen($dataSearch) && strlen($ageChange)) {

                if ($ageSearch > $ageChange) {
                    $collection = unserialize($dataSearch);

                    if (true === $populate) {
                        $this->results = $collection;
                    }

                    return $collection;
                } else {
                    $this->cache()->del(sha1($this->dir . serialize(func_get_args())) . 'dataSearch');
                    $this->cache()->del(sha1($this->dir . serialize(func_get_args())) . 'ageSearch');
                }
            }

            $all = empty($results) ? $this->all() : $results;

            if (empty($condition)) {
                return $all;
            }

            $collection = [];

            $condition  = str_replace(
                [' LIKE START ', ' LIKE END ', ' NOT LIKE ', ' NOT IN '],
                [' LIKESTART ', ' LIKEEND ', ' NOTLIKE ', ' NOTIN '],
                $condition
            );

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

            $crud           = Crud::instance($this);
            $config         = $crud->config();
            $configFields   = isAke($config, 'fields', []);
            $type           = isAke($configFields, 'form_type', 'text');

            if (($field == 'created_at' || $field == 'updated_at' || 'date' == $type) && fnmatch('*/*/*', $value)) {
                list($d, $m, $y) = explode('/', $value, 3);
                $value = mktime(23, 59, 59, $m, $d, $y);
            }

            if ($value instanceof Container) {
                $value = $value->id();
                $field = $field . '_id';
            }

            $keyPattern = 'jsonDB::fields::' . $this->db . '_' . $this->getEnv() . '::' . $this->table . '::' . $field;

            $join = false;

            /* join query */
            if (strstr($field, '.')) {
                list($tmpModel, $tmpField) = explode('.', $field, 2);

                if ($tmpModel == $this->table) {
                    $keyPattern = 'jsonDB::fields::' . $this->db . '_' . $this->getEnv() . '::' . $this->table . '::' . $tmpField;
                } else {
                    $foreignField   = isAke($this->joinTable, $tmpModel, $tmpModel . '_id');
                    $keyPattern     = 'jsonDB::fields::' . $this->db . '_' . $this->getEnv() . '::' . $this->table . '::' . $foreignField;
                    $join = true;
                }
            }

            $datas = $this->cache()->hgetall($keyPattern);
            $keyRow = 'jsonDB::data::' . $this->db . '_' . $this->getEnv() . '::' . $this->table;

            if(count($datas)) {
                foreach ($datas as $id => $val) {
                    $val = unserialize($val);

                    if (is_numeric($id)) {
                        if (true === $join) {
                            $tmpRow = jdb($this->db, $tmpModel)->find($val);

                            if ($tmpRow) {
                                $tmpTab = $tmpRow->assoc();
                                $val = isAke($tmpTab, $tmpField, null);
                            } else {
                                $val = null;
                            }
                        }

                        if (Arrays::is($val)) {
                            if ($op != '!=' && $op != '<>') {
                                $check = Arrays::in($value, $val);
                            } else {
                                $check = !Arrays::in($value, $val);
                            }
                        } else {
                            if (strlen($val)) {
                                $val = str_replace('|', ' ', $val);
                                $check = $this->compare($val, $op, $value);
                            } else {
                                $check = ('null' == $value) ? true : false;
                            }
                        }

                        if (true === $check) {
                            array_push(
                                $collection,
                                json_decode(
                                    $this->cache()->hget($keyRow, $id),
                                    true
                                )
                            );
                        }
                    }
                }
            }

            if (true === $populate) {
                $this->results = $collection;
            }

            $this->cache()->set(sha1($this->dir . serialize(func_get_args())) . 'dataSearch', serialize($collection));
            $this->cache()->set(sha1($this->dir . serialize(func_get_args())) . 'ageSearch', time());

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

        public function extend($name, $callable)
        {
            $settings   = isAke(self::$config, "$this->db.$this->table");
            $functions  = isAke($settings, 'functions');

            $functions[$name] = $callable;

            self::$config["$this->db.$this->table"]['functions'] = $functions;

            return $this;
        }

        private function getKeys()
        {
            if (empty($this->keys)) {
                $ids = [];
                $data = $this->glob();

                if (!empty($data)) {
                    foreach ($data as $row) {
                        $id = str_replace(
                            [
                                $this->dir . DS,
                                '.row'
                            ],
                            '',
                            $row
                        );
                        array_push($this->keys, $id);
                    }
                }
            }

            return $this->keys;
        }

        public function reset($f = null)
        {
            $this->cacheEnabled = null;
            $this->session      = null;
            $this->results      = null;
            $this->isView       = false;
            $this->forceEs      = false;
            $this->view         = null;
            $this->count        = 0;
            $this->wheres       = [];
            $this->joinTable    = [];
            $this->hooks        = [];
            $this->take         = [];

            return $this;
        }

        private function makeId()
        {
            $storage    = Config::get($this->db . '.storage', 'local');

            if ($storage == 'local') {
                $instance = new Local($this);
            }

            if (true === $this->cacheEnabled) {
                $id = $this->cache()->incr(sha1($this->dir) . 'indexes');

                if ($storage == 'local') {
                    while (File::exists($instance->hashFile($this->dir . DS . $id . '.row', false))) {
                        $id = $this->cache()->incr(sha1($this->dir) . 'indexes');
                    }
                } else {
                    while (File::exists($this->dir . DS . $id . '.row')) {
                        $id = $this->cache()->incr(sha1($this->dir) . 'indexes');
                    }
                }
            } else {
                $id = 1;

                if ($storage == 'local') {
                    while (File::exists($instance->hashFile($this->dir . DS . $id . '.row', false))) {
                        $id++;
                    }
                } else {
                    while (File::exists($this->dir . DS . $id . '.row')) {
                        $id++;
                    }
                }
            }

            array_push($this->keys, $id);

            return $id;
        }

        /* API static */

        public static function keys($pattern)
        {
            $collection = [];
            $db         = self::instance('system', 'kvs');
            $pattern    = str_replace('*', '', $pattern);

            return $db->where("key LIKE '$pattern'")->exec(true);
        }

        public static function get($key, $default = null, $object = false)
        {
            self::clean();
            $db = self::instance('system', 'kvs');
            $value = $db->where("key = $key")->first(true);

            return $value instanceof Container ? false === $object ? $value->getValue() : $value : $default;
        }

        public static function set($key, $value, $expire = 0)
        {
            $db     = self::instance('system', 'kvs');
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
            $db = self::instance('system', 'kvs');
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
            $db = self::instance('system', 'kvs');
            $exists = $db->where("key = $key")->first(true);

            if ($exists) {
                if (0 < $expire) $expire += time();
                $exists->setExpire($expire)->save();

                return true;
            }

            return false;
        }

        public static function clean()
        {
            $db = self::instance('system', 'kvs');

            return $db->where(['expire', '>', 0])->where(['expire', '<', time()])->exec(true)->delete();
        }

        private static function structure($ns, $table, $fields)
        {
            $dbt = jdb('system', 'jma_table');
            $dbf = jdb('system', 'jma_field');
            $dbs = jdb('system', 'jma_structure');

            $t = $dbt->name($table)->ns($ns)->first(true);

            if (is_null($t)) {
                $t = $dbt->create(['name' => $table, 'ns' => $ns])->save();
            }

            if (!is_null($t)) {
                if (!empty($fields)) {
                    foreach ($fields as $field) {
                        if ('id' != $field) {
                            $f = $dbf->name($field)->first(true);

                            if (is_null($f)) {
                                $f = $dbf->create()->setName($field)->save();
                            }

                            $s = $dbs
                            ->table($t->getId())
                            ->field($f->getId())
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
            set_time_limit(0);

            $dbt    = jdb('system', 'jma_table');
            $dirs   = glob(self::dirStore() . DS . 'dbjson' . DS . '*' . APPLICATION_ENV . '*');
            $rows   = [];

            if (!empty($dirs)) {
                foreach ($dirs as $dir) {
                    $tmp    = glob($dir . DS . '*');
                    $rows   = array_merge($rows, $tmp);
                }
            }

            $tables = [];

            if (!empty($rows)) {
                foreach ($rows as $row) {
                    $tab    = explode(DS, $row);
                    $index  = Arrays::last($tab);
                    $ns     = str_replace('_' . APPLICATION_ENV, '', $tab[count($tab) - 2]);

                    if (!fnmatch('jma_*', $index)) {
                        $t = $dbt->name($index)->ns($ns)->first(true);

                        if (is_null($t)) {

                            $tables[$index]['ns']       = $ns;
                            $data                       = jdb($ns, $index)->fetch()->exec();

                            if (!empty($data)) {
                                $first = Arrays::first($data);
                                $fields = array_keys($first);
                                $tables[$index]['fields'] = $fields;
                            } else {
                                $tables[$index]['fields'] = [];
                            }
                        }
                    }
                }
            }

            foreach ($tables as $t => $i) {
                self::structure($i['ns'], $t, $i['fields']);
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

            if (!empty($rows)) {
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

            if (!Arrays::exists($entity, self::$config)) {
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

            $reverse    = strrev($key);
            $last       = $reverse{0};

            if ('s' == $last) {
                self::$config[$entity][$key] = $value;
            } else {
                if (!Arrays::exists($key . 's', self::$config[$entity])) {
                    self::$config[$entity][$key . 's'] = [];
                }

                array_push(self::$config[$entity][$key . 's'], $value);
            }

            return !is_callable($cb) ? true : call_user_func_array($cb, []);
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

            if (!empty($datas)) {
                $fields = $this->fields();
                $rows   = [];
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
                if (!empty($this->wheres)) {
                    $this->reset();
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
            Utils::go(str_replace(['jma_dev.php', 'jma_prod.php'], '', URLSITE) . 'tmp/' . $name);
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
                        $idFk = $model->id;
                    } else {
                        $idFk = $model;
                    }

                    return $this->where([$field, '=', $idFk]);
                } else {
                    return $this->where([$field, '=', Arrays::first($args)]);
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
                        $idFk = $model->id;
                    } else {
                        $idFk = $model;
                    }

                    return $this->where([$field, '=', $idFk], 'OR');
                } else {
                    return $this->where([$field, '=', Arrays::first($args)], 'OR');
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
                            $idFk = $model->id;
                        } else {
                            $idFk = $model;
                        }

                        return $this->where([$field, '=', $idFk]);
                    } else {
                        return $this->where([$field, '=', Arrays::first($args)]);
                    }
                } elseif ('xorIs' == $method) {
                    $field = Inflector::uncamelize($object);

                    if (!Arrays::in($field, $fields)) {
                        $field = $field . '_id';
                        $model = Arrays::first($args);

                        if ($model instanceof Container) {
                            $idFk = $model->id;
                        } else {
                            $idFk = $model;
                        }

                        return $this->where([$field, '=', $idFk], 'XOR');
                    } else {
                        return $this->where([$field, '=', Arrays::first($args)], 'XOR');
                    }
                } elseif ('andIs' == $method) {
                    $field = Inflector::uncamelize($object);

                    if (!Arrays::in($field, $fields)) {
                        $field = $field . '_id';
                        $model = Arrays::first($args);

                        if ($model instanceof Container) {
                            $idFk = $model->id;
                        } else {
                            $idFk = $model;
                        }

                        return $this->where([$field, '=', $idFk]);
                    } else {
                        return $this->where([$field, '=', Arrays::first($args)]);
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
                            $idFk = $model->id;
                        } else {
                            $idFk = $model;
                        }

                        return $this->where([$field, '=', $idFk], 'OR');
                    } else {
                        return $this->where([$field, '=', Arrays::first($args)], 'OR');
                    }
                } elseif ('orderBy' == $method) {
                    $object = Inflector::uncamelize(lcfirst(substr($fn, 7)));

                    if (!Arrays::in($object, $fields) && 'id' != $object) {
                        $object = Arrays::in($object . '_id', $fields) ? $object . '_id' : $object;
                    }

                    $direction = (!empty($args)) ? Arrays::first($args) : 'ASC';

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

            $method = substr($fn, 0, 11);
            $object = Inflector::uncamelize(lcfirst(substr($fn, 11)));

            if (strlen($fn) > 11) {
                if ('findFirstBy' == $method) {
                    $obj = count($args) == 2 ? $args[1] : true;

                    if (!is_bool($obj)) {
                        $obj = true;
                    }

                    return $this->where([$object, '=', Arrays::first($args)])->first($obj);
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
                            $idFk = $model->id;
                        } else {
                            $idFk = $model;
                        }
                        return $this->where([$field, '=', $idFk], 'XOR');
                    } else {
                        return $this->where([$field, '=', Arrays::first($args)], 'XOR');
                    }
                } elseif('andWhere' == $method) {
                    $field = Inflector::uncamelize($object);

                    if (!Arrays::in($field, $fields)) {
                        $field = $field . '_id';
                        $model = Arrays::first($args);

                        if ($model instanceof Container) {
                            $idFk = $model->id;
                        } else {
                            $idFk = $model;
                        }

                        return $this->where([$field, '=', $idFk]);
                    } else {
                        return $this->where([$field, '=', Arrays::first($args)]);
                    }
                }
            }

            $field = $fn;
            $fieldFk = $fn . '_id';
            $op = count($args) == 2 ? Inflector::upper(Arrays::last($args)) : 'AND';

            if (Arrays::in($field, $fields)) {
                return $this->where([$field, '=', Arrays::first($args)], $op);
            } else if (Arrays::in($fieldFk, $fields)) {
                $model = Arrays::first($args);

                if ($model instanceof Container) {
                    $idFk = $model->id;
                } else {
                    $idFk = $model;
                }

                return $this->where([$field, '=', $idFk], $op);
            }

            if (!empty($args) && Arrays::first($args) instanceof Closure) {
                $closure = Arrays::first($args);
                array_shift($args);

                return call_user_func_array($closure, $args);
            }

            throw new Exception("Method '$fn' is unknown.");
        }

        public function backup()
        {
            $file   = 'backup_data_' . SITE_NAME . '_' . date('d_m_Y_H_i_s') . '.zip';
            $cmd    = 'cd ' . $this->dirStore() . ' && zip -r ' . $file . ' dbjson
            lftp -e ' . "'put $file; bye' -u \"" . Config::get('ftp.backup.user') . "\"," . Config::get('ftp.backup.password') . " " . Config::get('ftp.backup.host') . "
            rm $file";

            exec($cmd);
        }

        public function indexation($fields)
        {
            if (false === $this->cacheEnabled) {
                throw new Exception("You need to enable cache to use indexation.");
            }

            /* polymorphism */
            $fields = !Arrays::is($fields)
            ? strstr($fields, ',')
                ? explode(',', str_replace(' ', '', $fields))
                : [$fields]
            : $fields;

            $ageChange  = $this->cache()->get(sha1($this->dir));
            $ageIndex   = $this->cache()->get("index::json::age::$this->db::$this->table");

            if (!strlen($ageIndex) || $ageIndex < $ageChange) {
                $data = $this->all();
                $indexation = new Indexation($this->db, $this->table, $fields);

                foreach ($data as $row) {
                    $indexation->handle($row['id']);
                }

                $this->cache()->set("index::json::age::$this->db::$this->table", time());
            }

            return $this->cache()->get("index::json::age::$this->db::$this->table");
        }

        public function fulltext($fields, $query, $strict = false)
        {
            if (false === $this->cacheEnabled) {
                throw new Exception("You need to enable cache to use fulltext.");
            }

            /* polymorphism */
            $fields = !Arrays::is($fields)
            ? strstr($fields, ',')
                ? explode(',', str_replace(' ', '', $fields))
                : [$fields]
            : $fields;

            $this->indexation($fields);

            $indexation = new Indexation($this->db, $this->table, $fields);

            return $indexation->search($query, $strict);
        }

        private function getTime()
        {
            $time = microtime();
            $time = explode(' ', $time, 2);

            return Arrays::last($time) + Arrays::first($time);
        }

        private function countQuery($start)
        {
            self::$queries++;
            self::$duration += $this->getTime() - $start;
        }

        private function hooks()
        {
            $crud = Crud::instance($this);

            return array_merge($crud->config(), $this->hooks);
        }

        private function inConfig($key, $default = null)
        {
            $crud = Crud::instance($this);

            return isAke($crud->config(), $key, $default);
        }

        private function fieldConfig($field, $key, $default = null)
        {
            $infos = isAke($this->inConfig('fields', []), $field, []);

            return isAke($infos, $key, $default);
        }

        public function hook($hook, $cb)
        {
            return $this->setHook($hook, $cb);
        }

        public function setHook($hook, $cb)
        {
            $this->hooks[$hook] = $cb;

            return $this;
        }

        public function cache()
        {
            $method = Config::get($this->db . '.cache', Config::get('redis.enabled', false) ? 'redis' : 'jcache');

            if ($method == 'redis') {
                $servers    = Config::get('redis.cluster', ['tcp://127.0.0.1:6379']);
                $has        = Instance::has('DbjsonRedisCluster', sha1(serialize($servers)));

                if (true === $has) {
                    return Instance::get('DbjsonRedisCluster', sha1(serialize($servers)));
                } else {
                    $instance = new \Predis\Client([
                        'host' => 'localhost',
                        'port' => 6379,
                        'database' => 6
                    ]);

                    return Instance::make('DbjsonRedisCluster', sha1(serialize($servers)), $instance);
                }
            } else {
                return $method();
            }
        }

        private function writeFile($file, $data)
        {
            $storage    = Config::get($this->db . '.storage', 'local');
            $es         = $this->inConfig('es_search', true);

            if ('redis' != $storage) {
                if (true === $this->cacheEnabled) {
                    $key    = 'jsonDB::data::' . $this->db . '_' . $this->getEnv() . '::' . $this->table;
                    $keyId  = 'jsonDB::ids::' . $this->db . '_' . $this->getEnv() . '::' . $this->table;

                    $this->cache()->hdel($key, $this->extractId($file));
                    $this->cache()->hset($key, $this->extractId($file), $data);
                    $this->cache()->hset($keyId, $this->extractId($file), null);
                    $this->cache()->del('jsonDB::es::count::' . $this->db . '_' . $this->getEnv() . '::' . $this->table);
                }

                if (true === $es && true === $this->esEnabled) {
                    $this->es($data);
                }
            }

            $class      = '\\' . __NAMESPACE__ . '\\' . ucfirst(Inflector::lower($storage));
            $instance   = $class::instance($this);
            $result     = $instance->write($instance->extractId($file), $data);

            $this->setAge();

            return $result instanceof $class;
        }

        private function readFile($file)
        {
            $storage    = Config::get($this->db . '.storage', 'local');
            $es         = $this->inConfig('es_search', true);

            if ('redis' != $storage && true === $this->cacheEnabled) {
                $key    = 'jsonDB::data::' . $this->db . '_' . $this->getEnv() . '::' . $this->table;
                $keyId  = 'jsonDB::ids::' . $this->db . '_' . $this->getEnv() . '::' . $this->table;
                $cache  = $this->cache()->hget($key, $this->extractId($file));

                if (strlen($cache)) {
                    return $cache;
                }
            }

            $class      = '\\' . __NAMESPACE__ . '\\' . ucfirst(Inflector::lower($storage));
            $instance   = $class::instance($this);
            $data       = $instance->read($instance->extractId($file));

            if ('redis' != $storage && true === $this->cacheEnabled) {
                if (strlen($data)) {
                    $this->cache()->hset($key, $this->extractId($file), $data);
                    $this->cache()->hset($keyId, $this->extractId($file), null);

                    if (true === $es && true === $this->esEnabled) {
                        $this->es($data);
                    }
                }
            }

            return $data;
        }

        private function deleteFile($file)
        {
            $storage    = Config::get($this->db . '.storage', 'local');
            $es         = $this->inConfig('es_search', true);
            $softDelete = $this->inConfig('soft_delete', false);
            $key        = 'jsonDB::data::' . $this->db . '_' . $this->getEnv() . '::' . $this->table;
            $keyId      = 'jsonDB::ids::' . $this->db . '_' . $this->getEnv() . '::' . $this->table;

            $this->cache()->hdel($key, $this->extractId($file));
            $this->cache()->hdel($keyId, $this->extractId($file));

            if ('redis' != $storage && true === $this->cacheEnabled) {
                $data   = $this->cache()->hget($key, $this->extractId($file));
                // $this->hashFields($data, false);
                if (true === $es && true === $this->esEnabled) {
                    $this->es($data, true);
                }

                if (true === $softDelete) {
                    $this->softDelete($data);
                }

                $this->cache()->del('jsonDB::es::count::' . $this->db . '_' . $this->getEnv() . '::' . $this->table);
            }

            $class = '\\' . __NAMESPACE__ . '\\' . ucfirst(Inflector::lower($storage));

            $instance   = $class::instance($this);
            $result     = $instance->delete($instance->extractId($file));

            $this->setAge();

            return $result instanceof $class;
        }

        private function softDelete($data)
        {
            $row                        = json_decode($data, true);
            $row['source_id']           = $row['id'];
            $row['source_created_at']   = $row['created_at'];
            $row['source_updated_at']   = $row['updated_at'];
            $row['source_database']     = $this->db;
            $row['source_environment']  = $this->getEnv();
            $row['source_table']        = $this->table;

            unset($row['id']);
            unset($row['created_at']);
            unset($row['updated_at']);

            self::instance('system', 'trash')->create($row)->save();
        }

        private function es($data, $delete = false)
        {
            $data   = json_decode($data, true);
            $id     = $data['id'];

            $this->purgeEsCache();

            $refresh = $this->inConfig('es_refresh', true);

            if (false === $delete) {
                es()->index([
                    'index'     => $this->db . '_' . $this->getEnv(),
                    'type'      => $this->table,
                    'id'        => (int) $id,
                    'refresh'   => $refresh,
                    'body'      => $data
                ]);
            } else {
                try {
                    es()->delete([
                        'index'     => $this->db . '_' . $this->getEnv(),
                        'type'      => $this->table,
                        'refresh'   => $refresh,
                        'id'        => (int) $id
                    ]);
                } catch (\Elasticsearch\Common\Exceptions\Missing404Exception $e) {

                }
            }
        }

        private function purgeEsCache()
        {
            if (true === $this->cacheEnabled) {
                $agePattern     = '*::' . $this->db . '_' . $this->getEnv() . '::' . $this->table . '::age::ES::Search';
                $dataPattern    = '*::' . $this->db . '_' . $this->getEnv() . '::' . $this->table . '::data::ES::Search';

                $dataKeys = $this->cache()->keys($dataPattern);

                if (!empty($dataKeys)) {
                    foreach ($dataKeys as $dataKey) {
                        $this->cache()->del($dataKey);
                    }
                }

                $ageKeys = $this->cache()->keys($agePattern);

                if (!empty($ageKeys)) {
                    foreach ($ageKeys as $ageKey) {
                        $this->cache()->del($ageKey);
                    }
                }
            }
        }

        private function hashFields($data, $insert = true)
        {
            $data = json_decode($data, true);
            $id = $data['id'];
            unset($data['id']);

            foreach ($data as $key => $value) {
                $hashKey = 'jsonDB::fields::' . $this->db . '_' . $this->getEnv() . '::' . $this->table . '::' . $key;
                $this->cache()->hdel($hashKey, $id);

                if (true === $insert) {
                    $this->cache()->hset($hashKey, $id, serialize($value));
                }
            }

            return $this;
        }

        private function extractId($file)
        {
            return str_replace('.row', '', Arrays::last(explode(DS, $file)));
        }

        public function purgeRows()
        {
            $storage = Config::get($this->db . '.storage', 'local');

            if ('redis' != $storage && true === $this->cacheEnabled) {
                $key = 'jsonDB::data::' . $this->db . '_' . $this->getEnv() . '::' . $this->table;
                $this->cache()->del($key);
                $key = 'jsonDB::ids::' . $this->db . '_' . $this->getEnv() . '::' . $this->table;
                $this->cache()->del($key);
            }

            return $this;
        }

        public function noCache()
        {
            return $this->inCache(false);
        }

        public function inCache($bool = true)
        {
            $this->cacheEnabled = $bool;

            return $this;
        }

        public function inEs($bool = true)
        {
            $this->forceEs = $bool;

            return $this;
        }

        public function fresh()
        {
            return $this->emptyCache();
        }

        public function emptyCache()
        {
            if (true === $this->cacheEnabled) {
                $this->cache()->flushdb();
            }

            $es = $this->inConfig('es_search', true);

            if (true === $es && true === $this->esEnabled) {
                exec("curl -XDELETE 'http://127.0.0.1:9200/_all'", $result);
            }

            return $this;
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

        public function sql()
        {
            return new Sql($this);
        }

        public function archive()
        {
            return new Archive($this);
        }

        public function schema()
        {
            return new Schema($this);
        }

        public function join($model, $fieldJoin = null)
        {
            $fields = $this->fields();

            $fieldJoin = is_null($fieldJoin) ? $model . '_id' : $fieldJoin;

            if (!Arrays::in($fieldJoin, $fields)) {
                throw new Exception("'$fieldJoin' unknown in $this->table model. This join is not possible.");
            }

            $this->joinTable[$model] = $fieldJoin;

            return $this;
        }

        public function dbClone($name = null)
        {
            $name = is_null($name) ? 'clone_' . $this->table : $name;

            File::cpdir($this->dir, str_replace(DS . $this->table, DS . $name, $this->dir));

            return $this;
        }

        public function declone()
        {
            File::rmdir($this->dir);

            return $this;
        }

        public function reclone($name = null)
        {
            $name = is_null($name) ? 'clone_' . $this->table : $name;

            File::rmdir(str_replace(DS . $name, DS . $this->table, $this->dir));

            File::cpdir($this->dir, str_replace(DS . $name, DS . $this->table, $this->dir));

            File::rmdir($this->dir);

            return $this;
        }

        public function begin()
        {
            $this->purgeRows()->dbClone();

            $this->dir = str_replace(DS . $this->table, DS . 'clone_' . $this->table, $this->dir);

            return $this;
        }

        public function commit()
        {
            $this->reclone()->cache()->del(sha1($this->dir));

            $this->dir = str_replace('clone_', '', $this->dir);

            $this->purgeRows()->cache()->del(sha1($this->dir));

            return $this;
        }

        public function rollback()
        {
            $this->declone()->cache()->del(sha1($this->dir));

            $this->dir = str_replace('clone_', '', $this->dir);

            $this->purgeRows()->cache()->del(sha1($this->dir));

            return $this;
        }

        private function facade()
        {
            $crud   = new Crud($this);
            $config = $crud->config();

            $facade = isAke($config, 'facade', false);

            $facade2 = false;

            if (false === $facade) {
                $facade = ucfirst($this->db) . ucfirst($this->table);

                if ($this->db == SITE_NAME) {
                    $facade2 = ucfirst($this->table);
                }
            }

            $class = '\\Dbjson\\' . $facade;

            if (!class_exists($class)) {
                $code = 'namespace Dbjson; class ' . $facade . ' extends Facade { public static $database = "' . $this->db . '"; public static $table = "' . $this->table . '"; }';

                eval($code);

                Alias::facade('Db' . $facade, $facade, 'Dbjson');
            }

            if (false !== $facade2) {
                $class2 = '\\Dbjson\\' . $facade2;

                if (!class_exists($class2)) {
                    $code2 = 'namespace Dbjson; class ' . $facade2 . ' extends Facade { public static $database = "' . $this->db . '"; public static $table = "' . $this->table . '"; }';

                    eval($code2);

                    Alias::facade('Db' . $facade2, $facade2, 'Dbjson');
                }
            }
        }

        private function glob()
        {
            $storage = Config::get($this->db . '.storage', 'local');

            if (true === $this->cacheEnabled) {
                $data   = 'dbJSon::glob::data::' . $this->db . '_' . $this->getEnv() . '::' . $this->table;
                $ageKey = 'dbJSon::glob::age::' . $this->db . '_' . $this->getEnv() . '::' . $this->table;

                $ageGlob = $this->cache()->get($ageKey);

                $ageGlob = strlen($ageGlob) > 0 ? $ageGlob : 0;
                $ageAll  = $this->cache()->get(sha1($this->dir));

                if ($ageGlob < $ageAll || !strlen($ageAll)) {
                    $class      = '\\' . __NAMESPACE__ . '\\' . ucfirst(Inflector::lower($storage));
                    $instance   = $class::instance($this);
                    $rows       = $instance->glob();

                    $this->cache()->set($data, serialize($rows));
                    $this->cache()->set($ageKey, time());

                    return $rows;
                } else {
                    return unserialize($this->cache()->get($data));
                }
            } else {
                $class      = '\\' . __NAMESPACE__ . '\\' . ucfirst(Inflector::lower($storage));
                $instance   = $class::instance($this);
                $rows       = $instance->glob();

                return $rows;
            }
        }

        private function globIds()
        {
            $storage = Config::get($this->db . '.storage', 'local');

            if (true === $this->cacheEnabled) {
                $data   = 'dbJSon::globids::data::' . $this->db . '_' . $this->getEnv() . '::' . $this->table;
                $ageKey = 'dbJSon::globids::age::' . $this->db . '_' . $this->getEnv() . '::' . $this->table;

                $ageGlob = $this->cache()->get($ageKey);

                $ageGlob = strlen($ageGlob) > 0 ? $ageGlob : 0;
                $ageAll  = $this->cache()->get(sha1($this->dir));

                if ($ageGlob < $ageAll || !strlen($ageAll)) {
                    $class      = '\\' . __NAMESPACE__ . '\\' . ucfirst(Inflector::lower($storage));
                    $instance   = $class::instance($this);
                    $rows       = $instance->globids();

                    $this->cache()->set($data, serialize($rows));
                    $this->cache()->set($ageKey, time());

                    return $rows;
                } else {
                    return unserialize($this->cache()->get($data));
                }
            } else {
                $class      = '\\' . __NAMESPACE__ . '\\' . ucfirst(Inflector::lower($storage));
                $instance   = $class::instance($this);
                $rows       = $instance->globids();

                return $rows;
            }
        }

        public function speedView($sql)
        {
            if (false === $this->cacheEnabled) {
                throw new Exception("You must enable cache to use view method.");
            }

            $this->isView = sha1($sql);

            $this->view = 'dbJSon::views::data::' . $this->getEnv() . '::' . sha1($sql);
            $ageKey     = 'dbJSon::views::age::' . $this->getEnv() . '::' . sha1($sql);

            $ageView = $this->cache()->get($ageKey);

            $ageView = strlen($ageView) > 0 ? $ageView : 0;
            $ageAll  = $this->cache()->get(sha1($this->dir));

            if ($ageView < $ageAll || !strlen($ageAll)) {
                $data = $this->query($sql)->run();

                $collection = [];

                if (!empty($data)) {
                    foreach ($data as $row) {
                        $id = isAke($row, 'id', false);

                        if (false !== $id) {
                            array_push($collection, $this->dir . DS . $id . '.row');
                        }
                    }
                }

                $this->cache()->set($this->view, serialize($collection));
                $this->cache()->set($ageView, time());
            }

            return $this;
        }

        public function view($queries = [])
        {
            return new View($this, $queries);
        }

        public static function dirStore()
        {
            return Config::get('directory.store', STORAGE_PATH);
        }

        public function timestamp($date)
        {
            return ts($date);
        }

        public function cached($name, $overAge = false)
        {
            return Cached::make($name, $this, $overAge);
        }

        public function memory($ttl = 0)
        {
            return new Memory($this, $ttl);
        }

        public function __sleep()
        {
            $this->reset();
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
                    array_push($collection, $this->row($row));
                }
            }

            return $collection;
        }

        public function take($fields)
        {
            /* polymorphism */
            $fields = !Arrays::is($fields)
            ? fnmatch('*,*', $fields)
                ? explode(',', str_replace(' ', '', $fields))
                : [$fields]
            : $fields;

            $this->take = $fields;

            return $this;
        }
    }
