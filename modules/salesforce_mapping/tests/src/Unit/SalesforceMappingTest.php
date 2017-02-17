<?php
namespace Drupal\Tests\salesforce_mapping\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\salesforce_mapping\SalesforceMappingFieldPluginManager;
use Drupal\salesforce_mapping\Entity\SalesforceMapping;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\salesforce_mapping\MappingConstants;
use Drupal\salesforce_mapping\Plugin\SalesforceMappingField\Properties;
use Prophecy\Argument;

/**
 * Test Object instantitation
 *
 * @group salesforce_mapping
 */

class SalesforceMappingTest extends UnitTestCase {
  static $modules = ['salesforce_mapping'];

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $this->id = $this->randomMachineName();
    $this->saleforceObjectType = $this->randomMachineName();
    $this->drupalEntityTypeId = $this->randomMachineName();
    $this->drupalBundleId = $this->randomMachineName();
    $this->values = array(
      'id' => $this->id,
      'langcode' => 'en',
      'uuid' => '3bb9ee60-bea5-4622-b89b-a63319d10b3a',
      'label' => 'Test Mapping',
      'weight' => 0,
      'locked' => 0,
      'status' => 1,
      'type' => 'salesforce_mapping',
      'key' => 'Drupal_id__c',
      'async' => 1,
      'pull_trigger_date' => 'LastModifiedDate',
      'sync_triggers' => [
        MappingConstants::SALESFORCE_MAPPING_SYNC_DRUPAL_CREATE => 1,
        MappingConstants::SALESFORCE_MAPPING_SYNC_DRUPAL_UPDATE => 1,
        MappingConstants::SALESFORCE_MAPPING_SYNC_DRUPAL_DELETE => 1,
        MappingConstants::SALESFORCE_MAPPING_SYNC_SF_CREATE => 1,
        MappingConstants::SALESFORCE_MAPPING_SYNC_SF_UPDATE => 1,
        MappingConstants::SALESFORCE_MAPPING_SYNC_SF_DELETE => 1,
      ],
      'salesforce_object_type' => $this->saleforceObjectType,
      'drupal_entity_type' => $this->drupalEntityTypeId,
      'drupal_bundle' => $this->drupalBundleId,
      'field_mappings' => [
        [
          'drupal_field_type' => 'properties',
          'drupal_field_value' => 'title',
          'salesforce_field' => 'Name',
          'direction' => 'sync',
        ],
        [
          'drupal_field_type' => 'properties',
          'drupal_field_value' => 'nid',
          'salesforce_field' => 'Drupal_id_c',
          'direction' => 'sync',
        ],
      ],
    );

    // mock EntityType Definition
    $this->entityTypeId = $this->randomMachineName();
    $this->provider = $this->randomMachineName();
    $prophecy = $this->prophesize(ConfigEntityTypeInterface::CLASS);
    $prophecy->getProvider(Argument::any())->willReturn($this->provider);
    $prophecy->getConfigPrefix(Argument::any())
      ->willReturn('test_provider.' . $this->entityTypeId);
    $this->entityDefinition = $prophecy->reveal();

    // mock EntityTypeManagerInterface
    $prophecy = $this->prophesize(EntityTypeManagerInterface::CLASS);
    $prophecy->getDefinition($this->entityTypeId)->willReturn($this->entityDefinition);
    $this->etm = $prophecy->reveal();

    // mock Properties SalesforceMappingField
    $prophecy = $this->prophesize(Properties::CLASS);
    $prophecy->pull()->willReturn(true);
    $sf_mapping_field = $prophecy->reveal();

    // mode field plugin manager
    $prophecy = $this->prophesize(SalesforceMappingFieldPluginManager::CLASS);
    $prophecy->createInstance(Argument::any(), Argument::any())->willReturn($sf_mapping_field);
    $field_manager = $prophecy->reveal();

    $this->mapping = $this->getMockBuilder(SalesforceMapping::CLASS)
      ->setMethods(['fieldManager'])
      ->setConstructorArgs([$this->values, $this->entityTypeId])
      ->getMock();
    $this->mapping->expects($this->any())
      ->method('fieldManager')
      ->willReturn($field_manager);
  }

  /**
   * Test object instantiation
   */
  public function testObject() {
    $this->assertTrue($this->mapping instanceof SalesforceMapping);
    $this->assertEquals($this->id, $this->mapping->id);
  }

    /**
     * Test getPullFields()
     */
  public function testGetPullFields() {
    $fields_array = $this->mapping->getPullFields();
    $this->assertTrue(is_array($fields_array));
    $this->assertTrue($fields_array[0] instanceof Properties);
  }

  /**
   * Test checkTriggers()
   */
  public function testCheckTriggers() {
    $triggers = $this->mapping->checkTriggers([
      MappingConstants::SALESFORCE_MAPPING_SYNC_DRUPAL_CREATE,
      MappingConstants::SALESFORCE_MAPPING_SYNC_DRUPAL_UPDATE
    ]);
    $this->assertTrue($triggers);
  }
}