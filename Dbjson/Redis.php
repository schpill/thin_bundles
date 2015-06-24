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

    class Redis implements Storage
    {
        private $model, $replicas, $redisStore;

        public function __construct(Database $model)
        {
            $this->model    = $model;
            $this->replicas = Config::get('local.replicas', []);
            $this->redisStore = 'store::' . $model->db . '::' . $model->table;
        }

        public static function instance(Database $model)
        {
            $key    = sha1($model->db . $model->table);
            $has    = Instance::has('DbjsonRedisStore', $key);

            if (true === $has) {
                return Instance::get('DbjsonRedisStore', $key);
            } else {
                return Instance::make('DbjsonRedisStore', $key, new self($model));
            }
        }

        public function write($file, $data)
        {
            $key        = $this->redisKey($file);
            $source     = $this->fp($file, $data);

            if (count($this->replicas)) {
                $dirStore   = $this->model->dirStore();
                $raw        = repl($dirStore, '', $file);

                foreach ($this->replicas as $replica) {
                    $rawFile = $replica . $raw;

                    $copy = $this->fp($rawFile, $data);
                }
            }

            $this->model->cache()->set($key, $data);
        }

        public function read($file)
        {
            $key = $this->redisKey($file);

            $cache = $this->model->cache()->get($key);

            if (!strlen($cache)) {
                if (File::exists($file)) {
                    $cache = File::read($file);
                    $this->model->cache()->set($key, $cache);
                }
            }

            return $cache;
        }

        public function delete($file)
        {
            $key = $this->redisKey($file);
            $this->model->cache()->del($key);

            return File::delete($file);
        }

        public function glob()
        {
            $collection = [];

            $keys = $this->model->cache()->keys($this->redisStore . '::row::*');

            if (count($keys)) {
                foreach ($keys as $key) {
                    $file = $this->localFile($key);
                    array_push($collection, $file);
                }
            }

            if (!count($collection)) {
                $collection = glob($this->model->dir . DS . '*.row', GLOB_NOSORT);
            }

            return $collection;
        }

        public function extractId($file)
        {
            return $file;
        }

        private function redisKey($file)
        {
            $tab = explode(DS, $file);

            $id = (int) str_replace('.row', '', Arrays::last($tab));

            return $this->redisStore . '::row::' . $id;
        }

        private function localFile($key)
        {
            $tab = explode('::', $key);

            $id = Arrays::last($tab);

            return $this->model->dir . DS . $id . '.row';
        }

        private function fp($file, $data)
        {
            $fp = fopen($file, 'w');

            if (!flock($fp, LOCK_EX)) {
                throw new Exception("The file '$file' can not be locked.");
            }

            $result = fwrite($fp, $data);

            flock($fp, LOCK_UN);

            fclose($fp);

            umask(0000);

            chmod($file, 0777);

            return $result !== false;
        }
    }
