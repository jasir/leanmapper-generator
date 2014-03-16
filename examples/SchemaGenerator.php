$schemaGenerator = new SchemaGenerator(...);
$platform = new Doctrine\DBAL\Platforms\MySqlPlatform();
$schema = $schemaGenerator->createSchema(...);
$sqls = [];

if (file_exists(__DIR__ . '/schema.log')) {
	$fromSchema = unserialize(file_get_contents(__DIR__ . '/schema.log'));

	$sqls = $schema->getMigrateFromSql($fromSchema, $platform);
} else {
	$sqls = $schema->toSql($platform);
}

// Save schema
file_put_contents('schema.log', serialize($schema));

if (count($sqls) > 0) {
	foreach ($sqls as $sql)
	{
		$connection->query($sql);
	}
}