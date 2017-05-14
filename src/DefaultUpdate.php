<?php
/**
 * Contains the class Update.
 */
namespace Fridde;

use Fridde\Utility as U;
use Fridde\Entities\Cookie;
use Carbon\Carbon;


/**
 * Contains the logic to update records in the database.
 */
class DefaultUpdate
{
    /* @var $ORM \Fridde\Naturskolan  */
    protected $ORM;
    /** @var array The request data sent into the constructor */
    protected $RQ;
    /** @var array  */
    protected $Return = [];
    /** @var array  */
    protected $Errors = [];

    /* @var array */
    protected const DEFAULT_METHOD_ARGUMENTS = [
        "updateProperty"            => ["entity_class","entity_id","property","value"],
        "batchUpdateProperties"     => ["array_of_updates"],
        "createNewEntity"           => ["entity_class", "properties"],
        "createNewEntityFromModel"  => ["entity_class", "property", "value", "model_entity_id"]
    ];

    /**
     * Update constructor.
     * @param array $request_data
     * @param \Fridde\ORM $ORM
     */
    public function __construct(array $request_data = [], \Fridde\ORM $ORM)
    {
        $this->ORM = $ORM;
        $this->RQ = $request_data;
    }

    public static function create()
    {
        // TODO: remove after correction of other methods
    }

    /**
     * @param string $entity_class
     * @param int|string $entity_id
     * @param string $property The name of the property that is supposed to be updated
     * @param mixed $value
     */
    public function updateProperty(string $entity_class, $entity_id, string $property, $value)
    {
        $this->ORM->updateProperty($entity_class, $entity_id, $property, $value);
        $this->ORM->EM->flush();
    }

    public function batchUpdateProperties(array $array_of_updates)
    {
        // array of entity_class, entity_id, property, value
        foreach($array_of_updates as $update_args){
            if(count(array_filter(array_keys($update_args), "is_string")) > 0){
                $update_args = U::pluck($update_args, self::getMethodArgs("updateProperty"));
            }
            call_user_func_array([$this->ORM, "updateProperty"], array_values($update_args));
        }
        $this->ORM->EM->flush();
    }

    public function createNewEntityFromModel(string $entity_class, string $property, $value, $model_entity_id)
    {

        $syncables = $this->syncables ?? [];

        $model_entity = $this->N->ORM->getRepository($entity_class)->find($model_entity_id);
        $properties = [$property => $value];

        $properties_to_sync = $syncables[$entity_class] ?? [];
        foreach($properties_to_sync as $property_name){
            $method_name = "get" . $property_name;
            $properties[$property_name] = $model_entity->$method_name;
        }
        $this->createNewEntity($entity_class, $properties);
    }

    public function createNewEntity(string $entity_class, array $properties = [])
    {

        $properties = $this->replaceIdsWithObjects($entity_class, $properties);
        $entity = $this->N->ORM->createNewEntity($entity_class, $properties);
        $this->N->ORM->EM->flush();
        $this->setReturn("new_id", $entity->getId());
    }

    protected function replaceIdsWithObjects($entity_class, $properties)
    {
        $object_required = $this->object_required ?? [];

        foreach($properties as $property => &$value){
            $replacements = $object_required[$entity_class] ?? [];
            if(in_array($property, $replacements)){
                if(!is_object($value)){
                    $value = $this->findById($property, $value);
                }
            }
        }
        return $properties;
    }

    /**
     * @param string $method_name
     * @return array An array of expected argument names. Returns an empty array if the key
     * was not found in either DEFAULT_METHOD_ARGUMENTS or METHOD_ARGUMENTS
     */
    public static function getMethodArgs(string $method_name, array $additional_args = [])
    {
        $method_args = self::DEFAULT_METHOD_ARGUMENTS + $additional_args;
        return $method_args[$method_name] ?? [];
    }



    /**
     * Prepares and returns the answer to the request for further handling by JS or
     * other parts of the app.
     *
     * @param  string|null $key If specified, only the value of $Return[$key] is returned.
     * @return array|mixed If no key was specified, the whole $Return is returned.
     *                     It contains ["onReturn" => "...", "success" => true|false,
     *                     "errors" => [...]]
     */
    public function getReturn($key = null)
    {
        if(empty($key)){
            $this->setReturn("onReturn");
            $this->setReturn("success", !$this->hasErrors());
            $this->setReturn("errors", $this->getErrors());
            return $this->Return;
        }
        return $this->Return[$key];
    }

    /**
     * Sets $Return[$key]  with either a given $value or with a value taken from the
     * initial request $RQ.
     *
     * @param string|array  $key The key to set. If array each key-value pair
     *                           are the arguments for this function.
     * @param mixed|null  $value  The value to set at $Return[$key].
     */
    public function setReturn($key, $value = null)
    {
        if(is_string($key)){
            $key_value_pairs = [$key => $value];
        } else {
            $key_value_pairs = $key;
        }
        foreach($key_value_pairs as $key => $value){
            $this->Return[$key] = $value;
        }
        return $this;
    }

    public function setReturnFromRequest($keys)
    {
        if(is_string($keys)){
            $keys = [$keys];
        }
        foreach($keys as $key){
            $this->setReturn($key, $this->RQ[$key]);
        }
    }

    /**
     * Returns $Errors.
     *
     * @return string[] All error strings as array.
     */
    public function getErrors()
    {
        return $this->Errors;
    }

    /**
     * Adds error string as element to $Errors.
     *
     * @param string $error_string A string describing the error.
     */
    public function addError($error_string)
    {
        $this->Errors[] = $error_string;
    }

    /**
     * Checks if $Errors is not empty.
     *
     * @return boolean Returns true if $Errors is not empty.
     */
    public function hasErrors()
    {
        return !empty($this->getErrors());
    }




    /**
     * Quick shortcut to retrieving an entity by id.
     *
     * @param  string $entity_class The (unqualified) class name of the entity.
     * @param  integer|string $id The id of the entity to look for.
     * @return object|null The entity or null if no entity was found.
     */
    private function findById(string $entity_class, $id)
    {
        return $this->ORM->getRepository($entity_class)->find($id);
    }


}
