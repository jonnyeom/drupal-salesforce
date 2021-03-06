<?php

namespace Drupal\salesforce_mapping\Entity;

use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\RevisionableContentEntityBase;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Language\LanguageInterface;
use Drupal\salesforce\Event\SalesforceEvents;
use Drupal\salesforce\Event\SalesforceNoticeEvent;
use Drupal\salesforce\Event\SalesforceWarningEvent;
use Drupal\salesforce\Exception as SalesforceException;
use Drupal\salesforce\SFID;
use Drupal\salesforce\SObject;
use Drupal\salesforce_mapping\Event\SalesforcePullEntityValueEvent;
use Drupal\salesforce_mapping\Event\SalesforcePullEvent;
use Drupal\salesforce_mapping\Event\SalesforcePushParamsEvent;
use Drupal\salesforce_mapping\MappingConstants;
use Drupal\salesforce_mapping\PushParams;
use Drupal\salesforce_mapping\Plugin\Field\ComputedItemList;
use Drupal\typed_data\DataFetcherTrait;

/**
 * Defines a Salesforce Mapped Object entity class.
 *
 * Mapped Objects are content entities, since they're defined by references
 * to other content entities.
 *
 * @ContentEntityType(
 *   id = "salesforce_mapped_object",
 *   label = @Translation("Salesforce Mapped Object"),
 *   module = "salesforce_mapping",
 *   handlers = {
 *     "storage" = "Drupal\salesforce_mapping\MappedObjectStorage",
 *     "storage_schema" = "Drupal\salesforce_mapping\MappedObjectStorageSchema",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *      "views_data" = "Drupal\views\EntityViewsData",
 *     "list_builder" = "Drupal\salesforce_mapping\MappedObjectList",
 *     "form" = {
 *       "default" = "Drupal\salesforce_mapping\Form\MappedObjectForm",
 *       "add" = "Drupal\salesforce_mapping\Form\MappedObjectForm",
 *       "edit" = "Drupal\salesforce_mapping\Form\MappedObjectForm",
 *       "delete" = "Drupal\salesforce_mapping\Form\MappedObjectDeleteForm",
 *      },
 *     "access" = "Drupal\salesforce_mapping\MappedObjectAccessControlHandler",
 *   },
 *   base_table = "salesforce_mapped_object",
 *   revision_table = "salesforce_mapped_object_revision",
 *   admin_permission = "administer salesforce mapping",
 *   links = {
 *     "canonical" = "/admin/content/salesforce/{salesforce_mapped_object}",
 *     "add-form" = "/admin/content/salesforce/add",
 *     "edit-form" = "/admin/content/salesforce/{salesforce_mapped_object}/edit",
 *     "delete-form" = "/admin/content/salesforce/{salesforce_mapped_object}/delete"
 *   },
 *   entity_keys = {
 *      "id" = "id",
 *      "entity_id" = "drupal_entity__target_id",
 *      "salesforce_id" = "salesforce_id",
 *      "revision" = "revision_id",
 *      "label" = "salesforce_id"
 *   },
 *   constraints = {
 *     "MappingSfid" = {},
 *     "MappingEntity" = {},
 *     "MappingEntityType" = {}
 *   }
 * )
 */
class MappedObject extends RevisionableContentEntityBase implements MappedObjectInterface {

  use DataFetcherTrait;
  use EntityChangedTrait;

  /**
   * Salesforce Object.
   *
   * @var \Drupal\salesforce\SObject
   */
  protected $sf_object = NULL;

  protected $drupal_entity_stub = NULL;

  /**
   * Overrides ContentEntityBase::__construct().
   */
  public function __construct(array $values) {
    // @TODO: Revisit this language stuff
    // Drupal adds a layer of abstraction for translation purposes, even though
    // we're talking about numeric identifiers that aren't language-dependent
    // in any way, so we have to build our own constructor in order to allow
    // callers to ignore this layer.
    foreach ($values as &$value) {
      if (!is_array($value)) {
        $value = [LanguageInterface::LANGCODE_DEFAULT => $value];
      }
    }
    parent::__construct($values, 'salesforce_mapped_object');
  }

