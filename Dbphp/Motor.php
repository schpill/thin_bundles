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

    namespace Dbphp;

    use Thin\Arrays;
    use Thin\Utils;
    use Thin\File;
    use Thin\Exception;
    use Thin\Instance;
    use Thin\Inflector;

    class Motor
    {
        private $collection, $path;

        public function __construct($collection)
        {
            $this->collection   = $collection;
            $this->path         = APPLICATION_PATH . DS . 'storage' . DS . 'dbPhp' . DS . str_replace('.', DS, $collection);

            $this->prepare();
        }

        public static function instance($collection)
        {
            $args   = func_get_args();
            $key    = sha1(serialize($args));
            $has    = Instance::has('phpDbMotor', $key);

            if (true === $has) {
                return Instance::get('phpDbMotor', $key);
            } else {
                return Instance::make('phpDbMotor', $key, new self($collection));
            }
        }

        private function prepare()
        {
            $tab = explode('.', $this->collection);

            if (!is_dir(APPLICATION_PATH . DS . 'storage' . DS . 'dbPhp')) {
                File::mkdir(APPLICATION_PATH . DS . 'storage' . DS . 'dbPhp');
            }

            $path = APPLICATION_PATH . DS . 'storage' . DS . 'dbPhp';

            foreach ($tab as $subPath) {
                $path .= DS . $subPath;

                if (!is_dir($path)) {
                    File::mkdir($path);
                }
            }
        }

        public function write($name, $data = [])
        {
            $file = $this->getFile($name);

            File::delete($file);

            $data = is_array($data) ? var_export($data, 1) : var_export([$data], 1);
            File::put($file, "<?php\nreturn " . $data . ';');

            // touch($file, time() - 10);

            return $this;
        }

        public function count($dir)
        {
            $path = $this->path . DS . str_replace('.', DS, $dir);

            $files = glob($path . DS . '*.php');

            return count($files);
        }

        public function ids($dir)
        {
            return array_keys($this->all($dir));
        }

        public function all($dir)
        {
            $path = $this->path . DS . str_replace('.', DS, $dir);

            $files = glob($path . DS . '*.php');

            $collection = [];

            foreach ($files as $file) {
                $content = include($file);

                if (!Arrays::isAssoc($content)) {
                    $content = current($content);
                }

                $id = (int) str_replace('.php', '', Arrays::last(explode(DS, $file)));

                $collection[$id] = $content;
            }

            return $collection;
        }

        public function read($name)
        {
            $file = $this->getFile($name);

            if (File::exists($file)) {
                $content = include($file);

                if (Arrays::isAssoc($content)) {
                    return $content;
                }

                return current($content);
            }

            return null;
        }

        public function remove($name)
        {
            $file = $this->getFile($name);

            File::delete($file);

            return $this;
        }

        private function getFile($name)
        {
            $path = $this->path;

            $tab = $tmp = explode('.', $name);

            $fileName = end($tmp) . '.php';

            array_pop($tab);

            foreach ($tab as $subPath) {
                $path .= DS . $subPath;

                if (!is_dir($path)) {
                    File::mkdir($path);
                }
            }

            return $path . DS . $fileName;
        }
    }
