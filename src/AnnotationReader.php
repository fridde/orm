<?php

namespace Fridde;

use Doctrine\Common\Annotations\AnnotationRegistry;
use Doctrine\Common\Annotations\SimpleAnnotationReader;
use Doctrine\ORM\Configuration;

class AnnotationReader extends SimpleAnnotationReader
{
    /* @var array $annotations */
    private $annotations;

    public const _CLASS = 0;
    public const _PROPERTY = 1;
    public const _METHOD = 2;

    private const TYPE_REFLECTION = 0;
    private const TYPE_READER = 1;


    public function registerCustomAnnotations(string $dir = null, string $file_name = 'CustomAnnotations.php'): void
    {
        $dir = $dir ?? BASE_DIR.'/src/Annotations';
        AnnotationRegistry::registerFile($dir.'/'.$file_name);
    }

    public function registerDoctrineAnnotations(): void
    {
        $rc = new \ReflectionClass(Configuration::class);
        $dir = dirname($rc->getFileName());
        AnnotationRegistry::registerFile($dir.'/Mapping/Driver/DoctrineAnnotations.php');
    }

    public function getAnnotationForProperty(string $class, string $property, string $annotation_name)
    {
        $this->setPropertyAnnotationsForClass($class);

        return $this->annotations[$class][self::_PROPERTY][$property][$annotation_name] ?? null;
    }

    public function hasPropertyAnnotation(string $class, string $property, string $annotation_name): bool
    {
        return !empty($this->getAnnotationForProperty($class, $property, $annotation_name));
    }

    private function setPropertyAnnotationsForClass(string $class_name): void
    {
        $this->setAnyAnnotationsForClass($class_name, self::_PROPERTY);
    }

    public function getAnnotationForMethod(string $class, string $method, string $annotation_name)
    {
        $this->setMethodAnnotationsForClass($class);

        return $this->annotations[$class][self::_METHOD][$method][$annotation_name] ?? null;
    }

    public function hasMethodAnnotation(string $class, string $method, string $annotation_name): bool
    {
        return !empty($this->getAnnotationForMethod($class, $method, $annotation_name));
    }

    private function setMethodAnnotationsForClass(string $class_name): void
    {
        $this->setAnyAnnotationsForClass($class_name, self::_METHOD);
    }

    private function setClassAnnotations(string $class_name): void
    {
        $this->setAnyAnnotationsForClass($class_name, self::_CLASS);
    }

    public function getAnnotationForClass(string $class, string $annotation_name)
    {
        $this->setClassAnnotations($class);

        return $this->annotations[$class][self::_CLASS][$class][$annotation_name] ?? null;
    }

    private function setAnyAnnotationsForClass(string $class_name, int $element_type)
    {
        if (isset($this->annotations[$class_name][$element_type])) {
            return;
        }

        $reflection_class = new \ReflectionClass($class_name);
        $reflection_function = self::translateElementToFunction($element_type, self::TYPE_REFLECTION);
        $reader_function = self::translateElementToFunction($element_type, self::TYPE_READER);


        if (empty($reflection_function)) {
            $parts = [$reflection_class];
        } else {
            $parts = call_user_func([$reflection_class, $reflection_function]);
        }

        foreach ($parts as $part) {
            $annotations = call_user_func(['parent', $reader_function], $part);
            foreach ($annotations as $annot) {
                $name = $part->getName();
                $this->annotations[$class_name][$element_type][$name][get_class($annot)] = $annot;
            }
        }
    }

    public function getPropertyAnnotation(\ReflectionProperty $property, $annotationName)
    {
        $class = $property->getDeclaringClass()->getName();
        $property_name = $property->getName();

        return $this->getAnnotationForProperty($class, $property_name, $annotationName);
    }

    public function getPropertyAnnotations(\ReflectionProperty $property)
    {
        $class_name = $property->getDeclaringClass()->getName();
        $property_name = $property->getName();

        $this->setPropertyAnnotationsForClass($class_name);

        return $this->annotations[$class_name][self::_PROPERTY][$property_name] ?? [];
    }

    private static function translateElementToFunction(int $element_type, int $class_type): string
    {
        $a = self::TYPE_REFLECTION;
        $b = self::TYPE_READER;

        $function_translator = [
            self::_CLASS => [$a => null, $b => 'getClassAnnotations'],
            self::_PROPERTY => [$a => 'getProperties', $b => 'getPropertyAnnotations'],
            self::_METHOD => [$a => 'getMethods', $b => 'getMethodAnnotations'],
        ];

        return $function_translator[$element_type][$class_type];
    }

}
