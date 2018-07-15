<?php

namespace Fridde;

use Doctrine\Common\Annotations\AnnotationRegistry;
use Doctrine\Common\Annotations\CachedReader;
use Doctrine\Common\Annotations\SimpleAnnotationReader;
use Doctrine\Common\EventManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Mapping\Driver\AnnotationDriver;
use Doctrine\ORM\Tools\Setup;
use Doctrine\ORM\EntityManager;
use Doctrine\Common\Util\Debug;

class ORM
{
    public $paths_to_entities = [BASE_DIR . '/src/Entities/'];
    /* @var SimpleAnnotationReader $annotation_reader  */
    private $annotation_reader;
    /* @var array $entity_to_class_mapping  */
    private $entity_to_class_mapping;
    /* @var array $entity_column_data  */
    private $entity_column_data;
    /* @var ClassMetadata[] $entity_meta_data */
    private $entity_meta_data;
    /* @var EntityManager $EM  */
    public $EM;

    public function __construct(array $db_settings = null, array $orm_settings = null)
    {
        $db_settings = $db_settings ?? (SETTINGS['Connection_Details'] ?? []);
  
        if (empty($db_settings)) {
            throw new \Exception('No database settings found.');
        }
        $db_params = [
            'driver' => 'pdo_mysql',
            'host' => $db_settings['db_host'],
            'user' => $db_settings['db_username'],
            'password' => $db_settings['db_password'],
            'dbname' => $db_settings['db_name'],
            'charset' => 'utf8',
        ];
        
        
        $this->registerDoctrineAnnotations();
        $this->registerCustomAnnotations();
        //AnnotationRegistry::registerAutoloadNamespace('Fridde\Annotations', BASE_DIR . '/src');
        $this->annotation_reader = new SimpleAnnotationReader();
        $this->annotation_reader->addNamespace('Doctrine\ORM\Mapping');
        $this->annotation_reader->addNamespace('Fridde\Annotations');

        $mapping_driver = new AnnotationDriver($this->annotation_reader, $this->paths_to_entities);

        $is_dev_mode = $GLOBALS['debug'] ?? false;
        $config = Setup::createConfiguration($is_dev_mode);
        $config->setMetadataDriverImpl($mapping_driver);
        $config->setAutoGenerateProxyClasses(true);

        $this->EM = EntityManager::create($db_params, $config, new EventManager());
    }

    private function registerCustomAnnotations(string $dir = null)
    {
        $dir = $dir ?? BASE_DIR . '/src/Annotations';
        AnnotationRegistry::registerFile($dir . '/CustomAnnotations.php');
    }

    private function registerDoctrineAnnotations()
    {
        $rc = new \ReflectionClass(Configuration::class);
        $dir = dirname($rc->getFileName());
         AnnotationRegistry::registerFile($dir . '/Mapping/Driver/DoctrineAnnotations.php');
    }

    public function save($entity)
    {
        $this->EM->persist($entity);
        $this->EM->flush();
    }

    public function delete($entity)
    {
        $this->EM->remove($entity);
        $this->EM->flush();
    }

    public function getEM()
    {
        return $this->EM;
    }

    /**
     * @param string $entity_class *
     */
    public function getRepository(string $entity_class)
    {
        $entity_class = $this->qualifyEntityClassname($entity_class);

        return $this->EM->getRepository($entity_class);
    }




    public function find($entity_class, $id)
    {
        return $this->getRepository($entity_class)->find($id);
    }

    public function findBy(string $entity_class, array $criteria = []): array
    {
        return $this->getRepository($entity_class)->findBy($criteria);
    }

    public static function dump($var = null, $return = false)
    {
        if ($return) {
            return Debug::export($var, false);
        } 
        Debug::dump($var);
    }

    /**
     * @param string $entity_class
     * @param int|string $entity_id
     * @param string $property The name of the property that is supposed to be updated
     * @param mixed $value
	 * @throws ExceptionalException
     */
    public function updateProperty(string $entity_class, $entity_id, string $property, $value)
    {
        $entity = $this->getRepository($entity_class)->find($entity_id);
        if (empty($entity)) {
            throw new ExceptionalException(':no_entity:', [$entity_class, $entity_id]);
        }

        $setter = 'set'.$property;

        if (!method_exists($entity, $setter)) {
            throw new \Exception('The method "' .$setter.'" does not exist for the entity of class "'.$entity_class.'".');
        }
        $entity->$setter($value);
    }

    public function batchUpdateProperties(array $updates)
    {

        foreach ($updates as $update) {
            call_user_func_array([$this, 'updateProperty'], $update);
        }
    }

    public function createNewEntity(string $entity_class, array $properties = [])
    {
        $full_class_name = $this->qualifyEntityClassname($entity_class);
        $entity = new $full_class_name();

        foreach ($properties as $property => $value) {
            $method_name = 'set'.ucfirst($property);
            $entity->$method_name($value);
        }
        $this->EM->persist($entity);

        return $entity;
    }

    /**
     * @return mixed
     */
    public function getEntityToClassMapping(string $short_name = null)
    {
        if(empty($short_name)){
            return $this->entity_to_class_mapping;
        }

        return $this->entity_to_class_mapping[$short_name];
    }

