<?php
    namespace Dbredis;

    class Definition extends Base
    {
        private $output;

        /**
         * Constructor.
         *
         * @param string $class  The class.
         * @param Output $output The output.
         *
         * @api
         */
        public function __construct($class, Output $output)
        {
            parent::__construct($class);

            $this->setOutput($output);
        }

        /**
         * Set the output.
         *
         * @param Output $output The output.
         *
         * @api
         */
        public function setOutput(Output $output)
        {
            $this->output = $output;
        }

        /**
         * Returns the output.
         *
         * @return Output The output.
         *
         * @api
         */
        public function getOutput()
        {
            return $this->output;
        }
    }
