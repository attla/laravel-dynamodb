<?php

namespace Kitar\Dynamodb\Query;

use Closure;
use Kitar\Dynamodb\Connection;
use Kitar\Dynamodb\Query\Grammar;
use Kitar\Dynamodb\Query\Processor;
use Kitar\Dynamodb\Query\ExpressionAttributes;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\Builder as BaseBuilder;

class Builder extends BaseBuilder
{

    /**
     * DynamoDB params which expected to share across all subqueries.
     */
    public $dynamo_params = [

        /**
         * Name of the index.
         * @var string|null
         */
        'index' => null,

        /**
         * The key.
         * @var array
         */
        'key' => [],

        /**
         * The item.
         * @var array
         */
        'item' => [],

        /**
         * The source of ProjectionExpression.
         * @var array
         */
        'projections' => [],

        /**
         * ConsistentRead option.
         * @var boolean
         */
        'consistent_read' => null,

        /**
         * dry run option.
         * @var boolean
         */
        'dry_run' => false,

        /**
         * The attribute name to place compiled wheres.
         * @var string
         */
        'bind_wheres_to' => 'FilterExpression',

        /**
         * The ExpressionAttributes object.
         * @var Kitar\Dynamodb\Query\ExpressionAttributes
         */
        'expression_attributes' => null,
    ];

    /**
     * @inheritdoc
     */
    public function __construct(Connection $connection, Grammar $grammar, Processor $processor, array $dynamo_params = [])
    {
        $this->connection = $connection;

        $this->grammar = $grammar;

        $this->processor = $processor;

        if (empty($dynamo_params['expression_attributes'])) {
            $dynamo_params['expression_attributes'] = new ExpressionAttributes;
        }

        $this->dynamo_params = array_merge(
            $this->dynamo_params,
            $dynamo_params
        );
    }

    /**
     * Set the index name.
     * @param string $index
     * @return $this
     */
    public function index(string $index)
    {
        $this->dynamo_params['index'] = $index;

        return $this;
    }

    /**
     * Set the key.
     * @param array $key
     * @return $this
     */
    public function key(array $key)
    {
        $this->dynamo_params['key'] = $key;

        return $this;
    }

    /**
     * Set the ConsistentRead option.
     * @param bool $active
     * @return $this
     */
    public function consistentRead($active = true)
    {
        $this->dynamo_params['consistent_read'] = $active;

        return $this;
    }

    /**
     * Set the dry run option. It'll return compiled params instead of calling DynamoDB.
     * @param bool $active
     * @return $this
     */
    public function dryRun($active = true)
    {
        $this->dynamo_params['dry_run'] = $active;

        return $this;
    }

    /**
     * If called, compiled wheres will be placed to FilterExpression.
     * @return $this
     */
    public function whereAsFilter()
    {
        $this->dynamo_params['bind_wheres_to'] = 'FilterExpression';

        return $this;
    }

    /**
     * If called, compiled wheres will be placed to ConditionExpression.
     * @return $this
     */
    public function whereAsCondition()
    {
        $this->dynamo_params['bind_wheres_to'] = 'ConditionExpression';

        return $this;
    }

    /**
     * If called, compiled wheres will be placed to KeyConditionExpression.
     * @return $this
     */
    public function whereAsKeyCondition()
    {
        $this->dynamo_params['bind_wheres_to'] = 'KeyConditionExpression';

        return $this;
    }

    /**
     * Get item.
     * @return Illuminate\Support\Collection|null
     */
    public function getItem($key = null)
    {
        if ($key) {
            $this->key($key);
        }

        return $this->process('getItem', 'processSingleItem');
    }

    /**
     * Put item.
     * @return \Aws\Result;
     */
    public function putItem($item)
    {
        $this->dynamo_params['item'] = $item;

        return $this->process('putItem', null);
    }

    /**
     * Delete item.
     * @return \Aws\Result;
     */
    public function deleteItem($key)
    {
        if ($key) {
            $this->key($key);
        }

        return $this->process('deleteItem', null);
    }

    /**
     * Query.
     * @return Illuminate\Support\Collection
     */
    public function query()
    {
        return $this->process('clientQuery', 'processMultipleItems');
    }

    /**
     * Scan.
     * @return Illuminate\Support\Collection
     */
    public function scan()
    {
        return $this->process('scan', 'processMultipleItems');
    }

    /**
     * @inheritdoc
     */
    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        // Convert column and value to ExpressionAttributes.
        if (! $column instanceof Closure) {
            $column = $this->dynamo_params['expression_attributes']->addName($column);
            if (! empty($value)) {
                $value = $this->dynamo_params['expression_attributes']->addValue($value);
            }
        }

        // If the columns is actually a Closure instance, we will assume the developer
        // wants to begin a nested where statement which is wrapped in parenthesis.
        // We'll add that Closure to the query then return back out immediately.
        if ($column instanceof Closure) {
            return $this->whereNested($column, $boolean);
        }

        // If the given operator is not found in the list of valid operators we will
        // assume that the developer is just short-cutting the '=' operators and
        // we will set the operators to '=' and set the values appropriately.
        if ($this->invalidOperator($operator)) {
            [$value, $operator] = [$operator, '='];
        }

        $type = 'Basic';

        // Now that we are working with just a simple query we can put the elements
        // in our array and add the query binding to our array of bindings that
        // will be bound to each SQL statements when it is finally executed.
        $this->wheres[] = compact(
            'type',
            'column',
            'operator',
            'value',
            'boolean'
        );

        if (! $value instanceof Expression) {
            $this->addBinding($value, 'where');
        }

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function newQuery()
    {
        return new static($this->connection, $this->grammar, $this->processor, $this->dynamo_params);
    }

    /**
     * Execute DynamoDB call and returns processed result.
     * @param string $query_method
     * @param array $params
     * @param string $processor_method
     * @return array|Illuminate\Support\Collection|Aws\Result
     */
    protected function process($query_method, $processor_method)
    {
        // Compile columns and wheres attributes.
        // These attributes needs to intaract with ExpressionAttributes during compile,
        // so it need to run before compileExpressionAttributes.
        $params = array_merge(
            $this->grammar->compileProjectionExpression($this->columns, $this->dynamo_params['expression_attributes']),
            $this->grammar->compileConditions($this),
        );

        // Compile rest of attributes.
        $params = array_merge(
            $params,
            $this->grammar->compileTableName($this->from),
            $this->grammar->compileIndexName($this->dynamo_params['index']),
            $this->grammar->compileKey($this->dynamo_params['key']),
            $this->grammar->compileItem($this->dynamo_params['item']),
            $this->grammar->compileConsistentRead($this->dynamo_params['consistent_read']),
            $this->grammar->compileExpressionAttributes($this->dynamo_params['expression_attributes']),
        );

        // Dry run.
        if ($this->dynamo_params['dry_run']) {
            return [
                'method' => $query_method,
                'params' => $params,
                'processor' => $processor_method
            ];
        }

        // Execute.
        $response = $this->connection->$query_method($params);

        // Process.
        if ($processor_method) {
            return $this->processor->$processor_method($response);
        } else {
            return $response;
        }
    }
}