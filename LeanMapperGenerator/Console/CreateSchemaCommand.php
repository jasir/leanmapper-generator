<?php

namespace LeanMapper\Console;

use Symfony\Component\Console\Command\Command,
    Symfony\Component\Console\Input\InputInterface,
    Symfony\Component\Console\Output\OutputInterface,
    Nette\Reflection\ClassType,
    Nette\Utils\Finder;

class CreateSchemaCommand extends Command
{
    protected $config;
    
    public function __construct(array $config, $name = NULL)
    {
	
	parent::__construct($name);
	$this->config = $config;
    }
    
    protected function configure()
    {
        $this->setName('app:schema')
            ->setDescription('Creates database schema from LeanMapper entities');
    }

    
    protected function findNamespace()
    {
	$container = $this->getHelper('container');
	$config = $this->config;
	
	if(isset($config['namespace'])) {
	    return $config['namespace'];
	} else {
	    $mapper = $container->getByType('LeanMapper\IMapper');
	    $reflection = new ClassType($mapper);
	    try {
		
		$properties = $reflection->getDefaultProperties();
		if(isset($properties['defaultEntityNamespace'])) {
		    return $properties['defaultEntityNamespace'];
		}
	    } catch (\ReflectionException $ex) {}
	}
	
	throw new SchemaException('Entity namespace not set, use config.namespace to set proper namespace.');
    }
    
    protected function getEntities($namespace)
    {
	$config = $this->config;	
	
	if(isset($config['directory'])) {
	    $path = $config['directory'];
	} else {
	    $container = $this->getHelper('container');	  
	    $dirs = explode('\\', $namespace);
	    array_shift($dirs);
	    $dirs[0] = strtolower($dirs[0]);

	    $path = $container->getContainer()->getParameters()['appDir'].
		    DIRECTORY_SEPARATOR.
		    implode(DIRECTORY_SEPARATOR, $dirs);
	    
	    
	    $entities = array();
	    
	    try {
		foreach(Finder::findFiles('*.php')->in($path) as $file) {
		    $class = $namespace.'\\'.$file->getBaseName('.php');
		    $entities[] = new $class;
		}
	    } catch (\UnexpectedValueException $ex) {
		throw new SchemaException('Directory not set, use config.directory to set proper directory.');
	    }
	    
	    return $entities;
	}
    }
    
    protected function execute(InputInterface $input, OutputInterface $output)
    {	
	try {
	    $namespace = $this->findNamespace();
	    $entities = $this->getEntities($namespace);	    
	} catch (SchemaException $e) {
	    $output->writeln('<error>'.$e->getMessage().'</error>');
	    return 1;
	}
	
	$container = $this->getHelper('container')->getContainer();
	$config = $this->config;
	
	$generator = $container->getByType('LeanMapperGenerator\SchemaGenerator');
	$platform = new Doctrine\DBAL\Platforms\MySqlPlatform();
	$schema = $generator->createSchema($entities);
	
	if(file_exists($config->logFile)) {
	    $fromSchema = unserialize(file_get_contents($config->logFile));
	    $sqls = $schema->getMigrateFromSql($fromSchema, $platform);
	} else {
	    $sqls = $schema->toSql($platform);
	}
	
	if(count($sqls) > 0) {
	    $output->writeln('Creating database schema...');
	    $connection = $container->getByType('DibiConnection'); 
	    
	    
	    $sql = '';
	    foreach($sqls as $query) {
		$sql .= $query.'\n';
		$output->writeln($query);
		$connection->query($query);
	    }
	    $output->write(count($sqls).'queries executed.\n'
		    . $config->logFile.' updated.\n'
		    . $config->sqlFile.' updated.');
	    
	    
	    file_put_contents($config->sqlFile, $sql);
	    file_put_contents($config->logFile, serialize($schema));
	} else {
	    $output->writeln('Nothing to change...');
	}
	

    }
}

class SchemaException extends \Exception {}