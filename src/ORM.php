<?php

namespace Fridde;

use \Doctrine\ORM\Tools\Setup;
use \Doctrine\ORM\EntityManager;

class ORM {

    public $paths_to_entities = ["src/Entities/"];
    private $EM;

    public function __construct($settings = null)
    {
        $is_dev_mode = $GLOBALS["debug"] ?? false;
        $db_settings = $settings ?? ($GLOBALS["SETTINGS"]["Connection_Details"] ?? []);

        if(!empty($db_settings)){
            $db_params = [
                'driver'   => 'pdo_mysql',
                'user'     => $db_settings["db_username"],
                'password' => $db_settings['db_password'],
                'dbname'   => $db_settings['db_name']
            ];

            $config = Setup::createAnnotationMetadataConfiguration($this->paths_to_entities, $is_dev_mode);
            $this->EM = EntityManager::create($db_params, $config);
        } else {
            throw new \Exception("No database settings found.");
        }
    }

    public function getEM()
    {
        return $this->EM;
    }

}
