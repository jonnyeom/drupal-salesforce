<?php
namespace Drupal\Tests\salesforce_pull\Unit;

use Drupal\Component\EventDispatcher\ContainerAwareEventDispatcher;
use Drupal\Core\Entity\Entity;
use Drupal\Core\Entity\EntityStorageBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\salesforce_mapping\Entity\MappedObjectInterface;
use Drupal\salesforce_mapping\Entity\SalesforceMappingInterface;
use Drupal\salesforce_mapping\MappedObjectStorage;
use Drupal\salesforce_mapping\MappingConstants;
use Drupal\salesforce_mapping\SalesforceMappingStorage;
use Drupal\salesforce_pull\DeleteHandler;
use Drupal\salesforce\Rest\RestClientInterface;
use Drupal\Tests\UnitTestCase;
use Prophecy\Argument;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ServerBag;

/**
 * Test Object instantitation.
 *
 * @group salesforce_pull
 */
class DeleteHandlerTest extends UnitTestCase {
  protected static $modules = ['salesforce_pull'];

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    $result = [
      'totalSize' => 1,
      'done' => TRUE,
      'deletedRecords' => [
        [
          'id' => '1234567890abcde',
          'attributes' => ['type' => 'dummy',],
          'name' => 'Example',
        ],
      ]
    ];

    $prophecy = $this->prophesize(RestClientInterface::CLASS);
    $prophecy->getDeleted(Argument::any(), Argument::any(), Argument::any())
      ->willReturn($result);
    $this->sfapi = $prophecy->reveal();

    // Mock Drupal entity.
    $prophecy = $this->prophesize(Entity::CLASS);
    $prophecy->delete()->willReturn(TRUE);
    $prophecy->id()->willReturn(1);
    $this->entity = $prophecy->reveal();

    $this->mapping = $this->getMock(SalesforceMappingInterface::CLASS);
    $this->mapping->expects($this->any())
      ->method('__get')
      ->with($this->equalTo('id'))
      ->willReturn(1);
    $this->mapping->expects($this->any())
      ->method('__get')
      ->with($this->equalTo('entity'))
      ->willReturn($this->entity);
    $this->mapping->expects($this->any())
      ->method('getSalesforceObjectType')
      ->willReturn('default');
    $this->mapping->expects($this->any())
      ->method('getPullFieldsArray')
      ->willReturn(['Name' => 'Name', 'Account Number' => 'Account Number']);
    $this->mapping->expects($this->any())
      ->method('checkTriggers')
      ->with([MappingConstants::SALESFORCE_MAPPING_SYNC_SF_DELETE])
      ->willReturn(TRUE);

    // Mock mapped object.
    $this->entityTypeId = new \stdClass();
    $this->entityId = new \stdClass();
    $this->entityRef = new \stdClass();
    $this->entityTypeId->value = 'test';
    $this->entityId->value = '1';
    $this->entityRef->entity = $this->mapping;

    $this->mappedObject = $this->getMock(MappedObjectInterface::CLASS);
    $this->mappedObject
      ->expects($this->any())
      ->method('delete')
      ->willReturn(TRUE);
    $this->mappedObject
      ->expects($this->any())
      ->method('getMapping')
      ->willReturn($this->mapping);
    $this->mappedObject
      ->expects($this->any())
      ->method('getFieldDefinitions')
      ->willReturn(['entity_type_id', 'entity_id', 'salesforce_mapping']);
    $this->mappedObject
      ->expects($this->any())
      ->method('getMappedEntity')
      ->willReturn($this->entity);

    // Mock mapping ConfigEntityStorage object.
    $prophecy = $this->prophesize(SalesforceMappingStorage::CLASS);
    $prophecy->loadByProperties(Argument::any())->willReturn([$this->mapping]);
    $prophecy->load(Argument::any())->willReturn($this->mapping);
    $prophecy->getMappedSobjectTypes()->willReturn([
      'default'
    ]);
    $this->configStorage = $prophecy->reveal();

    // Mock mapped object EntityStorage object.
    $this->entityStorage = $this->getMockBuilder(MappedObjectStorage::CLASS)
      ->disableOriginalConstructor()
      ->getMock();
    $this->entityStorage->expects($this->any())
      ->method('loadBySfid')
      ->willReturn([$this->mappedObject]);

    // Mock Drupal entity EntityStorage object.
    $prophecy = $this->prophesize(EntityStorageBase::CLASS);
    $prophecy->load(Argument::any())->willReturn($this->entity);
    $this->drupalEntityStorage = $prophecy->reveal();

    // Mock EntityTypeManagerInterface.
    $prophecy = $this->prophesize(EntityTypeManagerInterface::CLASS);
    $prophecy->getStorage('salesforce_mapping')->willReturn($this->configStorage);
    $prophecy->getStorage('salesforce_mapped_object')->willReturn($this->entityStorage);
    $prophecy->getStorage('test')->willReturn($this->drupalEntityStorage);
    $this->etm = $prophecy->reveal();

    // Mock state.
    $prophecy = $this->prophesize(StateInterface::CLASS);
    $prophecy->get('salesforce_pull_last_delete_default', Argument::any())->willReturn('1485787434');
    $prophecy->set('salesforce_pull_last_delete_default', Argument::any())->willReturn(null);
    $this->state = $prophecy->reveal();

   // mock event dispatcher
    $prophecy = $this->prophesize(ContainerAwareEventDispatcher::CLASS);
    $prophecy->dispatch(Argument::any())->willReturn();
    $this->ed = $prophecy->reveal();

    // Mock server.
    $prophecy = $this->prophesize(ServerBag::CLASS);
    $prophecy->get(Argument::any())->willReturn('1485787434');
    $this->server = $prophecy->reveal();

    // Mock request.
    $prophecy = $this->prophesize(Request::CLASS);
    $prophecy->server = $this->server;
    $this->request = $prophecy->reveal();

    $this->dh = DeleteHandler::create(
      $this->sfapi,
      $this->etm,
      $this->state,
      $this->ed,
      $this->request
    );
  }

  /**
<<<<<<< HEAD
   * Test object creation.
=======
   * Test object instantiation.
>>>>>>> 8.x-3.x
   */
  public function testObject() {
    $this->assertTrue($this->dh instanceof DeleteHandler);
  }

  /**
<<<<<<< HEAD
   * Test processDeletedRecords.
=======
   * Test handler operation, good data.
>>>>>>> 8.x-3.x
   */
  public function testGetUpdatedRecords() {
    $result = $this->dh->processDeletedRecords();
    $this->assertTrue($result);
  }

}
