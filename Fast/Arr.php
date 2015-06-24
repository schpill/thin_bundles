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

    namespace Fast;

    use Iterator;

    class Arr implements Iterator
    {
        private $position, $collection;
        private $ids = [];

        public function __construct($collection)
        {
            $this->collection   = $collection;
            $this->position     = 0;
        }

        public function set($row)
        {
            $id = isAke($row, 'id', false);

            if (false !== $id) {
                if (!in_array($id, $this->ids)) {
                    $this->ids[] = $id;
                }
            }

            return $this;
        }

        public function del($row)
        {
            $id = isAke($row, 'id', false);

            if (false !== $id) {
                $i = 0;

                foreach ($this->ids as $tmpId) {
                    if ($tmpId == $id) {
                        unset($this->ids[$i]);

                        return true;
                    }

                    $i++;
                }
            }

            return false;
        }

        function rewind()
        {
            return reset($this->ids);
        }

        function current()
        {
            $id = current($this->ids);

            $data = $this->motor()->hget($this->collection . '.datas', $id);

            if ($data) {
                return unserialize($data);
            }

            return null;
        }

        function key()
        {
            return key($this->ids);
        }

        function next()
        {
            return next($this->ids);
        }

        function valid()
        {
            return key($this->ids) !== null;
        }

        public function motor()
        {
            $has = Instance::has('fastDbMotor', sha1($this->collection));

            if (true === $has) {
                return Instance::get('fastDbMotor', sha1($this->collection));
            } else {
                $instance = new Client([
                    'host'      => 'localhost',
                    'port'      => 6379,
                    'database'  => 2
                ]);

                return Instance::make('fastDbMotor', sha1($this->collection), $instance);
            }
        }
    }
