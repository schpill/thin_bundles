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
    use \Thin\Arr;
    use \Thin\File;
    use \Thin\Queue;

    class Local implements Storage
    {
        private $model, $replicas;

        public function __construct(Database $model)
        {
            $this->model    = $model;
            $this->replicas = Config::get('local.replicas', []);
        }

        public static function instance(Database $model)
        {
            $key    = sha1($model->db . $model->table);
            $has    = Instance::has('DbjsonLocal', $key);

            if (true === $has) {
                return Instance::get('DbjsonLocal', $key);
            } else {
                return Instance::make('DbjsonLocal', $key, new self($model));
            }
        }

        public function write($file, $data)
        {
            $dirStore   = $this->model->dirStore();

            self::fp($this->hashFile($file), $data);

            if (count($this->replicas)) {
                $raw = repl($dirStore, '', $this->hashFile($file));

                foreach ($this->replicas as $replica) {
                    $rawFile = $replica . $raw;
                    self::fp($rawFile, $data);
                }
            }

            return $this;
        }

        public function read($file)
        {
            return File::read($this->hashFile($file));
        }

        public function delete($file)
        {
            File::delete($this->hashFile($file));

            return $this;
        }

        public function glob()
        {
            $rows = [];

            $firstLevels =  glob($this->model->dir . DS . '*', GLOB_NOSORT);

            foreach ($firstLevels as $firstLevel) {
                $secondLevels =  glob($firstLevel . DS . '*', GLOB_NOSORT);

                foreach ($secondLevels as $secondLevel) {
                    $files = glob($secondLevel . DS . '*.row', GLOB_NOSORT);

                    foreach ($files as $file) {
                        $id = str_replace('.row', '', Arrays::last(explode(DS, $file)));

                        if (is_numeric($id)) {
                            array_push($rows, $file);
                        } else {
                            File::delete($file);
                        }
                    }
                }
            }

            return $rows;
        }

        public function globids()
        {
            // $rows = new Arr(Arr::INT_TO_INT);
            $rows = [];

            $i = 0;

            $firstLevels =  glob($this->model->dir . DS . '*', GLOB_NOSORT);

            foreach ($firstLevels as $firstLevel) {
                $secondLevels =  glob($firstLevel . DS . '*', GLOB_NOSORT);

                foreach ($secondLevels as $secondLevel) {
                    $files = glob($secondLevel . DS . '*.row', GLOB_NOSORT);

                    foreach ($files as $file) {
                        $id = str_replace('.row', '', Arrays::last(explode(DS, $file)));

                        if (is_numeric($id)) {
                            $rows[$i] = $id;
                            $i++;
                        } else {
                            File::delete($file);
                        }
                    }
                }
            }

            return $rows;
        }

        public function extractId($file)
        {
            return $file;
        }

        private function idFile($file)
        {
            return (int) str_replace('.row', '', Arrays::last(explode(DS, $file)));
        }

        public function hashFile($file, $create = true)
        {
            list($prefix, $suffix) = explode('/zelift/dbjson', $this->model->dir, 2);

            $suffix = str_replace('_production', '_development', $suffix);

            $hashDir = '/home/gerald/hubic/Web/DB/zelift/dbjson' . $suffix;

            $id     = $this->idFile($file);
            $hash   = sha1($hashDir . $id);

            $parts  = array_slice(str_split($hash, 2), 0, 2);

            $dir    = $this->model->dir;

            foreach($parts as $part) {
                $dir .= DS . $part;

                if (!is_dir($dir) && $create) {
                    File::mkdir($dir);
                }
            }

            $newFile = $dir . DS . $id . '.row';

            return $newFile;
        }

        public static function fp($file, $data)
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

        public static function populateHash()
        {
            set_time_limit(0);

            $files = [];
            $dbs = glob(STORAGE_PATH . DS . 'dbjson' . DS . '*');

            foreach ($dbs as $db) {
                $tables = glob($db . DS . '*');

                foreach ($tables as $table) {
                    $rows = glob($table . DS . '*.row');

                    foreach ($rows as $row) {
                        array_push($files, $row);
                    }
                }
            }

            foreach ($files as $file) {
                $tab    = explode(DS, $file);
                $db     = str_replace('_' . APPLICATION_ENV, '', $tab[count($tab) - 3]);
                $table  = $tab[count($tab) - 2];

                $instance = new self(jdb($db, $table));

                $newFile = $instance->hashFile($file);
                File::move($file, $newFile);
            }
        }
    }
