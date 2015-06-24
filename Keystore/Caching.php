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

    namespace Keystore;

    class Caching
    {
        private $db, $ns;

        public function __construct($database = null)
        {
            $this->ns = is_null($database) ? SITE_NAME : $database;

            $this->db = lib('mysql', 'kvcaching');

            $this->clean();
        }

        private function clean()
        {
            $this->db
            ->where('expire', '>', 0)
            ->where('expire', '<', time())
            ->where('caching_ns', '=', $this->ns)
            ->delete();
        }

        public function get($key, $default = null)
        {
            $this->clean();

            $row = $this->db
            ->where('caching_key', '=', $key)
            ->where('caching_ns', '=', $this->ns)
            ->first();

            return $row ? unserialize($row->caching_value) : $default;
        }

        public function set($key, $value, $ttl = 0)
        {
            $ttl = 0 < $ttl ? $ttl + time() : $ttl;

            $row = $this->db->firstOrCreate([
                'caching_key'   => $key,
                'caching_ns'    => $this->ns
            ]);

            $row->expire = (int) $ttl;
            $row->caching_value = serialize($value);
            $row->save();

            return true;
        }

        public function expireat($key, $timestamp)
        {
            $row = $this->db
            ->where('caching_key', '=', $key)
            ->where('caching_ns', '=', $this->ns)
            ->first();

            if ($row) {
                if (time() > $timestamp) {
                    $row->delete();

                    return false;
                } else {
                    $row->expire = (int) $timestamp;
                    $row->save();

                    return true;
                }
            }

            return false;
        }

        public function expire($key, $ttl = 0)
        {
            $ttl = 0 < $ttl ? $ttl + time() : $ttl;
            $row = $this->db
            ->where('caching_key', '=', $key)
            ->where('caching_ns', '=', $this->ns)
            ->first();

            if ($row) {
                $row->expire = (int) $ttl;
                $row->save();

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
            $this->clean();

            $row = $this->db
            ->where('caching_key', '=', $key)
            ->where('caching_ns', '=', $this->ns)
            ->first();

            return $row ? $row->delete() : false;
        }

        public function has($key)
        {
            return $this->exists($key);
        }

        public function exists($key)
        {
            $this->clean();

            $count = $this->db
            ->where('caching_key', '=', $key)
            ->where('caching_ns', '=', $this->ns)
            ->count();

            return $count > 0 ? true : false;
        }

        public function ttl($key)
        {
            $this->clean();

            $row = $this->db
            ->where('caching_key', '=', $key)
            ->where('caching_ns', '=', $this->ns)
            ->first();

            return $row ? (int) ($row->expire - time()) : 0;
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

            $rows = $this->db
            ->where('caching_key', 'LIKE', str_replace('*', '%', $pattern))
            ->where('caching_ns', '=', $this->ns)
            ->get();

            foreach ($rows as $row) {
                $collection[] = $row['key'];
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
    }
