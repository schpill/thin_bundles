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

    use \Aws\S3\S3Client;
    use \Aws\S3\Exception\NoSuchKeyException;
    use \Dbjson\Dbjson as Database;
    use \Thin\Instance;
    use \Thin\Arrays;
    use \Thin\File;

    class S3
    {
        private $client, $key, $bucket, $model;

        public function __construct(Database $model)
        {
            /* CLI case */
            if (!defined('APPLICATION_ENV')) {
                define('APPLICATION_ENV', 'production');
            }

            $this->model    = $model;
            $this->client   = S3Client::factory(
                [
                    'region' => Config::get('s3.region', 'eu-west-1'),
                    'key'    => Config::get('s3.key'),
                    'secret' => Config::get('s3.secret')
                ]
            );

            $this->key      = SITE_NAME . '::' . $model->db . '_' . APPLICATION_ENV . '::' . $model->table;

            $this->bucket   = 'dbjson';

            $check          = $this->client->doesBucketExist($this->bucket);

            if (!$check) {
                $result = $client->createBucket(
                    array(
                        'Bucket' => $this->bucket
                    )
                );
            }
        }

        public static function instance(Database $model)
        {
            $key    = sha1($model->db . $model->table);
            $has    = Instance::has('DbjsonS3', $key);

            if (true === $has) {
                return Instance::get('DbjsonS3', $key);
            } else {
                return Instance::make('DbjsonS3', $key, new self($model));
            }
        }

        public function write($id, $data)
        {
            $key = $this->key . '::' . $id;

            $result = $this->client->putObject(
                array(
                    'Bucket'    => $this->bucket,
                    'Key'       => $key,
                    'Body'      => $data
                )
            );

            return $this;
        }

        public function read($id, $default = null)
        {
            $key = $this->key . '::' . $id;

            try {
                $result = $this->client->getObject(
                    array(
                        'Bucket'    => $this->bucket,
                        'Key'       => $key
                    )
                );
            } catch (NoSuchKeyException $e) {
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

            return $result['Body'];
        }

        public function delete($id)
        {
            $key = $this->key . '::' . $id;

            $result = $this->client->deleteObject(
                array(
                    'Bucket'    => $this->bucket,
                    'Key'       => $key
                )
            );

            return $this;
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
                    $table = Arrays::last(explode(DS, $tableDir));
                    $db = jdb($database, $table);
                    $s3 = static::instance($db);

                    foreach ($rows as $row) {
                        $s3->read($s3->extractId($row));
                    }
                }
            }
        }

        public static function populateTable($table, $database = null)
        {
            $database = is_null($database) ? SITE_NAME : $database;

            $rows = glob(Config::get('directory.store', STORAGE_PATH) . DS . 'dbjson' . DS . $database . '_' . APPLICATION_ENV . DS . $table . DS . '*.row');

            if (count($rows)) {
                $db = jdb($database, $table);
                $s3 = static::instance($db);

                foreach ($rows as $row) {
                    $s3->read($s3->extractId($row));
                }
            }
        }
    }
