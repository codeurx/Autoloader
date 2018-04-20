<?php
namespace Codeurx\Autoloader;

use Exception;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class Autoloader
{
    const CLASS_NOT_FOUND = 0;
    const CLASS_EXISTS    = 1;
    const CLASS_IS_NULL   = 2;
    const SCAN_NEVER   = 0; 
    const SCAN_ONCE    = 1; 
    const SCAN_ALWAYS  = 3; 
    const SCAN_CACHE   = 4; 

    private $_folders = array();

    private $_excludedFolders = array();

    private $_classes = array();

    private $_filesRegex = '/\.(inc|php)$/';

    private $_saveFile = null;

    private $_scanOptions = self::SCAN_ONCE;

    public function __construct($saveFile = null, $scanOptions = self::SCAN_ONCE)
    {
        if (!defined('T_NAMESPACE'))
        {
            define('T_NAMESPACE', -1);
            define('T_NS_SEPARATOR', -1);
        }
        if (!defined('T_TRAIT'))
        {
            define('T_TRAIT', -1);
        }
        $this->setSaveFile($saveFile);
        $this->setScanOptions($scanOptions);
    }

    public function getSaveFile()
    {
        return $this->_saveFile;
    }

    public function setSaveFile($pathToFile)
    {
        $this->_saveFile = $pathToFile;
        if ($this->_saveFile && file_exists($this->_saveFile))
        {
            $this->_classes = include($this->_saveFile);
        }
    }

    public function setFileRegex($regex)
    {
        $this->_filesRegex = $regex;
    }
    
    public function setAllowedFileExtensions($extensions)
    {
        $regex = '/\.';
        if (is_array($extensions))
        {
            $regex .= '(' . implode('|', $extensions) . ')';
        }
        else {
            $regex .= $extensions;
        }
        
        $this->_filesRegex = $regex . '$/';
    }

    public function addFolder($path)
    {
        if ($realpath = realpath($path) and is_dir($realpath))
        {
            $this->_folders[] = $realpath;
        }
        else
        {
            throw new Exception('Failed to open dir : ' . $path);
        }
    }

    public function excludeFolder($path)
    {
        if ($realpath = realpath($path) and is_dir($realpath))
        {
            $this->_excludedFolders[] = $realpath . DIRECTORY_SEPARATOR;
        }
        else
        {
            throw new Exception('Failed to open dir : ' . $path);
        }
    }

    public function classExists($className)
    {
        return self::CLASS_EXISTS === $this->checkClass($className, $this->_classes);
    }

    public function setScanOptions($options)
    {
        $this->_scanOptions = $options;
    }

    public function getScanOptions()
    {
        return $this->_scanOptions;
    }

    public function loadClass($className)
    {
        $className = strtolower($className);
        $loaded = $this->checkClass($className, $this->_classes);
        if (!$loaded && (self::SCAN_ONCE & $this->_scanOptions))
        {
            $this->refresh();
            $loaded = $this->checkClass($className, $this->_classes);
            if (!$loaded && (self::SCAN_CACHE & $this->_scanOptions))
            {
                $this->_classes[$className] = null;
            }

            if ($this->getSaveFile())
            {
                $this->saveToFile($this->_classes);
            }

            if (!($this->_scanOptions & 2))
            {
                $this->_scanOptions = $this->_scanOptions & ~ self::SCAN_ONCE;
            }
        }
    }

    private function checkClass($className, array $classes)
    {
        if (isset($classes[$className]))
        {
            require $classes[$className];
            return self::CLASS_EXISTS;
        }
        elseif (array_key_exists($className, $classes))
        {
            return self::CLASS_IS_NULL;
        }
        return self::CLASS_NOT_FOUND;
    }

    private function parseFolders()
    {
        $classesArray = array();
        foreach ($this->_folders as $folder)
        {
            $classesArray = array_merge($classesArray, $this->parseFolder($folder));
        }
        return $classesArray;
    }

    private function parseFolder($folder)
    {
        $classes = array();
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder));

        foreach ($files as $file)
        {
            if ($file->isFile() && preg_match($this->_filesRegex, $file->getFilename()))
            {
                foreach ($this->_excludedFolders as $folder)
                {
                    $len = strlen($folder);
                    if (0 === strncmp($folder, $file->getPathname(), $len))
                    {
                        continue 2;
                    }
                }

                if ($classNames = $this->getClassesFromFile($file->getPathname()))
                {
                    foreach ($classNames as $className)
                    {
                        $classes[$className] = $file->getPathname();
                    }
                }
            }
        }
        return $classes;
    }

    private function getClassesFromFile($file)
    {
        $namespace = null;
        $classes = array();
        $tokens = token_get_all(file_get_contents($file));
        $nbtokens = count($tokens);

        for ($i = 0 ; $i < $nbtokens ; $i++)
        {
            switch ($tokens[$i][0])
            {
                case T_NAMESPACE:
                    $namespace = null;
                    $i+=2;
                    while ($tokens[$i][0] === T_STRING || $tokens[$i][0] === T_NS_SEPARATOR)
                    {
                        $namespace .= $tokens[$i++][1];
                    }
                    break;
                case T_INTERFACE:
                case T_CLASS:
                case T_TRAIT:
                    if(($tokens[$i][0] === T_CLASS) && $tokens[$i-1][0] === T_DOUBLE_COLON) 
                    {
                        continue(2);
                    }

                    $i+=2;
                    if ($namespace)
                    {
                        $classes[] = strtolower($namespace . '\\' . $tokens[$i][1]);
                    }
                    else
                    {
                        $classes[] = strtolower($tokens[$i][1]);
                    }
                    break;
            }
        }

        return $classes;
    }

    private function saveToFile(array $classes)
    {
        $content  = '<?php ' . PHP_EOL;
        $content .= '/** ' . PHP_EOL;
        $content .= ' * Autoloader Script' . PHP_EOL;
        $content .= ' * ' . PHP_EOL;
        $content .= ' * @authors      Amir Ali Salah' . PHP_EOL;
        $content .= ' * ' . PHP_EOL;
        $content .= ' * @description This file was automatically generated at ' . date('Y-m-d [H:i:s]') . PHP_EOL;
        $content .= ' * ' . PHP_EOL;
        $content .= ' */ ' . PHP_EOL;

        $content .= 'return ' . var_export($classes, true) . ';';
        file_put_contents($this->getSaveFile(), $content);
    }

    public function getRegisteredClasses()
    {
        return $this->_classes;
    }

    public function refresh()
    {
        $existantClasses = $this->_classes;
        $nullClasses = array_filter($existantClasses, array('self','_getNullElements'));
        $newClasses = $this->parseFolders();

        $this->_classes = array_merge($nullClasses, $newClasses);
        return true;
    }

    public function generate()
    {
        if ($this->getSaveFile())
        {
            $this->refresh();
            $this->saveToFile($this->_classes);
        }
    }

    private function _getNullElements($element)
    {
        return null === $element;
    }

    public function register()
    {
        spl_autoload_register(array($this, 'loadClass'));
    }

    public function unregister()
    {
        spl_autoload_unregister(array($this, 'loadClass'));
    }
}
