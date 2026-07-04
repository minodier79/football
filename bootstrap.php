<?php

declare(strict_types=1);

use App\Config\DatabaseConfig;
use App\Core\Container;
use App\Core\EventDispatcher;
use App\Database\PdoConnectionFactory;
use App\Event\EntityCreatedEvent;
use App\Event\EntityDeletedEvent;
use App\Event\EntityUpdatedEvent;
use App\Listener\EntityIndexerListener;
use App\Scheduler\IndexFullTask;
use App\Scheduler\Scheduler;
use Dotenv\Dotenv;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use Http\Adapter\Guzzle7\Client as GuzzleAdapter;
use Psr\Http\Client\ClientInterface;

require_once __DIR__ . '/vendor/autoload.php';

/**
 * ENV
 */
Dotenv::createImmutable(__DIR__)->load();

/**
 * CONTAINER
 */
$container = new Container();

/**
 * SELF REGISTER
 */
$container->set(
    Container::class,
    $container
);

/**
 * DATABASE CONFIG
 */
$config = new DatabaseConfig(
    $_ENV['DB_DSN'],
    $_ENV['DB_USER'],
    $_ENV['DB_PASS']
);

/**
 * PDO
 */
$pdo = PdoConnectionFactory::fromConfig(
    $config
)->pdo();

$container->set(
    PDO::class,
    $pdo
);

/**
 * HTTP CLIENT (PSR-18)
 */
$httpClient = new GuzzleAdapter();

$container->set(
    ClientInterface::class,
    $httpClient
);

/**
 * ELASTICSEARCH CLIENT
 */
$esClient = ClientBuilder::create()
    ->setHosts([
        $_ENV['ES_HOST'] ?? 'https://localhost:9200'
    ])
    ->setBasicAuthentication(
        $_ENV['ES_USER'] ?? 'elastic',
        $_ENV['ES_PASS'] ?? ''
    )
    ->setSSLVerification(false) // DEV ONLY
    ->build();

$container->set(
    Client::class,
    $esClient
);

/**
 * EVENT DISPATCHER
 */
$dispatcher = new EventDispatcher();

$container->set(
    EventDispatcher::class,
    $dispatcher
);

/**
 * LISTENERS
 */
$listener = $container->get(
    EntityIndexerListener::class
);

/**
 * ENTITY CREATED
 */
$dispatcher->listen(
    EntityCreatedEvent::class,
    [$listener, 'onCreated']
);

/**
 * ENTITY UPDATED
 */
$dispatcher->listen(
    EntityUpdatedEvent::class,
    [$listener, 'onUpdated']
);

/**
 * ENTITY DELETED
 */
$dispatcher->listen(
    EntityDeletedEvent::class,
    [$listener, 'onDeleted']
);


$container->set(
    IndexFullTask::class,
    new IndexFullTask()
);

$container->set(
    Scheduler::class,
    new Scheduler([
        $container->get(IndexFullTask::class)
    ])
);

/**
 * RETURN CONTAINER
 */
return $container;