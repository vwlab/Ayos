<?php

class Layout
{
    private $m_parent = null;
    private $m_file = NULL;
    private $m_variables = array();

    static $s_php_open_tag = "<?php";
    static $s_php_close_tag = "?>";

    static $s_options = array(
        'cache' => true,    ///-- Cache
        'cache.name' => 'cache',  ///-- Cache Directory Name
        'cache.root' => '',        // -- Cache Root
        'error' => true,        ///-- Error Reporting
        'error.exit' => true,        ///-- Quit on Error
        'root' => '');        ///-- Layout Root

    static $m_static_variables = array();


    public function __construct($file)
    {
        $this->m_file = $file;

        $this->m_variables['_CWD'] = getcwd();
        $this->m_variables['_FILE'] =& $this->m_file;
        $this->m_variables['_SELF'] = $_SERVER['PHP_SELF'];
        $this->m_variables['_URI'] = $_SERVER['REQUEST_URI'];
        $this->m_variables['_ROOT'] =& self::$s_options['root'];
    }

    private function IsOdd($value)
    {
        return ($value & 1);
    }

    private static function IsTrue($value)
    {
        if ($value) {
            if (is_bool($value) || is_int($value) || is_float($value)) {
                return true;
            } else if (is_numeric($value)) {
                if (floatval($value)) {
                    return true;
                }
            } else if (is_string($value)) {
                return in_array(strtolower($value), array("true", "t", "yes", "y"));
            }
        }
        return false;
    }

    final public function Parse()
    {
        $parsed_code = $this->ParseFile($this->m_file);
        if ($parsed_code) {
            ob_start();
            eval(sprintf(" %s%s%s ", self::$s_php_close_tag, $parsed_code, self::$s_php_open_tag));
            $interpreted_output = ob_get_contents();
            ob_end_clean();
            return $interpreted_output;
        }
        return NULL;
    }

    private function ParseCode($code)
    {
        $code = preg_replace_callback('@<if\s+var=(\"{.*\}")\s+(eq|neq|is)=(".*"|\{.*\}|\d*|\d+\.\d+)>@misU', function ($m) {
            return $this->WrapCode($this->ParseIf($m[1], $m[2], $m[3]));
        }, $code);
        $code = preg_replace_callback('@<elseif\s+var=("\{.*\}")\s+(eq|neq|is)=(".*"|\{.*\}|\d*|\d+\.\d+)>@misU', function ($m) {
            return $this->WrapCode(sprintf("%s%s", "else", $this->ParseIf($m[1], $m[2], $m[3])));
        }, $code);
        $code = preg_replace_callback('@<else>@mis', function ($m) {
            return $this->WrapCode("else {");
        }, $code);
        $code = preg_replace_callback('@(</if>|</elseif>|</else>|</foreach>|</loop>)@mis', function ($m) {
            return $this->WrapCode("}");
        }, $code);
        $code = preg_replace_callback('@<dump\s+var="(\{.*\})">@misU', function ($m) {
            return $this->WrapCode($this->ParseDump($m[1]));
        }, $code);
        $code = preg_replace_callback('@<foreach\s+var="(\{.*\})">@misU', function ($m) {
            return $this->WrapCode($this->ParseForEach($m[1]));
        }, $code);
        $code = preg_replace_callback('@<eval>(.*)</eval>@misU', function ($m) {
            return $this->WrapCode($this->ParseVariables($m[1]));
        }, $code);
        $code = preg_replace_callback('@<set\s+name=(".*"|\{.*\})\s+value=(".*"|\{.*\}|\d*|\d+\.\d+)\s*/?>@misU', function ($m) {
            return $this->WrapCode(sprintf('$this->__set(%s, %s);', $this->ParseVariables($m[1]), $this->ParseVariables($m[2])));
        }, $code);
        $code = preg_replace_callback('@<unset\s+name=(".*"|\{.*\})\s*/?>@misU', function ($m) {
            return $this->WrapCode(sprintf('$this->__unset(%s);', $this->ParseVariables($m[1])));
        }, $code);
        $code = preg_replace_callback('@<loop\s+count=("\d*"|\{.*\}|\d*)>@misU', function ($m) {
            return $this->WrapCode($this->ParseLoop($m[1]));
        }, $code);
        $code = preg_replace_callback('@<include\s+file=(".*"|\{.*\})\s*/?>\s*</include>@misU', function ($m) {
            return $this->ParseFile(trim($this->ParseVariables($m[1]), '"'));
        }, $code);
        $code = preg_replace_callback('@\?>\s*<\?php@mis', function ($m) {
            return '';
        }, $code);
        return $code;
    }

