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
    /* @var ORM $ORM */
    protected $ORM;
    /** @var array $RQ The request data sent into the constructor */
    protected $RQ;
    /** @var array */
    protected $Return = [];
    /** @var array */
    protected $Errors = [];

    /* @var array */
    public const DEFAULT_METHOD_ARGUMENTS = [
        'updateProperty' => ['entity_class', 'entity_id', 'property', 'value'],
        'batchUpdateProperties' => ['array_of_updates'],
        'createNewEntity' => ['entity_class', 'properties'],
        'createNewEntityFromModel' => ['entity_class', 'property', 'value', 'model_entity_id'],
    ];

    /**
     * Update constructor.
     * @param array $request_data
     * @param ORM $ORM
     */
    public function __construct(array $request_data = [], ORM $ORM)
    {
        $this->ORM = $ORM;
        $this->RQ = $request_data;
        $this->Return = $request_data['return'] ?? [];
    }

    /**
     * @param string $entity_class
     * @param int|string $entity_id
     * @param string $property The name of the property that is supposed to be updated
     * @param mixed $value
     */
    public function updateProperty(string $entity_class, $entity_id, string $property, $value)
    {
        $value = $this->replaceIdWithObject($entity_class, $property, $value);
        $this->ORM->updateProperty($entity_class, $entity_id, $property, $value);

        return $this;
    }

    public function batchUpdateProperties(array $array_of_updates)
    {
        // array of entity_class, entity_id, property, value
        foreach ($array_of_updates as $update_args) {
            if (count(array_filter(array_keys($update_args), 'is_string')) > 0) {
                $update_args = U::pluck($update_args, self::getMethodArgs('updateProperty'));
            }
            call_user_func_array([$this->ORM, 'updateProperty'], array_values($update_args));
        }

        return $this;
    }


    /**
     * @param string $entity_class
     * @param array $properties
     * @param bool $flush
     * @return $this
     */
    public function createNewEntity(string $entity_class, array $properties = [], bool $flush = true)
    {
        //debug_allRequiredFieldsGiven
        if (!$this->debug_allRequiredFieldsGiven($entity_class, $properties)) {
            //if(!$this->allRequiredFieldsGiven($entity_class, $properties)){
            $this->setReturn('old_properties', $properties);

            return $this;
        }
        $properties = $this->replaceIdsWithObjects($entity_class, $properties);
        $entity = $this->ORM->createNewEntity($entity_class, $properties);
        if ($flush) {
            $this->flush();
        }
        $this->setReturn('new_id', $entity->getId());

        return $this;
    }


    private function allRequiredFieldsGiven(string $entity_class, array $properties)
    {
        return 0 === count(
                array_diff(
                    $this->ORM->getRequiredFields($entity_class),
                    array_keys(
                        array_filter($properties, 'is_null')
                    )
                )
            );
    }

    private function debug_allRequiredFieldsGiven(string $entity_class, array $properties)
    {
        $filtered_properties = array_filter(
            $properties,
            function ($p) {
                return !is_null($p);
            }
        );
        $keys = array_keys($filtered_properties);
        $required_fields = $this->ORM->getRequiredFields($entity_class);
        $diff = array_diff($required_fields, $keys);
        $not_defined = count($diff);

        return $not_defined === 0;
    }


    public function flush()
    {
        $this->ORM->EM->flush();

        return $this;
    }

    protected function replaceIdsWithObjects(string $entity_class, $properties)
    {
        foreach ($properties as $property_name => &$value) {
            $value = $this->replaceIdWithObject($entity_class, $property_name, $value);
        }

        return $properties;
    }

    protected function replaceIdWithObject(string $entity_class, string $property_name, $value)
    {
        $replacements = self::$object_required[$entity_class] ?? [];
        if (in_array($property_name, $replacements) && !is_object($value)) {
            $property_name = $this->ORM->qualifyEntityClassname($property_name);
            $value = $this->ORM->EM->getReference($property_name, $value);
        }

        return $value;
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
     *                     It contains ['onReturn' => '...', 'success' => true|false,
     *                     'errors' => [...]]
     */
    public function getReturn($key = null)
    {
        if (empty($key)) {
            $this->setReturn('onReturn', $this->RQ['onReturn'] ?? null);
            $this->setReturn('success', !$this->hasErrors());
            $this->setReturn('errors', $this->getErrors());

            return $this->Return;
        }

        return $this->Return[$key];
    }

    /**
     * Sets $Return[$key]  with either a given $value or with a value taken from the
     * initial request $RQ.
     *
     * @param string|array $key The key to set. If array each key-value pair
     *                           are the arguments for this function.
     * @param mixed|null $value The value to set at $Return[$key].
     */
    public function setReturn($key, $value = null)
    {
        if (is_string($key)) {
            $key_value_pairs = [$key => $value];
        } elseif (is_array($key)) {
            $key_value_pairs = $key;
        } else {
            throw new \Exception('Argument must be either string or array');
        }
        foreach ($key_value_pairs as $key => $value) {
            $this->Return[$key] = $value;
        }

        return $this;
    }

    public function setReturnFromRequest($keys)
    {
        if (is_string($keys)) {
            $keys = [$keys];
        }
        foreach ($keys as $key) {
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
    protected function findById(string $entity_class, $id)
    {
        return $this->ORM->getRepository($entity_class)->find($id);
    }


}
