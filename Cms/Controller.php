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

    use \Michelf\MarkdownExtra;
    use \Thin\CmsConfig;

    class Cmscontroller
    {
        public static $vars = array();
        public static $routes = array();

        private $dir, $theme, $themeDir, $uri;
        private $plugins = array();

        public function __construct()
        {
            $dispatch = false;
            $this->dir = realpath(APPLICATION_PATH . DS . '..' . DS . 'public' . DS . 'content' . DS . SITE_NAME);

            if (is_dir($this->dir)) {
                $configFile = $this->dir . DS . 'config' . DS . 'application.php';
                CmsConfig::load($configFile);

                if (File::exists($configFile)) {
                    $dispatch = true;
                }

                $routesFile = $this->dir . DS . 'config' . DS . 'routes.php';

                if (File::exists($routesFile)) {
                    self::$routes = include $routesFile;
                }
            }

            if (true === $dispatch) {
                $this->loadPlugins();
                $this->theme = CmsConfig::get('theme', 'default');
                $this->themeDir = $this->dir . DS . 'themes' . DS . $this->theme;
                $this->uri = $_SERVER['REQUEST_URI'];
                $this->dispatch();
            }
        }

        private function dispatch()
        {
            $uri = $this->uri;
            $page = false;
            $dirPages = $this->dir . DS . 'pages';

            if (is_dir($dirPages)) {
                if (strlen($uri) == 1) {
                    $page = $dirPages . DS . 'home.html';
                } else {
                    $page = $dirPages . $uri . '.html';

                    if (!File::exists($page)) {
                        $page = $this->route();

                        if (false !== $page) {
                            $page = $dirPages . $page;
                        }
                    }
                }
            }

            if (false !== $page) {
                if (File::exists($page)) {
                    $this->tests();
                    $this->display($page);
                }
            }
        }

        private function route()
        {
            $uri = $this->uri;
            if (count(self::$routes)) {
                $uri = strlen($uri) > 1 ? rtrim($uri, '/') : $uri;

                foreach (self::$routes as $route => $config) {
                    $path = isAke($config, 'path', false);
                    $closure = isAke($config, 'closure', false);

                    if (false !== $path) {
                        $regex = '#^' . $path . '$#';
                        $res = preg_match($regex, $uri, $values);

                        if ($res === 0) {
                            continue;
                        }

                        array_shift($values);
                        if (false !== $closure) return call_user_func_array($closure, array($values));
                    }
                }
            }

            return false;
        }

        private function display($page)
        {
            $v = $this;

            $e = function($_) use ($v) {
                return $v->show($_);
            };

            $cache      = container()->redis();
            $keyHtml    = 'cms::pages::' . sha1($page) . '::html';
            $keyAge     = 'cms::pages::' . sha1($page) . '::age';

            $age        = filemtime($page);

            $cached     = $cache->get($keyHtml);
            $useCache   = APPLICATION_ENV == 'production';

            if (strlen($cached) && $useCache) {
                $aged   = $cache->get($keyAge);

                if ($aged == $age) {
                    echo $cached;
                    echo View::showStats();
                    exit;
                }
            }

            $content    = fgc($page);
            $config     = $this->getConfigPage($content);

            eval($config);

            $templateFile = $this->themeDir . DS . $template . '.html';

            if (File::exists($templateFile)) {
                $html = fgc($templateFile);
                $html = $this->parse($config, $html, $this->getHtml($content));

                ob_start();

                eval(' ?>' . $html . '<?php ');

                $content = ob_get_contents();
                ob_end_clean();

                if ($useCache) {
                    $cache->set($keyAge, $age);
                    $cache->set($keyHtml, $content);
                }

                echo $content;
                echo View::showStats();

                exit;
            }
        }

        private function parse($pageConfig, $htmlTemplate, $htmlPage)
        {
            eval($pageConfig);
            $code = str_replace('@@content', $htmlPage, $htmlTemplate);
            $code = str_replace(array('{{', '}}'), array('<?php', '?>'), $code);

            if (strstr($code, '@@partial')) {
                $partials = $this->getPartials($code);

                if (count($partials)) {
                    foreach ($partials as $partial) {
                        $partialFile = $this->dir . DS . 'partials' . DS .$partial . '.html';

                        if (File::exists($partialFile)) {
                            $code = str_replace("@@partial($partial)", fgc($partialFile), $code);
                        }
                    }
                }
            }

            $vars = $this->getVars($code);
            $url = container()->getUrlsite();
            $assets = '/content/' . SITE_NAME . '/themes/' . $this->theme . '/assets';

            if (count($vars)) {
                foreach ($vars as $var) {
                    if (!isset($$var)) {
                        $value = CmsConfig::get($var, false);

                        if (false === $value) {
                            $value = isAke($_REQUEST, $var, false);

                            if (false === $value) {
                                $value = '';
                            }
                        }
                    } else {
                        $value = $$var;
                    }

                    $code = str_replace("%($var)%", $value, $code);
                }
            }

            return $code;
        }

        public function show($string, $echo = true)
        {
            if (true !== $echo) {
                return Html\Helper::display($string);
            } else {
                echo Html\Helper::display($string);
            }
        }

        private function getPartials($code)
        {
            $tab = explode('@@partial', $code);
            array_shift($tab);

            $partials = array();

            if (count($tab)) {
                foreach ($tab as $partial) {
                    $partial = Utils::cut('(', ')', $partial);
                    array_push($partials, $partial);
                }
            }

            return $partials;
        }

        private function getVars($code)
        {
            $tab = explode('%(', $code);
            array_shift($tab);
            $vars = array();

            if (count($tab)) {
                foreach ($tab as $var) {
                    list($var, $dummy) = explode(')%', $var, 2);
                    array_push($vars, $var);
                }
            }

            return $vars;
        }

        private function getConfigPage($content)
        {
            $res = preg_match('#/\*.+?\*/#s', $content, $config);

            if ($res !== 0) {
                $config = array_shift($config);

                return str_replace(array('/*', '*/'), '', $config);
            }

            return null;
        }

        private function getHtml($content)
        {
            $content = preg_replace('#/\*.+?\*/#s', '', $content);

            return $content;
        }

        public static function gets()
        {
            return static::$vars;
        }

        public static function get($key, $default = null)
        {
            return arrayGet(static::$vars, $key, $default);
        }

        public static function set($key, $value = null)
        {
            static::$items = arraySet(static::$vars, $key, $value);
        }

        public static function has($key)
        {
            return !is_null(static::get($key));
        }

        public static function forget($key)
        {
            if (static::has($key)) {
                arrayUnset(static::$vars, $key);
            }
        }

        /**
         * Helper function to recusively get all files in a directory
         *
         * @param string $directory start directory
         * @param string $ext optional limit to file extensions
         * @return array the matched files
         */
        protected function getFiles($directory, $ext = '')
        {
            $items = array();

            if($handle = opendir($directory)) {
                while(false !== ($file = readdir($handle))) {
                    if(preg_match("/^(^\.)/", $file) === 0) {
                        if(is_dir($directory . DS . $file)) {
                            $items = array_merge($items, $this->getFiles($directory . DS . $file, $ext));
                        } else {
                            $file = $directory . DS . $file;

                            if(!$ext || strstr($file, $ext)) {
                                $items[] = preg_replace("/\/\//si", DS, $file);
                            }
                        }
                    }
                }

                closedir($handle);
            }
            return $items;
        }

        /**
         * Helper function to limit the words in a string
         *
         * @param string $string the given string
         * @param int $wordLimit the number of words to limit to
         * @return string the limited string
         */
        protected function limitWords($string, $wordLimit, $ending = '&hellip;')
        {
            $words      = explode(' ', $string);
            $excerpt    = trim(implode(' ', array_splice($words, 0, $wordLimit)));

            if(count($words) > $wordLimit) {
                $excerpt .= $ending;
            }

            return $excerpt;
        }

        /**
         * Load any plugins
         */
        private function loadPlugins()
        {
            $dir = realpath(APPLICATION_PATH . DS . '..' . DS . 'public' . DS . 'content' . DS . SITE_NAME . DS . 'plugins');
            $this->plugins = array();
            $plugins = $this->getFiles($dir, '.php');

            if(!empty($plugins)) {
                foreach($plugins as $plugin) {
                    $getNamespaceAndClassNameFromCode = getNamespaceAndClassNameFromCode(fgc($plugin));

                    list($namespace, $class) = $getNamespaceAndClassNameFromCode;

                    require_once $plugin;

                    $actions    = get_class_methods($namespace . '\\' . $class);
                    $nsClass    = '\\' . $namespace . '\\' . $class;
                    $instance   = new $nsClass;

                    if (Arrays::in('init', $actions)) {
                        $instance::init();
                    }

                    array_push($this->plugins, $instance);
                    $this->plugins[] = $obj;
                }
            }
        }

        /**
         * Processes any hooks and runs them
         *
         * @param string $id the ID of the hook
         * @param array $args optional arguments
         */
        protected function hook($id, $args = array())
        {
            if(!empty($this->plugins)) {
                foreach($this->plugins as $plugin) {
                    if(is_callable(array($plugin, $id))) {
                        call_user_func_array(array($plugin, $id), $args);
                    }
                }
            }
        }

        public function tests()
        {

        }
    }
