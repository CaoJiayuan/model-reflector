<?php namespace Nerio\ModelReflector;

use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\PropertyInfo\Type;

/**
 * @author caojiayuan
 */
class Reflector
{
    protected static $propInfo;

    protected static $cacheDir;

    protected static $cacheClasses = [];


    /**
     * [
     *      'App\\' => '/home/www/project/app'
     * ]
     *
     * @param $psr4Dirs
     * @param \Closure|null $afterEach
     */
    public static function makeCaches($psr4Dirs, \Closure $afterEach = null)
    {
        foreach ($psr4Dirs as $prefix => $dir) {
            \CaoJiayuan\Utility\file_map($dir, function ($path, \SplFileInfo $fileInfo, $isDir) use ($dir, $prefix, $afterEach) {
                if (!$isDir) {
                    $class = $prefix . substr(str_replace([$dir, '/'], ['', '\\'], $path), 0, -4);
                    $props = self::getPropertyInfo()->getProperties($class);
                    $cacheKey = md5($class);
                    $path = self::getCacheDir($cacheKey);
                    $data = [];
                    foreach ($props as $prop) {
                        $types = self::getPropertyInfo()->getTypes($class, $prop);
                        if ($types) {
                            $t = $types[0];
                        } else {
                            $t = self::makeDefaultType();
                        }
                        $data[$prop] = self::makeCacheData($class, $prop, $t);
                    }
                    file_put_contents($path, json_encode($data));
                    $afterEach && $afterEach($class);
                }
            });
        }
    }

    public static function getPropertyInfo()
    {
        if (!is_null(self::$propInfo)) {
            return self::$propInfo;
        }

        $phpDocExtractor = new PhpDocExtractor();
        $reflectionExtractor = new ReflectionExtractor();
        $listExtractors = [$reflectionExtractor];
        $typeExtractors = [$phpDocExtractor, $reflectionExtractor];
        $descriptionExtractors = [$phpDocExtractor];
        $accessExtractors = [$reflectionExtractor];
        $propertyInitializableExtractors = [$reflectionExtractor];
        return self::$propInfo = new PropertyInfoExtractor(
            $listExtractors,
            $typeExtractors,
            $descriptionExtractors,
            $accessExtractors,
            $propertyInitializableExtractors
        );
    }

    public static function getCacheDir($key = '')
    {
        if (!self::$cacheDir) {
            self::$cacheDir = __DIR__ . '/../_cache';
        }
        if (!file_exists(self::$cacheDir)) {
            mkdir(self::$cacheDir);
        }

        return self::$cacheDir . DIRECTORY_SEPARATOR . $key;
    }

    /**
     * @param mixed $cacheDir
     */
    public static function setCacheDir($cacheDir)
    {
        self::$cacheDir = $cacheDir;
    }

    protected static function makeDefaultType()
    {
        return new Type(Type::BUILTIN_TYPE_STRING, true);
    }

    protected static function makeCacheData($class, $prop, Type $type)
    {
        $collectionKeyType = $type->getCollectionKeyType();
        $collectionValueType = $type->getCollectionValueType();

        return [
            'builtinType'         => $type->getBuiltinType(),
            'nullable'            => $type->isNullable(),
            'class'               => $type->getClassName(),
            'collection'          => $type->isCollection(),
            'collectionKeyType'   => $collectionKeyType ? self::makeCacheData($class, $prop, $collectionKeyType) : null,
            'collectionValueType' => $collectionValueType ? self::makeCacheData($class, $prop, $collectionValueType) : null,
        ];
    }

    public static function clearCaches()
    {
        @unlink(self::getCacheDir());
    }