    private function ParseFile($filename)
    {
        $file = sprintf("%s%s", self::$s_options['root'], $filename);

        if (!file_exists($file)) {
            $this->__error("No such file '%s'", $file);
        } else {
            $cache_file = NULL;

            if (self::$s_options['cache']) {
                $cache_directory = sprintf("%s%s", self::$s_options['cache.root'], self::$s_options['cache.name']);

                if (!is_dir($cache_directory)) {
                    if (!mkdir($cache_directory, 0777)) {
                        return $this->__error("Unable to create directory '%s'", $cache_directory);
                    }
                } else if (!is_writable($cache_directory)) {
                    if (!chmod($cache_directory, 0777)) {
                        return $this->__error("Unable to write to directory '%s'", $cache_directory);
                    }
                }

                $cache_file = sprintf("%s/%s.xtc", $cache_directory, md5($file));
                if (file_exists($cache_file)) {
                    if (filemtime($cache_file) >= filemtime($file)) {
                        return base64_decode(file_get_contents($cache_file));
                    } else {
                        if (!is_writable($cache_file)) {
                            if (!chmod($cache_file, 0777)) {
                                return $this->__error("Unable to write to file '%s'", $cache_file);
                            }
                        }
                    }
                }
            }

            $code = file_get_contents($file);
            $code = $this->ParseCode($code);
            $parsed_code = $this->ParseVariables($code, true);

            if ($cache_file) {
                file_put_contents($cache_file, base64_encode($parsed_code));
            }
            return $parsed_code;
        }
        return NULL;
    }

    private function ParseDump($variable)
    {
        $variable = $this->ParseVariables($variable);
        return sprintf('print_r(%s)', $variable);
    }

    private function ParseForEach($variable)
    {
        $variable = $this->ParseVariables($variable);
        // We tap directly into the variable container for maximum efficiency
        return sprintf('foreach (%s as $this->m_variables[\'_KEY\'] => &$this->m_variables[\'_VALUE\']) {', $variable);
    }

    private function ParseIf($variable, $modifier, $value)
    {
        $modifiers = array('EQ' => "==", 'NEQ' => "!=",
            'LT' => "<", 'LTE' => "<=",
            'GT' => ">", 'GTE' => ">=");

        $variable = $this->ParseVariables($variable);

        if (!strcasecmp($modifier, "IS")) {
            $values = array('ARRAY' => "is_array",
                'STRING' => "is_string",
                'NUMERIC' => "is_numeric",
                'EMPTY' => "empty",
                'SET' => "isset",
                'FLOAT' => "is_float",
                'INT' => "is_int", 'LONG' => "is_long",
                'ODD' => '$this->IsOdd', 'EVEN' => '!$this->IsOdd',
                'TRUE' => '$this->IsTrue', 'FALSE' => '!$this->IsTrue');

            $value = trim(strtoupper($value), '"');
            if (!array_key_exists($value, $values)) {
                $this->__error("No such modifier value '%s'", $value);
            } else {
                $operator = $variable;
                $variable = sprintf("%s%s", $values[$value], "(");
                $value = ")";
            }
        } else {
            $modifier = strtoupper($modifier);
            $operator = $modifiers[$modifier];
        }
        return sprintf("if (%s%s%s) {", $variable, $operator, $value);
    }

    private function ParseLoop($count)
    {
        $count = trim($this->ParseVariables($count), '"');
        // We tap directly into the variable container for maximum efficiency
        return sprintf('for ($this->m_variables[\'_I\'] = 0; $this->m_variables[\'_I\'] < %s; $this->m_variables[\'_I\']++) {', $count);
    }

    private $wrap;

    private function ParseVariables($code, $wrap = false)
    {
        $this->wrap = $wrap;
        $code = preg_replace_callback('@\{(\S+?)\!\}@mis', function ($m) {
            return $this->WrapEchoCode($this->ParseVariables($m[1]), $this->wrap);
        }, $code);
        $code = preg_replace_callback('@\{(\w+?)(\[[^\]]+?\])+\@\}@mis', function ($m) {
            return $this->WrapEchoCode(sprintf('$this->__sizeof($this->%s%s)', $m[1], $this->ParseVariables($m[2])), $this->wrap);
        }, $code);
        $code = preg_replace_callback('@\{(\w+?)\@\}@mis', function ($m) {
            return $this->WrapEchoCode(sprintf('$this->__sizeof($this->%s)', $m[1]), $this->wrap);
        }, $code);
        $code = preg_replace_callback('@\{(\w+?)(\[[^\]]+?\])+\}@mis', function ($m) {
            return $this->WrapEchoCode(sprintf('$this->%s%s', $m[1], $this->ParseVariables($m[2])), $this->wrap);
        }, $code);
        $code = preg_replace_callback('@\{(\w+?)\}@mis', function ($m) {
            return $this->WrapEchoCode(sprintf('$this->%s', $m[1]), $this->wrap);
        }, $code);

        return $code;
    }

