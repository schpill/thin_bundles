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

    namespace Utils;

    class Tree
    {

        /**
         * Nodes collection
         *
         * @var Collection
         */
        protected $_nodes;

        /**
         * Enter description here...
         *
         */
        public function __construct()
        {
            $this->_nodes = new Collection($this);
        }

        /**
         * Enter description here...
         *
         * @return Varien_Data_Tree
         */
        public function getTree()
        {
            return $this;
        }

        /**
         * Enter description here...
         *
         * @param Node $parentNode
         */
        public function load($parentNode = null)
        {
        }

        /**
         * Enter description here...
         *
         * @param unknown_type $nodeId
         */
        public function loadNode($nodeId)
        {
        }

        /**
         * Enter description here...
         *
         * @param array|Node $data
         * @param Node $parentNode
         * @param Node $prevNode
         * @return Node
         */
        public function appendChild($data=array(), $parentNode, $prevNode=null)
        {
            if (is_array($data)) {
                $node = $this->addNode(
                    new Node($data, $parentNode->getIdField(), $this),
                    $parentNode
                );
            } elseif ($data instanceof Node) {
                $node = $this->addNode($data, $parentNode);
            }

            return $node;
        }

        /**
         * Enter description here...
         *
         * @param Node $node
         * @param Node $parent
         * @return Node
         */
        public function addNode($node, $parent = null)
        {
            $this->_nodes->add($node);
            $node->setParent($parent);

            if (!is_null($parent) && ($parent instanceof Node)) {
                $parent->addChild($node);
            }

            return $node;
        }

        /**
         * Enter description here...
         *
         * @param Node $node
         * @param Node $parentNode
         * @param Node $prevNode
         */
        public function moveNodeTo($node, $parentNode, $prevNode = null)
        {
        }

        /**
         * Enter description here...
         *
         * @param Node $node
         * @param Node $parentNode
         * @param Node $prevNode
         */
        public function copyNodeTo($node, $parentNode, $prevNode = null)
        {
        }

        /**
         * Enter description here...
         *
         * @param Node $node
         * @return Varien_Data_Tree
         */
        public function removeNode($node)
        {
            $this->_nodes->delete($node);

            if ($node->getParent()) {
                $node->getParent()->removeChild($node);
            }

            unset($node);

            return $this;
        }

        /**
         * Enter description here...
         *
         * @param Node $parentNode
         * @param Node $prevNode
         */
        public function createNode($parentNode, $prevNode = null)
        {
        }

        /**
         * Enter description here...
         *
         * @param Node $node
         */
        public function getChild($node)
        {
        }

        /**
         * Enter description here...
         *
         * @param Node $node
         */
        public function getChildren($node)
        {
        }

        /**
         * Enter description here...
         *
         * @return Collection
         */
        public function getNodes()
        {
            return $this->_nodes;
        }

        /**
         * Enter description here...
         *
         * @param unknown_type $nodeId
         * @return Node
         */
        public function getNodeById($nodeId)
        {
            return $this->_nodes->searchById($nodeId);
        }

        /**
         * Enter description here...
         *
         * @param Node $node
         * @return array
         */
        public function getPath($node)
        {
            if ($node instanceof Node) {

            } elseif (is_numeric($node)){
                if ($_node = $this->getNodeById($node)) {
                    return $_node->getPath();
                }
            }
            return array();
        }
    }