  /**
   * {@inheritdoc}
   */
  public function save() {
    $this->changed = $this->getRequestTime();
    if ($this->isNew()) {
      $this->created = $this->getRequestTime();
    }
    return parent::save();
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(EntityStorageInterface $storage, $update = TRUE) {
    if ($update) {
      $this->pruneRevisions($storage);
    }
    return parent::postSave($storage, $update);
  }

  /**
   * {@inheritdoc}
   */
  public function pruneRevisions(EntityStorageInterface $storage) {
    $limit = $this
      ->config('salesforce.settings')
      ->get('limit_mapped_object_revisions');
    if ($limit <= 0) {
      // limit 0 means no limit.
      return;
    }
    $count = $storage
      ->getQuery()
      ->allRevisions()
      ->condition('id', $this->id())
      ->count()
      ->execute();

    // Query for any revision id beyond the limit
    if ($count <= $limit) {
      return;
    }
    $vids_to_delete = $storage
      ->getQuery()
      ->allRevisions()
      ->condition('id', $this->id())
      ->range($limit, $count)
      ->sort('changed', 'DESC')
      ->execute();
    if (empty($vids_to_delete)) {
      return;
    }
    foreach ($vids_to_delete as $vid => $dummy) {
      $storage->deleteRevision($vid);
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $i = 0;
    if (\Drupal::moduleHandler()->moduleExists('dynamic_entity_reference')) {
      $fields['drupal_entity'] = BaseFieldDefinition::create('dynamic_entity_reference')
        ->setLabel(t('Mapped Entity'))
        ->setDescription(t('Reference to the Drupal entity mapped by this mapped object.'))
        ->setRevisionable(FALSE)
        ->setCardinality(1)
        ->setDisplayOptions('form', [
          'type' => 'dynamic_entity_reference_default',
          'weight' => $i,
        ])
        ->setDisplayOptions('view', [
          'label' => 'above',
          'type' => 'dynamic_entity_reference_label',
          'weight' => $i++,
        ])
        ->setDisplayConfigurable('form', TRUE)
        ->setDisplayConfigurable('view', TRUE);
    }

    $fields['salesforce_mapping'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Salesforce mapping'))
      ->setDescription(t('Salesforce mapping used to push/pull this mapped object'))
      ->setRevisionable(TRUE)
      ->setSetting('max_length', EntityTypeInterface::ID_MAX_LENGTH)
      ->setSetting('target_type', 'salesforce_mapping')
      ->setSetting('handler', 'default')
      ->setRequired(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => $i,
      ])
      ->setSettings([
        'allowed_values' => [
          // SF Mappings for this entity type go here.
        ],
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => $i++,
      ]);

    // @TODO make this work with Drupal\salesforce\SFID (?)
    $fields['salesforce_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Salesforce ID'))
      ->setDescription(t('Reference to the mapped Salesforce object (SObject)'))
      ->setRevisionable(TRUE)
      ->setTranslatable(FALSE)
      ->setSetting('is_ascii', TRUE)
      ->setSetting('max_length', SFID::MAX_LENGTH)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => $i++,
      ])
      ->setDisplayOptions('view', [
        'type' => 'hidden',
      ]);

