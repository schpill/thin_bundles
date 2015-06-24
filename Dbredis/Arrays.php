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

    namespace Dbredis;

    class Arrays implements \Countable, \IteratorAggregate
    {
        private $cursor;

        public function __construct($cursor)
        {
            $this->cursor = $cursor;
        }

        public function getCursor()
        {
            return $this->cursor;
        }

        public function current()
        {
            $current = $this->cursor->current();

            return $current;
        }

        public function getNext()
        {
            $next = $this->getCursor()->getNext();

            unset($next['_id']);

            return $next;
        }

        public function getIterator()
        {
            return $this->cursor;
        }

        public function count()
        {
            return $this->cursor->count();
        }

        public function toArray()
        {
            return iterator_to_array($this->cursor);
        }

        public function __call($method, $arguments)
        {
            vd($method);
            $function   = [$this->cursor, $method];
            $result     = call_user_func_array($function, $arguments);

            if ($result instanceof \MongoCursor) {
                return $this;
            }

            return $result;
        }
    }
