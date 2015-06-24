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

    use \Dbjson\Dbjson as Database;
    use \Thin\Instance;
    use \Thin\Exception;
    use \Thin\Arrays;
    use \Thin\File;
    use \Thin\Database as Db;
    use \Thin\Config as ConfigApp;

    class Mysql
    {
        private $client, $key, $bucket, $model, $db, $table;

        public function __construct(Database $model)
        {
            /* CLI case */
            if (!defined('APPLICATION_ENV')) {
                define('APPLICATION_ENV', 'production');
            }

            $this->model    = $model;

            $this->key      = SITE_NAME . '::' . $model->db . '_' . APPLICATION_ENV . '::' . $model->table;
            $this->table    = Config::get('mysql.table', 'dbjson');

            $this->db       = Db::instance(
                Config::get('mysql.dbname', ConfigApp::get('database.dbname', SITE_NAME)),
                $this->table,
                Config::get('mysql.host', ConfigApp::get('database.host', 'localhost')),
                Config::get('mysql.username', ConfigApp::get('database.username', 'root')),
                Config::get('mysql.password', ConfigApp::get('database.password', ''))
            );
        }

        public static function instance(Database $model)
        {
            $key    = sha1($model->db . $model->table);
            $has    = Instance::has('DbjsonMysql', $key);

            if (true === $has) {
                return Instance::get('DbjsonMysql', $key);
            } else {
                return Instance::make('DbjsonMysql', $key, new self($model));
            }
        }

        public function write($id, $data)
        {
            $key = $this->key . '::' . $id;

            $query = "INSERT INTO $this->table SET dbjson_id = '" . addslashes($key) . "', value = '" . $this->db->escape(serialize(json_decode($data, true))) . "'";

            $result = $this->db->query($query);

            return $this;
        }

        public function read($id, $default = null)
        {
            $key = $this->key . '::' . $id;

            $query = "SELECT value as data FROM $this->table WHERE dbjson_id = '" . addslashes($key) . "'";

            $row = $this->db->fetch($query, false, true);

            if (empty($row)) {
                if (!strstr($id, 'file::')) {
                    $data = File::read($this->findFile($id));
                } else {
                    $data = File::read(
                        str_replace(
                            'thinseparator',
                            '',
                            str_replace(
                                'file::',
                                '',
                                $id
                            )
                        )
                    );
                }

                if (strlen($data)) {
                    $this->write($id, $data);

                    return $data;
                } else {
                    return $default;
                }
            }

            return json_encode(unserialize($row['data']));
        }

        public function delete($id)
        {
            $key = $this->key . '::' . $id;

            $query = "DELETE FROM $this->table WHERE dbjson_id = '" . addslashes($key) . "'";

            $this->db->query($query);

            return $this;
        }

        public function glob()
        {
            $collection = [];

            $query = "SELECT dbjson_id FROM $this->table WHERE dbjson_id LIKE '" . addslashes($this->key . '::%') . "'";

            $rows = $this->db->fetch($query);


            if (count($rows)) {
                foreach ($rows as $row) {
                    $key = $row['dbjson_id'];
                    $addRow = $this->model->dir . DS . str_replace($this->key . '::', '', $key) . '.row';

                    array_push($collection, $addRow);
                }
            }

            return $collection;
        }

        public function extractId($file)
        {
            $tab = explode(DS, $file);

            return str_replace('.row', '', Arrays::last($tab));
        }

        public function findFile($id)
        {
            return $this->model->dir . DS . $id . '.row';
        }

        public static function populateDatabase($database = null)
        {
            $database   = is_null($database) ? SITE_NAME : $database;
            $tables     = glob(Config::get('directory.store', STORAGE_PATH) . DS . 'dbjson' . DS . $database . '_' . APPLICATION_ENV . DS . '*');

            foreach ($tables as $tableDir) {
                $rows = glob($tableDir . DS . '*.row');

                if (count($rows)) {
                    $table  = Arrays::last(explode(DS, $tableDir));
                    $db     = jdb($database, $table);
                    $mysql  = static::instance($db);

                    foreach ($rows as $row) {
                        $mysql->read($mysql->extractId($row));
                    }
                }
            }
        }

        public static function populateTable($table, $database = null)
        {
            $database   = is_null($database) ? SITE_NAME : $database;
            $db         = jdb($database, $table);
            $mysql      = static::instance($db);

            $rows = glob(Config::get('directory.store', STORAGE_PATH) . DS . 'dbjson' . DS . $database . '_' . APPLICATION_ENV . DS . $table . DS . '*.row');

            if (count($rows)) {
                foreach ($rows as $row) {
                    $mysql->read($mysql->extractId($row));
                }
            }
        }

        public function drop()
        {
            $query = "DROP TABLE IF EXISTS `$this->table`;
            CREATE TABLE `$this->table` (
              `dbjson_id` varchar(255) NOT NULL,
              `value` text NOT NULL,
              PRIMARY KEY (`dbjson_id`)
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8;";
        }
    }
