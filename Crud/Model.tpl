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

    namespace Thin;

    use \CrudBundle\Crud as bundleCrud;

    return array(
        /* GENERAL SETTINGS */
        'singular'                  => '##singular##',
        'plural'                    => '##plural##',
        'default_order'             => '##default_order##',
        'default_order_direction'   => 'ASC',
        'items_by_page'             => 25,
        'display'                   => false,
        'many'                      => array(),

        /* EVENTS */
        'before_create'             => false,
        'after_create'              => false,

        'before_read'               => false,
        'after_read'                => false,

        'before_update'             => false,
        'after_update'              => false,

        'before_delete'             => false,
        'after_delete'              => false,

        'before_list'               => false,
        'after_list'                => false,

        /* FIELDS */
        'fields'                    => array(
            ##fields##
        )
    );
