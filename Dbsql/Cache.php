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

    class Cache
    {
        private $db, $collection, $orm;

        public function __construct(Db $db)
        {
            $this->db           = $db;
            $this->collection   = $db->collection;
            $this->orm          = lib('mysql')->table('kvs_db', SITE_NAME);
        }

        public function makeKey($key)
        {
            return 'cache.' . $this->collection . '.' . $key;
        }

        public function setExpire($key, $value, $expire)
        {
            return $this->set($key, $value, $expire);
        }

        public function set($key, $value, $expire = 0)
        {
            $key = $this->makeKey($key);

            $row = $this->orm->where('kvs_db_id', '=', $key)->first();

            if ($row) {
                $row->value = $value;
                $row->expire = 0;
                $row->save();
            } else {
                $this->orm->create([
                    'expire' => $expire > 0 ? (int) $expire + time() : 0,
                    'value' => $value,
                    'kvs_db_id' => $key
                ]);
            }

            return $this;
        }

        public function expire($key, $expire)
        {
            $key = $this->makeKey($key);

            $row = $this->orm->where('kvs_db_id', '=', $key)->first();

            if ($row) {
                $row->expire = (int) $expire + time();
                $row->save();

                return true;
            }

            return false;
        }

        public function incrBy($key, $by)
        {
            return $this->incr($key, $by);
        }

        public function incr($key, $by = 1)
        {
            $key = $this->makeKey($key);

            $row = $this->orm->where('kvs_db_id', '=', $key)->first();

            if ($row) {
                $old = $row->value;
                $new = $row->value = $old + $by;

                $row->save();

                return $new;
            } else {
                $this->orm->create([
                    'expire' => 0,
                    'value' => 1,
                    'kvs_db_id' => $key
                ]);

                return 1;
            }
        }

        public function decrBy($key, $by)
        {
            return $this->decr($key, $by);
        }

        public function decr($key, $by = 1)
        {
            $key = $this->makeKey($key);

            $row = $this->orm->where('kvs_db_id', '=', $key)->first();

            if ($row) {
                $old = $row->value;
                $new = $row->value = $old - $by;

                $row->save();

                return $new;
            } else {
                $this->orm->create([
                    'expire' => 0,
                    'value' => 0,
                    'kvs_db_id' => $key
                ]);

                return 0;
            }
        }

        public function get($key, $default = null)
        {
            $key = $this->makeKey($key);
            $row = $this->orm->where('kvs_db_id', '=', $key)->first();

            if ($row) {
                return $row->value;
            }

            return $default;
        }

        public function has($key)
        {
            $key = $this->makeKey($key);
            $row = $this->orm->where('kvs_db_id', '=', $key)->first();

            return $row ? true : false;
        }

        public function delete($key)
        {
            return $this->del($key);
        }

        public function del($key)
        {
            $key = $this->makeKey($key);

            $row = $this->orm->where('kvs_db_id', '=', $key)->first();

            if ($row) {
                $row->delete();

                return true;
            }

            return false;
        }
    }
