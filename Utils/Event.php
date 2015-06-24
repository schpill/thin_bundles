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

    class Event extends Object
    {
        /**
         * Observers collection
         *
         * @var ObserverCollection
         */
        protected $_observers;

        /**
         * Constructor
         *
         * Initializes observers collection
         *
         * @param array $data
         */
        public function __construct(array $data=array())
        {
            $this->_observers = new ObserverCollection();
            parent::__construct($data);
        }

        /**
         * Returns all the registered observers for the event
         *
         * @return ObserverCollection
         */
        public function getObservers()
        {
            return $this->_observers;
        }

        /**
         * Register an observer for the event
         *
         * @param Observer $observer
         * @return Event
         */
        public function addObserver(Observer $observer)
        {
            $this->getObservers()->addObserver($observer);
            return $this;
        }

        /**
         * Removes an observer by its name
         *
         * @param string $observerName
         * @return Event
         */
        public function removeObserverByName($observerName)
        {
            $this->getObservers()->removeObserverByName($observerName);
            return $this;
        }

        /**
         * Dispatches the event to registered observers
         *
         * @return Event
         */
        public function dispatch()
        {
            $this->getObservers()->dispatch($this);
            return $this;
        }

        /**
         * Retrieve event name
         *
         * @return string
         */
        public function getName()
        {
            return isset($this->_data['name']) ? $this->_data['name'] : null;
        }

        public function setName($data)
        {
            $this->_data['name'] = $data;
            return $this;
        }

        public function getBlock()
        {
            return $this->_getData('block');
        }
    }
