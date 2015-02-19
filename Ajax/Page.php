<?php
    namespace Ajax;

    use Thin\Api;

    class Page
    {
        public function success(array $array)
        {
            $array['status'] = 200;
            Api::render($array);
        }

        public function error(array $array)
        {
            $array['status'] = 500;
            Api::render($array);
        }

        public function status(array $array, $status = 200)
        {
            $array['status'] = $status;
            Api::render($array);
        }
    }