    public static function loadFromArray($clz, $data)
    {
        if (is_object($clz)) {
            $instance = $clz;
        } else {
            $instance = new $clz;
        }

        foreach ($data as $key => $value) {
            $setMethod = 'set' . ucfirst($key);
            if (method_exists($instance, $setMethod)) {
                $instance->$setMethod($value);
            } else if (property_exists($instance, $key)) {
                if (is_null($value)) {
                    $instance->$key = null;
                } else {
                    $type = self::getTypeFromCache($clz, $key);
                    if ($type) {
                        $builtinType = $type->getBuiltinType();
                        $class = $type->getClassName();
                        $collection = $type->isCollection();
                        if ($builtinType == 'object' && is_subclass_of($class, ModelReflector::class)) {
                            self::validateReflectData($value, $class);
                            $instance->$key = self::loadFromArray($class, $value);
                        } elseif (self::isCommonType($builtinType)) {
                            $instance->$key = self::castCommonType($builtinType, $value);
                        } elseif ($collection) {
                            $ct = $type->getCollectionValueType();
                            $itemClass = $ct->getClassName();
                            if (!is_subclass_of($itemClass, ModelReflector::class)) {
                                throw new \InvalidArgumentException("Model property [{$key}] must an array of instance of ModelReflector");
                            }
                            foreach ($value as $k => $v) {
                                self::validateReflectData($value, $itemClass);
                                $instance->$key[$k] = self::loadFromArray($itemClass, $v);
                            }
                        } else {
                            $instance->$key = $value;
                        }
                    } else {
                        $instance->$key = $value;
                    }
                }
            }
        }

        return $instance;
    }

    protected static function getTypeFromCache($class, $prop)
    {
        if ($type = self::getCacheType($class, $prop)) {
            return $type;
        }

        $types = self::getPropertyInfo()->getTypes($class, $prop);

        if ($types) {
            $t = $types[0];
        } else {
            $t = self::makeDefaultType();
        }

        return self::putCacheType($class, $prop, $t);
    }

    protected static function getCacheType($class, $prop)
    {
        $data = null;
        if (array_key_exists($class, self::$cacheClasses)) {
            $data = self::$cacheClasses[$class];
        } else {
            $cacheKey = md5($class);
            $path = self::getCacheDir($cacheKey);
            if (file_exists($path)) {
                $data = json_decode(file_get_contents($path), true);
                $data && self::$cacheClasses[$class] = $data;
            }
        }

        if ($data && array_key_exists($prop, $data)) {
            return self::makeTypeFromCacheData($data[$prop]);
        }

        return null;
    }

    protected static function makeTypeFromCacheData($data)
    {
        if (is_null($data)) {
            return null;
        }

        return new Type($data['builtinType'], $data['nullable'], $data['class'],
            $data['collection'], self::makeTypeFromCacheData($data['collectionKeyType']), self::makeTypeFromCacheData($data['collectionValueType']));
    }

    protected static function putCacheType($class, $prop, Type $type)
    {
        $data = [];
        $cacheKey = md5($class);
        $path = self::getCacheDir($cacheKey);

        if (array_key_exists($class, self::$cacheClasses)) {
            $data = self::$cacheClasses[$class];
        } else {
            if (file_exists($path)) {
                $data = json_decode(file_get_contents($path), true);
                $data && self::$cacheClasses[$class] = $data;
            }
        }

        $data[$prop] = self::makeCacheData($class, $prop, $type);

        file_put_contents($path, json_encode($data));

        return $type;
    }

    protected static function validateReflectData($value, $class)
    {
        if (!is_array($value)) {
            $type = gettype($value);
            throw new \InvalidArgumentException("Try to reflect [$type] to [$class]");
        }

        return true;
    }

    protected static function isCommonType($type)
    {
        return in_array($type, [
            'int',
            'integer',
            'float',
            'double',
            'real',
            'bool',
            'boolean',
            'date',
            'datetime',
            'timestamp',
            'json',
            'string'
        ]);
    }

    protected static function castCommonType($type, $value)
    {
        switch ($type) {
            case 'int':
            case 'integer':
                return (int)$value;
            case 'real':
            case 'float':
            case 'double':
                return (float)$value;
            case 'string':
                return (string)$value;
            case 'bool':
            case 'boolean':
                return (bool)$value;
            case 'json':
                return json_decode($value, true);
            default:
                return $value;
        }
    }

}