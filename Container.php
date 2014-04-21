<?php
namespace Vda\DependencyInjection;

/**
 * Very basic DI container
 *
 * To use configure it with array of bean definitions:
 * <code>
 * //...
 * 'beanAlias' => [
 *     'alias' => 'beanName', //reference to another bean, must be only entry
 * ],
 * 'beanName' => [
 *     'class' => '\Fully\Qualified\ClassName', //mandatory for non-abstract entries
 *     'builder' => customBuilder, //optional, any callable, must return instanceof class
 *     'constructorArgs' => [], //optional, array of constructor or builder parameters
 *     'init' => [], //optional, set properties and call methods
 *     'instanceof' => ['SomeClass', 'SomeInterface'], //optional check that created instance is instance of SomeClass and SomeInterface
 * ],
 * 'beanChild' => [
 *     'extends' => 'beanParent', //will inherit (and possibly override) config from beanParent
 * ],
 * 'beanParent' => [
 *     'abstract' => true, //only abstract beans can be extended
 *     'class' => '\Fully\Qualified\ClassName', //must be set either here or in child
 * ],
 * //...
 * </code>
 *
 * Example:
 * <code>
 * [
 *     //$c1 = new \Fully\Qualified\ClassName1;
 *     'simpleBean' => [
 *         'class' => '\Fully\Qualified\ClassName1',
 *     ],
 *     //$c2 = new \Fully\Qualified\ClassName2('foo', $c1);
 *     'simpleBeanWithConsructorParams' => [
 *         'class' => '\Fully\Qualified\ClassName2',
 *         'constructorArgs' => ['foo', 'ref:simpleBean']
 *     ]
 *     //$c3 = buildClassName3Object(1, '2', $c2);
 *     'beanWithCustomBuilder' => [
 *         'class' => '\Fully\Qualified\ClassName3',
 *         'builder' => function($a, $b, \Fully\Qualified\ClassName2 $c) {
 *             $result = new \Fully\Qualified\ClassName3;
 *             if ($a == 1) {
 *                 $result->setC($c);
 *             } else {
 *                 $result->setA($a);
 *                 $result->setB($b);
 *             }
 *
 *             return $result;
 *          },
 *          'constructorArgs' => [1, '2', 'ref:simpleBeanWithConsructorParams'],
 *     ],
 *     //$c4 = new \Fully\Qualified\ClassName4;
 *     //$c4->scalarProp = 'foo',
 *     //$c4->arrayProp = [1, 2, 3];
 *     //$c4->beanProp = $c1;
 *     //$c4->beanArrayProp = [$c1, $c3]
 *     //$c4->setArray([1,2,3]);
 *     //$c4->setScalar('bar');
 *     //$c4->setBeanRef($c1);
 *     //$c4->setBeanRefArray([$c1, $c3]);
 *     //$c4->someMethod();
 *     //$c4->someMethodParams(1, 2, [1, 2, 3]);
 *     'beanWithCustomInit' => [
 *         'class' => '\Fully\Qualified\ClassName4',
 *         'init' => [
 *             'prop:scalarProp' => 'foo',
 *             'prop:arrayProp' => [1, 2, 3],
 *             'prop:beanProp' => 'ref:simpleBean',
 *             'prop:beanArrayProp' => ['ref:simpleBean', 'ref:beanWithCustomBuilder'],
 *             'call:setArray' => [[1,2,3]], //call: suffix is optional, array must be enclosed in array
 *             'setScalar' => 'bar',
 *             'setBeanRef' => 'ref:simpleBean',
 *             'setBeanRefArray' => [['ref:simpleBean', 'ref:beanWithCustomBuilder']],
 *             'someMethod' => [],
 *             'someMethodParams' => [1, 2, [1, 2, 3]],
 *         ]
 *     ],
 *     //$c5 = new \Fully\Qualified\ClassName5;
 *     //if (!$c5 instanceof \SomeInterface) { throw new BeanInstantiationException(); }
 *     'beanWithTypeCheck' => [
 *         'class' => '\Fully\Qualified\ClassName5',
 *         'instanceof' = '\SomeInterface', //optional, may by array
 *     ]
 * ]
 * </code>
 */
class Container implements IContainer
{
    private $beansInProgress = array();
    private $beans = array();
    private $definitions = array();

    public function __construct(array $definitions)
    {
        $this->definitions = $definitions;
    }

    public function get($beanName)
    {
        if (empty($this->beans[$beanName])) {
            $this->beans[$beanName] = $this->create($beanName);
        }

        return $this->beans[$beanName];
    }

    public function hasBean($beanName)
    {
        return array_key_exists($beanName, $this->definitions);
    }

