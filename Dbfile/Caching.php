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

    namespace Dbfile;

    use Thin\Arrays;
    use Thin\File;
    use Thin\Instance;

    class Caching
    {
        private $ns;

        public function __construct($database = null)
        {
            $this->ns = is_null($database) ? SITE_NAME : $database;

            $this->clean();
        }

        public static function instance($database = null)
        {
            $args   = func_get_args();
            $key    = sha1(serialize($args));
            $has    = Instance::has('fileKh', $key);

            if (true === $has) {
                return Instance::get('fileKh', $key);
            } else {
                return Instance::make('fileKh', $key, new self($database));
            }
        }

        private function clean()
        {
            $dirs = glob(APPLICATION_PATH . DS . 'storage' . DS . 'db' . DS . 'caching' . DS . 'expires' . DS . '*');

            foreach ($dirs as $dir) {
                $when = (int) Arrays::last(explode('/', $dir));

                if ($when < time()) {
                    $files = glob($dir . DS . '*.db');

                    if (!empty($files)) {
                        foreach ($files as $file) {
                            $key = str_replace('.db', '', Arrays::last(explode('/', $file)));
                            $fileToDelete = $this->getFile($key);
                            File::delete($fileToDelete);
                            File::delete($file);
                        }
                    }

                    File::rmdir($dir);
                }
            }
        }

        public function get($key, $default = null)
        {
            $key = $this->key($key);

            $this->clean();

            $file = $this->getFile($key);

            if (File::exists($file)) {
                return unserialize(File::read($file));
            }

            return $default;
        }

        public function set($key, $value, $ttl = 0)
        {
            $key = $this->key($key);
            $ttl = 0 < $ttl ? $ttl + time() : $ttl;

            $file = $this->getFile($key);
            File::delete($file);
            File::put($file, serialize($value));

            if ($ttl > 0) {
                $file = $this->getFile('expires.' . $ttl . '.' . $key);
                File::delete($file);
                File::put($file, '');
            }

            return true;
        }

        public function expireat($key, $timestamp)
        {
            $key    = $this->key($key);
            $file   = $this->getFile($key);

            if (File::exists($file)) {
                if (time() > $timestamp) {
                    File::delete($file);

                    return false;
                } else {
                    $file = $this->getFile('expires.' . $timestamp . '.' . $key);
                    File::delete($file);
                    File::put($file, '');

                    return true;
                }
            }

            return false;
        }

        public function expire($key, $ttl = 0)
        {
            $key    = $this->key($key);
            $ttl    = 0 < $ttl ? $ttl + time() : $ttl;
            $file   = $this->getFile($key);

            if (File::exists($file)) {
                $file = $this->getFile('expires.' . $ttl . '.' . $key);
                File::delete($file);
                File::put($file, '');

                return true;
            }

            return false;
        }

        public function delete($key)
        {
            return $this->del($key);
        }

        public function del($key)
        {
            $key = $this->key($key);
            $this->clean();

            $file = $this->getFile($key);

            if (File::exists($file)) {
                foreach ($dirs as $dir) {
                    $when = (int) Arrays::last(explode('/', $dir));

                    $files = glob($dir . DS . '*.db');

                    if (!empty($files)) {
                        foreach ($files as $fileTtl) {
                            if (fnmatch("*$key.db", $fileTtl)) {
                                File::delete($fileTtl);

                                break;
                            }
                        }
                    }
                }
            }

            return File::exists($file) ? File::delete($file) : false;
        }

        public function has($key)
        {
            return $this->exists($key);
        }

        public function exists($key)
        {
            $key = $this->key($key);
            $this->clean();

            $file = $this->getFile($key);

            return File::exists($file);
        }

        public function ttl($key)
        {
            $key = $this->key($key);
            $this->clean();

            $dirs = glob(APPLICATION_PATH . DS . 'storage' . DS . 'db' . DS . 'caching' . DS . 'expires');

            foreach ($dirs as $dir) {
                $when = (int) Arrays::last(explode('/', $dir));

                $files = glob($dir . DS . '*.db');

                if (!empty($files)) {
                    foreach ($files as $file) {
                        if (fnmatch("*$key.db", $file)) {
                            return $when;
                        }
                    }
                }
            }

            return 0;
        }

        public function incr($key, $by = 1)
        {
            $val = $this->get($key);

            if (!strlen($val)) {
                $val = 1;
            } else {
                $val = (int) $val;
                $val += $by;
            }

            $this->set($key, $val);

            return $val;
        }

        public function decr($key, $by = 1)
        {
            $val = $this->get($key);

            if (!strlen($val)) {
                $val = 0;
            } else {
                $val = (int) $val;
                $val -= $by;
                $val = 0 > $val ? 0 : $val;
            }

            $this->set($key, $val);

            return $val;
        }

        public function keys($pattern = '*')
        {
            $this->clean();

            $collection = [];

            $files = glob(APPLICATION_PATH . DS . 'storage' . DS . 'db' . DS . 'caching' . DS . '*.db');

            foreach ($files as $file) {
                $key = str_replace('.db', '', Arrays::last(explode('/', $file)));

                if (fnmatch($pattern, $key)) {
                    $collection[] = $key;
                }
            }

            return $collection;
        }

        public function hset($hash, $key, $value, $ttl = 0)
        {
            return $this->set("$hash.$key", $value, $ttl);
        }

        public function httl($hash, $key)
        {
            return $this->ttl("$hash.$key");
        }

        public function hget($hash, $key, $default = null)
        {
            return $this->get("$hash.$key", $default);
        }

        public function hexpire($hash, $key, $ttl = 0)
        {
            return $this->expire("$hash.$key", $ttl);
        }

        public function hexpireat($hash, $key, $timestamp)
        {
            return $this->expireat("$hash.$key", $timestamp);
        }

        public function hdelete($hash, $key)
        {
            return $this->del("$hash.$key");
        }

        public function hdel($hash, $key)
        {
            return $this->del("$hash.$key");
        }

        public function hincr($hash, $key, $by = 1)
        {
            return $this->incr("$hash.$key", $by);
        }

        public function hdecr($hash, $key, $by = 1)
        {
            return $this->decr("$hash.$key", $by);
        }

        public function hkeys($hash, $pattern = '*')
        {
            return $this->keys($pattern, $hash);
        }

        public function hhas($hash, $key)
        {
            return $this->has("$hash.$key");
        }

        public function hexists($hash, $key)
        {
            return $this->has("$hash.$key");
        }

        public function hgetall($hash)
        {
            $collection = [];
            $keys       = $this->hkeys($hash);

            foreach ($keys as $key) {
                $value = $this->hget($hash, $key, null);
                $collection[$key] = $value;
            }

            return $collection;
        }

        public function getFile($file)
        {
            $path = APPLICATION_PATH . DS . 'storage' . DS . 'db';
            $file = 'caching.' . $file;

            $tab = $tmp = explode('.', $file);

            $fileName = end($tmp) . '.db';

            array_pop($tab);

            foreach ($tab as $subPath) {
                $path .= DS . $subPath;

                if (!is_dir($path)) {
                    File::mkdir($path);
                }
            }

            return $path . DS . $fileName;
        }

        public function getFiles($pattern)
        {
            $path = APPLICATION_PATH . DS . 'storage' . DS . 'db';
            $file = 'caching.' . $pattern;

            $tab = explode('.', $file);

            foreach ($tab as $subPath) {
                $path .= DS . $subPath;

                if (!is_dir($path)) {
                    File::mkdir($path);
                }
            }

            return glob($path . DS . '*.db');
        }

        private function key($key)
        {
            return sha1($this->ns . '.' . $key);
        }
    }
