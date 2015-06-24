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

    namespace Raw;

    use Thin\Instance;
    use Thin\File;

    class Store
    {
        private $dir;

        public function __construct($ns)
        {
            if (!is_dir(STORAGE_PATH . DS . 'raw')) {
                File::mkdir(STORAGE_PATH . DS . 'raw');
            }

            $this->dir = STORAGE_PATH . DS . 'raw' . DS . $ns;

            if (!is_dir($this->dir)) {
                File::mkdir($this->dir);
            }
        }

        public function getDir()
        {
            return $this->dir;
        }

        public static function instance($collection)
        {
            $args   = func_get_args();
            $key    = sha1(serialize($args));
            $has    = Instance::has('rawStore', $key);

            if (true === $has) {
                return Instance::get('rawStore', $key);
            } else {
                return Instance::make('rawStore', $key, new self($collection));
            }
        }

        public function set($key, $value)
        {
            $file = $this->getFile($key);

            if (File::exists($file)) {
                File::delete($file);
            }

            File::put($file, serialize($value));

            return $this;
        }

        public function get($key, $default = null)
        {
            $file = $this->getFile($key);

            if (File::exists($file)) {
                return unserialize(File::read($file));
            }

            return $default;
        }

        public function delete($key)
        {
            $file = $this->getFile($key);

            if (File::exists($file)) {
                File::delete($file);

                return true;
            }

            return false;
        }

        public function del($key)
        {
            return $this->delete($key);
        }

        public function has($key)
        {
            $file = $this->getFile($key);

            return File::exists($file);
        }

        public function age($key)
        {
            $file = $this->getFile($key);

            if (File::exists($file)) {
                return filemtime($file);
            }

            return 0;
        }

        public function incr($key, $by = 1)
        {
            $old = $this->get($key, 0);
            $new = $old + $by;

            $this->set($key, $new);

            return $new;
        }

        public function decr($key, $by = 1)
        {
            $old = $this->get($key, 1);
            $new = $old - $by;

            $this->set($key, $new);

            return $new;
        }

        public function hset($hash, $key, $value)
        {
            $file = $this->getHashFile($hash, $key);

            if (File::exists($file)) {
                File::delete($file);
            }

            File::put($file, serialize($value));

            return $this;
        }

        public function hget($hash, $key, $default = null)
        {
            $file = $this->getHashFile($hash, $key);

            if (File::exists($file)) {
                return unserialize(File::read($file));
            }

            return $default;
        }

        public function hdelete($hash, $key)
        {
            $file = $this->getHashFile($hash, $key);

            if (File::exists($file)) {
                File::delete($file);

                return true;
            }

            return false;
        }

        public function hdel($hash, $key)
        {
            return $this->hdelete($hash, $key);
        }

        public function hhas($hash, $key)
        {
            $file = $this->getHashFile($hash, $key);

            return File::exists($file);
        }

        public function hage($hash, $key)
        {
            $file = $this->getHashFile($hash, $key);

            if (File::exists($file)) {
                return filemtime($file);
            }

            return 0;
        }

        public function hincr($hash, $key, $by = 1)
        {
            $old = $this->hget($hash, $key, 1);
            $new = $old + $by;

            $this->hset($hash, $key, $new);

            return $new;
        }

        public function hdecr($hash, $key, $by = 1)
        {
            $old = $this->hget($hash, $key, 1);
            $new = $old - $by;

            $this->hset($hash, $key, $new);

            return $new;
        }

        public function keys($pattern = '*')
        {
            $coll = [];

            $glob = glob($this->dir . DS . '*');

            foreach ($glob as $row) {
                $row = str_replace([$this->dir . DS, '.raw'], '', $row);
                $coll[] = $row;
            }

            return $coll;
        }

        public function hkeys($hash)
        {
            $coll = [];

            $glob = glob($this->dir . DS . $hash . DS . '*');

            foreach ($glob as $row) {
                $row = str_replace([$this->dir . DS . $hash .DS, '.raw'], '', $row);
                $coll[] = $row;
            }

            return $coll;
        }

        private function getFiles($dir, $pattern)
        {

        }

        public function getFile($file)
        {
            return $this->dir . DS . $file . '.raw';
        }

        public function getHashFile($hash, $file)
        {
            if (!is_dir($this->dir . DS . $hash)) {
                File::mkdir($this->dir . DS . $hash);
            }

            return $this->dir . DS . $hash . DS . $file . '.raw';
        }
    }
