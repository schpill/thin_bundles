<?php
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

            $path = $this->motor()->getPath() . DS . 'values';

            $dirs = glob($path . '*');

            foreach ($dirs as $dir) {
                $expire = (int) Arrays::last(explode(DS, $dir));

                if (0 < $expire) {
                    if ($now > $expire) {
                        $files = glob($dir . DS . '*.php');

                        foreach ($files as $file) {
                            $key = include($file);
                            $this->motor()->remove('values.' . $key);
                        }

                        File::rmdir($dir);
                    }
                }
            }
        }

        public function set($key, $value, $expire = 0)
        {
            $this->clean();

            $this->motor()->write('values.' . $key, $value);

            if ($expire > 0) {
                $this->motor()->write('expires.' . $expire, $key);
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

        public function motor()
        {
            return new Motor('core.cache');
        }
    }