    final static public function SetOption($option, $value)
    {
        $option = strtolower($option);
        if (array_key_exists($option, self::$s_options)) {
            $option_value = self::$s_options[$option];

            // Make sure provided value corresponds with option
            if ((is_bool($option_value) && !is_bool($value)) ||
                (is_long($option_value) && !is_long($value)) ||
                (is_string($option_value) && !is_string($value))
            ) {
                return self::__error("Illegal option value");
            }
            self::$s_options[$option] = $value;
        } else {
            return self::__error("No such option '%s'", $option);
        }
        return true;
    }

    private function WrapCode($code)
    {
        return sprintf("%s %s %s", self::$s_php_open_tag, $code, self::$s_php_close_tag);
    }

    private function WrapEchoCode($code, $wrap)
    {
        if ($wrap) {
            return sprintf("%s %s %s%s %s", self::$s_php_open_tag, "echo", $code, ";", self::$s_php_close_tag);
        }
        return $code;
    }

    final static private function __error()
    {
        if (self::$s_options['error']) {
            $debug = debug_backtrace();

            if ($debug && isset($debug[1])) {
                if (func_num_args()) {
                    $args = func_get_args();
                    $format = array_shift($args);

                    $func_args = "";
                    $arg_count = count($debug[1]['args']);

                    for ($i = 0; $i < $arg_count; $i++) {
                        $func_args .= var_export($debug[1]['args'][$i], true);
                        if ($i != ($arg_count - 1)) {
                            $func_args .= "<b>,</b> ";
                        }
                    }

                    printf("<div style='background: #fff0cb; padding: 5px 5px 5px 10px; border: 1px solid #b50600; color: #680300; font-family: Verdana; font-size: 11px'><strong>%s</strong>::%s<strong>(</strong>%s<strong>):</strong> %s</div>", $debug[1]['class'], $debug[1]['function'], $func_args, vsprintf($format, $args));

                    if (self::$s_options['error.exit']) {
                        exit;
                    }
                }
            }
        }
        return false;
    }

    final public function __find($name)
    {
        if (array_key_exists($name, $this->m_variables)) {
            return $this;
        } else if ($this->m_parent) {
            return $this->m_parent->__find($name);
        }
        return NULL;
    }

    final public function &__get($name)
    {
        $null = null;
        $name = strtoupper($name);
        if (array_key_exists($name, $this->m_variables)) {
            return $this->m_variables[$name];
        } else if ($this->m_parent) {
            return $this->m_parent->__get($name);
        } else if (array_key_exists($name, self::$m_static_variables)) {
            return self::$m_static_variables[$name];
        }
        return $null;
    }

    final public function __isset($name)
    {
        $name = strtoupper($name);
        return (isset($this->m_variables[$name]) || array_key_exists($name, self::$m_static_variables));
    }

    final public function __set($name, $value)
    {
        $name = strtoupper($name);
        if ($value instanceof Element) {
            $value->SetParent($this);
        }
        if ($this->m_parent) {
            $owner = $this->m_parent->__find($name);
            if ($owner) {
                $owner->__set_explicit($name, $value);
                return;
            }
        }
        $this->__set_explicit($name, $value);
    }

    final public function __set_explicit($name, $value)
    {
        $this->m_variables[$name] = $value;
    }

    final private function __sizeof($value)
    {
        if (is_array($value)) {
            return count($value);
        } else if (is_string($value)) {
            return strlen($value);
        }
        return 0;
    }

    final public function __unset($name)
    {
        $name = strtoupper($name);
        unset($this->m_variables[$name]);
    }

    public static function Set($name, $value)
    {
        $name = strtoupper($name);
        self::$m_static_variables[$name] = $value;
    }

    public function Display()
    {
        echo $this->Parse();
    }

    public function SetParent(Element $parent)
    {
        $this->m_parent = $parent;
    }

    public function __toString()
    {
        return $this->Parse();
    }
}

?>