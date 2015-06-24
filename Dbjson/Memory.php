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

    use Dbjson\dbjson as Db;

    class Memory
    {
        private $args = [];
        private $db, $ttl;

        public function __construct(Db $database, $ttl = 0)
        {
            $this->db   = $database;
            $this->ttl  = $ttl;
        }

        public function exec($object = false, $return = true)
        {
            $key        = $this->db->db . '.' .$this->db->table . '.' . $this->db->getEnv() . '.' . sha1(serialize($this->args));
            $cache      = apcCache($this->ttl);

            $results    = $cache->get($key);

            if (!is_array($results)) {
                if (!empty($this->args)) {
                    foreach ($this->args as $arg) {
                        $this->db = call_user_func_array([$this->db, current($arg)], end($arg));
                    }

                    $cache->save($key, $this->db->results);
                }
            } else {
                $this->db->results = $results;
            }

            if (true === $return) {
                return $this->db->exec($object);
            }
        }

        public function first($object = false)
        {
            $this->exec($object);

            return $this->db->first($object);
        }

        public function last($object = false)
        {
            $this->exec($object);

            return $this->db->last($object);
        }

        public function count()
        {
            $this->exec(false, false);

            return $this->db->count();
        }

        public function sum($field)
        {
            $this->exec(false, false);

            return $this->db->sum($field);
        }


        public function avg($field)
        {
            $this->exec(false, false);

            return $this->db->avg($field);
        }

        public function min($field)
        {
            $this->exec(false, false);

            return $this->db->min($field);
        }

        public function max($field)
        {
            $this->exec(false, false);

            return $this->db->max($field);
        }

        public function __call($method, $args)
        {
            array_push($this->args, [$method, $args]);

            return $this;
        }
    }
