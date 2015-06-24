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

    use Thin\Container;
    use Thin\Inflector;
    use Thin\Utils;
    use Dbjson\Dbjson as Db;

    class Record
    {
        private $model;

        public function __construct(Db $model, array $tab = [])
        {
            $this->model = $model;

            $tab['created_at'] = isAke($tab, 'created_at', time());
            $tab['updated_at'] = isAke($tab, 'updated_at', time());

            return $this->row($tab);
        }

        public function row($tab = [])
        {
            $o = new Container;
            $o->populate($tab);

            return $this->closures($o);
        }

        private function closures($obj)
        {
            $db = $this->model;

            $db->results    = null;
            $db->wheres     = null;

            $save = function () use ($obj, $db) {
                return $db->save($obj);
            };

            $database = function () use ($db) {
                return $db;
            };

            $delete = function () use ($obj, $db) {
                return $db->deleteRow($obj->id);
            };

            $id = function () use ($obj) {
                return $obj->id;
            };

            $exists = function () use ($obj) {
                return isset($obj->id);
            };

            $touch = function () use ($obj) {
                if (!isset($obj->created_at))  $obj->created_at = time();
                $obj->updated_at = time();

                return $obj;
            };

            $duplicate = function () use ($obj, $db) {
                $obj->copyrow = Utils::token();

                $data = $obj->assoc();

                unset($data['id']);
                unset($data['created_at']);
                unset($data['updated_at']);

                $obj = $db->row($data);

                return $obj->save();
            };

            $hydrate = function ($data = []) use ($obj) {
                $data = empty($data) ? $_POST : $data;

                if (Arrays::isAssoc($data)) {
                    foreach ($data as $k => $v) {
                        if ('true' == $v) {
                            $v = true;
                        } elseif ('false' == $v) {
                            $v = false;
                        } elseif ('null' == $v) {
                            $v = null;
                        }

                        $obj->$k = $v;
                    }
                }

                return $obj;
            };

            $date = function ($f) use ($obj) {
                return date('Y-m-d H:i:s', $obj->$f);
            };

            $obj->event('save', $save)
            ->event('delete', $delete)
            ->event('date', $date)
            ->event('exists', $exists)
            ->event('id', $id)
            ->event('db', $database)
            ->event('touch', $touch)
            ->event('hydrate', $hydrate)
            ->event('duplicate', $duplicate);

            $settings   = isAke(Db::$config, "$this->model->db.$this->model->table");
            $functions  = isAke($settings, 'functions');

            if (count($functions)) {
                foreach ($functions as $closureName => $callable) {
                    $closureName    = lcfirst(Inflector::camelize($closureName));

                    $share          = function () use ($obj, $callable, $db) {
                        $args[]     = $obj;
                        $args[]     = $db;

                        return call_user_func_array($callable, $args);
                    };

                    $obj->event($closureName, $share);
                }
            }

            return $this->related($obj);
        }

        private function related(Container $obj)
        {
            $fields = array_keys($obj->assoc());

            foreach ($fields as $field) {
                if (endsWith($field, '_id')) {
                    if (is_string($field)) {
                        $value = $obj->$field;

                        if (!is_callable($value)) {
                            $fk = repl('_id', '', $field);
                            $ns = $this->model->db;

                            $cb = function() use ($value, $fk, $ns) {
                                $db = jdb($ns, $fk);

                                return $db->find($value);
                            };

                            $obj->event($fk, $cb);

                            $setter = lcfirst(Inflector::camelize("link_$fk"));

                            $cb = function(Container $fkObject) use ($obj, $field, $fk) {
                                $obj->$field = $fkObject->getId();

                                $newCb = function () use ($fkObject) {
                                    return $fkObject;
                                };

                                $obj->event($fk, $newCb);

                                return $obj;
                            };

                            $obj->event($setter, $cb);
                        }
                    }
                }
            }

            return $obj;
        }
    }
