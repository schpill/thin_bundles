<?php
    namespace Dbjson;

    Interface Storage
    {
        public function write($file, $value);
        public function read($file);
        public function delete($file);
        public function glob();
        public function extractId($file);
    }
