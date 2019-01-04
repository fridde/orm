<?php
/**
 * Contains the class Update.
 */

namespace Fridde;

use Doctrine\ORM\Mapping\ManyToOne;
use Fridde\Annotations\PostArgs;
use Fridde\Utility as U;



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
     * @return DefaultUpdate
     * @throws \Exception
     */
    public function updateProperty(string $entity_class, $entity_id, string $property, $value): self
    {
        $value = $this->replaceIdWithObject($entity_class, $property, $value);
        $this->ORM->updateProperty($entity_class, $entity_id, $property, $value);

        return $this;
    }

    /**
     * @param array $array_of_updates
     * @return $this
	*/
    public function batchUpdateProperties(array $array_of_updates): self
    {
        // array of entity_class, entity_id, property, value
        foreach ($array_of_updates as $update_args) {
            if (count(array_filter(array_keys($update_args), 'is_string')) > 0) {
                $update_args = U::pluck($update_args, $this->getMethodArgs('updateProperty'));
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
     * @throws \Doctrine\ORM\ORMException
     */
    public function createNewEntity(string $entity_class, array $properties = [], bool $flush = true): self
    {

        if (!$this->allRequiredFieldsGiven($entity_class, $properties)) {
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


    private function allRequiredFieldsGiven(string $entity_class, array $properties): bool
    {
        $properties = array_filter(
            $properties,
            function ($v) {
                return null !== $v;
            }
        );
        $entity_class = $this->ORM->qualifyEntityClassname($entity_class);
        return empty(array_diff($this->ORM->getRequiredFields($entity_class), array_keys($properties)));
    }


    public function flush(): self
    {
        $this->ORM->EM->flush();

        return $this;
    }

    protected function replaceIdsWithObjects(string $entity_class, array $properties): array
    {
        foreach ($properties as $property_name => &$value) {
            $value = $this->replaceIdWithObject($entity_class, $property_name, $value);
        }

        return $properties;
    }

    protected function replaceIdWithObject(string $entity_class, string $property_name, $value)
    {
        if (is_object($value)) {
            return $value;
        }
        $entity_class = $this->ORM->qualifyEntityClassname($entity_class);
        if (!$this->ORM->getAnnotationReader()->hasPropertyAnnotation($entity_class, $property_name, ManyToOne::class)) {
            return $value;
        }
        $property_name = $this->ORM->qualifyEntityClassname($property_name);

        return $this->ORM->EM->getReference($property_name, $value);
    }

    /**
     * @param string $method_name
     * @return array An array of expected argument names defined in PostArgs. 
     */
    public function getMethodArgs(string $method_name): array
    {
        $reader = $this->ORM->getAnnotationReader();
        $annot = $reader->getAnnotationForMethod(get_class($this), $method_name, PostArgs::class);

        if(!empty($annot) && isset($annot->args)){
            return $annot->args;
        }
        return [];
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
    public function getReturn(string $key = null)
    {
        if ($key === null) {
            $this->setReturn('onReturn', $this->RQ['onReturn'] ?? null);
            $this->setReturn('success', !$this->hasErrors());
            $this->setReturn('errors', $this->getErrors());

            return $this->Return;
        }

        return $this->Return[$key] ?? null;
    }

    /**
     * Sets $Return[$key]  with either a given $value or with a value taken from the
     * initial request $RQ.
     *
     * @param mixed $key_or_array The key to set. If array each key-value pair
     *                           are the arguments for this function.
     * @param mixed|null $value The value to set at $Return[$key].
     */
    public function setReturn($key_or_array, $value = null)
    {
        if (!is_array($key_or_array)) {
            $key_or_array = [$key_or_array => $value];
        }
        foreach ($key_or_array as $key => $val) {
            $this->Return[$key] = $val;
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
    public function getErrors(): array
    {
        return $this->Errors;
    }

    /**
     * Adds error string as element to $Errors.
     *
     * @param string $error_string A string describing the error.
     */
    public function addError($error_string): void
    {
        $this->Errors[] = $error_string;
    }

    /**
     * Checks if $Errors is not empty.
     *
     * @return boolean Returns true if $Errors is not empty.
     */
    public function hasErrors(): bool
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
    /*
    protected function findById(string $entity_class, $id)
    {
        return $this->ORM->getRepository($entity_class)->find($id);
    }
    */
}
