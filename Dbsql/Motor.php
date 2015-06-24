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

    namespace Dbsql;

    use Thin\Arrays;
    use Thin\Utils;
    use Thin\File;
    use Thin\Exception;
    use Thin\Instance;
    use Thin\Inflector;

    class Motor
    {
        private $collection, $orm;

        public function __construct($collection)
        {
            $this->collection   = $collection;

            $this->orm = lib('mysql')->table('kvs_db', SITE_NAME);
        }

        public static function instance($collection)
        {
            $args   = func_get_args();
            $key    = sha1(serialize($args));
            $has    = Instance::has('phpDbSqlMotor', $key);

            if (true === $has) {
                return Instance::get('phpDbSqlMotor', $key);
            } else {
                return Instance::make('phpDbSqlMotor', $key, new self($collection));
            }
        }

        public function write($name, $data = [])
        {
            $name = $this->makeName($name);
            $file = $this->getFile($name);

            if ($file) {
                $file->delete();
            }

            $file = $this->orm->create([
                'expire' => 0,
                'kvs_db_id' => $name,
                'value' => serialize($data)
            ]);

            return $this;
        }

        public function count($dir)
        {
            $pattern = $this->makeName($dir . '.');

            return $this->orm->where('kvs_db_id', 'LIKE', $pattern . '%')->count();
        }

        public function ids($dir)
        {
            return array_keys($this->all($dir));
        }

        public function all($dir)
        {
            $pattern = $this->makeName($dir . '.');

            $files = $this->orm->where('kvs_db_id', 'LIKE', $pattern . '%')->get();

            $collection = [];

            foreach ($files as $file) {
                $content = unserialize($file->value);

                if (is_array($content)) {
                    if (!Arrays::isAssoc($content)) {
                        $content = is_array($content) ? current($content) : $content;
                    }
                }

                $id = (int) Arrays::last(explode('.', $file->kvs_db_id));

                $collection[$id] = $content;
            }

            return $collection;
        }

        public function read($name, $default = null)
        {
            $name = $this->makeName($name);
            $file = $this->getFile($name);

            if ($file) {
                $content = unserialize($file->value);

                if (is_array($content)) {
                    if (Arrays::isAssoc($content)) {
                        return $content;
                    }
                }

                return is_array($content) ? current($content) : $content;
            }

            return $default;
        }

        public function remove($name)
        {
            $name = $this->makeName($name);
            $file = $this->getFile($name);

            if ($file) {
                $file->delete();
            }

            return $this;
        }

        public function makeName($name)
        {
            return $this->collection . '.' . $name;
        }

        public function getFile($name)
        {
            return $this->orm->where('kvs_db_id', '=', addslashes($name))->first();
        }
    }
