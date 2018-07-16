<?php

namespace Fridde;

use Doctrine\Common\Annotations\AnnotationRegistry;
use Doctrine\Common\Annotations\SimpleAnnotationReader;
use Doctrine\ORM\Configuration;

class AnnotationReader extends SimpleAnnotationReader
{
    /* @var array $annotations  */
    private $annotations;

    public const _CLASS = 0;
    public const _PROPERTY = 1;
    public const _METHOD = 2;


    public function registerCustomAnnotations(string $dir = null, string $file_name = 'CustomAnnotations.php'): void
    {
        $dir = $dir ?? BASE_DIR . '/src/Annotations';
        AnnotationRegistry::registerFile($dir . '/' . $file_name);
    }

    public function registerDoctrineAnnotations(): void
    {
        $rc = new \ReflectionClass(Configuration::class);
        $dir = dirname($rc->getFileName());
        AnnotationRegistry::registerFile($dir . '/Mapping/Driver/DoctrineAnnotations.php');
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
        $this->setAnnotationsForClass($class_name, self::_PROPERTY);
    }

    public function getAnnotationForMethod(string $class, string $method, string $annotation_name)
    {
        $this->setMethodAnnotationsForClass($class);

        return $this->annotations[$class][self::_METHOD][$method][$annotation_name] ?? null;
    }

    private function setMethodAnnotationsForClass(string $class_name): void
    {
        $this->setAnnotationsForClass($class_name, self::_METHOD);
    }

    private function setAnnotationsForClass(string $class_name, int $element_type)
    {
        if(isset($this->annotations[$class_name][$element_type])){
            return;
        }

        $reflection_class = new \ReflectionClass($class_name);
        $reflection_function = self::translateElementToFunction($element_type, 'reflection');
        $reader_function = self::translateElementToFunction($element_type, 'reader');

        $parts = call_user_func([$reflection_class, $reflection_function]);

        foreach($parts as $part){
            $annotations = call_user_func([$this, $reader_function], $part);
            foreach($annotations as $annot){
                $name = $part->getName();
                $this->annotations[$class_name][$element_type][$name][get_class($annot)] = $annot;
            }
        }
    }

    private static function translateElementToFunction(int $element_type, string $class_abbreviation = 'reflection')
    {
        $index = [
            'reflection' => 0,
            'reader' => 1
        ];
        $function_translator = [
            //self::_CLASS => ['newInstance', 'getClassAnnotations'],
            self::_PROPERTY => ['getProperties', 'getPropertyAnnotations'],
            self::_METHOD => ['getMethods', 'getMethodAnnotations']
        ];

        $index_nr = $index[$class_abbreviation];
        return $function_translator[$element_type][$index_nr];
    }

}