    /**
     * Attempts to create either requested or every registered bean, one by one
     *
     * Use this function to test your deployment. Pass list of beans you'd like
     * to check. Will create every registered bean otherwise.
     *
     * @param array $beanNames
     * @throws \InvalidArgumentException when explicit beans list is empty
     * @throws Vda\DependencyInjection\Exception\NoBeanDefFoundException
     * @throws Vda\DependencyInjection\Exception\BeanInstantiationException
     * @throws Vda\DependencyInjection\Exception\CircularDependency
     */
    public function test(array $beanNames = null)
    {
        if (is_null($beanNames)) {
            $beanNames = array();
            foreach ($this->definitions as $beanName => $def) {
                if (is_callable($def) || empty($def['abstract'])) {
                    $beanNames[] = $beanName;
                }
            }
        } elseif (empty($beanNames)) {
            throw new \InvalidArgumentException(
                "The check list passed to the test method can't be empty"
            );
        }

        foreach ($beanNames as $beanName) {
            $this->get($beanName);
        }
    }

    private function create($beanName)
    {
        $this->checkCircularDependency($beanName);

        $d = $this->getDefinition($beanName);

        $this->beansInProgress[$beanName] = true;

        if (is_callable($d)) {
            $result = call_user_func($d, $this);
        } elseif (!empty($d['alias'])) {
            $result = $this->get($d['alias']);
        } else {
            $result = $this->instantiate($d);

            $this->checkInstance($result, $d, $beanName);

            $this->configure($result, $d);
        }

        unset($this->beansInProgress[$beanName]);

        return $result;
    }

    private function getDefinition($beanName, $isAbstract = false)
    {
        if (empty($this->definitions[$beanName])) {
            throw new Exception\BeanInstantiationException(
                    "Failed to locate definition for bean '{$beanName}'");
        }

        $d = $this->definitions[$beanName];

        if (is_array($d) && !empty($d['extends'])) {
            $parent = $this->getDefinition($d['extends'], true);
            unset($d['extends'], $parent['abstract']);
            $d = array_merge($parent, $d);
        }

        $this->checkDefinition($beanName, $d, $isAbstract);

        return $d;
    }

    private function checkCircularDependency($beanName)
    {
        if (!empty($this->beansInProgress[$beanName])) {
            throw new Exception\CircularDependencyException(
                "Circular dependency found while creating bean '{$beanName}'"
            );
        }
    }

    private function checkDefinition($beanName, $definition, $isAbstract)
    {
        if ($isAbstract && (!is_array($definition) || empty($definition['abstract']))) {
            throw new Exception\DependencyInjectionException(
                "Definition for '{$beanName}' must be abstract"
            );
        }

        if (is_callable($definition)) {
            return;
        }

        if (!empty($definition['alias'])) {
            if (count($definition) > 1) {
                throw new Exception\DependecyInjectionException(
                    "Definition for '{$beanName}' must only have the 'alias' entry"
                );
            }
        }

        if (empty($definition['alias']) && !$isAbstract) {
            if (!empty($definition['abstract'])) {
                throw new Exception\DependencyInjectionException(
                    "Definition for bean '{$beanName}' can not be abstract"
                );
            }

            if (empty($definition['class'])) {
                throw new Exception\DependencyInjectionException(
                    "Definition for bean '{$beanName}' must have a 'class' entry"
                );
            }
        }
    }

    private function checkInstance($bean, $def, $beanName)
    {
        if (is_null($bean)) {
            throw new Exception\BeanInstantiationException(
                "Failed to create instance for bean '{$beanName}'"
            );
        }

        if (empty($def['instanceof'])) {
            $types = array();
        } else {
            $types = (array) $def['instanceof'];
        }

        $types[] = $def['class'];

        foreach ($types as $type) {
            if (!$bean instanceof $type) {
                throw new Exception\BeanInstantiationException(
                    "Bean check failed: '{$beanName}' instanceof {$type}"
                );
            }
        }
    }

    private function instantiate($def)
    {
        if (!empty($def['builder'])) {
            if (empty($def['constructorArgs'])) {
                $result = call_user_func($def['builder']);
            } else {
                $result = call_user_func_array(
                    $def['builder'],
                    $this->preProcessParams($def['constructorArgs'])
                );
            }
        } else {
            if (empty($def['constructorArgs'])) {
                $result = new $def['class']();
            } else {
                $rc = new \ReflectionClass($def['class']);
                $result = $rc->newInstanceArgs(
                    $this->preProcessParams($def['constructorArgs'])
                );
            }
        }

        return $result;
    }

    private function configure($obj, $def)
    {
        if (empty($def['init'])) {
            return;
        }

        foreach ($def['init'] as $op => $params) {
            $params = $this->preProcessParams($params);

            if (strpos($op, 'prop:') === 0) {
                $op = substr($op, 5);
                $obj->$op = $params;
            } else {
                if (strpos($op, 'call:') === 0) {
                    $op = substr($op, 5);
                }
                if (is_array($params)) {
                    call_user_func_array(array($obj, $op), $params);
                } else {
                    $obj->$op($params);
                }
            }
        }
    }

    private function preProcessParams($params)
    {
        if (is_array($params)) {
            array_walk_recursive($params, array($this, 'resolveReferences'));
        } else {
            $this->resolveReferences($params);
        }

        return $params;
    }

    private function resolveReferences(&$param)
    {
        if (is_string($param) && strpos($param, 'ref:') === 0) {
            if ($param == 'ref:@this') {
                $param = $this;
            } else {
                $param = $this->get(substr($param, 4));
            }
        }
    }
}
