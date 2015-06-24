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

    use \Monga as Db;

    class Mongo
    {
        private $db, $collection, $model, $key;

        public function __construct(Database $model)
        {
            $this->model    = $model;
            $this->key      = $model->db . '::' . $model->table;

            $connection     = Db::connection(Config::get('mongo.host', 'mongodb://localhost:27017'));

            $this->db           = $connection->database('dbjson' . '_' . APPLICATION_ENV);
            $this->collection   = $this->db->collection(SITE_NAME);
        }

        public static function instance(Database $model)
        {
            $key    = sha1($model->db . $model->table);
            $has    = Instance::has('DbjsonMongo', $key);

            if (true === $has) {
                return Instance::get('DbjsonMongo', $key);
            } else {
                return Instance::make('DbjsonMongo', $key, new self($model));
            }
        }

        public function write($id, $data)
        {
            $key = $this->key . '::' . $id;

            $insert = $this->collection->insert(
                [
                    [
                        'key'   => $key,
                        'value' => $data
                    ]
                ]
            );

            return $this;
        }

        public function read($id, $default = null)
        {
            $key = $this->key . '::' . $id;

            $row = $this->collection->findOne(function($query) use ($key) {
                $query->where('key', $key);
            });

            if ($row) {
                return isAke($row, 'value', $default);
            } else {
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

            return $default;
        }

        public function delete($id)
        {
            $key = $this->key . '::' . $id;

            $row = $this->collection->findOne(function($query)  use ($key) {
                $query->where('key', $key);
            });

            if ($row) {
                dd($row);
                $del = $this->collection->remove($row);
            }

            return $this;
        }

        public function glob()
        {
            $collection = [];

            $key = $this->key;

            $rows = $this->collection->find(function($query) use ($key) {
                $query->whereLike('key', $key . '::%');
            });

            if (count($rows)) {
                foreach ($rows as $row) {
                    $key    = $row['key'];
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
                    $mongo  = static::instance($db);

                    foreach ($rows as $row) {
                        $mongo->read($mongo->extractId($row));
                    }
                }
            }
        }

        public static function populateTable($table, $database = null)
        {
            $db         = jdb($database, $table);
            $mongo      = static::instance($db);
            $database   = is_null($database) ? SITE_NAME : $database;

            $rows = glob(Config::get('directory.store', STORAGE_PATH) . DS . 'dbjson' . DS . $database . '_' . APPLICATION_ENV . DS . $table . DS . '*.row');

            if (count($rows)) {
                foreach ($rows as $row) {
                    $mongo->read($mongo->extractId($row));
                }
            }
        }

        public function drop()
        {
            $this->collection->drop();

            return $this;
        }
    }
