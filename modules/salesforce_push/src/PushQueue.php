<?php

namespace Drupal\salesforce_push;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Merge;
use Drupal\Core\Database\SchemaObjectExistsException;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\DatabaseQueue;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\State\State;
use Drupal\salesforce_mapping\MappedObjectStorage;
use Drupal\salesforce_mapping\SalesforceMappingStorage;
use Drupal\salesforce\EntityNotFoundException;

/**
 * Salesforce push queue.
 *
 * @ingroup queue
 */
class PushQueue extends DatabaseQueue {

  /**
   * The database table name.
   */
  const TABLE_NAME = 'salesforce_push_queue';

  const DEFAULT_CRON_PUSH_LIMIT = 200;

  const DEFAULT_QUEUE_PROCESSOR = 'rest';

  const DEFAULT_MAX_FAILS = 10;

  protected $limit;
  protected $connection;
  protected $state;
  protected $queueManager;
  protected $max_fails;

  /**
   * Storage handler for SF mappings
   *
   * @var SalesforceMappingStorage
   */
  protected $mapping_storage;

  /**
   * Storage handler for Mapped Objects
   *
   * @var MappedObjectStorage
   */
  protected $mapped_object_storage;

  /**
   * Constructs a \Drupal\Core\Queue\DatabaseQueue object.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The Connection object containing the key-value tables.
   */
  public function __construct(Connection $connection, State $state, PushQueueProcessorPluginManager $queue_manager, EntityManagerInterface $entity_manager, LoggerChannelFactoryInterface $logger_factory) {
    $this->connection = $connection;
    $this->state = $state;
    $this->queueManager = $queue_manager;
    $this->entity_manager = $entity_manager;
    $this->mapping_storage = $entity_manager->getStorage('salesforce_mapping');
    $this->mapped_object_storage = $entity_manager->getStorage('salesforce_mapped_object');
    $this->logger = $logger_factory->get('Salesforce Push');

    $this->limit = $state->get('salesforce.push_limit', static::DEFAULT_CRON_PUSH_LIMIT);

    $this->max_fails = $state->get('salesforce.push_queue_max_fails', static::DEFAULT_MAX_FAILS);
  }

  /**
   * Parent class DatabaseQueue relies heavily on $this->name, so it's best to
   * just set the value appropriately.
   *
   * @param string $name
   *
   * @return $this
   */
  public function setName($name) {
    $this->name = $name;
    return $this;
  }

  /**
   * Adds a queue item and store it directly to the queue.
   *
   * @param array $data
   *   Data array with the following key-value pairs:
   *   * 'name': the name of the salesforce mapping for this entity
   *   * 'entity_id': the entity id being mapped / pushed
   *   * 'op': the operation which triggered this push.
   *
   * @return
   *   On success, Drupal\Core\Database\Query\Merge::STATUS_INSERT or Drupal\Core\Database\Query\Merge::STATUS_UPDATE
   *
   * @throws Exception if the required indexes are not provided.
   *
   * @TODO convert $data to a proper class and make sure that's what we get for this argument.
   */
  protected function doCreateItem($data) {
    if (empty($data['name'])
    || empty($data['entity_id'])
    || empty($data['op'])) {
      throw new \Exception('Salesforce push queue data values are required for "name", "entity_id" and "op"');
    }
    $this->name = $data['name'];
    $time = time();
    $fields = [
      'name' => $this->name,
      'entity_id' => $data['entity_id'],
      'op' => $data['op'],
      'updated' => $time,
      'failures' => empty($data['failures'])
        ? 0
        : $data['failures'],
      'mapped_object_id' => empty($data['mapped_object_id'])
        ? 0
        : $data['mapped_object_id'],
    ];

    $query = $this->connection->merge(static::TABLE_NAME)
      ->key(array('name' => $this->name, 'entity_id' => $data['entity_id']))
      ->fields($fields);

    // Return Merge::STATUS_INSERT or Merge::STATUS_UPDATE
    $ret = $query->execute();

    // Drupal still doesn't support now() https://www.drupal.org/node/215821
    // 9 years.
    if ($ret == Merge::STATUS_INSERT) {
      $this->connection->merge(static::TABLE_NAME)
        ->key(array('name' => $this->name, 'entity_id' => $data['entity_id']))
        ->fields(['created' => $time])
        ->execute();
    }
    return $ret;
  }

