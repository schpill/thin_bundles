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

    class ##class## extends \Keystore\Model
    {
        public $_fields = [];

        /* Make hooks of model */
        public function _hooks()
        {
            $obj = $this;
            // $this->_hooks['beforeCreate'] =  function () use ($obj) {};
            // $this->_hooks['beforeRead'] = ;
            // $this->_hooks['beforeUpdate'] = ;
            // $this->_hooks['beforeDelete'] = ;
            // $this->_hooks['afterCreate'] = ;
            // $this->_hooks['afterRead'] = ;
            // $this->_hooks['afterUpdate'] = ;
            // $this->_hooks['afterDelete'] = ;
            // $this->_hooks['validate'] = ;

            $this->_definition();
        }

        public function _definition()
        {
            $this->_fields['id']            = 'int';
            $this->_fields['created_at']    = 'int';
            $this->_fields['updated_at']    = 'int';

            return $this;
        }
    }
