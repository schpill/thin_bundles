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

    namespace Clue;

    class Cursor implements \Iterator, \Countable
    {
        private $db;
        private $cursor;
        private $position = 0;
        private $ids = [];

        public function __construct(Db $db)
        {
            $this->position = 0;
            $this->db = $db;

            $this->find();
        }

        public function rewind()
        {
            $this->position = 0;
        }

        public function current()
        {
            $tab = include($this->cursor);

            return isset($tab[$this->position]) ? $tab[$this->position] : null;
        }

        public function key()
        {
            return $this->position;
        }

        public function next()
        {
            ++$this->position;
        }

        public function previous()
        {
            --$this->position;
        }

        public function valid()
        {
            $tab = include($this->cursor);

            return isset($tab[$this->position]);
        }

        public function count()
        {
            $tab = include($this->cursor);

            return count($tab);
        }

        public function toArray()
        {
            $tab = include($this->cursor);

            return $tab;
        }

        public function toJson()
        {
            $tab = include($this->cursor);

            return json_encode($tab);
        }

        public function limit($limit, $offset = 0)
        {
            $hash = sha1($this->db->getHash() . 'limit' . serialize(func_get_args()));

            $cursor = $this->db->motor()->getFile('cursors.' . $hash);

            if (is_file($cursor)) {
                $ageCursor  = filemtime($cursor);
                $ageDb      = $this->db->getAge();

                if ($ageDb < $ageCursor) {
                    $this->cursor = $cursor;

                    return $this;
                }
            }

            $tab = include($this->cursor);
            $collection = array_slice($tab, $offset, $limit, false);

            $this->cursor = $cursor;

            $this->db->motor()->write('cursors.' . $hash, $collection);

            return $this;
        }

        public function sort($field, $direction = 'ASC')
        {
            $hash = sha1($this->db->getHash() . 'sort' . serialize(func_get_args()));

            $cursor = $this->db->motor()->getFile('cursors.' . $hash);

            if (is_file($cursor)) {
                $ageCursor  = filemtime($cursor);
                $ageDb      = $this->db->getAge();

                if ($ageDb < $ageCursor) {
                    $this->cursor = $cursor;

                    return $this;
                }
            }

            $tab = include($this->cursor);
            $collection = lib('collection', [$tab]);

            if ($direction == 'ASC') {
                $collection->sortBy($field);
            } else {
                $collection->sortByDesc($field);
            }

            $this->cursor = $cursor;

            $this->db->motor()->write('cursors.' . $hash, array_values($collection->toArray()));

            return $this;
        }

        public function groupBy($field)
        {
            $hash = sha1($this->db->getHash() . 'groupby' . serialize(func_get_args()));

            $cursor = $this->db->motor()->getFile('cursors.' . $hash);

            if (is_file($cursor)) {
                $ageCursor  = filemtime($cursor);
                $ageDb      = $this->db->getAge();

                if ($ageDb < $ageCursor) {
                    $this->cursor = $cursor;

                    return $this;
                }
            }

            $tab = include($this->cursor);
            $collection = lib('collection', [$tab]);

            $collection->groupBy($field);

            $this->cursor = $cursor;

            $this->db->motor()->write('cursors.' . $hash, array_values($collection->toArray()));

            return $this;
        }

        public function find()
        {
            $results    = $collection = [];
            $hash       = $this->db->getHash();

            $this->cursor = $this->db->motor()->getFile('cursors.' . $hash);

            if (is_file($this->cursor)) {
                $ageCursor  = filemtime($this->cursor);
                $ageDb      = $this->db->getAge();

                if ($ageDb < $ageCursor) {
                    return;
                }
            }

            if (empty($this->db->wheres)) {
                $ids = $this->db->motor()->ids('datas');

                foreach ($ids as $id) {
                    $results[$id] = [];
                }

                unset($ids);
            } else {
                $results = $this->db->results;
            }

            foreach ($results as $id => $row) {
                if (false !== $id) {
                    $data = $this->db->motor()->read('datas.' . $id);
                    $collection[] = $data;
                }
            }

            $this->db->motor()->write('cursors.' . $hash, $collection);
        }

        public function fetch($object = false)
        {
            $row = $this->current();

            $this->next();

            $id = isAke($row, 'id', false);

            if (!$id) {
                return false;
            }

            return $object ? $this->db->model($row) : $row;
        }

        public function model()
        {
            $row = $this->current();

            $this->next();

            $id = isAke($row, 'id', false);

            return false !== $id ? $this->db->model($row) : false;
        }

        public function first($object = false)
        {
            $row = $this->current();

            $this->rewind();

            $id = isAke($row, 'id', false);

            if (!$id) {
                return null;
            }

            return $object ? $this->db->model($row) : $row;
        }
    }
