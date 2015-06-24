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

    use Dbjson\Dbjson as Db;
    use Closure;
    use Thin\Exception;

    class View
    {
        private $db, $results;

        public function __construct(Db $database, $queries = [])
        {
            $this->db = $database;

            if (!empty($queries)) {
                foreach ($queries as $query) {
                    if (is_array($query)) {
                        if (count($query) >= 3) {
                            if (count($query) == 4) {
                                $operand = $query[3];
                            } else {
                                $operand = 'AND';
                            }

                            $this->db->where([$query[0], $query[1], $query[2]], $operand);
                        } else {
                            throw new Exception('Wrong view query.');
                        }
                    } else {
                        throw new Exception('Wrong view query.');
                    }
                }

                $this->results = $this->db->results;
            } else {
                throw new Exception('A view requires at least a query.');
            }
        }

        public function exec($object = false)
        {
            return $this->db->exec($object);
        }

        public function first($object = false)
        {
            return $this->db->first($object);
        }

        public function last($object = false)
        {
            return $this->db->last($object);
        }

        public function where($condition, $op = 'AND')
        {
            $this->db->where($condition, $op, $this->results);

            $this->results = $this->db->results;

            return $this;
        }

        public function in($ids, $field = null, $op = 'AND')
        {
            /* polymorphism */
            $ids = !is_array($ids)
            ? strstr($ids, ',')
                ? explode(',', str_replace(' ', '', $ids))
                : [$ids]
            : $ids;

            $field = is_null($field) ? 'id' : $field;

            $this->db->where([$field, 'IN', '(' . implode(',', $ids) . ')'], $op, $this->results);

            $this->results = $this->db->results;

            return $this;
        }

        public function notIn($ids, $field = null, $op = 'AND')
        {
            /* polymorphism */
            $ids = !is_array($ids)
            ? strstr($ids, ',')
                ? explode(',', str_replace(' ', '', $ids))
                : [$ids]
            : $ids;

            $field = is_null($field) ? 'id' : $field;

            $this->db->where([$field, 'NOT IN', '(' . implode(',', $ids) . ')'], $op, $this->results);

            $this->results = $this->db->results;

            return $this;
        }

        public function like($field, $str, $op = 'AND')
        {
            $this->db->where([$field, 'LIKE', $str], $op, $this->results);

            $this->results = $this->db->results;

            return $this;
        }

        public function likeStart($field, $str, $op = 'AND')
        {
            $this->db->where([$field, 'LIKE START', $str], $op, $this->results);

            $this->results = $this->db->results;

            return $this;
        }

        public function likeEnd($field, $str, $op = 'AND')
        {
            $this->db->where([$field, 'LIKE END', $str], $op, $this->results);

            $this->results = $this->db->results;

            return $this;
        }

        public function notLike($field, $str, $op = 'AND')
        {
            $this->db->where([$field, 'NOT LIKE', $str], $op, $this->results);

            $this->results = $this->db->results;

            return $this;
        }

        public function custom(Closure $condition, $op = 'AND')
        {
            $this->db->trick($condition, $op, $this->results);

            $this->results = $this->db->results;

            return $this;
        }

        public function trick(Closure $condition, $op = 'AND')
        {
            $this->db->trick($condition, $op, $this->results);

            $this->results = $this->db->results;

            return $this;
        }


        public function limit($limit, $offset = 0)
        {
            $offset         = count($this->results) < $offset ? count($this->results) : $offset;
            $this->results  = array_slice($this->results, $offset, $limit);

            $this->db->results = $this->results;

            return $this;
        }

        public function order($fieldOrder, $orderDirection = 'ASC')
        {
            $this->db->order($fieldOrder, $orderDirection, $this->results);

            $this->results = $this->db->results;

            return $this;
        }

        public function __call($method, $args)
        {
            array_push($args, $this->results);

            $returnMethods = ['sum', 'avg', 'min', 'max'];

            $res = call_user_func_array([$this->db, $method], $args);

            $this->results = $this->db->results;

            return in_array($method, $returnMethods) ? $res : $this;
        }
    }