  /**
   * Claim up to $n items from the current queue.
   * If queue is empty, return an empty array.
   * @see DatabaseQueue::claimItem
   * @return array $items
   *   Zero to $n Items indexed by item_id
   */
  public function claimItems($n, $lease_time = 300) {
    while (TRUE) {
      try {
        // @TODO: convert items to content entities.
        // @see \Drupal::entityQuery()
        $items = $this->connection->queryRange('SELECT * FROM {' . static::TABLE_NAME . '} q WHERE expire = 0 AND name = :name AND failures < :fail_limit ORDER BY created, item_id ASC', 0, $n, array(':name' => $this->name, ':fail_limit' => $this->max_fails))->fetchAllAssoc('item_id');
      }
      catch (\Exception $e) {
        $this->catchException($e);
        // If the table does not exist there are no items currently available to
        // claim.
        return [];
      }
      if ($items) {
        // Try to update the item. Only one thread can succeed in UPDATEing the
        // same row. We cannot rely on REQUEST_TIME because items might be
        // claimed by a single consumer which runs longer than 1 second. If we
        // continue to use REQUEST_TIME instead of the current time(), we steal
        // time from the lease, and will tend to reset items before the lease
        // should really expire.
        $update = $this->connection->update(static::TABLE_NAME)
          ->fields(array(
            'expire' => time() + $lease_time,
          ))
          ->condition('item_id', array_keys($items), 'IN')
          ->condition('expire', 0);
        // If there are affected rows, this update succeeded.
        if ($update->execute()) {
          return $items;
        }
      }
      else {
        // No items currently available to claim.
        return [];
      }
    }
  }

  /**
   * DO NOT USE THIS FUNCTION.
   * Use claimItems() instead.
   */
  public function claimItem($lease_time = NULL) {
    throw new \Exception('This queue is designed to process multiple items at once. Please use "claimItems" instead.');
  }

  /**
   * Defines the schema for the queue table.
   */
  public function schemaDefinition() {
    return [
      'description' => 'Drupal entities to push to Salesforce.',
      'fields' => [
        'item_id' => [
          'type' => 'serial',
          'unsigned' => TRUE,
          'not null' => TRUE,
          'description' => 'Primary Key: Unique item ID.',
        ],
        'name' => [
          'type' => 'varchar_ascii',
          'length' => 255,
          'not null' => TRUE,
          'default' => '',
          'description' => 'The salesforce mapping id',
        ],
        'entity_id' => [
          'type' => 'int',
          'not null' => TRUE,
          'default' => 0,
          'description' => 'The entity id',
        ],
        'mapped_object_id' => [
          'type' => 'int',
          'not null' => TRUE,
          'default' => 0,
          'description' => 'Foreign key for salesforce_mapped_object table.'
        ],
        'op' => [
          'type' => 'varchar_ascii',
          'length' => 16,
          'not null' => TRUE,
          'default' => '',
          'description' => 'The operation which triggered this push',
        ],
        'failures' => [
          'type' => 'int',
          'not null' => TRUE,
          'default' => 0,
          'description' => 'Number of failed push attempts for this queue item.',
        ],
        'expire' => [
          'type' => 'int',
          'not null' => TRUE,
          'default' => 0,
          'description' => 'Timestamp when the claim lease expires on the item.',
        ],
        'created' => [
          'type' => 'int',
          'not null' => TRUE,
          'default' => 0,
          'description' => 'Timestamp when the item was created.',
        ],
        'updated' => [
          'type' => 'int',
          'not null' => TRUE,
          'default' => 0,
          'description' => 'Timestamp when the item was created.',
        ],
      ],
      'primary key' => ['item_id'],
      'unique keys' => [
        'name_entity_id' => ['name', 'entity_id'],
      ],
      'indexes' => [
        'entity_id' => ['entity_id'],
        'name_created' => ['name', 'created'],
        'expire' => ['expire'],
      ],
    ];
  }

