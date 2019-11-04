<?php

namespace Fridde;

use Doctrine\ORM\EntityRepository;
use Doctrine\Common\Collections\Criteria;

class CustomRepository extends EntityRepository
{
    public const AND = 'and';
    public const OR = 'or';

    protected $selection = [];

    public function getSelection(): array
    {
        return $this->selection;
    }

    public function fetch(): array
    {
        return $this->selection;
    }

    public function all()
    {
        $this->selection = $this->findAll();
    }

    /**
     * Wrapper for selectAnd()
     *
     * @param array $expression_args An array of expression-arguments
     * @return array An array containing the entities that match the criteria
     */
    public function select(...$expression_args): array
    {
        return call_user_func_array([$this, 'selectAnd'], $expression_args);
    }

    /**
     * Can be used with either 3 arguments of the type
     * $operator, $field, $value
     * OR an arbitrary number of arguments where each argument is an array
     * containing aforementioned arguments
     * OR one single array containing arrays
     * where each element contains the basic arguments.
     * Each array is then a part of a larger "AND" statement
     * The default value for $value is null
     * fn(op, fi, val) **OR** fn([op, fi, val], [op, fi, val]) **OR** fn([[op, fi, val],[op, fi, val],...])
     *
     * @param mixed $expression_args An array of expression-arguments     *
     * @return array An array containing the entities that match the criteria
     */

    public function selectAnd(...$expression_args): array
    {
        return $this->selectAndOr(self::AND, $expression_args);
    }

    public function selectOr(...$expression_args): array
    {
        return $this->selectAndOr(self::OR, $expression_args);
    }

    /**
     *
     * Don't call this function directly. Use selectOr() and selectAnd() instead.
     *
     * @param $type
     * @param array $expression_args
     * @return array An array containing the entities that match the criteria
     * @throws \Exception
     */
    protected function selectAndOr($type, ...$expression_args): array
    {

        $type .= 'X';

        $args = $this->normalizeArgs($expression_args[0]);
        if (empty($args)) {
            return $this->findAll();
        }
        $expressions = $this->getExpressionArray($args);
        $ex_count = count($expressions);

        if ($ex_count === 1) {
            $criteria = Criteria::create()->where($expressions[0]);
        } elseif ($ex_count > 1) {
            $big_expression = Criteria::expr();
            $big_expression = call_user_func_array([$big_expression, $type], $expressions);
            $criteria = Criteria::create()->where($big_expression);
        } else {
            throw new \Exception('Wrong amount of arguments in number of expressions');
        }

        return $this->matching($criteria)->toArray();
    }

    /**
     * Takes the arguments of the selectAnd/selectOr and converts them to an array
     * of criteria, where each critera is itself an array consisting of three elements
     * ["operator", "field", "value"]
     *
     * @param  array $args An array containing the arguments given to the select methods.
     *                     Notice that
     * @return array|null   The normalized array that only contains arrays containing arguments,
     *                      i.e. [["operatorA", "fieldA", "valueA"], ["operatorB", "fieldB", "valueB"]]
     */
    protected function normalizeArgs(array $args): ?array
    {
        $args = array_values($args); // to ensure that we can use numerical indices

        // selectAnd([])
        if (empty($args) || empty($args[0])) {
            return null;
        } // selectAnd([['eq', 'Status', 1], ['neq', 'FirstName', 'Bob']])
        if (count($args) === 1 && array_filter($args[0], 'is_array') === $args[0]) {
            $args = $args[0];
        } // selectAnd('eq', 'Status', 1)
        elseif (array_filter($args, 'is_array') !== $args) {
            $args = [$args];
        }
        // else
        // selectAnd(['eq', 'Status', 1])
        //  $args = $args
        if (empty(array_filter($args))) {
            return null;
        }

        return $args;
    }

