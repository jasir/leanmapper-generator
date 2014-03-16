<?php

use LeanMapper\IMapper;

class SchemaGenerator
{

	private $mapper;


	public function __construct(IMapper $mapper)
	{
		$this->mapper = $mapper;
	}


	public function createSchema(array $entities)
	{
		$schema = new Doctrine\DBAL\Schema\Schema();

		/** @var \LeanMapper\Entity $entity */
		foreach ($entities as $entity)
		{
			$reflection = $entity->getReflection($this->mapper);
			$properties = $reflection->getEntityProperties();

			if (count($properties) === 0) {
				continue;
			}

			$table = $schema->createTable($this->mapper->getTable(get_class($entity)));

			foreach ($properties as $property)
			{
				if (!$property->hasRelationship()) {
					$type = $this->getType($property);

					if ($type === NULL) {
						continue;
					}

					/** @var \Doctrine\DBAL\Schema\Column $column */
					$column = $table->addColumn($property->getColumn(), $type);

					if ($property->hasCustomFlag('autoincrement')) {
						$column->setAutoincrement(true);
						$table->setPrimaryKey([$property->getColumn()]);
					}

					if ($property->hasCustomFlag('unique')) {
						$table->addUniqueIndex([$column->getName()]);
					}

					if ($property->containsEnumeration()) {
						$column->getType()->setEnumeration($property->getEnumValues());
					}

					if ($property->hasCustomFlag('size')) {
						$column->setLength($property->getCustomFlagValue('size'));
					}
				} else {
					$relationship = $property->getRelationship();

					if ($relationship instanceof \LeanMapper\Relationship\HasMany) {
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

					} elseif ($relationship instanceof \LeanMapper\Relationship\HasOne) {
						$column = $table->addColumn($relationship->getColumnReferencingTargetTable(), 'integer');
						$cascade = $property->isNullable() ? 'SET NULL' : 'CASCADE';
						$table->addForeignKeyConstraint($relationship->getTargetTable(), [$column->getName()], [$this->mapper->getPrimaryKey($relationship->getTargetTable())], array('onDelete' => $cascade));
					}
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
		}

		return $schema;
	}


	private function getType(LeanMapper\Reflection\Property $property)
	{
		$type = NULL;

		if ($property->isBasicType()) {
			$type = $property->getType();

			if ($type == 'string') {
				if (!$property->hasCustomFlag('size')) {
					$type = 'text';
				}
			}

			if ($property->containsEnumeration()) {
				$type = 'enum';
			}
		} else {
			// Objects
			$class = new ReflectionClass($property->getType());
			$class = $class->newInstance();

			if ($class instanceof DateTime) {
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