  /**
   * Process Salesforce queues
   */
  public function processQueues() {
    $mappings = $this
      ->mapping_storage
      ->loadPushMappings();
    if (empty($mappings)) {
      return $this;
    }
    $i = 0;

    // @TODO push queue processor could be set globally, or per-mapping. Exposing some UI setting would probably be better than this:
    $plugin_name = $this->state->get('salesforce.push_queue_processor', static::DEFAULT_QUEUE_PROCESSOR);

    $queue_processor = $this->queueManager->createInstance($plugin_name);

    foreach ($mappings as $mapping) {
      // Set the queue name, which is the mapping id.
      $this->setName($mapping->id());

      // Iterate through items in this queue until we run out or hit the limit.
      while (TRUE) {
        // Claim as many items as we can from this queue and advance our counter. If this queue is empty, move to the next mapping.
        $items = $this->claimItems($this->limit);
        if (empty($items)) {
          continue 2;
        }

        // Hand them to the queue processor.
        try {
          $queue_processor->process($items);
        }
        catch (RequeueException $e) {
          // Getting a Requeue here is weird for a group of items, but we'll
          // deal with it.
          $this->releaseItems($items);
          watchdog_exception('Salesforce Push', $e);
        }
        catch (SuspendQueueException $e) {
          // Getting a SuspendQueue is more likely, e.g. because of a network
          // or authorization error. Release items and move on to the next
          // mapping in this case.
          $this->releaseItems($items);
          watchdog_exception('Salesforce Push', $e);

          continue 2;
        }
        catch (\Exception $e) {
          // In case of any other kind of exception, log it and leave the item
          // in the queue to be processed again later.
          // @TODO: this is how Cron.php queue works, but I don't really understand why it doesn't get re-queued.
          watchdog_exception('Salesforce Push', $e);
        }
        finally {
          // If we've reached our limit, we're done. Otherwise, continue to next items.
          $i += count($items);
          if ($i >= $this->limit) {
            return $this;
          }
        }
      }
    }
    return $this;
  }

  /**
   * Exception handler so that Queue Processors don't have to worry about what
   * happens when a queue item fails.
   *
   * @param Exception $e
   * @param stdClass $item
   */
  public function failItem(\Exception $e, \stdClass $item) {
    $mapping = $this->mapping_storage->load($item->name);

    if ($e instanceof EntityNotFoundException) {
      // If there was an exception loading any entities, we assume that this queue item is no longer relevant.
      $this->logger->error($e->getMessage() .
        ' Exception while loading entity %type %id for salesforce mapping %mapping. Queue item deleted.',
        [
          '%type' => $mapping->get('drupal_entity_type'),
          '%id' => $item->entity_id,
          '%mapping' => $mapping->id(),
        ]
      );
      $this->deleteItem($item);
      return;
    }

    $item->failures++;

    $message = $e->getMessage();
    if ($item->failures >= $this->max_fails) {
      $message = 'Permanently failed queue item %item failed %fail times. Exception while pushing entity %type %id for salesforce mapping %mapping. ' . $message;
    }
    else {
      $message = 'Queue item %item failed %fail times. Exception while pushing entity %type %id for salesforce mapping %mapping. ' . $message;
    }

    $this->logger->error($message,
      [
        '%type' => $mapping->get('drupal_entity_type'),
        '%id' => $item->entity_id,
        '%mapping' => $mapping->id(),
        '%item' => $item->item_id,
        '%fail' => $item->failures,
      ]
    );

    // Failed items will remain in queue, but not be released. They'll be
    // retried only when the current lease expires.
    // doCreateItem() doubles as "save" function.
    $this->doCreateItem(get_object_vars($item));
  }

  /**
   * same as releaseItem, but for multiple items
   * @param array $items
   *   Indexes must be item ids. Values are ignored. Return from claimItems()
   *   is acceptable.
   */
  public function releaseItems(array $items) {
    try {
      $update = $this->connection->update(static::TABLE_NAME)
        ->fields(array(
          'expire' => 0,
        ))
        ->condition('item_id', array_keys($items), 'IN');
      return $update->execute();
    }
    catch (\Exception $e) {
      watchdog_exception('Salesforce Push', $e);
      $this->catchException($e);
      // If the table doesn't exist we should consider the item released.
      return TRUE;
    }
  }

  public function deleteItemByEntity(EntityInterface $entity) {
    try {
      $this->connection->delete(static::TABLE_NAME)
        ->condition('entity_id', $entity->id())
        ->condition('name', $this->name)
        ->execute();
    }
    catch (\Exception $e) {
      $this->catchException($e);
    }
  }

}