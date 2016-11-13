<?php

/**
 * Dotenv.
 *
 * Loads a `.env` file in the given directory and sets the environment vars.
 */
class Dotenv
{
    /**
     * If true, then environment variables will not be overwritten.
     *
     * @var bool
     */
    protected static $immutable = true;

    /**
     * 加载目录 $path 下的环境配置文件 $file（默认为根目录的 .env）
     *
     * @param string $path
     * @param string $file
     *
     * @return void
     */
    public static function load($path, $file = '.env')
    {
        if (!is_string($file)) {
            $file = '.env';
        }

        $filePath = rtrim($path, '/').'/'.$file;
        if (!is_readable($filePath) || !is_file($filePath)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Dotenv: Environment file %s not found or not readable. '.
                    'Create file with your environment settings at %s',
                    $file,
                    $filePath
                )
            );
        }

        // Read file into an array of lines with auto-detected line endings
        // 这里读取 auto_detect_line_endings 后保存在变量中是为了不确定原有
        // auto_detect_line_endings 值的情况时保证不改变它
        
        $autodetect = ini_get('auto_detect_line_endings');
        ini_set('auto_detect_line_endings', '1');
        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        // 读取配置文件的行以后将变量重新设置回去
        
        ini_set('auto_detect_line_endings', $autodetect);

        foreach ($lines as $line) {
            // Disregard comments
            // 忽略注释掉的行
            if (strpos(trim($line), '#') === 0) {
                continue;
            }
            // Only use non-empty lines that look like setters
            // 当行的内容格式是赋值表达式，例如 a = 1 时才处理
            if (strpos($line, '=') !== false) {
                static::setEnvironmentVariable($line);
            }
        }
    }

    /**
     * 通过或向 putenv(), $_ENV, $_SERVER 设置环境变量
     * The environment variable value is stripped of single and double quotes.
     *
     * @param string      $name
     * @param string|null $value
     *
     * @return void
     */
    public static function setEnvironmentVariable($name, $value = null)
    {
        // 分解出环境变量的名称（$name）和值（$value）
        // 并将它们消消毒（过滤，去除一些字符串等）后方可使用
        
        list($name, $value) = static::normaliseEnvironmentVariable($name, $value);

        // Don't overwrite existing environment variables if we're immutable
        // Ruby's dotenv does this with `ENV[key] ||= value`.
        // 如果设置了环境变量不可变，而且该环境变量已存在于配置中，则无法设置并直接返回
        
        if (static::$immutable === true && !is_null(static::findEnvironmentVariable($name))) {
            return;
        }
        
        // 设置环境变量
        
        putenv("$name=$value");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    /**
     * Require specified ENV vars to be present, or throw an exception.
     *
     * You can also pass through an set of allowed values for the environment variable.
     *
     * @param mixed    $environmentVariables
     * @param string[] $allowedValues
     *
     * @throws \RuntimeException
     *
     * @return true
     */
    public static function required($environmentVariables, array $allowedValues = array())
    {
        $environmentVariables = (array) $environmentVariables;
        $missingEnvironmentVariables = array();

        foreach ($environmentVariables as $environmentVariable) {
            $value = static::findEnvironmentVariable($environmentVariable);
            if (is_null($value)) {
                $missingEnvironmentVariables[] = $environmentVariable;
            } elseif ($allowedValues) {
                if (!in_array($value, $allowedValues)) {
                    // may differentiate in the future, but for now this does the job
                    $missingEnvironmentVariables[] = $environmentVariable;
                }
            }
        }

        if ($missingEnvironmentVariables) {
            throw new \RuntimeException(
                sprintf(
                    "Required environment variable missing, or value not allowed: '%s'",
                    implode("', '", $missingEnvironmentVariables)
                )
            );
        }

        return true;
    }

    /**
     * Takes value as passed in by developer.
     *
     * We're also:
     * - ensuring we're dealing with a separate name and value, breaking apart the name string if needed
     * - cleaning the value of quotes
     * - cleaning the name of quotes
     * - resolving nested variables
     *
     * @param string $name
     * @param string $value
     *
     * @return array
     */
    protected static function normaliseEnvironmentVariable($name, $value)
    {
        list($name, $value) = static::splitCompoundStringIntoParts($name, $value);
        $name = static::sanitiseVariableName($name);
        $value = static::sanitiseVariableValue($value);
        $value = static::resolveNestedVariables($value);

        return array($name, $value);
    }

    /**
     * 如果 $name 包含字符 =，则分割 $name 为2部分，每部分使用 trim() 函数去除左右空白<br>
     * 第一部分为 $name，第二部分为$ value<br>
     * 返回格式：array($name, $value)
     *
     * @param string $name
     * @param string $value
     *
     * @return array
     */
    protected static function splitCompoundStringIntoParts($name, $value)
    {
        if (strpos($name, '=') !== false) {
            list($name, $value) = array_map('trim', explode('=', $name, 2));
        }

        return array($name, $value);
    }

    /**
     * Strips quotes from the environment variable value.
     *
     * @param string $value
     *
     * @return string
     */
    protected static function sanitiseVariableValue($value)
    {
        $value = trim($value);
        if (!$value) {
            return '';
        }
        if (strpbrk($value[0], '"\'') !== false) { // value starts with a quote
            $quote = $value[0];
            $regexPattern = sprintf('/^
                %1$s          # match a quote at the start of the value
                (             # capturing sub-pattern used
                 (?:          # we do not need to capture this
                  [^%1$s\\\\] # any character other than a quote or backslash
                  |\\\\\\\\   # or two backslashes together
                  |\\\\%1$s   # or an escaped quote e.g \"
                 )*           # as many characters that match the previous rules
                )             # end of the capturing sub-pattern
                %1$s          # and the closing quote
                .*$           # and discard any string after the closing quote
                /mx', $quote);
            $value = preg_replace($regexPattern, '$1', $value);
            $value = str_replace("\\$quote", $quote, $value);
            $value = str_replace('\\\\', '\\', $value);
        } else {
            $parts = explode(' #', $value, 2);
            $value = $parts[0];
        }

        return trim($value);
    }

    /**
     * 去除环境变量名 $name 中的引号以及 "export "
     * 
     * @param string $name
     *
     * @return string
     */
    protected static function sanitiseVariableName($name)
    {
        return trim(str_replace(array('export ', '\'', '"'), '', $name));
    }

    /**
     * Look for {$varname} patterns in the variable value.
     *
     * Replace with an existing environment variable.
     *
     * @param string $value
     *
     * @return mixed
     */
    protected static function resolveNestedVariables($value)
    {
        if (strpos($value, '$') !== false) {
            $value = preg_replace_callback(
                '/{\$([a-zA-Z0-9_]+)}/',
                function ($matchedPatterns) {
                    $nestedVariable = Dotenv::findEnvironmentVariable($matchedPatterns[1]);
                    if (is_null($nestedVariable)) {
                        return $matchedPatterns[0];
                    } else {
                        return  $nestedVariable;
                    }
                },
                $value
            );
        }

        return $value;
    }

    /**
     * 按照顺序从或通过 $_ENV, $_SERVER, getenv() 寻找环境变量 $name<br>
     * 找到即返回，不再继续寻找
     * 
     * @param string $name
     *
     * @return string
     */
    public static function findEnvironmentVariable($name)
    {
        switch (true) {
            case array_key_exists($name, $_ENV):
                return $_ENV[$name];
            case array_key_exists($name, $_SERVER):
                return $_SERVER[$name];
            default:
                $value = getenv($name);
                return $value === false ? null : $value; // switch getenv default to null
        }
    }

    /**
     * 判断环境变量是否可改变
     *
     * @return bool
     */
    public static function isImmutable()
    {
        return static::$immutable;
    }

    /**
     * 设置已经设置的环境变量不可改变<br>
     * “已经设置的”不再此函数中体现，而是调用此函数的地方体现
     *
     * @return void
     */
    public static function makeImmutable()
    {
        static::$immutable = true;
    }

    /**
     * 设置环境变量可以改变
     *
     * @return void
     */
    public static function makeMutable()
    {
        static::$immutable = false;
    }
}