    $fields['salesforce_link'] = BaseFieldDefinition::create('salesforce_link')
      ->setLabel('Salesforce Record')
      ->setDescription(t('Link to salesforce record'))
      ->setRevisionable(FALSE)
      ->setTranslatable(FALSE)
      ->setComputed(TRUE)
      ->setClass(ComputedItemList::class)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => $i++,
      ]);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Authored on'))
      ->setDescription(t('The time that the object mapping was created.'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'timestamp',
        'weight' => $i++,
      ]);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time that the object mapping was last edited.'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => $i++,
      ]);

    $fields['entity_updated'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Drupal Entity Updated'))
      ->setDescription(t('The Unix timestamp when the mapped Drupal entity was last updated.'))
      ->setRevisionable(TRUE)
      ->setDefaultValue(0)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => $i++,
      ]);

    $fields['last_sync_status'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Status of most recent sync'))
      ->setDescription(t('Indicates whether most recent sync was successful or not.'))
      ->setRevisionable(TRUE);

    $fields['last_sync_action'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Action of most recent sync'))
      ->setDescription(t('Indicates acion which triggered most recent sync for this mapped object'))
      ->setSetting('is_ascii', TRUE)
      ->setSetting('max_length', MappingConstants::SALESFORCE_MAPPING_TRIGGER_MAX_LENGTH)
      ->setRevisionable(TRUE);

    $fields['force_pull'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Force Pull'))
      ->setDescription(t('Whether to ignore entity timestamps and force an update on the next pull for this record.'))
      ->setRevisionable(FALSE);

    // @see ContentEntityBase::baseFieldDefinitions
    // and RevisionLogEntityTrait::revisionLogBaseFieldDefinitions
    $fields += parent::baseFieldDefinitions($entity_type);

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getChanged() {
    return $this->get('entity_updated')->value;
  }

  /**
   * Get the attached mapping entity.
   *
   * @return SalesforceMappingInterface
   *   The mapping entity.
   */
  public function getMapping() {
    return $this->salesforce_mapping->entity;
  }

  /**
   * Get the mapped Drupal entity.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The mapped Drupal entity.
   */
  public function getMappedEntity() {
    return $this->drupal_entity->entity;
  }

  /**
   * {@inheritdoc}
   */
  public function setDrupalEntity(EntityInterface $entity = NULL) {
    $this->set('drupal_entity', $entity);
    return $this;
  }

  /**
   * @return Link
   */
  public function getSalesforceLink(array $options = []) {
    // @TODO this doesn't work
    return;
    $defaults = ['attributes' => ['target' => '_blank']];
    $options = array_merge($defaults, $options);
    return l($this->sfid(), $this->getSalesforceUrl(), $options);
  }

  /**
   * Wrapper for salesforce.client.
   *
   * @return \Drupal\salesforce\Rest\RestClient service
   *   Salesforce REST client service.
   */
  public function client() {
    return \Drupal::service('salesforce.client');
  }

  /**
   * Wrapper for Drupal core event_dispatcher service.
   */
  public function eventDispatcher() {
    return \Drupal::service('event_dispatcher');
  }

  public function config($name) {
    return \Drupal::service('config.factory')->get($name);
  }

  /**
   * @return string
   */
  public function getSalesforceUrl() {
    // @TODO dependency injection here:
    return $this->client()->getInstanceUrl() . '/' . $this->salesforce_id->value;
  }

  /**
   * @return string
   *   SFID
   */
  public function sfid() {
    return $this->salesforce_id->value;
  }

  /**
   * @return mixed
   *   SFID or NULL depending on result from SF.
   */
  public function push() {
    // @TODO need error handling, logging, and hook invocations within this function, where we can provide full context, or short of that clear documentation on how callers should handle errors and exceptions. At the very least, we need to make sure to include $params in some kind of exception if we're not going to handle it inside this function.

    $mapping = $this->getMapping();

    $drupal_entity = $this->getMappedEntity();

    // Previously hook_salesforce_push_params_alter.
    $params = new PushParams($mapping, $drupal_entity);
    $this->eventDispatcher()->dispatch(
      SalesforceEvents::PUSH_PARAMS,
      new SalesforcePushParamsEvent($this, $params)
    );

    // @TODO is this the right place for this logic to live?
    // Cases:
    // 1. upsert key is defined: use upsert
    // 2. no upsert key, no sfid: use create
    // 3. no upsert key, sfid: use update
    $result = FALSE;
    $action = '';

    if ($mapping->hasKey()) {
      $action = 'upsert';
      $result = $this->client()->objectUpsert(
        $mapping->getSalesforceObjectType(),
        $mapping->getKeyField(),
        $mapping->getKeyValue($drupal_entity),
        $params->getParams()
      );
    }
    elseif ($this->sfid()) {
      $action = 'update';
      $result = $this->client()->objectUpdate(
        $mapping->getSalesforceObjectType(),
        $this->sfid(),
        $params->getParams()
      );
    }
    else {
      $action = 'create';
      $result = $this->client()->objectCreate(
        $mapping->getSalesforceObjectType(),
        $params->getParams()
      );
    }

    if ($drupal_entity instanceof EntityChangedInterface) {
      $this->set('entity_updated', $drupal_entity->getChangedTime());
    }

    // @TODO: catch EntityStorageException ? Others ?
    if ($result instanceof SFID) {
      $this->set('salesforce_id', (string) $result);
    }

    // @TODO setNewRevision not chainable, per https://www.drupal.org/node/2839075
    $this->setNewRevision(TRUE);
    $this
      ->set('last_sync_action', 'push_' . $action)
      ->set('last_sync_status', TRUE)
      ->set('revision_log_message', '')
      ->save();

    // Previously hook_salesforce_push_success.
    $this->eventDispatcher()->dispatch(
      SalesforceEvents::PUSH_SUCCESS,
      new SalesforcePushParamsEvent($this, $params)
    );

    return $result;
  }

  /**
   * Delete the mapped SF object in Salesforce.
   *
   * @return $this
   */
  public function pushDelete() {
    $mapping = $this->getMapping();
    $this->client()->objectDelete($mapping->getSalesforceObjectType(), $this->sfid());
    $this->setNewRevision(TRUE);
    $this
      ->set('last_sync_action', 'push_delete')
      ->set('last_sync_status', TRUE)
      ->save();
    return $this;
  }

  /**
   * Attach a Drupal entity to the mapped object.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to be attached.
   *
   * @return $this
   */
  public function setDrupalEntityStub(EntityInterface $entity = NULL) {
    $this->drupal_entity_stub = $entity;
    return $this;
  }

  /**
   *
   */
  public function getDrupalEntityStub() {
    return $this->drupal_entity_stub;
  }

  /**
   * @param \Drupal\salesforce\SObject $sf_object
   *
   * @return $this
   */
  public function setSalesforceRecord(SObject $sf_object) {
    $this->sf_object = $sf_object;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getSalesforceRecord() {
    return $this->sf_object;
  }

  /**
   * Push the mapped SF object to Salesforce.
   *
   * @return $this
   */
  public function pull() {
    $mapping = $this->getMapping();

    // If the pull isn't coming from a cron job.
    if ($this->sf_object == NULL) {
      if ($this->sfid()) {
        $this->sf_object = $this->client()->objectRead(
          $mapping->getSalesforceObjectType(),
          $this->sfid()
        );
      }
      elseif ($mapping->hasKey()) {
        $this->sf_object = $this->client()->objectReadbyExternalId(
          $mapping->getSalesforceObjectType(),
          $mapping->getKeyField(),
          $mapping->getKeyValue($this->getMappedEntity())
        );
        $this->set('salesforce_id', (string) $this->sf_object->id());
      }
    }

    // No object found means there's nothing to pull.
    if (!($this->sf_object instanceof SObject)) {
      throw new SalesforceException('Nothing to pull. Please specify a Salesforce ID, or choose a mapping with an Upsert Key defined.');
    }

    // @TODO better way to handle push/pull:
    $fields = $mapping->getPullFields();
    $drupal_entity = $this->getMappedEntity() ?: $this->getDrupalEntityStub();
    foreach ($fields as $field) {
      try {
        $value = $field->pullValue($this->sf_object, $drupal_entity, $mapping);
      }
      catch (\Exception $e) {
        // Field missing from SObject? Skip it.
        $message = 'Field @sobj.@sffield not found on @sfid';
        $args = [
          '@sobj' => $mapping->getSalesforceObjectType(),
          '@sffield' => $field->config('salesforce_field'),
          '@sfid' => $this->sfid(),
        ];
        $this->eventDispatcher()->dispatch(SalesforceEvents::NOTICE, new SalesforceNoticeEvent($e, $message, $args));
        continue;
      }

      $this->eventDispatcher()->dispatch(
        SalesforceEvents::PULL_ENTITY_VALUE,
        new SalesforcePullEntityValueEvent($value, $field, $this)
      );

      $drupal_field = $field->get('drupal_field_value');

      try {
        if (!$value) {
          continue;
        }

        $target = $drupal_entity;
        $sub_paths = explode('.', $drupal_field);
        $sub_paths = array_reverse($sub_paths);

        while (\count($sub_paths) > 1) {
          $sub_path = array_pop($sub_paths);
          $target = $target->$sub_path;
        }
        $sub_path = array_pop($sub_paths);
        $target->$sub_path = $value;

        // @Todo: Should we validate the $value based on the $data_definition of the field?
      }
      catch (\Exception $e) {
        $message = 'Exception during pull for @sfobj.@sffield @sfid to @dobj.@dprop @did with value @v';
        $args = [
          '@sfobj' => $mapping->getSalesforceObjectType(),
          '@sffield' => $field,
          '@sfid' => $this->sfid(),
          '@dobj' => $drupal_entity->getEntityTypeId(),
          '@dprop' => $drupal_field,
          '@did' => $drupal_entity->id(),
          '@v' => $value,
        ];
        $this->eventDispatcher()->dispatch(SalesforceEvents::WARNING, new SalesforceWarningEvent($e, $message, $args));
        continue;
      }
    }

    // @TODO: Event dispatching and entity saving should not be happening in this context, but inside a controller. This class needs to be more model-like.
    $this->eventDispatcher()->dispatch(
      SalesforceEvents::PULL_PRESAVE,
      new SalesforcePullEvent($this, $drupal_entity->isNew()
        ? MappingConstants::SALESFORCE_MAPPING_SYNC_SF_CREATE
        : MappingConstants::SALESFORCE_MAPPING_SYNC_SF_UPDATE)
    );

    // Set a flag here to indicate that a pull is happening, to avoid
    // triggering a push.
    $drupal_entity->salesforce_pull = TRUE;
    $drupal_entity->save();

    // Update mapping object.
    $this
      ->set('drupal_entity', $drupal_entity)
      ->set('entity_updated', $this->getRequestTime())
      ->set('last_sync_action', 'pull')
      ->set('last_sync_status', TRUE)
      ->set('force_pull', 0)
      ->save();

    return $this;
  }

  /**
   * Testable func to return the request time server variable.
   *
   * @return int REQUEST_TIME
   *   The request time.
   */
  protected function getRequestTime() {
    return \Drupal::time()->getRequestTime();
  }

}
