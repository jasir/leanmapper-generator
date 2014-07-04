<?php
namespace jasir\LeanMapperGenerator;

use LeanMapper\IMapper;
use LeanMapper\Exception;

class SchemaGenerator
{

	private $mapper;


	public function __construct(IMapper $mapper)
	{
		$this->mapper = $mapper;
	}


	public function createSchema(array $entities)
	{
		$schema = new \Doctrine\DBAL\Schema\Schema();

		$createdTables = array();
		foreach ($entities as $entity)
		{

			$reflection = $entity->getReflection($this->mapper);
			$properties = $reflection->getEntityProperties();
			$onEnd = array();


			if (count($properties) === 0) {
				continue;
			}

			$table = $schema->createTable($this->mapper->getTable(get_class($entity)));

			foreach ($properties as $property)
			{
				/** @var \LeanMapper\Reflection\Property $property */
				if (!$property->hasRelationship()) {
					$type = $this->getType($property);

					if ($type === NULL) {
						dump($property);
						throw new \Exception('Unknown type');
					}

					/** @var \Doctrine\DBAL\Schema\Column $column */
					$column = $table->addColumn($property->getColumn(), $type);

					if ($property->hasCustomFlag('primaryKey')) {
						$table->setPrimaryKey([$property->getColumn()]);
						if ($property->hasCustomFlag('unique')) {
							throw new Exception\InvalidAnnotationException(
								"Entity {$reflection->name}:{$property->getName()} - m:unique can not be used together with m:pk."
							);
						}
					}

					if ($property->hasCustomFlag('autoincrement')) {
						$column->setAutoincrement(true);
					}

					/*
					if ($property->containsEnumeration()) {
						$column->getType()->setEnumeration($property->getEnumValues());
					}
					*/

					if ($property->hasCustomFlag('size')) {
						$column->setLength($property->getCustomFlagValue('size'));
					}
				} else {
					$relationship = $property->getRelationship();

					if ($relationship instanceof \LeanMapper\Relationship\HasMany) {
						$relationshipTableName = $relationship->getRelationshipTable();
						if (!in_array($relationshipTableName, $createdTables)) {
							$createdTables[] = $relationshipTableName;
							$relationshipTable = $schema->createTable($relationship->getRelationshipTable());

							$relationshipTable->addColumn($relationship->getColumnReferencingSourceTable(), 'integer');
							$relationshipTable->addColumn($relationship->getColumnReferencingTargetTable(), 'integer');

							$relationshipTable->addForeignKeyConstraint(
								$table,
								[$relationship->getColumnReferencingSourceTable()],
								[$this->mapper->getPrimaryKey($relationship->getRelationshipTable())],
								array('onDelete' => 'CASCADE')
							);

							$relationshipTable->addForeignKeyConstraint(
								$relationship->getTargetTable(),
								[$relationship->getColumnReferencingTargetTable()],
								[$this->mapper->getPrimaryKey($relationship->getRelationshipTable())],
								array('onDelete' => 'CASCADE')
							);
						}

					} elseif ($relationship instanceof \LeanMapper\Relationship\HasOne) {
						$column = $table->addColumn($relationship->getColumnReferencingTargetTable(), 'integer');
						if (!$property->hasCustomFlag('nofk')) {
							$cascade = $property->isNullable() ? 'SET NULL' : 'CASCADE';
							$table->addForeignKeyConstraint(
								$relationship->getTargetTable(),
								[$column->getName()],
								[$this->mapper->getPrimaryKey($relationship->getTargetTable())],
								array('onDelete' => $cascade)
							);
						}

					}
				}

				if ($property->hasCustomFlag('unique')) {
					$indexColumns = $this->parseColumns($property->getCustomFlagValue('unique'), array($column->getName()));
					$onEnd[] = $this->createIndexClosure($table, $indexColumns, TRUE);
				}

				if ($property->hasCustomFlag('index')) {
					$indexColumns = $this->parseColumns($property->getCustomFlagValue('index'), array($column->getName()));
					$onEnd[] = $this->createIndexClosure($table, $indexColumns, FALSE);
				}

				if ($property->hasCustomFlag('comment')) {
					$column->setComment($property->getCustomFlagValue('comment'));
				}


				if (isset($column)) {
					if ($property->isNullable()) {
						$column->setNotnull(false);
					}

					if ($property->hasDefaultValue()) {
						$column->setDefault($property->getDefaultValue());
					}
				}
			}
			foreach ($onEnd as $cb) {
				$cb();
			}
		}


		return $schema;
	}

	private function createIndexClosure($table, $columns, $unique) {
		return function() use ($table, $columns, $unique) {
			if ($unique) {
				$table->addUniqueIndex($columns);
			} else {
				$table->addIndex($columns);
			}
		};
	}

	private function parseColumns($flag, $columns) {
		foreach (explode(',', $flag) as $c) {
			$c = trim($c);
			if (!empty($c)) {
				$columns[] = $c;
			}
		}
		return $columns;
	}


	private function getType(\LeanMapper\Reflection\Property $property)
	{
		$type = NULL;

		if ($property->isBasicType()) {
			$type = $property->getType();

			if ($type == 'string') {
				if (!$property->hasCustomFlag('size') || $property->getCustomFlagValue('size') >= 65536) {
					$type = 'text';
				}
			}

			/*if ($property->containsEnumeration()) {
				$type = 'enum';
			}*/

		} else {
			// Objects
			$class = new \ReflectionClass($property->getType());
			$class = $class->newInstance();

			if ($class instanceof \DateTime) {
				if ($property->hasCustomFlag('format')) {
					$type = $property->getCustomFlagValue('format');
				} else {
					$type = 'datetime';
				}
			}
		}

		return $type;
	}

}