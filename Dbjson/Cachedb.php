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

    class Cachedb
    {
        public static function clean()
        {
            $rows = jdb('system', 'cache')
            ->where('expire > 0')
            ->where('expire < ' . time())
            ->exec(true);

            if (count($rows)) {
                foreach ($rows as $row) {
                    $row->delete();
                }
            }
        }

        public static function all()
        {
            $collection = [];

            $data = jdb('system', 'cache')
            ->fetch()
            ->exec();

            if (count($data)) {
                foreach ($data as $row) {
                    $key = isAke($row, 'key', false);

                    if (false !== $key) {
                        $value = self::get($key, false);

                        if (false !== $value) {
                            $collection[$key] = $value;
                        }
                    }
                }
            }

            return $collection;
        }

        public static function getall()
        {
            return self::all();
        }

        public static function keys($pattern = null)
        {
            $collection = [];

            if (empty($pattern) || '*' == $pattern) {
                $data = jdb('system', 'cache')
                ->fetch()
                ->exec();
            } else {
                $pattern = str_replace('*', '%', $pattern);

                $data = jdb('system', 'cache')
                ->where('key LIKE ' . $pattern)
                ->exec();
            }

            if (count($data)) {
                foreach($data as $row) {
                    $collection[] = $row['key'];
                }
            }

            return $collection;
        }

        public static function setex($key, $value, $expireMinutes)
        {
            return self::set($key, $value, $expireMinutes * 60);
        }

        public static function set($key, $value, $expire = 0)
        {
            $expire = $expire > 0 ? $expire + time() : $expire;

            $value = is_numeric($value) ? $value : serialize($value);

            return jdb('system', 'cache')->create([
                'key'       => $key,
                'value'     => $value,
                'expire'    => $expire
            ])->save();
        }

        public static function get($key, $default = null)
        {
            self::clean();

            $row = jdb('system', 'cache')
            ->where('key = ' . $key)
            ->first(true);

            if ($row) {
                $value = $row->value;

                return is_numeric($value) ? $value : unserialize($value);
            }

            return $default;
        }

        public static function expire($key, $expire = 0)
        {
            $expiration = time() + ($expire * 60);

            $row = jdb('system', 'cache')
            ->where('key = ' . $key)
            ->first(true);

            if ($row) {
                $row->setExpire($expiration)->save();

                return true;
            }

            return false;
        }

        public static function del($key)
        {
            return self::delete($key);
        }

        public static function delete($key)
        {
            $row = jdb('system', 'cache')
            ->where('key = ' . $key)
            ->first(true);

            if ($row) {
                $row->delete();

                return true;
            }

            return false;
        }

        public static function incrBy($key, $by)
        {
            return self::incr($key, $by);
        }

        public static function decrBy($key, $by)
        {
            return self::decr($key, $by);
        }

        public static function incr($key, $by = 1)
        {
            $val = self::get($key);

            if (!strlen($val)) {
                $val = 1;
            } else {
                $val = (int) $val;
                $val += $by;
            }

            self::set($key, $val);

            return $val;
        }

        public static function decr($key, $by = 1)
        {
            $val = self::get($key);

            if (!strlen($val)) {
                $val = 0;
            } else {
                $val = (int) $val;
                $val -= $by;
                $val = 0 > $val ? 0 : $val;
            }

            self::set($key, $val);

            return $val;
        }

        public static function has($key)
        {
            $check = self::get($key, false);

            return $check !== false;
        }

        public static function hsetex($hash, $key, $value, $expireMinutes)
        {
            return self::hset($hash, $key, $value, $expire * 60);
        }

        public static function hset($hash, $key, $value, $expire = 0)
        {
            $tab = self::get($hash, []);

            $value = is_numeric($value) ? $value : serialize($value);

            $tab[] = [
                'key'       => $key,
                'value'     => $value,
                'expire'    => $expire
            ];

            self::del($hash);

            return self::set($hash, $tab);
        }

        public static function hget($hash, $key, $default = null)
        {
            $tab = self::get($hash, []);

            if (count($tab)) {
                foreach ($tab as $row) {
                    $k      = isAke($row, 'key', false);
                    $expire = isAke($row, 'expire', 0);

                    if ($k !== false && $k == $key) {
                        if ($expire == 0 || $expire > time()) {
                            $value = isAke($row, 'value', $default);

                            return is_numeric($value) ? $value : unserialize($value);
                        } else {
                            self::hdel($hash, $k);
                        }
                    } else {
                        if ($expire > 0 && $expire < time()) {
                            self::hdel($hash, $k);
                        }
                    }
                }
            }

            return $default;
        }

        public static function hdel($hash, $key)
        {
            return self::hdelete($hash, $key);
        }

        public static function hdelete($hash, $key)
        {
            $newHash = [];

            $tab = self::get($hash, []);

            if (count($tab)) {
                foreach ($tab as $row) {
                    $k = isAke($row, 'key', false);

                    if ($k !== false && $k != $key) {
                        $newHash[] = $row;
                    }
                }

                self::del($hash);
                self::set($hash, $newHash);
            }
        }

        public static function hincrBy($hash, $key, $by)
        {
            return self::hincr($hash, $key, $by);
        }

        public static function hdecrBy($hash, $key, $by)
        {
            return self::hdecr($hash, $key, $by);
        }

        public static function hincr($hash, $key, $by = 1)
        {
            $val = self::hget($hash, $key);

            if (!strlen($val)) {
                $val = 1;
            } else {
                $val = (int) $val;
                $val += $by;
            }

            self::hset($hash, $key, $val);

            return $val;
        }

        public static function hdecr($hash, $key, $by = 1)
        {
            $val = self::hget($hash, $key);

            if (!strlen($val)) {
                $val = 0;
            } else {
                $val = (int) $val;
                $val -= $by;
                $val = 0 > $val ? 0 : $val;
            }

            self::hset($hash, $key, $val);

            return $val;
        }

        public static function hhas($hash, $key)
        {
            $check = self::hget($hash, $key, false);

            return $check !== false;
        }

        public static function hgetall($hash)
        {
            $collection = [];

            $tab = self::get($hash, []);

            if (count($tab)) {
                foreach ($tab as $row) {
                    $k = isAke($row, 'key', false);
                    $v = isAke($row, 'value', false);

                    if (false !== $k && false !== $v) {
                        $collection[$k] = $v;
                    }
                }
            }

            return $collection;
        }

        public static function hkeys($hash, $pattern = null)
        {
            $collection = [];

            $tab = self::get($hash, []);

            if (count($tab)) {
                foreach ($tab as $row) {
                    $k = isAke($row, 'key', false);

                    if (false !== $k) {
                        if ($pattern == '*' || empty($pattern)) {
                            $collection[] = $k;
                        } else {
                            if (fnmatch($pattern, $k)) {
                                $collection[] = $k;
                            }
                        }
                    }
                }
            }

            return $collection;
        }
    }
