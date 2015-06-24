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

    namespace Clue;

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
        }

        public function getPath()
        {
            return $this->collection;
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

        public function write($name, $data = [])
        {
            $file = $this->getFile($name);

            redis()->del($file);

            $data = is_array($data) ? var_export($data, 1) : var_export([$data], 1);
            $res = redis()->set($file, "return " . $data . ';');

            return $this;
        }

        public function count($dir)
        {
            $path = $this->collection . '.' . $dir;

            $files = redis()->keys($path . '.*');

            return count($files);
        }

        public function ids($dir)
        {
            return array_keys($this->all($dir));
        }

        public function all($dir)
        {
            $path = $this->collection . '.' . $dir;

            $files = redis()->keys($path . '.*');

            $collection = [];

            foreach ($files as $file) {
                $content = $this->import($file);

                if (!Arrays::isAssoc($content)) {
                    $content = current($content);
                }

                $id = (int) Arrays::last(explode('.', $file));

                $collection[$id] = $content;
            }

            return $collection;
        }

        public function read($name, $default = null)
        {
            $file = $this->getFile($name);

            $content = $this->import($file);

            if ($content) {
                if (Arrays::isAssoc($content)) {
                    return $content;
                }

                return current($content);
            }

            return $default;
        }

        public function remove($name)
        {
            $file = $this->getFile($name);

            redis()->del($file);

            return $this;
        }

        public function getFile($name)
        {
            $path = $this->collection;

            $tab = $tmp = explode('.', $name);

            $fileName = end($tmp);

            array_pop($tab);

            foreach ($tab as $subPath) {
                $path .= '.' . $subPath;
            }

            return $path . '.' . $fileName;
        }

        public function import($file)
        {
            $content = redis()->get($file);

            if (!$content) {
                $content = 'return null;';
            }

            return eval($content);
        }
    }
