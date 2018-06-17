<?php

namespace Agility\Data;

use AttributeHelper\Accessor;
use Aqua\Attribute;
use Aqua\Binary;
use Aqua\DeleteStatement;
use Aqua\InsertStatement;
use Aqua\Order;
use Aqua\SelectStatement;
use Aqua\Table;
use Aqua\UpdateStatement;
use Aqua\Visitors\Sanitizer;
use ArrayUtils\Arrays;
use ArrayUtils\Helpers\IndexFetcher;
use StringHelpers\Inflect;
use StringHelpers\Str;

	class Relation {

		use Accessor;
		use IndexFetcher;
		use Sanitizer;

		const Select = 1;
		const Insert = 2;
		const Update = 3;
		const Delete = 4;

		protected $_model;
		protected $_aquaTable;
		protected $_connection;
		protected $_statement;

		protected $_transformToModel = true;
		protected $_includes = false;

		const Ones = [
			"first",
			"second",
			"third",
			"fourth",
			"fifth",
			"sixth",
			"seventh",
			"eigth",
			"nineth"
		];

		const Tens = [
			"tenth" => 10,
			"eleventh" => 11,
			"twelfth" => 12,
			"thirteenth" => 13,
			"fourteenth" => 14,
			"fifteenth" => 15,
			"sixteenth" => 16,
			"seventeenth" => 17,
			"eighteenth" => 18,
			"nineteenth" => 19,
			"twentieth" => "twenty", /* 10th index */
			"thirtieth" => "thirty",
			"fourtieth" => "fourty",
			"fiftieth" => "fifty",
			"sixtieth" => "sixty",
			"seventieth" => "seventy",
			"eightieth" => "eighty",
			"ninetieth" => "ninety"
		];

		function __construct($model, $operation = Relation::Select) {

			$this->_model = $model;
			$this->_aquaTable = $model::aquaTable();
			$this->_connection = $model::connection();
			if ($operation == Relation::Select) {
				$this->_statement = new SelectStatement($this->_aquaTable);
			}
			else if ($operation == Relation::Insert) {
				$this->_statement = new InsertStatement($this->_aquaTable);
			}
			else if ($operation == Relation::Update) {
				$this->_statement = new UpdateStatement($this->_aquaTable);
			}
			else if ($operation == Relation::Delete) {
				$this->_statement = new DeleteStatement($this->_aquaTable);
			}
			else {
				throw new InvalidSqlOperationException();
			}

			$this->prependUnderscore();
			$this->readonly("aquaTable", "connection", "statement");
			$this->methodsAsProperties();

			$this->notFoundResponse(ACCESSOR_NOT_FOUND_CALLBACK, "defaultCallback");

		}

		function all() {
			return $this->_executeQuery();
		}

		function as($name) {

			$this->_statement->as($name);
			return $this;

		}

		protected function _buildJoinSequence($table, $type, $source = null) {

			if (is_array($table)) {

				// ["posts" => "comments"] => INNER JOIN posts ON posts.user_id = users.id INNER JOIN comments ON comments.post_id = posts.id
				// or
				// ["posts" => ["comments" => "guests"]] =>
				//	INNER JOIN posts ON posts.user_id = users.id INNER JOIN comments ON comments.post_id = posts.id INNER JOIN guests ON guests.comment_id = comments.id
				// ["posts" => ["comments" => "guests", "moderators"]] =>
				//	INNER JOIN posts ON posts.user_id = users.id INNER JOIN comments ON comments.post_id = posts.id INNER JOIN guests ON guests.comment_id = comments.id INNER JOIN moderators ON moderators.post_id = posts.id

				foreach ($table as $with => $subJoins) {

					if (is_numeric($with)) {

						$with = $subJoins;
						$subJoins = null;

					}

					$this->_statement->join($with, $type);
					$this->_setDefaultOnClause(empty($source) ? $this->_statement->relation->_name : $source, $with);

					if (!empty($subJoins)) {
						$this->join($subJoins, $type, $with);
					}

				}

			}
			else {

				$this->_statement->join($table, $type);
				$this->_setDefaultOnClause(empty($source) ? $this->_statement->relation->_name : $source, $table);

			}

		}

		protected function defaultCallback($name, $args = []) {

			$index = static::getIndex($name);
			if ($index !== false) {

				$index -= 1;
				return $this->range($index, 1)->first;

			}

		}

		function delete($clause, $params = []) {
			return $this->where($clause, $params);
		}

		function execute() {
			return $this->_executeQuery();
		}

		protected function _executeQuery() {

			$collections = $this->_connection->execute($this->_statement);
			if (is_a($this->_statment, SelectStatment::class)) {
				return $this->_tryBuildingObjects($collections);
			}

			return $collections;

		}

		function exists($statement) {

			$this->_statement->exists($statement);
			return $this;

		}

		function from($model) {

			$this->_statement->from($model::aquaTable());
			$this->_model = $model;
			return $this;

		}

		function fullJoin($with) {
			return $this->join($with, "FullJoin");
		}

		function groupBy($attribute) {

			$this->_statement->groupBy($attribute);
			return $this;

		}

		function includes($model) {

			$class = Helpers\NameHelper::classify($model, $this->_model);
			if (!class_exists($class)) {
				throw new ClassNotFoundException($class);
			}

			$this->_includes = [$class, $model];
			return $this;

		}

		function innerJoin($with) {
			return $this->join($with);
		}

		function insert($values = []) {

			if (empty($values)) {
				return;
			}

			foreach ($values as $name => $value) {
				$this->_statement->insert($this->_aquaTable->$name->eq($value));
			}

			return $this;

		}

		function join($table, $type = "InnerJoin") {

			$this->_buildJoinSequence($table, $type);
			// $this->_transformToModel = false;
			return $this;

		}

		function leftJoin($with) {
			return $this->join($with, "LeftJoin");
		}

		function notExists($statement) {

			return $this;

		}

		function on($clause) {

			$this->_statement->on($clause);
			return $this;

		}

		function order() {

			$sequences = func_get_args();
			foreach ($sequences as $seq) {

				if (is_array($seq)) {
					$this->_statement->order(new Order($seq[0], intval($seq[1] > 0)));
				}
				else {
					$this->_statement->order(new Order($seq, 1));
				}

			}

			return $this;

		}

		function range(int $offset, int $length = -1) {

			$this->_statement->range($offset, $length);
			return $this->_executeQuery();

		}

		protected function _resolveIncludes($objects) {

			if (empty($this->_includes)) {
				return;
			}

			$key = Inflect::singularize($this->_aquaTable->_name);
			$attribute = $key."Id";
			$key .= "_id";

			$includesName = $this->_includes[1];

			$relation = new Relation($this->_includes[0]);
			$resultSet = $relation->where($relation->aquaTable->$key->in($objects->map(":id")->all))->all;

			$objects->map(function($e) use ($includesName, $resultSet, $attribute) {

				$e->addSubObject($includesName, $resultSet->map(function ($r) use ($e, $attribute) {

					if ($e->id == $r->$attribute) {
						return $r;
					}

				}));

			});

		}

		function select() {

			$projections = func_get_args();
			if (count($projections) == 1 && is_array($projections[0])) {
				$projections = $projections[0];
			}

			foreach ($projections as $project) {
				$this->_statement->project($project);
			}

			return $this;

		}

		function _setDefaultOnClause($sourceTable, $referenceTable) {

			$foreignKeyName = Inflect::singularize($sourceTable)."_id";
			$this->_statement->on($this->sanitize($referenceTable).".".$this->sanitize($foreignKeyName)." = ".$this->sanitize($sourceTable).".`id`");

		}

		function skip(int $offset) {
			return $this->range($offset);
		}

		function take(int $length) {
			return $this->range(0, $length);
		}

		protected function _tryBuildingObjects($collections) {

			$collections = new Arrays($collections);
			if ($collections->empty) {
				return $collections;
			}

			$nativeAttributes = $collections[0]->toArray->keys;
			$modelAttributes = $this->_model::generatedAttributes()->keys;
			if (!$nativeAttributes->diff($modelAttributes)->empty) {
				return $collections;
			}

			$objects = new Arrays;
			foreach ($collections as $collection) {

				$object = new $this->_model;
				$object->fillAttributes($collection, false);

				$objects[] = $object;

			}

			$this->_resolveIncludes($objects);
			return $objects;

		}

		function toSql() {
			return $this->_connection->toSql($this->_statement);
		}

		function where($clause, $params = []) {

			if (is_array($clause)) {

				foreach ($clause as $col => $value) {

					if (is_numeric($col)) {
						$this->_statement->where($value, $params);
					}
					else {

						$col = Str::snakeCase($col);

						if (is_array($value)) {
							$this->_statement->where($this->_aquaTable->$col->in($value));
						}
						else {
							$this->_statement->where($this->_aquaTable->$col->eq($value));
						}

					}

				}

			}
			else {
				$this->_statement->where($clause, $params);
			}

			return $this;

		}

	}

?>