    /**
     * [getExpressionArray description]
     * @param  array $array_of_expression_args An array containing arrays with 2 or 3 elements
     *                                         that conform to the standard [operator, field, value]
     * @return Doctrine\Common\Collections\Expr[] An array of expressions
     */
    protected function getExpressionArray($array_of_expression_args): array
    {
        return array_map(
            function ($arg) {
                if (count($arg) < 3 && $arg[0] !== 'isNull') {
                    array_unshift($arg, 'eq');
                }
                $operator = array_shift($arg);
                $field = array_shift($arg);
                $value = array_shift($arg);
                if ($operator === 'isNull') {
                    return Criteria::expr()->isNull($field);
                }

                return Criteria::expr()->$operator($field, $value);
            },
            $array_of_expression_args
        );
    }

    /**
     * Filters the repo by comparing the return value of a certain method with a certain
     * given value. Thus
     *
     * @param  string $method_name The method to be used on the objects
     * @param  mixed $value The value that the return value from the method should match
     *                           to be included in the array
     * @param  array $parameters The parameters to be passed to the callback,
     *                            as described in the docs for call_user_func_array()
     * @param string $operator
     * @return array             The filtered array of entities
     */
    public function findViaMethod(
        string $method_name,
        $value,
        array $parameters = [],
        string $operator = 'eq'
    ): array {
        return $this->findViaMultipleMethods([[$method_name, $value, $parameters, $operator]]);
    }

    public function findViaMultipleMethods(array $methods, $total_operator = self::AND, $entities = null): array
    {
        $entities = $entities ?? $this->findAll();

        $filtered = [];
        foreach ($entities as $entity) {
            $test_results = [];
            foreach ($methods as $method) {
                [$method_name, $value] = $method;
                $parameters = $method[2] ?? [];
                $operator = $method[3] ?? 'eq';

                if (!empty($parameters)) {
                    $method_result = call_user_func_array([$entity, $method_name], $parameters);
                } else {
                    $method_result = $entity->$method_name();
                }
                $test_results[] = $this->applyLogicalOperator($operator, $method_result, $value);
            }
            $nr_of_trues = count(array_filter($test_results));

            $test_fnc = [
                self::AND => 'array_product',
                self::OR => 'array_sum'
            ];

            if(call_user_func($test_fnc[$total_operator], $test_results)){
                $filtered[] = $entity;
            }
        }

        return $filtered;
    }

    /**
     * Applies a logical operator 'between' the left value and the right value.
     *
     * @param  string $operator One of the allowed operators *eq, neq, lt, lte,
     *                             gt, gte, in, nin*
     * @param  mixed $left_value [description]
     * @param  mixed $right_value [description]
     * @return boolean              The result of the logical operation
     */
    public function applyLogicalOperator(string $operator, $left_value, $right_value): bool
    {
        $l = $left_value;
        $r = $right_value;
        $operator = strtolower($operator);

        switch ($operator) {
            case 'eq':
                return $l === $r;
                break;
            case 'neq':
                return $l !== $r;
                break;
            case 'lt':
                return $l < $r;
                break;
            case 'lte':
                return $l <= $r;
                break;
            case 'gt':
                return $l > $r;
                break;
            case 'gte':
                return $l >= $r;
                break;
            case 'in':
                return in_array($l, $r, false);
                break;
            case 'nin':
                return !in_array($l, $r, false);
                break;
            default:
                throw new \Exception('The operator "'.$operator.'" is not defined.');
        }
    }


    public function getIndexFromConstant(string $constant, string $value, string $other_class_name = null)
    {
        if (!empty($other_class_name)) {
            $class_name = $this->getClassMetadata()->namespace.'\\'.$other_class_name;
        } else {
            $class_name = $this->getClassMetadata()->getName();
        }
        $prefix = '\\'.$class_name.'::';
        $constant_name = $prefix.$constant;
        if (!defined($constant_name)) {
            $constant_name = $prefix.strtoupper($constant);
        }
        if (!defined($constant_name)) {
            throw new \Exception('The constant "'.$constant_name.'" is not defined.');
        }
        $const_array = constant($constant_name);
        $return = array_flip($const_array)[$value] ?? null;
        if (empty($return)) {
            throw new \Exception('The value "'.$value.'" was not found inside "'.$constant_name.'"');
        }

        return $return;
    }

}
