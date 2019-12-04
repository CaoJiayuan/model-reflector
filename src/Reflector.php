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
                    /** @var Type[] $types */
                    $types = self::getPropertyInfo()->getTypes($clz, $key);
                    if ($types) {
                        foreach ($types as $type) {
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

                            break;
                        }
                    } else {
                        $instance->$key = $value;
                    }
                }
            }
        }

        return $instance;
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

    public static function getCacheDir($key = '')
    {
        if (!self::$cacheDir) {
            self::$cacheDir =  __DIR__ . '/../_cache';
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

    protected static function getTypeFromCache($class, $prop)
    {
        $types = self::getPropertyInfo()->getTypes($class, $prop);
        $cacheKey = md5($class);

        if ($types) {

            $cacheData = self::makeCacheData($class, $prop, $types[0]);
        }


        return null;
    }

    protected static function getCacheData($class, $prop)
    {
        $cacheKey = md5($class);

        $path = self::getCacheDir($cacheKey);
        if (file_exists($path)) {
            $data = json_decode(file_get_contents($path), true);
            if ($data && array_key_exists($prop, $data)) {

            }

        }

        return null;
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

}