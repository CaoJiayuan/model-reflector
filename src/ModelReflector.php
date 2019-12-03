<?php

namespace Nerio\ModelReflector;

use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\PropertyInfo\Type;

/**
 * @author caojiayuan
 */
class ModelReflector
{

    protected $propertyInfo;

    private $original;

    public function __construct($data)
    {
        $phpDocExtractor = new PhpDocExtractor();
        $reflectionExtractor = new ReflectionExtractor();
        $listExtractors = [$reflectionExtractor];
        $typeExtractors = [$phpDocExtractor, $reflectionExtractor];
        $descriptionExtractors = [$phpDocExtractor];
        $accessExtractors = [$reflectionExtractor];
        $propertyInitializableExtractors = [$reflectionExtractor];
        $this->propertyInfo = new PropertyInfoExtractor(
            $listExtractors,
            $typeExtractors,
            $descriptionExtractors,
            $accessExtractors,
            $propertyInitializableExtractors
        );

        $this->loadFromArray($data);
    }

    public function loadFromArray($data)
    {
        $this->original = $data;

        foreach ($data as $key => $value) {
            $setMethod = 'set' . ucfirst($key);
            if (method_exists($this, $setMethod)) {
                $this->$setMethod($value);
            } else if (property_exists($this, $key)) {
                if (is_null($value)) {
                    $this->$key = null;
                } else {
                    /** @var Type[] $types */
                    $types = $this->propertyInfo->getTypes(static::class, $key);
                    if ($types) {
                        foreach ($types as $type) {
                            $builtinType = $type->getBuiltinType();
                            $class = $type->getClassName();
                            $collection = $type->isCollection();
                            if ($builtinType == 'object' && is_subclass_of($class,ModelReflector::class)) {
                                $this->validateReflectData($value, $class);
                                $this->$key = $class::make($value);
                            } elseif ($this->isCommonType($builtinType)) {
                                $this->$key = $this->castCommonType($builtinType, $value);
                            } elseif ($collection) {
                                $ct = $type->getCollectionValueType();
                                $itemClass = $ct->getClassName();
                                if (!is_subclass_of($itemClass,ModelReflector::class)) {
                                    throw new \InvalidArgumentException("Model property [{$key}] must an array of instance of ModelReflector");
                                }
                                foreach ($value as $k => $v) {
                                    $this->validateReflectData($value, $class);
                                    $this->$key[$k] = $itemClass::make($v);
                                }
                            } else {
                                $this->$key = $value;
                            }

                            break;
                        }
                    } else {
                        $this->$key = $value;
                    }
                }
            }
        }
    }

    protected function validateReflectData($value, $class)
    {
        if (!is_array($value)) {
            $type = gettype($value);
            throw new \InvalidArgumentException("Try to reflect [$type] to [$class]");
        }

        return true;
    }

    protected function castCommonType($type, $value)
    {
        switch ($type) {
            case 'int':
            case 'integer':
                return (int) $value;
            case 'real':
            case 'float':
            case 'double':
                return (float) $value;
            case 'string':
                return (string) $value;
            case 'bool':
            case 'boolean':
                return (bool) $value;
            case 'json':
                return json_decode($value, true);
            default:
                return $value;
        }
    }

    protected function isCommonType($type)
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

    public static function make($data)
    {
        return new static($data);
    }

    /**
     * @return mixed
     */
    public function getOriginal()
    {
        return $this->original;
    }
}