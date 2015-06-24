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

    namespace Mysqldb;

    use Thin\Utils;
    use Thin\File;
    use Thin\Arrays;
    use Thin\Inflector;
    use Thin\Exception;
    use Closure;
    use ArrayObject;
    use ArrayAccess;
    use Countable;
    use IteratorAggregate;

    class Model extends ArrayObject implements ArrayAccess, Countable, IteratorAggregate
    {
        public $_db, $_data;
        private $_events = [];
        public $_hooks = [
            'beforeCreate'  => null,
            'beforeRead'    => null,
            'beforeUpdate'  => null,
            'beforeDelete'  => null,
            'afterCreate'   => null,
            'afterRead'     => null,
            'afterUpdate'   => null,
            'afterDelete'   => null,
            'validate'      => null
        ];

        public function __construct(Db $db, $data = [])
        {
            $this->_db  = $db;
            $data       = $this->treatCast($data);

            foreach ($data as $k => $v) {
                $this->$k = $v;
            }

            $id = isAke($data, $db->pk(), false);

            if (false !== $id) {
                $this->_related();
            }

            $this->_hooks();
        }

        private function treatCast($tab)
        {
            if (!empty($tab) && Arrays::isAssoc($tab)) {
                foreach ($tab as $k => $v) {
                    if (fnmatch('*_id', $k) && !empty($v)) {
                        if (is_numeric($v)) {
                            $tab[$k] = (int) $v;
                        }
                    }
                }
            }

            return $tab;
        }

        public function _related()
        {
            $fields = array_keys($this->_data);
            $obj = $this;

            foreach ($fields as $field) {
                if (fnmatch('*_id', $field)) {
                    if (is_string($field)) {
                        $value = $this->$field;

                        if (!is_callable($value)) {
                            $fk = str_replace('_id', '', $field);
                            $ns = $this->_db->db;

                            $cb = function($object = false) use ($value, $fk, $ns) {
                                $db = Db::instance($ns, $fk);

                                return $db->find($value, $object);
                            };

                            $this->_event($fk, $cb);

                            $setter = lcfirst(Inflector::camelize("link_$fk"));

                            $cb = function(Model $fkObject) use ($obj, $field, $fk) {
                                $obj->$field = $fkObject->id;

                                $newCb = function () use ($fkObject) {
                                    return $fkObject;
                                };

                                $obj->_event($fk, $newCb);

                                return $obj;
                            };

                            $this->_event($setter, $cb);
                        }
                    }
                }
            }

            return $this;
        }

        public function _event($name, Closure $cb)
        {
            $this->_events[$name] = $cb;

            return $this;
        }

        public function offsetSet($key, $value)
        {
            if (fnmatch('*_id', $key)) {
                if (is_numeric($value)) {
                    $value = (int) $value;
                }
            }

            $this->_data[$key] = $value;

            $autosave = isAke($this->_data, 'autosave', false);

            return !$autosave ? $this : $this->save();
        }

        public function offsetExists($key)
        {
            $check = Utils::token();

            return $check != isake($this->_data, $key, $check);
        }

        public function offsetUnset($key)
        {
            unset($this->_data[$key]);
        }

        public function offsetGet($key)
        {
            return isAke($this->_data, $key, null);
        }

        public function __set($key, $value)
        {
            $fields = $this->_db->fieldsSave();

            if (!in_array($key, $fields) && $key != $this->_db->pk()) {
                throw new Exception("The field $key does not exist in the model.");
            }

            if (fnmatch('*_id', $key)) {
                if (is_numeric($value)) {
                    $value = (int) $value;
                }
            }

            $this->_data[$key] = $value;

            $autosave = isAke($this->_data, 'autosave', false);

            return !$autosave ? $this : $this->save();
        }

        public function __get($key)
        {
            return isAke($this->_data, $key, null);
        }

        public function __isset($key)
        {
            $check = Utils::token();

            return $check != isake($this->_data, $key, $check);
        }

        public function __unset($key)
        {
            unset($this->_data[$key]);
        }

        public function __call($func, $args)
        {
            if (substr($func, 0, strlen('get')) == 'get') {
                $uncamelizeMethod   = Inflector::uncamelize(lcfirst(substr($func, strlen('get'))));
                $field              = Inflector::lower($uncamelizeMethod);

                $default = count($args) == 1 ? Arrays::first($args) : null;

                $res =  isAke($this->_data, $field, false);

                if (false !== $res) {
                    return $res;
                } else {
                    $resFk = isAke($this->_data, $field . '_id', false);

                    if (false !== $resFk) {
                        $db = Db::instance($this->_db->db, $field);
                        $object = count($args) == 1 ? $args[0] : false;

                        if (!is_bool($object)) {
                            $object = false;
                        }

                        return $db->find($resFk, $object);
                    } else {
                        if ($field[strlen($field) - 1] == 's' && isset($this->_data[$this->_db->pk()]) && $field[0] != '_') {
                            $db = Db::instance($this->_db->db, substr($field, 0, -1));
                            $object = count($args) == 1 ? $args[0] : false;

                            if (!is_bool($object)) {
                                $object = false;
                            }

                            $idField = $this->_db->table . '_id';

                            return $db->where([$idField, '=', $this->_data[$this->_db->pk()]])->exec($object);
                        } else {
                            return $default;
                        }
                    }
                }

            } elseif (substr($func, 0, strlen('has')) == 'has') {
                $uncamelizeMethod   = Inflector::uncamelize(lcfirst(substr($func, strlen('has'))));
                $field              = Inflector::lower($uncamelizeMethod);

                $res =  isAke($this->_data, $field, false);

                if (false !== $res) {
                    return true;
                } else {
                    $resFk = isAke($this->_data, $field . '_id', false);

                    if (false !== $resFk) {
                        return true;
                    } else {
                        if ($field[strlen($field) - 1] == 's' && isset($this->_data[$this->_db->pk()]) && $field[0] != '_') {
                            $db = Db::instance($this->_db->db, substr($field, 0, -1));

                            $idField = $this->_db->table . '_id';

                            $count = $db->where([$idField, '=', $this->_data[$this->_db->pk()]])->count();

                            return $count > 0 ? true : false;
                        }
                    }
                }

                return false;
            } elseif (substr($func, 0, strlen('set')) == 'set') {
                $fields             = $this->_db->fieldsSave();
                $uncamelizeMethod   = Inflector::uncamelize(lcfirst(substr($func, strlen('set'))));
                $field              = Inflector::lower($uncamelizeMethod);

                if (!in_array($field, $fields) && $field != $this->_db->pk()) {
                    throw new Exception("The field $field does not exist in the model.");
                }

                if (!empty($args)) {
                    $val = Arrays::first($args);
                } else {
                    $val = null;
                }

                if (is_object($val)) {
                    $val = $val->id;
                }

                if (fnmatch('*_id', $field)) {
                    if (is_numeric($val)) {
                        $val = (int) $val;
                    }
                }

                $this->_data[$field] = $val;

                $autosave = isAke($this->_data, 'autosave', false);

                return !$autosave ? $this : $this->save();
            } else {
                $cb = isake($this->_events, $func, false);

                if (false !== $cb) {
                    if ($cb instanceof Closure) {
                        return call_user_func_array($cb, $args);
                    }
                } else {
                    if ($func[strlen($func) - 1] == 's' && isset($this->_data[$this->_db->pk()]) && $func[0] != '_') {
                        $db = Db::instance($this->_db->db, substr($func, 0, -1));
                        $object = count($args) == 1 ? $args[0] : false;

                        if (!is_bool($object)) {
                            $object = false;
                        }

                        $idField = $this->_db->table . '_id';

                        return $db->where([$idField, '=', $this->_data[$this->_db->pk()]])->exec($object);
                    } else {
                        $auth = ['checkIndices', '_hooks'];

                        if (Arrays::in($func, $auth)) {
                            return true;
                        }

                        throw new Exception("$func is not a model function of $this->_db.");
                    }
                }
            }
        }

        public function save()
        {
            return $this->_db->save($this->_data);
        }

        public function delete()
        {
            $id = isake($this->_data, $this->_db->pk(), false);

            if (false !== $id) {
                return $this->_db->delete($id);
            }

            return false;
        }

        public function hydrate(array $data = [])
        {
            $data = empty($data) ? $_POST : $data;

            if (Arrays::isAssoc($data)) {
                foreach ($data as $k => $v) {
                    if ($k != $this->_db->pk()) {
                        if ('true' == $v) {
                            $v = true;
                        } elseif ('false' == $v) {
                            $v = false;
                        } elseif ('null' == $v) {
                            $v = null;
                        }

                        if (fnmatch('*_id', $k)) {
                            if (is_numeric($v)) {
                                $v = (int) $v;
                            }
                        }

                        $this->_data[$k] = $v;
                    }
                }
            }

            return $this;
        }

        public function id()
        {
            return isAke($this->_data, $this->_db->pk(), null);
        }

        public function exists()
        {
            return null !== isAke($this->_data, $this->_db->pk(), null);
        }

        public function duplicate()
        {
            unset($this->_data[$this->_db->pk()]);

            return $this->save();
        }

        public function assoc()
        {
            return $this->_data;
        }

        public function deleteSoft()
        {
            $this->_data['deleted_at'] = time();

            return $this->save();
        }

        public function db()
        {
            return $this->_db;
        }
    }
