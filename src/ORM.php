<?php

namespace Fridde;

use \Doctrine\ORM\Tools\Setup;
use \Doctrine\ORM\EntityManager;

class ORM {

    public $paths_to_entities = ["src/Entities/"];
    private $default_namespace;
    private $EM;

    public function __construct($db_settings = null, $orm_settings = null)
    {
        $is_dev_mode = $GLOBALS["debug"] ?? false;
        $db_settings = $db_settings ?? ($GLOBALS["SETTINGS"]["Connection_Details"] ?? []);
        $orm_settings = $orm_settings ?? ($GLOBALS["SETTINGS"]["ORM"] ?? []);

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
        if(!class_exists($entityName) && !empty($this->default_namespace)){
            class_alias($this->default_namespace . '\\' . $entityName, $entityName);
        }
        return $this->EM->getRepository($entityName);
    }

    public static function dump($var = null, $return = false)
    {
        if($return) {
            return \Doctrine\Common\Util\Debug::export($var);
        } else {
            \Doctrine\Common\Util\Debug::dump($var);
        }

    }

}