    /**
     * @param mixed $entity_to_class_mapping
     */
    public function setEntityToClassMapping()
    {
        if (!empty($this->entity_to_class_mapping)) {
            return;
        }
        $this->setEntityMetaData();

        $this->entity_to_class_mapping = array_map(
            function (ClassMetadata $data) {
                return $data->getName();
            },
            $this->getEntityMetaData()
        );
    }

    /**
     * @return array
     */
    public function getEntityColumnData($class_name = null, $column_name = null)
    {
        if(empty($class_name)){
            return $this->entity_column_data;
        } elseif (empty($column_name)){
            return $this->entity_column_data[$class_name];
        }
        return $this->entity_column_data[$class_name][$column_name];
    }

    /**
     * @param mixed $entity_column_data
     */
    public function setEntityColumnData()
    {
        if (!empty($this->entity_column_data)) {
            return;
        }
        $this->setEntityMetaData();

        $this->entity_column_data = array_map(
            function (ClassMetadata $data) {
                return $data->fieldMappings;
            },
            $this->getEntityMetaData()
        );
    }

    public function getShortEntityName(string $fqcn): ?string
    {
        $this->setEntityToClassMapping();
        $short_name = array_search($fqcn, $this->entity_to_class_mapping, true);

        return ($short_name === false ? null : $short_name);
    }

    public function qualifyEntityClassname(string $class_name): ?string
    {
        $this->setEntityToClassMapping();
        return $this->getEntityToClassMapping($class_name) ?? null;
    }

    public function isNullable($entity_name, $field_name)
    {
        $this->setEntityMetaData();

        return $this->getEntityMetaData($entity_name)->isNullable($field_name);
    }

    public function hasGenerator(string $entity_name)
    {
        $non_autogenerated = [
            ClassMetadataInfo::GENERATOR_TYPE_NONE,
        ];
        $generator_type = $this->getEntityMetaData($entity_name)->generatorType;

        return !in_array($generator_type, $non_autogenerated);
    }

    public function isAutoCreated(string $class_name, string $field_name)
    {
        $const_name = $this->qualifyEntityClassname($class_name) . '::AUTO_CREATED';
        return defined($const_name) && in_array($field_name, constant($const_name));
    }

    public function getRequiredFields($class_name)
    {
        $this->setEntityColumnData();

        $required_fields = array_filter(
            $this->getEntityColumnData($class_name),
            function ($f) use ($class_name) {
                return $this->isRequired($class_name, $f['fieldName']);
            }
        );

        return array_keys($required_fields);
    }

    private function isRequired($class_name, $field_name)
    {
        $this->setEntityColumnData();

        $is_id = $this->getEntityMetaData($class_name)->isIdentifier($field_name);
        if ($is_id && $this->hasGenerator($class_name, $field_name)) {
            return false;
        }
        if($this->isAutoCreated($class_name, $field_name)){
            return false;
        }

        return !$this->isNullable($class_name, $field_name);
    }


    /**
     * @return mixed
     */
    public function getEntityMetaData($class_name = null)
    {
        return $this->entity_meta_data[$class_name] ?? $this->entity_meta_data;
    }

    /**
     * @param mixed $entity_meta_data
     */
    public function setEntityMetaData()
    {
        if (!empty($this->entity_meta_data)) {
            return;
        }
        $meta_data = $this->EM->getMetadataFactory()->getAllMetadata();
        $keys = array_map(
            function (ClassMetadata $data) {
                return array_slice(explode('\\', $data->getName()), -1)[0];
            },
            $meta_data
        );

        $this->entity_meta_data = array_combine($keys, $meta_data);
    }

    /**
     * Shorthand function to set a value for a certain entity in a certain repository.
     *
     * @param mixed $requests The function takes either 3-4 arguments corresponding to
     *                        $repo, $id, $value and $attribute_name (with default 'Value').
     *                        Or it takes ONE array which in itself consists of one or more
     *                        arrays with each exactly 3-4 elements. The elements can either
     *                        be in right order or indexed with 'repo', 'id', 'value' and 'att_name'.
     *
     * @return void
     */
    public function set(...$requests)
    {
        if (count($requests) === 1 && is_array($requests[0])) {
            $requests = $requests[0];
        } else {
            $requests = [$requests];
        }

        foreach ($requests as $args) {
            $repo = $args[0] ?? $args['repo'];
            $id = $args[1] ?? $args['id'];
            $value = $args[2] ?? $args['value'];
            $attribute_name = $args[3] ?? ($args['att_name'] ?? 'Value');
            $e = $this->find($repo, $id);
            $method = 'set'.$attribute_name;
            if (!empty($e)) {
                $e->$method($value);
            } else {
                $msg = 'No entity of the class <'.$repo.'> with the id <';
                $msg .= $id.'> could be found.';
                throw new \Exception($msg);
            }
        }
    }

    public function setAndFlush(...$requests)
    {
        $this->set(...$requests);
        $this->EM->flush();
    }

    /**
     * @return SimpleAnnotationReader
     */
    public function getAnnotationReader(): SimpleAnnotationReader
    {
        return $this->annotation_reader;
    }

}
