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

    use \Thin\Exception;
    use \Thin\Instance;
    use \Thin\File;
    use \Thin\Utils;
    use \Thin\Config;
    use \Thin\Container;
    use \Thin\Inflector;
    use \Dbarray\Dbarray as Database;
    use \Thin\Database\Collection;
    use \Thin\Arrays;
    use \Thin\Paginator;

    class Crud
    {
        private $model, $config;

        public function __construct(Database $model)
        {
            $this->model    = $model;
            $config         = context('config')->load('crudarray');
            $this->config   = isAke(isAke($config, 'tables'), $model->table);

            if (!empty($this->config)) {
                $this->prepareFields();
            }

            if (get_magic_quotes_gpc()) {
                $_REQUEST = $this->stripslashes($_REQUEST);
            }
        }

        public static function instance(Database $model)
        {
            $key    = sha1($model->db . $model->table);
            $has    = Instance::has('DbarrayCrud', $key);

            if (true === $has) {
                return Instance::get('DbarrayCrud', $key);
            } else {
                return Instance::make('DbarrayCrud', $key, new self($model));
            }
        }

        public function config()
        {
            return $this->config;
        }

        private function prepareFields()
        {
            $fields         = $this->fields();
            $configFields   = isAke($this->config, 'fields', false);

            if (!$configFields) {
                $this->config['fields'] = [];
            }

            foreach ($fields as $field) {
                $settings = isAke($configFields, $field, false);
                $foreign = substr($field, -3) == '_id';

                if (false === $settings && true === $foreign && $field != $this->pk()) {
                    $this->config['fields'][$field] = [];
                    $label = substr($field, 0, -3);
                    $this->config['fields'][$field]['label'] = ucfirst($label);
                }
            }
        }

        public function create()
        {
            if (true === context()->isPost()) {
                $this->model->post(true);

                return true;
            }

            return false;
        }

        public function read($id, $object = true)
        {
            try {
                $row = $this->model->findOrFail($id, $object);
            } catch (Exception $e) {
                $row = $this->model->create();
            }

            return $row;
        }

        public function update($id = false)
        {
            $pk = $this->model->pk();
            $id = false === $id ? isAke($_POST, $pk, false) : $id;

            if (false !== $id && true === context()->isPost()) {
                $this->model->post()->save();

                return true;
            }

            return false;
        }

        public function delete($id = false)
        {
            $pk = $this->model->pk();
            $id = false === $id ? isAke($_POST, $pk, false) : $id;

            if (false !== $id) {
                $this->model->deleteRow($id);

                return true;
            }

            return false;
        }

        public function newRow()
        {
            return $this->model->create();
        }

        public function fields($keys = true)
        {
            $configFields   = isAke($this->config, 'fields', false);

            if (false === $configFields) {
                return $this->model->fields();
            } else {
                return array_merge(['id'], array_keys($configFields));
            }
        }

        public function listing($customFields = false)
        {
            $fields         = $this->fields();
            $fieldInfos     = isAke($this->config, 'fields');

            $defaultOrder   = isAke($this->config, 'default_order', $this->model->pk());
            $defaultDir     = isAke($this->config, 'default_order_direction', 'ASC');
            $limit          = isAke($this->config, 'items_by_page', Config::get('crud.items.number', 25));
            $many           = isAke($this->config, 'many');

            $where          = isAke($_REQUEST, 'crud_where', null);
            $page           = isAke($_REQUEST, 'crud_page', 1);
            $order          = isAke($_REQUEST, 'crud_order', $defaultOrder);
            $orderDirection = isAke($_REQUEST, 'crud_order_direction', $defaultDir);
            $export         = isAke($_REQUEST, 'crud_type_export', false);

            $export = !strlen($export) ? false : $export;

            $offset = ($page * $limit) - $limit;

            $whereData = '';

            if (!empty($where)) {
                $whereData = $this->parseQuery($where);
            }

            $db = call_user_func_array(['\\Dbarray\\Dbarray', 'instance'], [$this->model->db, $this->model->table]);

            if (strstr($whereData, ' && ') || strstr($whereData, ' || ')) {
                $wheres = explode(' && ', $whereData);

                foreach ($wheres as $tmpWhere) {
                    $db = $this->model->where($tmpWhere);
                }

                $wheres = explode(' || ', $whereData);

                foreach ($wheres as $tmpWhere) {
                    $db = $this->model->where($tmpWhere, 'OR');
                }
            } else {
                if (strlen($whereData)) {
                    $db = $this->model->where($whereData);
                } else {
                    $db = $this->model->fetch();
                }
            }

            $results    = $db->order($order, $orderDirection)->exec();

            if (count($results) < 1) {
                if (strlen($where)) {
                    return '<div class="alert alert-danger col-md-4 col-md-pull-4 col-md-push-4">La requête ne remonte aucun résultat.</div>';
                } else {
                    return '<div class="alert alert-info col-md-4 col-md-pull-4 col-md-push-4">Aucune donnée à afficher..</div>';
                }
            }

            if (false !== $export) {
                return $this->export($export, $results);
            }

            $total      = count($results);
            $last       = ceil($total / $limit);
            $paginator  = new Paginator($results, $page, $total, $limit, $last);
            $data       = $paginator->getItemsByPage();
            $pagination = $paginator->links();

            $pagination = '<div class="row">
            <div class="col-md-12">
            ' . $pagination . '
            </div>
            </div>';

            $html = $pagination . '<div class="row"><div class="col-md-12"><form action="' . urlAction('list') . '/table/' . $this->model->table . '" id="listForm" method="post">
            <input type="hidden" name="crud_page" id="crud_page" value="' . $page . '" /><input type="hidden" name="crud_order" id="crud_order" value="' . $order . '" /><input type="hidden" name="crud_order_direction" id="crud_order_direction"  value="' . $orderDirection . '" /><input type="hidden" id="crud_where" name="crud_where" value="' . \Thin\Crud::checkEmpty('crud_where') . '" /><input type="hidden" id="crud_type_export" name="crud_type_export" value="" />
            <table style="clear: both;" class="table table-striped tablesorter table-bordered table-condensed table-hover">
                        <thead>
                        <tr>';
            foreach ($fields as $field) {
                $fieldSettings  = isAke($fieldInfos, $field);
                $label          = isAke($fieldSettings, 'label', ucfirst($field));
                $listable       = isAke($fieldSettings, 'is_listable', true);
                $sortable       = isAke($fieldSettings, 'is_sortable', true);

                if (!$listable || $field == $this->model->pk()) {
                    continue;
                }

                if (!$sortable) {
                    $html .= '<th>'. \Thin\Html\Helper::display($label) . '</th>';
                } else {
                    if ($field == $order) {
                        $directionJs = ('ASC' == $orderDirection) ? 'DESC' : 'ASC';
                        $js = 'orderGoPage(\'' . $field . '\', \'' . $directionJs . '\');';
                        $html .= '<th><div onclick="' . $js . '" class="text-left field-sorting ' . Inflector::lower($orderDirection) . '" rel="' . $field . '">'. \Thin\Html\Helper::display($label) . '</div></th>';
                    } else {
                        $js = 'orderGoPage(\'' . $field . '\', \'ASC\');';
                        $html .= '<th><div onclick="' . $js . '" class="text-left field-sorting" rel="' . $field . '">'. \Thin\Html\Helper::display($label) . '</div></th>';
                    }
                }
            }

            if (true === $customFields) {
                $html .= '<th style="text-align: center;">Attr.</th>';
            }

            if (count($many)) {
                $html .= '<th style="text-align: center;">Rel.</th>';
            }

            $html .= '<th style="text-align: center;">Action</th></tr></thead><tbody>';

            foreach ($data as $item) {
                $id = isAke($item, $this->model->pk(), null);
                $html .= '<tr ondblclick="document.location.href = \'' . urlAction('update') . '/table/' . $this->model->table . '/id/' . $id . '\';">';

                foreach ($fields as $field) {
                    $fieldSettings  = isAke($fieldInfos, $field);
                    $listable       = isAke($fieldSettings, 'is_listable', true);
                    $languages      = isAke($fieldSettings, 'languages');

                    if (!$listable || $field == $this->model->pk()) {
                        continue;
                    }

                    $value = !count($languages) ? isAke($item, $field, null) : isAke($item, $field . '_' . Arrays::first($languages), null);

                    $closure = isAke($fieldSettings, 'content_view', false);

                    if (false === $closure || !is_callable($closure)) {
                        $continue = true;

                        if (ake('form_type', $fieldSettings)) {

                            if ($fieldSettings['form_type'] == 'image' && strlen($value)) {
                                $html .= '<td><img src="' . $value . '" style="max-width: 200px;" /></td>';
                                $continue = false;
                            }

                            if ($fieldSettings['form_type'] == 'email' && strlen($value)) {
                                $html .= '<td><a href="mailto:' . $value . '">'. \Thin\Html\Helper::display($this->truncate($value)) . '</a></td>';
                                $continue = false;
                            }

                            if ($fieldSettings['form_type'] == 'file' && strlen($value)) {
                                $html .= '<td><a class="btn btn-small btn-success" href="' . $value . '"><i class="fa fa-download"></i></td>';
                                $continue = false;
                            }
                        }

                        if (true === $continue) {
                            if ('email' == $field) {
                                $html .= '<td><a href="mailto:' . $value . '">'. \Thin\Html\Helper::display($this->truncate($value)) . '</a></td>';
                            } else {
                                $html .= '<td>'. \Thin\Html\Helper::display($this->truncate($value)) . '</td>';
                            }
                        }
                    } else {
                        $value = call_user_func_array($closure, array($item));
                        $html .= '<td>'. \Thin\Html\Helper::display($value) . '</td>';
                    }
                }

                if (true === $customFields) {
                    $html .= '<td style="text-align: center;"><a href="' . urlAction('customfields') . '/type/' . $this->model->table . '/row_id/' . $id . '" target="_blank" rel="tooltip" title="Gestion des attributs supplémentaires" class="btn btn-success"><i class="fa fa-tags"></i></a></td>';
                }

                if (count($many)) {
                    $html .= '<td style="text-align: center;"><ul class="list-inline">';

                    foreach ($many as $rel) {
                        $foreignCrud = new self(adb($this->model->db, $rel));
                        $nameRel   = isAke($foreignCrud->config(), 'plural', $rel . 's');
                        $html .= '<li><a rel="tooltip" title="Afficher les ' . strtolower($nameRel) . ' en relation" class="btn btn-primary" target="_blank" href="' . urlAction('many') . '/table/' . $rel . '/foreign/' . $this->model->table . '_id/id/' . $id . '"><i class="fa fa-chain"></i></a></li>';
                    }

                    $html .= '</ul></td>';
                }

                $html .= $this->options($id);
                $html .= '</tr>';
            }

            $html .= '</tbody></table></form>' . $pagination . '</div></div>';

            return $html;
        }

        private function truncate($str, $length = 20)
        {
            if (strlen($str) > $length) {
                $seg = substr($str, 0, $length);
                $str = $seg . '&hellip;';
            }

            return $str;
        }

        public function makeSearch()
        {
            $fields         = $this->fields();
            $fieldInfos     = isAke($this->config, 'fields');
            $where          = isAke($_REQUEST, 'crud_where', null);

            $search         = '<div class="row"><div class="col-md-10">' . NL;

            if (!empty($where)) {
                $where = $this->readableQuery($where);
                $search .= '<span class="badge badge-lg alert-success">Recherche en cours : ' . $where . '</span>';
                $search .= '&nbsp;&nbsp;<a class="btn btn-warning" href="#" onclick="document.location.href = \'' . urlAction('list') . '/table/' . $this->model->table . '\'"><i class="fa fa-trash-o icon-white"></i> Supprimer cette recherche</a>&nbsp;&nbsp;';
            }

            $search .= '<button id="newCrudSearch" type="button" class="btn btn-info" onclick="$(\'#crudSearchDiv\').slideDown();$(\'#newCrudSearch\').hide();$(\'#hideCrudSearch\').show();"><i class="fa fa-search fa-white"></i> Effectuer une nouvelle recherche</button>';
            $search .= '&nbsp;&nbsp;<button id="hideCrudSearch" type="button" style="display: none;" class="btn btn-danger" onclick="$(\'#crudSearchDiv\').slideUp();$(\'#newCrudSearch\').show();$(\'#hideCrudSearch\').hide();"><i class="fa fa-power-off fa-white"></i> Masquer la recherche</button>';
            $search .= '<fieldset id="crudSearchDiv" style="display: none;">' . NL;

            $search .= '<hr />' . NL;

            $i = 0;
            $fieldsJs = [];
            $js = '<script type="text/javascript">' . NL;

            foreach ($fields as $field) {
                $fieldSettings  = isAke($fieldInfos, $field);
                $label          = isAke($fieldSettings, 'label', ucfirst($field));

                $searchable     = isAke($fieldSettings, 'is_searchable', true);
                $type           = isAke($fieldSettings, 'type', 'text');
                $closure        = isAke($fieldSettings, 'content_search', false);

                if (true === $searchable) {
                    $fieldsJs[] = "'$field'";
                    $search .= '<div class="control-group">' . NL;
                    $search .= '<label class="control-label">' . \Thin\Html\Helper::display($label) . '</label>' . NL;
                    $search .= '<div class="controls" id="crudControl_' . $i . '">' . NL;
                    $search .= '<select id="crudSearchOperator_' . $i . '">
                    <option value="=">=</option>
                    <option value="LIKE">Contient</option>
                    <option value="NOT LIKE">Ne contient pas</option>
                    <option value="START">Commence par</option>
                    <option value="END">Finit par</option>
                    <option value="<">&lt;</option>
                    <option value=">">&gt;</option>
                    <option value="<=">&le;</option>
                    <option value=">=">&ge;</option>
                    </select>' . NL;

                    if (!$closure) {
                        $search .= '<input style="width: 150px;" type="text" id="crudSearchValue_' . $i . '" value="" />';
                    } else {
                        if (is_callable($closure)) {
                            $customSearch = call_user_func_array($closure, array('crudSearchValue_' . $i));
                            $search  .= $customSearch;
                        } else {
                            $search .= '<input style="150px;" type="text" id="crudSearchValue_' . $i . '" value="" />';
                        }
                    }

                    $search .= '&nbsp;&nbsp;<span class="btn btn-success" href="#" onclick="addRowSearch(\'' . $field . '\', ' . $i . '); return false;"><i class="fa fa-plus"></i></span>';
                    $search .= '</div>' . NL;
                    $search .= '</div>' . NL;
                    $i++;
                }
            }

            $js .= 'var searchFields = [' . implode(', ', $fieldsJs)  . ']; var numFieldsSearch = ' . ($i - 1) . ';';
            $js .= '</script>' . NL;
            $search .= '<div class="control-group">
                <div class="controls">
                    <button type="submit" class="btn btn-primary" name="Rechercher" onclick="makeCrudSearch();">Rechercher</button>
                </div>
            </div>' . NL;

            $search .= '</fieldset>' . NL;
            $search .= '</div><div class="col-md-2 clear"></div>' . NL . $js . NL;

            return $search . '</div></div><div class="wrapper">';
        }

        private function options($id, $plus = '')
        {
            $options = '';

            $options .= '<td style="text-align: center;"><ul class="list-inline">';

            $options .= '<li><a rel="tooltip" title="éditer" class="btn btn-xs btn-success" href="' . urlAction('update') . '/table/' . $this->model->table . '/id/' . $id . '"><i class="fa fa-edit"></i></a></li>';

            $options .= '<li><a rel="tooltip" title="dupliquer" class="btn btn-xs btn-warning" href="' . urlAction('duplicate') . '/table/' . $this->model->table . '/id/' . $id . '"><i class="fa fa-copy"></i></a></li>';

            $options .= '<li><a rel="tooltip" title="afficher" class="btn btn-xs btn-info" href="' . urlAction('read') . '/table/' . $this->model->table . '/id/' . $id . '"><i class="fa fa-file"></i></a></li>';

            $options .= '<li><a rel="tooltip" title="supprimer" class="btn btn-xs btn-danger" href="#" onclick="if (confirm(\'Confirmez-vous la suppression de cet élément ?\')) document.location.href = \'' . urlAction('delete') . '/table/' . $this->model->table . '/id/' . $id . '\'; return false;"><i class="fa fa-trash-o"></i></a></li>' . $plus;

            $options .= '</ul></td>';

            return $options;
        }

        private function parseQuery($queryJs)
        {
            $queryJs = substr($queryJs, 0, -2);

            $query = repl('##', ' && ', $queryJs);
            $query = repl('||', ' || ', $query);
            $query = repl('%%', ' ', $query);
            $query = repl('LIKESTART', 'LIKE', $query);
            $query = repl('LIKEEND', 'LIKE', $query);
            $query = repl("'", '', $query);

            return $query;
        }

        private function readableQuery($query)
        {
            $query = substr($query, 0, -2);
            $query = repl('##', ' AND ', $query);
            $query = repl('||', ' OR ', $query);
            $query = repl('%%', ' ', $query);

            $query = repl(' NOT LIKE ', ' ne contient pas ', $query);
            $query = repl(' LIKESTART ', ' commence par ', $query);
            $query = repl(' LIKEEND ', ' finit par ', $query);
            $query = repl(' LIKE ', ' contient ', $query);
            $query = repl(' IN ', ' compris dans ', $query);
            $query = repl(' NOT IN ', ' non compris dans ', $query);
            $query = repl('%', '', $query);
            $query = repl(' >= ', ' plus grand ou vaut ', $query);
            $query = repl(' <= ', ' plus petit ou vaut ', $query);
            $query = repl(' = ', ' vaut ', $query);
            $query = repl(' < ', ' plus petit que ', $query);
            $query = repl(' > ', ' plus grand que ', $query);
            $query = repl(' AND ', ' et ', $query);
            $query = repl(' OR ', ' ou ', $query);
            $query = repl(" '", ' <span class="badge alert-danger">', $query);
            $query = repl("'", '</span>', $query);

            $tab = explode(' ', $query);

            if ($tab[0] != 'id' && $tab['0'] != 'created_at' && $tab['0'] != 'updated_at') {
                foreach ($this->config['fields'] as $f => $settings) {
                    if ($f == $tab[0]) {
                        $label = isAke($settings, 'label', $f);
                    }
                }
            } else {
                $label = $tab[0];
            }

            $query = repl(
                $tab[0] . ' ' . $tab[1] . ' ' . $tab[2],
                '<span class="badge alert-warning">' . $label . '</span> ' . $tab[1] . ' ' . $tab[2],
                $query
            );

            $and = explode(' et ', $query);

            if (count($and)) {
                array_shift($and);

                foreach ($and as $row) {
                    $tab = explode(' ', $row);

                    if ($tab[0] != 'id' && $tab['0'] != 'created_at' && $tab['0'] != 'updated_at') {
                        foreach ($this->config['fields'] as $f => $settings) {
                            if ($f == $tab[0]) {
                                $label = isAke($settings, 'label', $f);
                            }
                        }
                    } else {
                        $label = $tab[0];
                    }

                    $query = repl(
                        $tab[0] . ' ' . $tab[1] . ' ' . $tab[2],
                        '<span class="badge alert-warning">' . $label . '</span> ' . $tab[1] . ' ' . $tab[2],
                        $query
                    );
                }
            }

            $and = explode(' ou ', $query);

            if (count($and)) {
                array_shift($and);

                foreach ($and as $row) {
                    $tab = explode(' ', $row);

                    if ($tab[0] != 'id' && $tab['0'] != 'created_at' && $tab['0'] != 'updated_at') {
                        foreach ($this->config['fields'] as $f => $settings) {
                            if ($f == $tab[0]) {
                                $label = isAke($settings, 'label', $f);
                            }
                        }
                    } else {
                        $label = $tab[0];
                    }

                    $query = repl(
                        $tab[0] . ' ' . $tab[1] . ' ' . $tab[2],
                        '<span class="badge alert-warning">' . $label . '</span> ' . $tab[1] . ' ' . $tab[2],
                        $query
                    );
                }
            }

            return $query;
        }

        private function export($type, $rows)
        {
            $fieldInfos = isAke($this->config, 'fields');
            $fields = $this->fields();

            if ('excel' == $type) {
                $excel = '<html xmlns:o="urn:schemas-microsoft-com:office:office"
        xmlns:x="urn:schemas-microsoft-com:office:excel"
        xmlns="http://www.w3.org/TR/REC-html40">

            <head>
                <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
                <meta name="ProgId" content="Excel.Sheet">
                <meta name="Generator" content="Microsoft Excel 11">
                <style id="Classeur1_17373_Styles">
                <!--table
                    {mso-displayed-decimal-separator:"\,";
                    mso-displayed-thousand-separator:" ";}
                .xl1517373
                    {padding-top:1px;
                    padding-right:1px;
                    padding-left:1px;
                    mso-ignore:padding;
                    color:windowtext;
                    font-size:10.0pt;
                    font-weight:400;
                    font-style:normal;
                    text-decoration:none;
                    font-family:Arial;
                    mso-generic-font-family:auto;
                    mso-font-charset:0;
                    mso-number-format:General;
                    text-align:general;
                    vertical-align:bottom;
                    mso-background-source:auto;
                    mso-pattern:auto;
                    white-space:nowrap;}
                .xl2217373
                    {padding-top:1px;
                    padding-right:1px;
                    padding-left:1px;
                    mso-ignore:padding;
                    color:#FFFF99;
                    font-size:10.0pt;
                    font-weight:700;
                    font-style:normal;
                    text-decoration:none;
                    font-family:Arial, sans-serif;
                    mso-font-charset:0;
                    mso-number-format:General;
                    text-align:center;
                    vertical-align:bottom;
                    background:#003366;
                    mso-pattern:auto none;
                    white-space:nowrap;}
                -->
                </style>
            </head>

                <body>
                <!--[if !excel]>&nbsp;&nbsp;<![endif]-->

                <div id="Classeur1_17373" align="center" x:publishsource="Excel">

                <table x:str border="0" cellpadding="0" cellspacing="0" width=640 style="border-collapse:
                 collapse; table-layout: fixed; width: 480pt">
                 <col width="80" span=8 style="width: 60pt">
                 <tr height="17" style="height:12.75pt">
                  ##headers##
                 </tr>
                 ##content##
                </table>
                </div>
            </body>
        </html>';
                $tplHeader = '<td class="xl2217373">##value##</td>';
                $tplData = '<td>##value##</td>';

                $headers = [];

                foreach ($fields as $field) {
                    $fieldSettings  = isAke($fieldInfos, $field);
                    $exportable     = isAke($fieldSettings, 'is_exportable', true);
                    $label          = isAke($fieldSettings, 'label', ucfirst($field));

                    if (true === $exportable) {
                        $headers[] = \Thin\Html\Helper::display($label);
                    }
                }

                $xlsHeader = '';

                foreach ($headers as $header) {
                    $xlsHeader .= repl('##value##', $header, $tplHeader);
                }

                $excel = repl('##headers##', $xlsHeader, $excel);

                $xlsContent = '';

                foreach ($rows as $item) {
                    $xlsContent .= '<tr>';

                    foreach ($fields as $field) {
                        $fieldSettings  = isAke($fieldInfos, $field);
                        $exportable     = isAke($fieldSettings, 'is_exportable', true);

                        if (true === $exportable) {
                            $value = isAke($item, $field, '&nbsp;');

                            if (Arrays::exists('content_list', $fieldSettings)) {
                                $closure = $fieldSettings['content_list'];

                                if (is_callable($closure)) {
                                    $value = call_user_func_array($closure, array($item));
                                }
                            }

                            if (empty($value)) {
                                $value = '&nbsp;';
                            }
                            $xlsContent .= repl('##value##', \Thin\Html\Helper::display($value), $tplData);
                        }
                    }

                    $xlsContent .= '</tr>';
                }

                $excel = repl('##content##', $xlsContent, $excel);

                $name = 'extraction_' . $this->model->table . '_' . date('d_m_Y_H_i_s') . '.xlsx';

                $file = TMP_PUBLIC_PATH . DS . $name;

                File::delete($file);
                File::put($file, $excel);
                Utils::go(URLSITE . '/tmp/' . $name);
            } elseif ('pdf' == $type) {
                $pdf = '<html>
            <head>
                <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
                <link href="//fonts.googleapis.com/css?family=Abel" rel="stylesheet" type="text/css" />
                <title>Extraction ' . $this->model->table . '</title>
                <style>
                    *
                    {
                        font-family: Abel, ubuntu, verdana, tahoma, arial, sans serif;
                        font-size: 11px;
                    }
                    h1
                    {
                        text-transform: uppercase;
                        font-size: 135%;
                    }
                    th
                    {
                        font-size: 120%;
                        color: #fff;
                        background-color: #394755;
                        text-transform: uppercase;
                    }
                    td
                    {
                        border: solid 1px #394755;
                    }

                    a, a:visited, a:hover
                    {
                        color: #000;
                        text-decoration: underline;
                    }
                </style>
            </head>
            <body>
                <center><h1>Extraction &laquo ' . $this->model->table . ' &raquo;</h1></center>
                <p></p>
                <table width="100%" cellpadding="5" cellspacing="0" border="0">
                <tr>
                    ##headers##
                </tr>
                ##content##
                </table>
                <p>&copy; GP 1996 - ' . date('Y') . ' </p>
            </body>
            </html>';

                $tplHeader = '<th>##value##</th>';
                $tplData = '<td>##value##</td>';

                $headers = [];

                foreach ($fields as $field) {
                    $fieldSettings  = isAke($fieldInfos, $field);
                    $exportable     = isAke($fieldSettings, 'is_exportable', true);
                    if (true === $exportable) {
                        $label = isAke($fieldSettings, 'label', ucfirst($field));
                        $headers[] = \Thin\Html\Helper::display($label);
                    }
                }

                $pdfHeader = '';

                foreach ($headers as $header) {
                    $pdfHeader .= repl('##value##', $header, $tplHeader);
                }

                $pdf = repl('##headers##', $pdfHeader, $pdf);

                $pdfContent = '';

                foreach ($rows as $item) {
                    $pdfContent .= '<tr>';

                    foreach ($fields as $field) {
                        $fieldSettings  = isAke($fieldInfos, $field);
                        $exportable     = isAke($fieldSettings, 'is_exportable', true);

                        if (true === $exportable) {
                            $value = isAke($item, $field, '&nbsp;');

                            if (Arrays::exists('content_list', $fieldSettings)) {
                                $closure = $fieldSettings['content_list'];

                                if (is_callable($closure)) {
                                    $value = call_user_func_array($closure, array($item));
                                }
                            }

                            if (empty($value)) {
                                $value = '&nbsp;';
                            }

                            $pdfContent .= repl('##value##', \Thin\Html\Helper::display($value), $tplData);
                        }
                    }

                    $pdfContent .= '</tr>';
                }

                $pdf = repl('##content##', $pdfContent, $pdf);

                return \Thin\Pdf::make($pdf, "extraction_" . $this->model->table . "_" . date('d_m_Y_H_i_s'), false);
            }
        }

        public function pk()
        {
            return $this->model->pk();
        }

        public function form()
        {
            $MAX_FILE_SIZE = isAke($_POST, 'MAX_FILE_SIZE', null);

            if (!is_null($MAX_FILE_SIZE)) {
                unset($_POST['MAX_FILE_SIZE']);
            }

            $pk = $this->model->pk();
            $action = false !== isAke($_POST, $pk, false) ? 'updating' : 'creating';

            return $this->$action();
        }

        private function updating()
        {
            $pk = $this->model->pk();
            $id = false !== isAke($_POST, $pk, false);

            if (false !== $id && count($_POST)) {
                $old = $this->model->find($id);
                $fieldInfos = isAke($this->config, 'fields');
                $fields = $this->fields();
                $closure = isAke($this->config, 'before_update', false);

                if (false !== $closure && is_callable($closure)) {
                    $closure($old);
                }

                foreach ($fields as $field) {
                    $settings   = isAke($fieldInfos, $field);
                    $type       = isAke($settings, 'form_type', false);

                    if ($type == 'file' || $type == 'image') {
                        $upload = $this->upload($field);

                        if (!is_null($upload)) {
                            $_POST[$field] = $upload;
                        }
                    }
                }

                $record = $old->hydrate()->save();
                $closure = isAke($this->config, 'after_update', false);

                if (false !== $closure && is_callable($closure)) {
                    $closure($record);
                }

                return true;
            }

            return false;
        }

        private function creating()
        {
            $pk = $this->model->pk();
            $id = false !== isAke($_POST, 'duplicate_id', false);

            if (count($_POST)) {
                $new        = $this->model->create();
                $fieldInfos = isAke($this->config, 'fields');
                $fields     = $this->fields();
                $closure    = isAke($this->config, 'before_create', false);

                if (false !== $closure && is_callable($closure)) {
                    $closure();
                }

                foreach ($fields as $field) {
                    $settings   = isAke($fieldInfos, $field);
                    $type       = isAke($settings, 'form_type', false);

                    if ($type == 'file' || $type == 'image') {
                        $upload = $this->upload($field);

                        if (!is_null($upload)) {
                            $_POST[$field] = $upload;
                        } else {
                            if (false !== $id) {
                                $old = $this->model->find($id);
                                $_POST[$field] = $old->$field;
                            }
                        }
                    }
                }

                if (false !== $id) {
                    unset($_POST['duplicate_id']);
                }

                $record = $new->hydrate()->save();
                $closure = isAke($this->config, 'after_create', false);

                if (false !== $closure && is_callable($closure)) {
                    $closure($record);
                }

                return true;
            }

            return false;
        }

        private function upload($field)
        {
            $bucket = container()->bucket();

            if (Arrays::exists($field, $_FILES)) {
                $fileupload         = $_FILES[$field]['tmp_name'];
                $fileuploadName     = $_FILES[$field]['name'];

                if (strlen($fileuploadName)) {
                    $tab = explode(".", $fileuploadName);
                    $data = fgc($fileupload);

                    if (!strlen($data)) {
                        return null;
                    }

                    $ext = Inflector::lower(Arrays::last($tab));
                    $res = $bucket->data($data, $ext);

                    return $res;
                }
            }
            return null;
        }

        private function stripslashes($val)
        {
            return Arrays::is($val)
            ? array_map(
                array(
                    __NAMESPACE__ . '\\Tools',
                    'stripslashes'
                ),
                $val
            )
            : stripslashes($val);
        }

        public static function __callStatic($method, $args)
        {
            return call_user_func_array(array(__NAMESPACE__ . '\\Tools', $method), $args);
        }
    }
