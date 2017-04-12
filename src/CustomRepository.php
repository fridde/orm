<?php
namespace Fridde;

use Doctrine\ORM\EntityRepository;
use Doctrine\Common\Collections\Criteria;

class CustomRepository extends EntityRepository
{

    //$expression_method = "eq", $field, $value = null

    /**
     * Can be used with either 3 arguments of the type
     * expression_method, $field, $value
     * OR an arbitrary number of arguments where each argument is an array
     * containing aforementioned arguments OR one single array containing arrays
     * where each element contains the basic arguments.
     * Each array is then a part of a larger "AND" statement
     * The default value for $value is null
     *
     * @return array An array containing the entities that match the criteria
     */
    public function select()
    {
        return call_user_func_array([$this, "selectAnd"], func_get_args());
    }

    public function selectAnd()
    {
        return $this->selectAndOr("and", func_get_args());
    }

    public function selectOr()
    {
        return $this->selectAndOr("or", func_get_args());
    }

    protected function selectAndOr()
    {
        $args = func_get_args();
        $type = $args[0] . "X";

        $args = $this->normalizeArgs($args[1]);
        if(empty($args)){
            return $this->findAll();
        }
        $expressions = $this->getExpressionArray($args);
        if(count($expressions) === 1){
            $criteria = Criteria::create()->where($expressions[0]);
        } elseif(count($expressions) > 1){
            $big_expression = Criteria::expr();
            $big_expression = call_user_func_array([$big_expression, $type], $expressions);
            $criteria = Criteria::create()->where($big_expression);
        } else {
            throw new \Exception("Wrong amount of arguments in number of expressions");
        }
        return $this->matching($criteria)->toArray();
    }

    protected function normalizeArgs($args)
    {
        // selectAnd([])
        if(empty($args)){
            return null;
        }
        // selectAnd([["eq", "Status", 1], ["neq", "FirstName", "Bob"]])
        elseif(count($args) === 1 && array_filter($args, "is_array") === $args){
            $args = $args[0];
        }
        // selectAnd("eq", "Status", 1)
        elseif(array_filter($args, "is_array") !== $args){
            $args = [$args];
        }
        if(empty(array_filter($args))){
            return null;
        }
        return $args;
    }

    protected function getExpressionArray($array_of_expression_args)
    {
        return array_map(function($arg){
            if(count($arg) < 3 && $arg[0] !== "isNull"){
                array_unshift($arg, "eq");
            }
            $method = array_shift($arg);
            $field = array_shift($arg);
            $value = array_shift($arg);
            if($method == "isNull"){
                return Criteria::expr()->isNull($field);
            } else {
                return Criteria::expr()->$method($field, $value);
            }
        }, $array_of_expression_args);
    }

}
