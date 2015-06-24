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

    namespace Dbarray;

    use Thin\Exception;
    use Thin\File;
    use Thin\Config;
    use Thin\Container;
    use Thin\Inflector;
    use \Dbarray\Dbarray as Database;
    use Thin\Database\Collection;
    use Thin\Arrays;
    use Thin\Paginator;

    class Tools
    {
        public static function row(array $row, $table, $fields, $link = true)
        {
            /* polymorphism */
            $fields = !Arrays::is($fields)
            ? strstr($fields, ',')
                ? explode(',', repl(' ', '', $fields))
                : array($fields)
            : $fields;

            $val = isAke($row, $table . '_id', 1);

            $db = amodel($table);
            $target = $db->find($val, false);
            $value = array();

            foreach ($fields as $field) {
                $value[] = isAke($target, $field, $field);
            }

            $value = implode(' ', $value);

            if (true === $link) {
                return '<a target="_blank" href="' . urlAction('update') . '/table/' . $table . '/id/' . $val . '">' . $value . '</a>';
            } else {
                return $value;
            }
        }

        public static function rows($idField, $table, $fields, $order = null, $data = null, $sort = 'ASC')
        {
            /* polymorphism */
            $fields = !Arrays::is($fields)
            ? strstr($fields, ',')
                ? explode(',', repl(' ', '', $fields))
                : array($fields)
            : $fields;

            $db = amodel($table);

            $html = '<select id="' . $idField . '">' . NL;
            $html .= '<option value="">Choisir</option>' . NL;

            $order = is_null($order) ? $db->pk() : $order;

            $data = is_null($data) ? $db->fetch()->order($order, $sort)->exec() : $data();

            if (count($data)) {
                foreach ($data as $target) {
                    $value = array();
                    $id = $target[$db->pk()];

                    foreach ($fields as $field) {
                        $value[] = isAke($target, $field, $field);
                    }

                    $value = implode(' ', $value);
                    $html .= '<option value="' . $id . '">' . \Thin\Html\Helper::display($value) . '</option>' . NL;
                }
            }

            $html .= '</select>';

            return $html;
        }

        public static function rowsForm($idField, $table, $fields, $order = null, $valueField = null, $required = true, $default = null, $data = null, $sort = 'ASC')
        {
            /* polymorphism */
            $fields = !Arrays::is($fields)
            ? strstr($fields, ',')
                ? explode(',', repl(' ', '', $fields))
                : array($fields)
            : $fields;

            $default = null !== request()->$idField ? request()->$idField : $default;

            $defined = !is_null($default) && null !== request()->$idField;

            $db = amodel($table);

            $require = $required ? 'required ' : '';

            $html = '<select ' . $require . 'class="form-control" name="' . $idField . '" id="' . $idField . '">' . NL;

            if (false === $defined) {
                $html .= '<option value="">Choisir</option>' . NL;
            }

            $order = is_null($order) ? $db->pk() : $order;

            $data = is_null($data) ? $db->fetch()->order($order, $sort)->exec() : $data();

            if (count($data)) {

                foreach ($data as $target) {
                    $value = array();
                    $id = $target[$db->pk()];

                    foreach ($fields as $field) {
                        $value[] = isAke($target, $field, $field);
                    }

                    $value = implode(' ', $value);

                    if (strstr($_SERVER['REQUEST_URI'], '/create/table/')) {
                        $valueField = empty($valueField) ? $default : $valueField;
                    }

                    $selected = ($valueField == $id) ? 'selected ' : '';

                    if (false === $defined) {
                        $html .= '<option ' . $selected . 'value="' . $id . '">' . \Thin\Html\Helper::display($value) . '</option>' . NL;
                    } else {
                        if (!strlen($selected)) {
                            $html .= '<option disabled value="' . $id . '">' . \Thin\Html\Helper::display($value) . '</option>' . NL;
                        } else {
                            $html .= '<option selected value="' . $id . '">' . \Thin\Html\Helper::display($value) . '</option>' . NL;
                        }
                    }
                }
            }

            $html .= '</select>';

            return $html;
        }

        public static function vocabulary($id, $vocables)
        {
            /* polymorphism */
            $vocables = !Arrays::is($vocables)
            ? strstr($vocables, ',')
                ? explode(',', repl(' ', '', $vocables))
                : array($vocables)
            : $vocables;

            /* start to index 1 */
            $search = array();
            $i = 1;

            foreach ($vocables as $vocable) {
                $search[$i] = $vocable;
                $i++;
            }

            return isAke($search, $id, ' ');
        }

        public static function vocabularies($idField, $data)
        {
            /* polymorphism */
            $data = !Arrays::is($data)
            ? strstr($data, ',')
                ? explode(',', repl(' ', '', $data))
                : array($data)
            : $data;

            $html = '<select id="' . $idField . '">' . NL;
            $html .= '<option value="">Choisir</option>' . NL;

            /* start to index 1 */
            $vocables = array();
            $i = 1;

            foreach ($data as $vocable) {
                $vocables[$i] = $vocable;
                $i++;
            }

            if (count($vocables)) {
                foreach ($vocables as $id => $vocable) {
                    if (1 > $id) continue;

                    $value = isAke($vocables, $id, ' ');
                    $html .= '<option value="' . $id . '">' . \Thin\Html\Helper::display($value) . '</option>' . NL;
                }
            }
            $html .= '</select>';
            return $html;
        }

        public static function vocabulariesForm($idField, $data, $valueField = null, $required = true)
        {
            /* polymorphism */
            $data = !Arrays::is($data)
            ? strstr($data, ',')
                ? explode(',', repl(' ', '', $data))
                : array($data)
            : $data;

            $require = $required ? 'required ' : '';

            $html = '<select ' . $require . 'class="form-control" name="' . $idField . '" id="' . $idField . '">' . NL;
            $html .= '<option value="">Choisir</option>' . NL;

            /* start to index 1 */
            $vocables = array();
            $i = 1;

            foreach ($data as $vocable) {
                $vocables[$i] = $vocable;
                $i++;
            }

            if (count($vocables)) {
                foreach ($vocables as $id => $vocable) {
                    if (1 > $id) continue;
                    $selected = ($valueField == $id) ? 'selected ' : '';

                    $value = isAke($vocables, $id, ' ');
                    $html .= '<option ' . $selected . 'value="' . $id . '">' . \Thin\Html\Helper::display($value) . '</option>' . NL;
                }
            }
            $html .= '</select>';
            return $html;
        }

        public static function generate($model, $overwrite = false)
        {
            if (!is_dir(APPLICATION_PATH . DS . 'models' . DS . 'CrudArray')) {
                File::mkdir(APPLICATION_PATH . DS . 'models' . DS . 'CrudArray', 0777);
            }

            $file = APPLICATION_PATH . DS . 'models' . DS . 'CrudArray' . DS . ucfirst(Inflector::camelize($model)) . '.php';

            if (!File::exists($file) || $overwrite) {
                $db     = amodel($model);
                $crud   = new Crud($db);

                File::delete($file);

                $tplModel = fgc(__DIR__ . DS . 'Model.tpl');
                $tplField = fgc(__DIR__ . DS . 'Field.tpl');

                $fields = $crud->fields();

                $singular       = ucfirst($model);
                $plural         = $singular . 's';
                $default_order  = $crud->pk();

                $tplModel       = str_replace(
                    array(
                        '##singular##',
                        '##plural##',
                        '##default_order##'
                    ),
                    array(
                        $singular,
                        $plural,
                        $default_order
                    ),
                    $tplModel
                );

                $fieldsSection = '';

                foreach ($fields as $field) {
                    if ($field != $crud->pk()) {
                        $label = substr($field, -3) == '_id'
                        ? ucfirst(
                            str_replace(
                                '_id',
                                '',
                                $field
                            )
                        )
                        : ucfirst(Inflector::camelize($field));

                        $fieldsSection .= str_replace(
                            array('##field##', '##label##'),
                            array($field, $label),
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
    }

