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

    use Thin\File;

    class Iterator implements \Iterator, \Countable
    {
        private $db, $cursor, $cursorRows, $position = 0, $count = 0, $ids = [], $orm;

        public function __construct(Db $db)
        {
            $this->position = 0;
            $this->db       = $db;
            $this->orm      = lib('mysql')->table('kvs_db', SITE_NAME);

            $this->prepare();
        }

        public function rewind()
        {
            $this->position = 0;
        }

        public function current()
        {
            return isset($this->cursorRows[$this->position]) ? unserialize($this->cursorRows[$this->position]->value) : null;
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
            $file = $this->cursor . DS . $this->position . '.php';

            return File::exists($file);
        }

        public function count()
        {
            return $this->count;
        }

        public function toArray()
        {
            $collection = [];

            foreach ($this->cursorRows as $data) {
                $data = unserialize($data->value);
                $collection[] = $data;
            }

            return $collection;
        }

        public function toJson()
        {
            $collection = [];

            foreach ($this->cursorRows as $data) {
                $data = unserialize($data->value);
                $collection[] = $data;
            }

            return json_encode($collection);
        }

        public function limit($limit, $offset = 0)
        {
            $hash = sha1($this->db->getHash() . 'limits' . serialize(func_get_args()));

            $pattern    = $this->db->motor()->makeName('cursors.' . $hash . '.');
            $cursor     = $this->orm->where('kvs_db_id', 'LIKE', $pattern . '%')->count();
            $cursorRows = $this->orm->where('kvs_db_id', 'LIKE', $pattern . '%')->get();

            if ($cursor > 0) {
                $ageCursor  = $this->db->motor()->read('cursors.age.' . $hash);
                $ageDb      = $this->db->getAge();

                if ($ageDb < $ageCursor) {
                    $this->cursor = $cursor;
                    $this->cursorRows = $cursorRows;

                    return $this;
                } else {
                    $this->db->motor()->remove('cursors.age.' . $hash);
                    $this->orm->where('kvs_db_id', 'LIKE', $pattern . '%')->delete();
                }
            }

            $index = 0;

            for ($i = $offset; $i < $limit; $i++) {
                $row = $this->cursorRows[$i];
                $row = unserialize($row->value);

                if ($row) {
                    $id     = isAke($row, 'id');
                    $data   = $this->db->motor()->read('datas.' . $id);
                    $this->db->motor()->write('cursors.' . $hash . '.' . $index, $data);
                    $index++;
                }
            }

            $this->cursor = $cursor;
            $this->cursorRows = $this->orm->where('kvs_db_id', 'LIKE', $pattern . '%')->get();
            $this->db->motor()->write('cursors.age.' . $hash, time());

            return $this;
        }

        public function sort($field, $direction = 'ASC')
        {
            $hash = sha1($this->db->getHash() . 'sort' . serialize(func_get_args()));

            $pattern    = $this->db->motor()->makeName('cursors.' . $hash . '.');
            $cursor     = $this->orm->where('kvs_db_id', 'LIKE', $pattern . '%')->count();
            $cursorRows = $this->orm->where('kvs_db_id', 'LIKE', $pattern . '%')->get();

            if ($cursor > 0) {
                $ageCursor  = $this->db->motor()->read('cursors.age.' . $hash);
                $ageDb      = $this->db->getAge();

                if ($ageDb < $ageCursor) {
                    $this->cursor = $cursor;
                    $this->cursorRows = $cursorRows;

                    return $this;
                } else {
                    $this->db->motor()->remove('cursors.age.' . $hash);
                    $this->orm->where('kvs_db_id', 'LIKE', $pattern . '%')->delete();
                }
            }

            $collection = [];

            foreach ($this->cursorRows as $data) {
                $data = unserialize($data->value);
                $id     = isAke($data, 'id');
                $val    = isAke($data, $field);

                $row    = ['id' => $id, $field => $val];

                $collection[] = $row;
            }

            $collection = lib('collection', [$collection]);

            if ($direction == 'ASC') {
                $collection->sortBy($field);
            } else {
                $collection->sortByDesc($field);
            }

            $index = 0;

            foreach ($collection as $row) {
                $id     = isAke($row, 'id');
                $data   = $this->db->motor()->read('datas.' . $id);
                $this->db->motor()->write('cursors.' . $hash . '.' . $index, $data);
                $index++;
            }

            $this->cursor = $cursor;
            $this->cursorRows = $this->orm->where('kvs_db_id', 'LIKE', $pattern . '%')->get();
            $this->db->motor()->write('cursors.age.' . $hash, time());

            return $this;
        }

        public function groupBy($field)
        {
            $hash = sha1($this->db->getHash() . 'groupby' . serialize(func_get_args()));

            $pattern    = $this->db->motor()->makeName('cursors.' . $hash . '.');
            $cursor     = $this->orm->where('kvs_db_id', 'LIKE', $pattern . '%')->count();
            $cursorRows = $this->orm->where('kvs_db_id', 'LIKE', $pattern . '%')->get();

            if ($cursor > 0) {
                $ageCursor  = $this->db->motor()->read('cursors.age.' . $hash);
                $ageDb      = $this->db->getAge();

                if ($ageDb < $ageCursor) {
                    $this->cursor = $cursor;

                    return $this;
                } else {
                    $this->db->motor()->remove('cursors.age.' . $hash);
                    $this->orm->where('kvs_db_id', 'LIKE', $pattern . '%')->delete();
                }
            }

            $collection = [];

            foreach ($this->cursorRows as $data) {
                $data   = unserialize($data->value);
                $id     = isAke($data, 'id');
                $val    = isAke($data, $field);

                $row    = ['id' => $id, $field => $val];

                $collection[] = $row;
            }

            $collection = lib('collection', [$collection]);

            $collection->groupBy($field);

            $index = 0;

            foreach ($collection as $row) {
                $id     = isAke($row, 'id');
                $data   = $this->db->motor()->read('datas.' . $id);
                $this->db->motor()->write('cursors.' . $hash . '.' . $index, $data);
                $index++;
            }

            $this->cursor = $cursor;
            $this->cursorRows = $this->orm->where('kvs_db_id', 'LIKE', $pattern . '%')->get();
            $this->db->motor()->write('cursors.age.' . $hash, time());

            return $this;
        }

        private function prepare()
        {
            $results        = $collection = [];
            $hash           = $this->db->getHash();

            $pattern        = $this->db->motor()->makeName('cursors.' . $hash . '.');
            $this->cursor   = $this->orm->where('kvs_db_id', 'LIKE', $pattern . '%')->count();
            $this->cursorRows   = $this->orm->where('kvs_db_id', 'LIKE', $pattern . '%')->get();

            if ($this->cursor > 0) {
                $ageCursor  = $this->db->motor()->read('cursors.age.' . $hash);
                $ageDb      = $this->db->getAge();

                if ($ageDb < $ageCursor) {
                    $this->count = $this->cursor;

                    return;
                } else {
                    $this->db->motor()->remove('cursors.age.' . $hash);
                    $cursorRows = $this->orm->where('kvs_db_id', 'LIKE', $pattern . '%')->delete();
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

            $this->count = count($results);

            if (empty($results)) {
                return true;
            } else {
                $index = 0;

                foreach ($results as $id => $row) {
                    if (false !== $id) {
                        $data = $this->db->motor()->read('datas.' . $id);
                        $this->db->motor()->write('cursors.' . $hash . '.' . $index, $data);
                        $index++;
                    }
                }

                $this->db->motor()->write('cursors.age.' . $hash, time());
            }
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
