<?php
/**
 * /src/App/Repository/Base.php
 *
 * @author  TLe, Tarmo Leppänen <tarmo.leppanen@protacon.com>
 */
namespace App\Repository;

use App\Entity;
use App\Entity\Interfaces\EntityInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query\Expr\Composite as CompositeExpression;
use Doctrine\ORM\QueryBuilder;

/**
 * Base doctrine repository class for entities.
 *
 * @package App\Repository
 * @author  TLe, Tarmo Leppänen <tarmo.leppanen@protacon.com>
 */
abstract class Base extends EntityRepository implements Interfaces\Base
{
    /**
     * Names of search columns.
     *
     * @var string[]
     */
    protected $searchColumns = [];

    /**
     * Parameter count in current query, this is used to track parameters which are bind to current query.
     *
     * @var integer
     */
    private $parameterCount = 0;

    /**
     * Getter method for entity name.
     *
     * @return  string
     */
    public function getEntityName()
    {
        return parent::getEntityName();
    }

    /**
     * Gets a reference to the entity identified by the given type and identifier without actually loading it,
     * if the entity is not yet loaded.
     *
     * @throws  \Doctrine\ORM\ORMException
     *
     * @param   integer $id
     *
     * @return  bool|\Doctrine\Common\Proxy\Proxy|null|object
     */
    public function getReference($id)
    {
        return $this->_em->getReference($this->getClassName(), $id);
    }

    /**
     * Gets all association mappings of the class.
     *
     * @return  array
     */
    public function getAssociations()
    {
        return $this->_em->getClassMetadata($this->getClassName())->getAssociationMappings();
    }

    /**
     * Getter method for search columns of current entity.
     *
     * @return \string[]
     */
    public function getSearchColumns()
    {
        return $this->searchColumns;
    }

    /**
     * Helper method to persist specified entity to database.
     *
     * @param   EntityInterface $entity
     *
     * @return  void
     */
    public function save(EntityInterface $entity)
    {
        // Persist on database
        $this->_em->persist($entity);
        $this->_em->flush();
    }

    /**
     * Helper method to remove specified entity from database.
     *
     * @param   EntityInterface $entity
     *
     * @return  void
     */
    public function remove(EntityInterface $entity)
    {
        // Remove from database
        $this->_em->remove($entity);
        $this->_em->flush();
    }

    /**
     * Generic count method to determine count of entities for specified criteria and search term(s).
     *
     * @param   array       $criteria
     * @param   array|null  $search
     *
     * @return  integer
     */
    public function count(array $criteria = [], array $search = null)
    {
        // Create new query builder
        $queryBuilder = $this->createQueryBuilder('entity');

        // Process normal and search term criteria
        $this->processCriteria($queryBuilder, $criteria);
        is_null($search) ?: $this->processSearchTerms($queryBuilder, $search);

        $queryBuilder->select('COUNT(entity.id)');

        return (int)$queryBuilder->getQuery()->getSingleScalarResult();
    }

