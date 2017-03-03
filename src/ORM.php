<?php

namespace Fridde;

use Doctrine\ORM\Tools\Setup;
use Doctrine\ORM\EntityManager;
use Doctrine\Common\Util\Debug;

class ORM {

    public $paths_to_entities = ["src/Entities/"];
    private $default_namespace;
    public $EM;

    public function __construct($db_settings = null, $orm_settings = null)
    {
        $is_dev_mode = $GLOBALS["debug"] ?? false;
        $db_settings = $db_settings ?? (SETTINGS["Connection_Details"] ?? []);
        $orm_settings = $orm_settings ?? (SETTINGS["ORM"] ?? []);

        if(!empty($db_settings)){
            $db_params = [
                'driver'   => 'pdo_mysql',
                'user'     => $db_settings["db_username"],
                'password' => $db_settings['db_password'],
                'dbname'   => $db_settings['db_name'],
                'charset'  => 'utf8'
            ];

            $config = Setup::createAnnotationMetadataConfiguration($this->paths_to_entities, $is_dev_mode);
            $this->EM = EntityManager::create($db_params, $config);
        } else {
            throw new \Exception("No database settings found.");
        }
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

    public function getRepository($entityName){

        $this->qualifyClassname($entityName, true);
        return $this->EM->getRepository($entityName);
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


    public function find($entityName, $id)
    {
        return $this->getRepository($entityName)->find($id);
    }

    public static function dump($var = null, $return = false)
    {
        if($return) {
            return Debug::export($var);
        } else {
            Debug::dump($var);
        }

    }

}
