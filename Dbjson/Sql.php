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
    use PDO;
    use Thin\Database\Collection;

    class Sql
    {
        private $model, $db;

        public function __construct(Database $model)
        {
            /* CLI case */
            if (!defined('APPLICATION_ENV')) {
                define('APPLICATION_ENV', 'production');
            }

            $this->model    = $model;
            $ageChange      = $model->cache()->get(sha1($model->dir));
            $ageDb          = $model->cache()->get(sha1($model->dir) . '_ageDb');

            $dbFile     = Config::get('directory.store', STORAGE_PATH) . DS . $model->db . '_' . APPLICATION_ENV . '.db';
            $this->db   = new PDO('sqlite:' . $dbFile);

            umask(0000);

            chmod($dbFile, 0777);

            $populate   = true;

            if (strlen($ageDb) && strlen($ageChange)) {
                if ($ageDb > $ageChange) {
                    $populate = false;
                } else {
                    $model->cache()->del(sha1($model->dir) . '_ageDb');
                }
            }

            if ($populate) {
                $this->populate();
            }
        }

        private function populate()
        {
            $model = $this->model;

            $query = 'DROP TABLE IF EXISTS `' . $model->table . '`;';
            $res = $this->db->query($query);

            $fields = array_merge($model->fields(), ['created_at', 'updated_at']);

            array_shift($fields);

            $query = 'CREATE TABLE `' . $model->table . '` (id INTEGER PRIMARY KEY AUTOINCREMENT, ' . implode(', ', $fields) . ');';
            $res = $this->db->query($query);

            foreach ($fields as $field) {
                if (substr($field, strlen($field) - 3, strlen($field)) == '_id') {
                    $query = 'CREATE INDEX `index_' . $field . '` ON `' . $model->table . '` (' . $field . ');';
                    $res = $this->db->query($query);
                }
            }

            $data = $model->all();

            if (count($data)) {
                $i = 0;
                $this->db->exec("BEGIN EXCLUSIVE TRANSACTION");

                foreach ($data as $row) {
                    $query = 'INSERT INTO `' . $model->table . '` (id, ' . implode(', ', $fields) . ') VALUES (\'' . addslashes($row['id']) . '\', ';

                    foreach ($fields as $field) {
                        $value = isAke($row, $field, '');
                        $query .= "'" . addslashes($value) . "', ";
                    }

                    $query = substr($query, 0, -2);

                    $query .= ');';

                    $this->db->exec($query);

                    if($i % 1000 == 0) {
                        $this->db->exec('COMMIT TRANSACTION');
                        $this->db->exec('BEGIN TRANSACTION');
                    }

                    unset($row);

                    $i++;
                }

                $this->db->exec('COMMIT TRANSACTION');
            }

            $model->cache()->set(sha1($model->dir) . '_ageDb', time());
        }

        public function select($sql, $object = false)
        {
            $model      = $this->model;
            $res        = $this->db->query($sql);
            $collection = array();

            if (!empty($res)) {
                foreach ($res as $row) {
                    $id = isAke($row, 'id', false);

                    if (false !== $id) {
                        $obj = $model->find($id, $object);
                        array_push($collection, $obj);
                    }
                }
            }

            return true === $object ? new Collection($collection) : $collection;
        }

        public function join($model)
        {
            if (is_string($model)) {
                $model = jmodel($model);
            }

            return new self($model);
        }
    }
