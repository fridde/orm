<?php

namespace Fridde;

use Doctrine\ORM\Tools\Setup;
use Doctrine\ORM\EntityManager;
use Doctrine\Common\Util\Debug;

class ORM {

    public $paths_to_entities = ["src/Entities/"];
    private $default_namespace;
    public $EM;

    public function __construct(array $db_settings = null, array $orm_settings = null)
    {
        $db_settings = $db_settings ?? (SETTINGS["Connection_Details"] ?? []);
        $orm_settings = $orm_settings ?? (SETTINGS["ORM"] ?? []);

        if(empty($db_settings)){
            throw new \Exception("No database settings found.");
        }
        $db_params = [
            'driver'   => 'pdo_mysql',
            'user'     => $db_settings["db_username"],
            'password' => $db_settings['db_password'],
            'dbname'   => $db_settings['db_name'],
            'charset'  => 'utf8'
        ];

        $is_dev_mode = $GLOBALS["debug"] ?? false;
        $config = Setup::createAnnotationMetadataConfiguration($this->paths_to_entities, $is_dev_mode);
        $this->EM = EntityManager::create($db_params, $config);

        $this->default_namespace = $orm_settings["default_namespace"] ?? null;
    }

    public function save($entity)
    {
        $this->EM->persist($entity);
        $this->EM->flush();
    }

    public function getEM()
    {
        return $this->EM;
    }

    public function getRepository($entity_class){

        $this->qualifyClassname($entity_class, true);
        return $this->EM->getRepository($entity_class);
    }

    public function qualifyClassname($class_names, $set_alias = false)
    {
        $full_names = [];
        $class_names = (array) $class_names;
        foreach($class_names as $class_name){
            $full_name = $class_name;
            if(!class_exists($class_name) && !empty($this->default_namespace)){
                $full_name = $this->default_namespace . '\\' . $class_name;
                if($set_alias){
                    class_alias($full_name, $class_name);
                }
            }
            $full_names[] = $full_name;
        }
        return count($full_names) > 1 ? $full_names : reset($full_names);
    }


    public function find($entity_class, $id)
    {
        return $this->getRepository($entity_class)->find($id);
    }

    public function findBy($entity_class, $criteria = [])
    {
        return $this->getRepository($entity_class)->findBy($criteria);
    }

    public static function dump($var = null, $return = false)
    {
        if($return) {
            return Debug::export($var, false);
        } else {
            Debug::dump($var);
        }

    }

    /**
     * @param string $entity_class
     * @param int|string $entity_id
     * @param string $property The name of the property that is supposed to be updated
     * @param mixed $value
     */
    public function updateProperty(string $entity_class, $entity_id, string $property, $value)
    {
        $entity = $this->getRepository($entity_class)->find($entity_id);
        if(empty($entity)){
            throw new ExceptionalException(':no_entity:', [$entity_class, $entity_id]);
        }

        $setter = "set" . $property;

        if (!method_exists($entity, $setter)) {
            throw new \Exception("The method <" . $setter . 'does not exist for the entity of class <' . $entity_class . '>.');
        }
        $entity->$setter($value);
        $this->EM->persist($entity);
    }

    public function batchUpdateProperties(array $updates)
    {

        foreach($updates as $update){
            call_user_func_array([$this, "updateProperty"], $update);
        }
    }

    public function createNewEntity(string $entity_class, array $properties = [])
    {
        $full_class_name = $this->qualifyClassname($entity_class);
        $entity = new $full_class_name();

        foreach($properties as $property => $value){
            $method_name = "set" . ucfirst($property);
            $entity->$method_name($value);
        }
        $this->EM->persist($entity);
        return $entity;
    }

}
