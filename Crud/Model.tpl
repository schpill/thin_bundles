<?php
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