    /**
     * Generic replacement for basic 'findBy' method if/when you want to use generic LIKE search.
     *
     * @param   array           $search
     * @param   array           $criteria
     * @param   null|array      $orderBy
     * @param   null|integer    $limit
     * @param   null|integer    $offset
     *
     * @return  array
     */
    public function findByWithSearchTerms(
        array $search,
        array $criteria,
        array $orderBy = null,
        $limit = null,
        $offset = null
    ) {
        // Create new query builder
        $queryBuilder = $this->createQueryBuilder('entity');

        // Process normal and search term criteria
        $this->processCriteria($queryBuilder, $criteria);
        $this->processSearchTerms($queryBuilder, $search);

        // Process order, limit and offset
        is_null($orderBy) ?: $this->processOrderBy($queryBuilder, $orderBy);
        is_null($limit) ?: $queryBuilder->setMaxResults($limit);
        is_null($offset) ?: $queryBuilder->setFirstResult($offset);

        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * Repository method to fetch current entity id values from database and return those as an array.
     *
     * @param   array   $criteria
     * @param   array   $search
     *
     * @return  array
     */
    public function findIds(array $criteria, array $search)
    {
        // Create new query builder
        $queryBuilder = $this->createQueryBuilder('entity');

        // Process normal and search term criteria
        $this->processCriteria($queryBuilder, $criteria);
        $this->processSearchTerms($queryBuilder, $search);

        $queryBuilder
            ->select('entity.id')
            ->distinct(true)
        ;

        return array_map('current', $queryBuilder->getQuery()->getArrayResult());
    }

    /**
     * Process given criteria which is given by ?where parameter. This is given as JSON string, which is converted
     * to assoc array for this process.
     *
     * Note that this supports by default (without any extra work) just 'eq' and 'in' expressions. See example array
     * below:
     *
     *  [
     *      'u.id'  => 3,
     *      'u.uid' => 'uid',
     *      'u.foo' => [1, 2, 3],
     *      'u.bar' => ['foo', 'bar'],
     *  ]
     *
     * And these you can make easily happen within REST controller and simple 'where' parameter. See example below:
     *
     *  ?where={"u.id":3,"u.uid":"uid","u.foo":[1,2,3],"u.bar":["foo","bar"]}
     *
     * Also note that you can make more complex use case fairly easy, just follow instructions below.
     *
     * If you're trying to make controller specified special criteria with projects generic Rest controller, just
     * add 'processCriteria(array &$criteria)' method to your own controller and pre-process that criteria in there
     * the way you want it to be handled. In other words just modify that basic key-value array just as you like it,
     * main goal is to create array that is compatible with 'getExpression' method in this class. For greater detail
     * just see that method comments.
     *
     * tl;dr Modify your $criteria parameter in your controller with 'processCriteria(array &$criteria)' method.
     *
     * @see \App\Repository\Base::getExpression
     * @see \App\Controller\Rest::processCriteria
     *
     * @param   QueryBuilder    $queryBuilder
     * @param   array           $criteria
     *
     * @return  void
     */
    protected function processCriteria(QueryBuilder $queryBuilder, array $criteria)
    {
        if (empty($criteria)) {
            return;
        }

        // Initialize condition array
        $condition = [];

        /**
         * Lambda function to create condition array for 'getExpression' method.
         *
         * @param   string      $column
         * @param   mixed       $value
         * @param   null|string $operator
         *
         * @return  array
         */
        $createCriteria = function (string $column, $value, $operator = null) {
            if (strpos($column, '.') === false) {
                $column = 'entity.' . $column;
            }

            // Determine used operator
            if (is_null($operator)) {
                $operator = is_array($value) ? 'in' : 'eq';
            }

            return [$column, $operator, $value];
        };

        /**
         * Lambda function to process criteria and add it to main condition array.
         *
         * @param   mixed   $value
         * @param   string  $column
         */
        $processCriteria = function ($value, $column) use (&$condition, $createCriteria) {
            // If criteria contains 'and' OR 'or' key(s) assume that array in only in the right format
            if (strcmp($column, 'and') === 0 || strcmp($column, 'or') === 0) {
                $condition[$column] = $value;
            } else {
                // Add condition
                $condition[] = call_user_func_array($createCriteria, [$column, $value]);
            }
        };

        // Create used condition array
        array_walk($criteria, $processCriteria);

        // And attach search term condition to main query
        $queryBuilder->andWhere($this->getExpression($queryBuilder, $queryBuilder->expr()->andX(), $condition));
    }

    /**
     * Helper method to process given search terms and create criteria about those. Note that each repository
     * has 'searchColumns' property which contains the fields where search term will be affected.
     *
     * @see \App\Controller\Rest::getSearchTerms
     *
     * @param   QueryBuilder $queryBuilder
     * @param   array        $searchTerms
     *
     * @return  void
     */
    protected function processSearchTerms(QueryBuilder $queryBuilder, array $searchTerms)
    {
        $columns = $this->searchColumns;

        if (empty($columns)) {
            return;
        }

        /**
         * Lambda function to process each search term to specified search columns.
         *
         * @param   string  $term
         *
         * @return  array
         */
        $iteratorTerm = function ($term) use ($columns) {
            $iteratorColumn = function ($column) use ($term) {
                if (strpos($column, '.') === false) {
                    $column = 'entity.' . $column;
                }

                return [$column, 'LIKE', '%' . $term . '%'];
            };

            return array_map($iteratorColumn, $columns);
        };

        // Iterate given search terms
        foreach ($searchTerms as $operand => $terms) {
            // Create search criteria for each search term
            $searchCriteria = array_map($iteratorTerm, $terms);

            if (count($searchCriteria)) {
                // Create used criteria array
                $criteria = [
                    $operand => call_user_func_array('array_merge', $searchCriteria)
                ];

                // And attach search term condition to main query
                $queryBuilder->andWhere($this->getExpression($queryBuilder, $queryBuilder->expr()->andX(), $criteria));
            }
        }
    }

    /**
     * Simple process method for order by part of for current query builder.
     *
     * @param   QueryBuilder    $queryBuilder
     * @param   array           $orderBy
     *
     * @return  void
     */
    protected function processOrderBy(QueryBuilder $queryBuilder, array $orderBy)
    {
        foreach ($orderBy as $column => $order) {
            if (strpos($column, '.') === false) {
                $column = 'entity.' . $column;
            }

            $queryBuilder->addOrderBy($column, $order);
        }
    }

    /**
     * Recursively takes the specified criteria and adds too the expression.
     *
     * The criteria is defined in an array notation where each item in the list
     * represents a comparison <fieldName, operator, value>. The operator maps to
     * comparison methods located in ExpressionBuilder. The key in the array can
     * be used to identify grouping of comparisons.
     *
     * Currently supported  Doctrine\ORM\Query\Expr methods:
     *
     * OPERATOR    EXAMPLE INPUT ARRAY             GENERATED QUERY RESULT      NOTES
     *  eq          ['u.id',  'eq',        123]     u.id = ?1                   -
     *  neq         ['u.id',  'neq',       123]     u.id <> ?1                  -
     *  lt          ['u.id',  'lt',        123]     u.id < ?1                   -
     *  lte         ['u.id',  'lte',       123]     u.id <= ?1                  -
     *  gt          ['u.id',  'gt',        123]     u.id > ?1                   -
     *  gte         ['u.id',  'gte',       123]     u.id >= ?1                  -
     *  in          ['u.id',  'in',        [1,2]]   u.id IN (1,2)               third value may contain n values
     *  notIn       ['u.id',  'notIn',     [1,2]]   u.id NOT IN (1,2)           third value may contain n values
     *  isNull      ['u.id',  'isNull',    null]    u.id IS NULL                third value must be set, but not used
     *  isNotNull   ['u.id',  'isNotNull', null]    u.id IS NOT NULL            third value must be set, but not used
     *  like        ['u.id',  'like',      'abc']   u.id LIKE ?1                -
     *  notLike     ['u.id',  'notLike',   'abc']   u.id NOT LIKE ?1            -
     *  between     ['u.id',  'between'   [1,6]]    u.id BETWEEN ?1 AND ?2      third value must contain two values
     *
     * Also note that you can easily combine 'and' and 'or' queries like following examples:
     *
     * EXAMPLE INPUT ARRAY                                  GENERATED QUERY RESULT
     *  [
     *      'and' => [
     *          ['u.firstname', 'eq',   'foo bar']
     *          ['u.surname',   'neq',  'not this one']
     *      ]
     *  ]                                                   (u.firstname = ?1 AND u.surname <> ?2)
     *  [
     *      'or' => [
     *          ['u.firstname', 'eq',   'foo bar']
     *          ['u.surname',   'neq',  'not this one']
     *      ]
     *  ]                                                   (u.firstname = ?1 OR u.surname <> ?2)
     *
     * Also note that you can nest these criteria arrays as many levels as you need - only the sky is the limit...
     *
     * @example
     *  $criteria = [
     *      'or' => [
     *          ['entity.field1', 'like', '%field1Value%'],
     *          ['entity.field2', 'like', '%field2Value%'],
     *      ],
     *      'and' => [
     *          ['entity.field3', 'eq', 3],
     *          ['entity.field4', 'eq', 'four'],
     *      ],
     *      ['entity.field5', 'neq', 5].
     *  ];
     *
     * $qb = $this->createQueryBuilder('entity');
     * $qb->where($this->getExpression($qb, $qb->expr()->andX(), $criteria));
     * $query = $qb->getQuery();
     * echo $query->getSQL();
     *
     * // Result:
     * // SELECT *
     * // FROM tableName
     * // WHERE ((field1 LIKE '%field1Value%') OR (field2 LIKE '%field2Value%'))
     * // AND ((field3 = '3') AND (field4 = 'four'))
     * // AND (field5 <> '5')
     *
     * Also note that you can nest these queries as many times as you wish...
     *
     * @see https://gist.github.com/jgornick/8671644
     *
     * @param   QueryBuilder        $queryBuilder
     * @param   CompositeExpression $expression
     * @param   array               $criteria
     *
     * @return  CompositeExpression
     */
    protected function getExpression(QueryBuilder $queryBuilder, CompositeExpression $expression, array $criteria)
    {
        if (!count($criteria)) {
            return $expression;
        }

        foreach ($criteria as $key => $comparison) {
            if ($key === 'or' || array_key_exists('or', $comparison)) {
                $expression->add($this->getExpression($queryBuilder, $queryBuilder->expr()->orX(), $comparison));
            } elseif ($key === 'and' || array_key_exists('and', $comparison)) {
                $expression->add($this->getExpression($queryBuilder, $queryBuilder->expr()->andX(), $comparison));
            } else {
                // list used field, operator and value
                list($field, $operator, $value) = $comparison;

                // Increase parameter count
                $this->parameterCount++;

                // Initialize used callback parameters
                $parameters = [$field];

                // Array values needs some extra work
                if (is_array($value)) {
                    // Operator is between, so we need to add third parameter for Expr method
                    if (strtolower($operator) === 'between') {
                        $parameters[] = '?' . $this->parameterCount;
                        $queryBuilder->setParameter($this->parameterCount, $value[0]);

                        $this->parameterCount++;

                        $parameters[] = '?' . $this->parameterCount;
                        $queryBuilder->setParameter($this->parameterCount, $value[1]);
                    } else { // Otherwise this must be IN or NOT IN expression
                        $parameters[] = array_map(function ($value) use ($queryBuilder) {
                            return  $queryBuilder->expr()->literal($value);
                        }, $value);
                    }
                } elseif (strtolower($operator) === 'isnull' || strtolower($operator) === 'isnotnull') {
                    // In these cases we don't want to bind any parameters to query - because it's not needed
                } else { // And with "normal" case just add parameter and set it to query builder
                    $parameters[] = '?' . $this->parameterCount;

                    $queryBuilder->setParameter($this->parameterCount, $value);
                }

                // And finally add new expression to main one with specified parameters
                $expression->add(call_user_func_array([$queryBuilder->expr(), $operator], $parameters));
            }
        }

        return $expression;
    }
}
