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

    namespace Dblight;

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
        public $_db, $_initial;
        public $_data = [];
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
            if (is_object($data) && !is_array($data)) {
                $data = [];
            }

            $this->_db  = clone $db;
            $data       = $this->treatCast($data);

            $id = isAke($data, 'id', false);

            if (false !== $id) {
                $this->_data['id'] = (int) $id;

                unset($data['id']);
            }

            $this->_data = array_merge($this->_data, $data);

            if (false !== $id) {
                $this->_related();
            }

            $this->_hooks();

            $this->_initial = $this->assoc();
            $this->_db->reset();
        }

        private function treatCast($tab)
        {
            if (!is_object($tab) && is_array($tab)) {
                if (!empty($tab) && Arrays::isAssoc($tab)) {
                    foreach ($tab as $k => $v) {
                        if (fnmatch('*_id', $k) && !empty($v)) {
                            if (is_numeric($v)) {
                                $tab[$k] = (int) $v;
                            }
                        }
                    }
                } else {
                    $tab = [];
                }
            }

            return $tab;
        }

        public function _keys()
        {
            return array_keys($this->_data);
        }

        public function expurge($field)
        {
            unset($this->_data[$field]);

            return $this;
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

                            $cb = function($object = false) use ($value, $fk, $ns, $field, $obj) {
                                $db = Db::instance($ns, $fk);

                                if (is_bool($object)) {
                                    return $db->find($value, $object);
                                } elseif (is_object($object)) {
                                    $obj->$field = (int) $object->id;

                                    return $obj;
                                }
                            };

                            $this->_event($fk, $cb);
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
                } elseif (is_object($value)) {
                    $value = (int) $value->id;
                }
            }

            $this->_data[$key] = $value;

            $autosave = isAke($this->_data, 'autosave', false);

            return !$autosave ? $this : $this->save();
        }

        public function offsetExists($key)
        {
            $check = Utils::token();

            return $check != isAke($this->_data, $key, $check);
        }

        public function offsetUnset($key)
        {
            unset($this->_data[$key]);
        }

        public function offsetGet($key)
        {
            $value = isAke($this->_data, $key, false);

            if (false === $value) {
                if ($key[strlen($key) - 1] == 's' && isset($this->_data['id']) && $key[0] != '_') {
                    $db = Db::instance($this->_db->db, substr($key, 0, -1));

                    $idField = $this->_db->table . '_id';

                    return $db->where([$idField, '=', $this->_data['id']])->exec(true);
                } elseif (isset($this->_data[$key . '_id'])) {
                    $db = Db::instance($this->_db->db, $key);

                    return $db->find($this->_data[$key . '_id']);
                } else {
                    $value = null;
                }
            }

            return $value;
        }

        public function __set($key, $value)
        {
            if (fnmatch('*_id', $key)) {
                if (is_numeric($value)) {
                    $value = (int) $value;
                } elseif (is_object($value)) {
                    $value = (int) $value->id;
                }
            }

            $this->_data[$key] = $value;

            $autosave = isAke($this->_data, 'autosave', false);

            return !$autosave ? $this : $this->save();
        }

        public function __get($key)
        {
            $value = isAke($this->_data, $key, false);

            if (false === $value) {
                if ($key[strlen($key) - 1] == 's' && isset($this->_data['id']) && $key[0] != '_') {
                    $db         = Db::instance($this->_db->db, substr($key, 0, -1));
                    $hasPivot   = $this->hasPivot($db);

                    if (true === $hasPivot) {
                        $model  = $db->model();
                        $pivots = $this->pivots($model)->exec();

                        $ids = [];

                        if (!empty($pivots)) {
                            foreach ($pivots as $pivot) {
                                $id = isAke($pivot, substr($key, 0, -1) . '_id', false);

                                if (false !== $id) {
                                    array_push($ids, $id);
                                }
                            }

                            if (!empty($ids)) {
                                return $db->where(['id', 'IN', implode(',', $ids)])->exec(true);
                            } else {
                                return [];
                            }
                        }
                    } else {
                        $idField = $this->_db->table . '_id';

                        return $db->where([$idField, '=', $this->_data['id']])->exec(true);
                    }
                } elseif (isset($this->_data[$key . '_id'])) {
                    $db = Db::instance($this->_db->db, $key);

                    return $db->find($this->_data[$key . '_id']);
                } else {
                    $value = null;
                }
            }

            return $value;
        }

        public function __isset($key)
        {
            $check = sha1(__file__);

            return $check != isAke($this->_data, $key, $check);
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
                        if ($field[strlen($field) - 1] == 's' && isset($this->_data['id']) && $field[0] != '_') {
                            $db = Db::instance($this->_db->db, substr($field, 0, -1));
                            $object = count($args) == 1 ? $args[0] : false;

                            if (!is_bool($object)) {
                                $object = false;
                            }

                            $hasPivot   = $this->hasPivot($db);

                            if (true === $hasPivot) {
                                $model  = $db->model();
                                $pivots = $this->pivots($model)->exec();

                                $ids = [];

                                if (!empty($pivots)) {
                                    foreach ($pivots as $pivot) {
                                        $id = isAke($pivot, substr($field, 0, -1) . '_id', false);

                                        if (false !== $id) {
                                            array_push($ids, $id);
                                        }
                                    }

                                    if (!empty($ids)) {
                                        return $db->where(['id', 'IN', implode(',', $ids)])->exec($object);
                                    } else {
                                        return [];
                                    }
                                }
                            } else {
                                $idField = $this->_db->table . '_id';

                                return $db->where([$idField, '=', $this->_data['id']])->exec($object);
                            }
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
                        if ($field[strlen($field) - 1] == 's' && isset($this->_data['id']) && $field[0] != '_') {
                            $db = Db::instance($this->_db->db, substr($field, 0, -1));

                            $hasPivot = $this->hasPivot($db);

                            if (true === $hasPivot) {
                                $model  = $db->model();
                                $pivots = $this->pivots($model)->exec();

                                $ids = [];

                                if (!empty($pivots)) {
                                    foreach ($pivots as $pivot) {
                                        $id = isAke($pivot, substr($field, 0, -1) . '_id', false);

                                        if (false !== $id) {
                                            array_push($ids, $id);
                                        }
                                    }

                                    return !empty($ids) ? true : false;
                                }
                            } else {
                                $idField = $this->_db->table . '_id';

                                $count = $db->where([$idField, '=', $this->_data['id']])->count();

                                return $count > 0 ? true : false;
                            }
                        }
                    }
                }

                return false;
            } elseif (substr($func, 0, strlen('set')) == 'set') {
                $uncamelizeMethod   = Inflector::uncamelize(lcfirst(substr($func, strlen('set'))));
                $field              = Inflector::lower($uncamelizeMethod);

                if (!empty($args)) {
                    $val = Arrays::first($args);
                } else {
                    $val = null;
                }

                if (is_object($val)) {
                    $val = (int) $val->id;
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
                $cb = isAke($this->_events, $func, false);

                if (false !== $cb) {
                    if ($cb instanceof Closure) {
                        return call_user_func_array($cb, $args);
                    }
                } else {
                    if ($func[strlen($func) - 1] == 's' && isset($this->_data['id']) && $func[0] != '_') {
                        $db     = Db::instance($this->_db->db, substr($func, 0, -1));
                        $object = count($args) == 1 ? $args[0] : false;

                        if (!is_bool($object)) {
                            $object = false;
                        }

                        $hasPivot   = $this->hasPivot($db);

                        if (true === $hasPivot) {
                            $model  = $db->model();
                            $pivots = $this->pivots($model)->exec();

                            $ids = [];

                            if (!empty($pivots)) {
                                foreach ($pivots as $pivot) {
                                    $id = isAke($pivot, substr($func, 0, -1) . '_id', false);

                                    if (false !== $id) {
                                        array_push($ids, $id);
                                    }
                                }

                                if (!empty($ids)) {
                                    return $db->where(['id', 'IN', implode(',', $ids)])->exec($object);
                                } else {
                                    return [];
                                }
                            }
                        } else {
                            $idField = $this->_db->table . '_id';

                            return $db->where([$idField, '=', $this->_data['id']])->exec($object);
                        }
                    } else {
                        if (count($args)) {
                            $object = count($args) == 1 ? $args[0] : false;
                            $db = Db::instance($this->_db->db, $func);

                            $field = $func . '_id';

                            if (is_bool($object) && isset($this->_data[$field])) {
                                return $db->find($value, $object);
                            } elseif (is_object($object)) {
                                $this->$field = (int) $object->id;

                                return $this;
                            }
                        }

                        $auth = ['_hooks'];

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
            $valid  = true;
            $create = false;
            $id     = isAke($this->_data, 'id', false);

            if (false !== $id) {
                $continue = sha1(serialize($this->_data)) != sha1(serialize($this->initial()));

                if (false === $continue) {
                    return $this;
                }
            }

            $hook = isAke($this->_hooks, 'validate', false);

            if ($hook) {
                $valid = call_user_func_array($hook, [$this]);
            }

            if (true !== $valid) {
                throw new Exception("Thos model must be valid to be saved.");
            }

            if ($id) {
                $hook   = isAke($this->_hooks, 'beforeUpdate', false);
            } else {
                $create = true;
                $hook   = isAke($this->_hooks, 'beforeCreate', false);
            }

            if ($hook) {
                call_user_func_array($hook, [$this]);
            }

            $row = $this->_db->save($this->_data);

            if ($create) {
                $hook = isAke($this->_hooks, 'afterCreate', false);
            } else {
                $hook = isAke($this->_hooks, 'afterUpdate', false);
            }

            if ($hook) {
                call_user_func_array($hook, [$row]);
            }

            return $row;
        }

        public function insert()
        {
            $valid = true;

            $hook = isAke($this->_hooks, 'validate', false);

            if ($hook) {
                $valid = call_user_func_array($hook, [$this]);
            }

            if (true !== $valid) {
                throw new Exception("Thos model must be valid to be saved.");
            }

            $hook = isAke($this->_hooks, 'beforeCreate', false);

            if ($hook) {
                call_user_func_array($hook, [$this]);
            }

            $row = $this->_db->insert($this->_data);

            $hook = isAke($this->_hooks, 'afterCreate', false);

            if ($hook) {
                call_user_func_array($hook, [$row]);
            }

            return $row;
        }

        public function delete()
        {
            $id = isAke($this->_data, 'id', false);

            if (false !== $id) {
                $hook = isAke($this->_hooks, 'beforeDelete', false);

                if ($hook) {
                    call_user_func_array($hook, [$this]);
                }

                $res = $this->_db->delete((int) $id);

                $hook = isAke($this->_hooks, 'afterDelete', false);

                if ($hook) {
                    call_user_func_array($hook, [$this]);
                }

                return $res;
            }

            return false;
        }

        public function hydrate(array $data = [])
        {
            $data = empty($data) ? $_POST : $data;

            if (Arrays::isAssoc($data)) {
                foreach ($data as $k => $v) {
                    if ($k != 'id') {
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
            return isAke($this->_data, 'id', null);
        }

        public function exists()
        {
            return null !== isAke($this->_data, 'id', null);
        }

        public function duplicate()
        {
            $this->_data['copyrow'] = sha1(__file__ . time());
            unset($this->_data['id']);
            unset($this->_data['created_at']);
            unset($this->_data['updated_at']);
            unset($this->_data['deleted_at']);

            return $this->save();
        }

        public function assoc()
        {
            return $this->_data;
        }

        public function toJson()
        {
            return json_encode($this->_data);
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

        public function attach($model, $attributes = [])
        {
            $m = !is_array($model) ? $model : Arrays::first($model);

            if (!isset($this->_data['id']) || empty($m->id)) {
                throw new Exception("Attach method requires a valid model.");
            }

            $mTable = $m->db()->table;

            $names = [$this->_db->table, $mTable];
            asort($names);
            $pivot = Inflector::lower('pivot' . implode('', $names));

            $db = Db::instance($this->_db->db, $pivot);

            if (is_array($model)) {
                foreach ($model as $mod) {
                    $id = (int) $mod->id;

                    $row = $mod->db()->find($id);

                    if ($row) {
                        $fieldAttach    = $mTable . '_id';
                        $fieldModel     = $this->_db->table . '_id';

                        $attach = $db->firstOrCreate([
                            $fieldAttach    => $id,
                            $fieldModel     => $this->_data['id']
                        ]);

                        if (!empty($attributes)) {
                            foreach ($attributes as $k => $v) {
                                $setter = setter($k);
                                $attach->$setter($v);
                            }

                            $attach->save();
                        }
                    }
                }
            } else {
                $id = (int) $model->id;
                $row = $model->db()->find($id);

                if ($row) {
                    $fieldAttach    = $mTable . '_id';
                    $fieldModel     = $this->_db->table . '_id';

                    $attach = $db->firstOrCreate([
                        $fieldAttach    => $id,
                        $fieldModel     => $this->_data['id']
                    ]);

                    if (!empty($attributes)) {
                        foreach ($attributes as $k => $v) {
                            $setter = setter($k);
                            $attach->$setter($v);
                        }

                        $attach->save();
                    }
                }
            }

            return $this;
        }

        public function detach($model)
        {
            if (!isset($this->_data['id'])) {
                throw new Exception("detach method requires a valid model.");
            }

            $m = !is_array($model) ? $model : Arrays::first($model);

            if ($m instanceof Db) {
                $m = $m->model();
            }

            $all = false;

            if (empty($m->id)) {
                $all = true;
            }

            $mTable = $m->db()->table;

            $names = [$this->_db->table, $mTable];
            asort($names);
            $pivot = Inflector::lower('pivot' . implode('', $names));

            $db = Db::instance($this->_db->db, $pivot);

            if (is_array($model)) {
                foreach ($model as $mod) {
                    $id = (int) $mod->id;

                    $row = $mod->db()->find($id);

                    if ($row) {
                        $fieldAttach    = $mTable . '_id';
                        $fieldModel     = $this->_db->table . '_id';

                        $attach = $db->where([$fieldAttach , '=', (int) $id])
                        ->where([$fieldModel, '=', (int) $this->_data['id']])
                        ->first(true);

                        if ($attach) {
                            $attach->delete();
                        }
                    }
                }
            } else {
                if (false === $all) {
                    $id = (int) $model->id;
                    $row = $model->db()->find($id);

                    if ($row) {
                        $fieldAttach    = $mTable . '_id';
                        $fieldModel     = $this->_db->table . '_id';

                        $attach = $db->where([$fieldAttach , '=', (int) $id])
                        ->where([$fieldModel, '=', (int) $this->_data['id']])
                        ->first(true);

                        if ($attach) {
                            $attach->delete();
                        }
                    }
                } else {
                    $fieldModel = $this->_db->table . '_id';

                    $attachs = $db->where([$fieldModel, '=', (int) $this->_data['id']])
                    ->exec(true);

                    if (!empty($attachs)) {
                        foreach ($attachs as $attach) {
                            $attach->delete();
                        }
                    }
                }
            }

            return $this;
        }

        public function pivot($model)
        {
            if ($model instanceof Db) {
                $model = $model->model();
            }

            $mTable = $model->db()->table;

            $names = [$this->_db->table, $mTable];
            asort($names);
            $pivot = Inflector::lower('pivot' . implode('', $names));

            return Db::instance($this->_db->db, $pivot);
        }

        public function pivots($model)
        {
            if (!isset($this->_data['id'])) {
                throw new Exception("pivots method requires a valid model.");
            }

            $fieldModel = $this->_db->table . '_id';

            return $this->pivot($model)->where([$fieldModel, '=', (int) $this->_data['id']]);
        }

        public function hasPivot($model)
        {
            if (!isset($this->_data['id'])) {
                throw new Exception("hasPivot method requires a valid model.");
            }

            if ($model instanceof Db) {
                $model = $model->model();
            }

            $fieldModel = $this->_db->table . '_id';

            $count = $this->pivot($model)->where([$fieldModel, '=', (int) $this->_data['id']])->count();

            return $count > 0 ? true : false;
        }

        public function log()
        {
            $ns = isset($this->_data['id']) ? 'row_' . $this->_data['id'] : null;

            return $this->_db->log($ns);
        }

        public function actual()
        {
            return $this;
        }

        public function initial($model = false)
        {
            return $model ? new self($this->_initial) : $this->_initial;
        }

        public function observer()
        {
            return new Observer($this);
        }
    }
