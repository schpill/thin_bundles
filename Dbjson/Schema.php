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

    use \Dbjson\Dbjson as Database;
    use \Thin\Arrays;
    use \Thin\Inflector;
    use \Thin\File;
    use \Thin\Utils;
    use Closure;

    class Schema
    {
        private $model;
        private $field;
        private $singular;
        private $softDelete;
        private $plural;
        private $order;
        private $replace;
        private $fields     = [];
        private $indices    = [];
        private $hooks      = [];

        public function __construct(Database $model)
        {
            $this->model = $model;
            $this->replace = false;
            $this->softDelete = false;
        }

        public function __destruct()
        {
            $file = APPLICATION_PATH . DS . 'models' . DS . 'CrudJson' . DS . ucfirst(Inflector::camelize($this->model->db)) . DS . ucfirst(Inflector::camelize($this->model->table)) . '.php';

            if (!is_dir(APPLICATION_PATH . DS . 'models' . DS . 'CrudJson' . DS . ucfirst(Inflector::camelize($this->model->db)))) {
                File::mkdir(APPLICATION_PATH . DS . 'models' . DS . 'CrudJson' . DS . ucfirst(Inflector::camelize($this->model->db)));
            }

            if (!File::exists($file) || $this->replace) {
                File::delete($file);

                $tplModel       = File::read(__DIR__ . DS . 'Model.tpl');
                $tplField       = File::read(__DIR__ . DS . 'Field.tpl');

                $uniques    = isAke($this->indices, 'unique', []);
                $foreigns   = isAke($this->indices, 'foreign', []);

                $softDelete = true === $this->softDelete ? 'true' : 'false';

                $uString = '[';

                if (count($uniques)) {
                    foreach ($uniques as $unique) {
                        $uString .= "'" . $unique . "'" . ',';
                    }

                    $uString = substr($uString, 0, -1);
                }

                $uString .= ']';

                $fString = '[';

                if (count($foreigns)) {
                    foreach ($foreigns as $foreign) {
                        $fString .= "'" . $foreign . "'" . ',';
                    }

                    $fString = substr($fString, 0, -1);
                }

                $fString .= ']';

                $before_create  = $this->getHook('before_create');
                $after_create   = $this->getHook('after_create');

                $before_update  = $this->getHook('before_update');
                $after_update   = $this->getHook('after_update');

                $before_read    = $this->getHook('before_read');
                $after_read     = $this->getHook('after_read');

                $before_delete  = $this->getHook('before_delete');
                $after_delete   = $this->getHook('after_delete');

                $before_list    = $this->getHook('before_list');
                $after_list     = $this->getHook('after_list');


                $tplModel       = str_replace(
                    [
                        '##singular##',
                        '##plural##',
                        '##default_order##',
                        '##soft_delete##',

                        '##foreigns##',
                        '##uniques##',

                        '##before_create##',
                        '##after_create##',

                        '##before_update##',
                        '##after_update##',

                        '##before_read##',
                        '##after_read##',

                        '##before_delete##',
                        '##after_delete##',

                        '##before_list##',
                        '##after_list##'
                    ],
                    [
                        $this->singular,
                        $this->plural,
                        $this->order,
                        $softDelete,

                        $fString,
                        $uString,

                        $before_create,
                        $after_create,

                        $before_update,
                        $after_update,

                        $before_read,
                        $after_read,

                        $before_delete,
                        $after_delete,

                        $before_list,
                        $after_list
                    ],
                    $tplModel
                );

                $fieldsSection = '';

                foreach ($this->fields as $field => $infos) {
                    if ($field != 'id') {
                        $label          = $this->getSetting($field, 'label', ucfirst($field));
                        $form_type      = $this->getSetting($field, 'form_type', 'text');
                        $helper         = $this->getSetting($field, 'helper', 'false');
                        $required       = $this->getSetting($field, 'required', 'true');
                        $form_plus      = $this->getSetting($field, 'form_plus', 'false');
                        $length         = $this->getSetting($field, 'length', 'false');

                        $is_listable    = $this->getSetting($field, 'is_listable', 'true');
                        $is_exportable  = $this->getSetting($field, 'is_exportable', 'true');
                        $is_searchable  = $this->getSetting($field, 'is_searchable', 'true');
                        $is_sortable    = $this->getSetting($field, 'is_sortable', 'true');
                        $is_readable    = $this->getSetting($field, 'is_readable', 'true');
                        $is_creatable   = $this->getSetting($field, 'is_creatable', 'true');
                        $is_updatable   = $this->getSetting($field, 'is_updatable', 'true');
                        $is_deletable   = $this->getSetting($field, 'is_deletable', 'true');

                        $content_view   = $this->getSetting($field, 'content_view', 'false');
                        $content_list   = $this->getSetting($field, 'content_list', 'false');
                        $content_search = $this->getSetting($field, 'content_search', 'false');
                        $content_create = $this->getSetting($field, 'content_create', 'false');

                        $fieldsSection .= str_replace(
                            [
                                '##field##',
                                '##form_type##',
                                '##helper##',
                                '##required##',
                                '##form_plus##',
                                '##length##',

                                '##is_listable##',
                                '##is_exportable##',
                                '##is_searchable##',
                                '##is_sortable##',
                                '##is_readable##',
                                '##is_creatable##',
                                '##is_updatable##',
                                '##is_deletable##',

                                '##content_view##',
                                '##content_list##',
                                '##content_search##',
                                '##content_create##',
                                '##label##'
                            ],
                            [
                                $field,
                                $form_type,
                                $helper,
                                $required,
                                $form_plus,
                                $length,

                                $is_listable,
                                $is_exportable,
                                $is_searchable,
                                $is_sortable,
                                $is_readable,
                                $is_creatable,
                                $is_updatable,
                                $is_deletable,

                                $content_view,
                                $content_list,
                                $content_search,
                                $content_create,

                                $label
                            ],
                            $tplField
                        );
                    }
                }

                $tplModel = str_replace(
                    '##fields##',
                    $fieldsSection,
                    $tplModel
                );

                File::put($file, $tplModel);
            }
        }

        public function replace($db, $table, Closure $next = null)
        {
            $this->replace = true;

            return $this->create($db, $table, $next);
        }

        public function create($db, $table, Closure $next = null)
        {
            $this->model = Database::instance($db, $table);

            $this->singular = ucfirst($table);
            $this->plural   = substr($this->singular, -1) != 's' ? $this->singular . 's' : $this->singular;

            $this->order = 'id';

            if (!is_null($next)) {
                $next($this);
            }
        }

        public function singular($str)
        {
            $this->singular = $str;

            return $this;
        }

        public function plural($str)
        {
            $this->plural = $str;

            return $this;
        }

        public function order($str)
        {
            $this->order = $str;

            return $this;
        }

        public function softDelete($bool = false)
        {
            $this->softDelete = $bool;

            return $this;
        }

        public function string($field)
        {
            $this->addColumn($field, 'form_type', 'text');

            return $this;
        }

        public function email($field)
        {
            $this->addColumn($field, 'form_type', 'email');

            return $this;
        }

        public function password($field)
        {
            $this->addColumn($field, 'form_type', 'password');

            return $this;
        }

        public function hidden($field)
        {
            $this->addColumn($field, 'form_type', 'hidden');

            return $this;
        }

        public function text($field)
        {
            $this->addColumn($field, 'form_type', 'textarea');

            return $this;
        }

        public function date($field)
        {
            $this->addColumn($field, 'form_type', 'date');

            return $this;
        }

        public function html($field)
        {
            $this->addColumn($field, 'form_type', 'html');

            return $this;
        }

        public function image($field)
        {
            $this->addColumn($field, 'form_type', 'image');

            return $this;
        }

        public function file($field)
        {
            $this->addColumn($field, 'form_type', 'file');

            return $this;
        }

        public function video($field)
        {
            $this->addColumn($field, 'form_type', 'file');

            return $this;
        }

        public function sound($field)
        {
            $this->addColumn($field, 'form_type', 'file');

            return $this;
        }

        public function color($field)
        {
            $this->addColumn($field, 'form_type', 'color');

            return $this;
        }

        public function url($field)
        {
            $this->addColumn($field, 'form_type', 'url');

            return $this;
        }

        public function helper($helper)
        {
            $this->addColumn($this->field, 'helper', $helper);

            return $this;
        }

        public function label($label)
        {
            $this->addColumn($this->field, 'label', $label);

            return $this;
        }

        public function required($required)
        {
            $this->addColumn($this->field, 'required', $required);

            return $this;
        }

        public function length($length)
        {
            $this->addColumn($this->field, 'length', $length);

            return $this;
        }

        public function listable($listable = true)
        {
            $this->addColumn($this->field, 'is_listable', $listable);

            return $this;
        }

        public function searchable($searchable = true)
        {
            $this->addColumn($this->field, 'is_searchable', $searchable);

            return $this;
        }

        public function sortable($sortable = true)
        {
            $this->addColumn($this->field, 'is_sortable', $sortable);

            return $this;
        }

        public function readable($readable = true)
        {
            $this->addColumn($this->field, 'is_readable', $readable);

            return $this;
        }

        public function creatable($creatable = true)
        {
            $this->addColumn($this->field, 'is_creatable', $creatable);

            return $this;
        }

        public function updatable($updatable = true)
        {
            $this->addColumn($this->field, 'is_updatable', $updatable);

            return $this;
        }

        public function exportable($exportable = true)
        {
            $this->addColumn($this->field, 'is_exportable', $exportable);

            return $this;
        }

        public function deletable($deletable = true)
        {
            $this->addColumn($this->field, 'is_deletable', $deletable);

            return $this;
        }

        public function viewing(Closure $view)
        {
            $this->addColumn($this->field, 'content_view', $view);

            return $this;
        }

        public function listing(Closure $list)
        {
            $this->addColumn($this->field, 'content_list', $list);

            return $this;
        }

        public function searching(Closure $search)
        {
            $this->addColumn($this->field, 'content_search', $search);

            return $this;
        }

        public function creating(Closure $create)
        {
            $this->addColumn($this->field, 'content_create', $create);

            return $this;
        }

        public function unique()
        {
            $this->addIndex($this->field, 'unique');

            return $this;
        }

        public function foreign()
        {
            $this->addIndex($this->field, 'foreign');

            return $this;
        }

        public function beforeCreate(Closure $closure)
        {
            $this->addHook('before_create', $closure);

            return $this;
        }

        public function afterCreate(Closure $closure)
        {
            $this->addHook('after_create', $closure);

            return $this;
        }

        public function beforeRead(Closure $closure)
        {
            $this->addHook('before_read', $closure);

            return $this;
        }

        public function afterRead(Closure $closure)
        {
            $this->addHook('after_read', $closure);

            return $this;
        }

        public function beforeUpdate(Closure $closure)
        {
            $this->addHook('before_update', $closure);

            return $this;
        }

        public function afterUpdate(Closure $closure)
        {
            $this->addHook('after_update', $closure);

            return $this;
        }

        public function beforeDelete(Closure $closure)
        {
            $this->addHook('before_delete', $closure);

            return $this;
        }

        public function afterDelete(Closure $closure)
        {
            $this->addHook('after_delete', $closure);

            return $this;
        }

        public function beforeList(Closure $closure)
        {
            $this->addHook('before_list', $closure);

            return $this;
        }

        public function afterList(Closure $closure)
        {
            $this->addHook('after_list', $closure);

            return $this;
        }

        private function addHook($key, $val)
        {
            if ($val instanceof Closure) {
                $code = $this->closureParse($val);
                $val = $code;
            }

            $this->hooks[$key] = $val;
        }

        private function addIndex($field, $key)
        {
            $tab = isAke($this->indices, $key, []);

            $this->indices[$key] = $tab;

            if (!Arrays::in($field, $this->indices[$key])) {
                $this->indices[$key][] = $field;
            }
        }

        private function addColumn($field, $key, $val)
        {
            $this->field = $field;

            $tab = isAke($this->fields, $field, []);

            $this->fields[$field] = $tab;

            $infos = isAke($tab, 'settings', []);

            $this->fields[$field]['settings'] = $infos;

            if ($val instanceof Closure) {
                $code = $this->closureParse($val);
                $val = $code;
            }

            $this->fields[$field]['settings'][$key] = $val;
        }

        private function getHook($hook)
        {
            $hook = isAke($this->hooks, $hook, false);

            if (false === $hook) {
                $hook = 'false';
            }

            return $hook;
        }

        private function getSetting($field, $key, $default)
        {
            $fieldTab = isAke($this->fields, $field, []);
            $fieldSettings = isAke($fieldTab, 'settings', []);

            return isAke($fieldSettings, $key, $default);
        }

        private function closureParse($closure)
        {
            $ref    = new \ReflectionFunction($closure);
            $file   = $ref->getFileName();
            $start  = $ref->getStartLine();
            $end    = $ref->getEndLine();

            $code   = File::readLines($file, $start, $end);

            $infos = Utils::cut('function(', '});', $code);

            $code = 'function(' . str_replace(["\n", "\r", "\t"], '', $infos) . '}';

            return $code;
        }
    }
