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

    namespace Dblight;

    use Thin\Arrays;
    use Thin\Utils;
    use Thin\File;
    use Thin\Exception;
    use Thin\Instance;
    use Thin\Inflector;

    class Cache
    {
        public function clean()
        {
            $now = time();
            $db = Db::instance('core', 'expire');

            $res = $db->where(['expire', '<', (int) $now])->get();

            while ($row = $res->model()) {
                $key = $row->key;
                $this->motor()->remove('values.' . $key);

                $row->delete();
            }
        }

        public function setExpire($key, $value, $expire)
        {
            return $this->set($key, $value, $expire);
        }

        public function set($key, $value, $expire = 0)
        {
            $this->clean();

            $this->motor()->write('values.' . $key, $value);

            if ($expire > 0) {
                $expire += time();
                $db = Db::instance('core', 'expire');
                $db->firstOrCreate(['key' => $key])->setExpire((int) $expire)->save();
            }

            return $this;
        }

        public function get($key, $default = null)
        {
            $this->clean();

            return $this->motor()->read('values.' . $key, $default);
        }

        public function has($key)
        {
            $this->clean();

            $token = Utils::UUID();

            return $this->motor()->read('values.' . $key, $token) != $token;
        }

        public function del($key)
        {
            return $this->delete($key);
        }

        public function delete($key)
        {
            $this->clean();

            $this->motor()->remove('values.' . $key);

            return $this;
        }

        public function expire($key, $expire = 0)
        {
            if ($expire > 0 && $this->has($key)) {
                $expire += time();
                $db = Db::instance('core', 'expire');
                $db->firstOrCreate(['key' => $key])->setExpire((int) $expire)->save();
            }

            return $this;
        }

        public function incr($key, $by = 1)
        {
            $val = $this->get($key, 0);
            $val += $by;

            $this->set($key, $val);

            return $val;
        }

        public function decr($key, $by = 1)
        {
            $val = $this->get($key, 0);
            $val -= $by;

            $this->set($key, $val);

            return $val;
        }

        public function keys($pattern = '*', $dir = null, $collection = [])
        {
            $dir = empty($dir) ? $this->motor()->getPath() . DS . 'values' : $dir;

            $segs = glob($dir . DS . '*');

            foreach ($segs as $seg) {
                if (is_dir($seg)) {
                    $collection[] = $this->keys($pattern, $seg, $collection);
                } else {
                    $seg = str_replace($this->motor()->getPath() . DS . 'values' . DS, '', $seg);

                    $key = str_replace([DS, '.php'], ['.', ''], $seg);

                    if (fnmatch($pattern, $key)) {
                        $collection[] = $key;
                    }
                }
            }

            return Arrays::flatten($collection);
        }

        public function motor()
        {
            return new Motor('core.cache');
        }
    }
