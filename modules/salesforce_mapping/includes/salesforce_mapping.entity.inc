<?php

/**
 * Entity class for Salesforce Mappings
 */
class SalesforceMapping extends Entity {

  public
    $salesforce_mapping_id,
    $name,
    $label,
    $type = 'salesforce_mapping', // only one bundle type for now
    $salesforce_object_type,
    $salesforce_record_type,
    $drupal_entity_type,
    $drupal_bundle,
    $description,
    $field_mapping,
    $created,
    $updated;

  public function save() {
    $this->updated = REQUEST_TIME;
    if ($this->is_new) {
      $this->created = REQUEST_TIME;
    }
    return parent::save();
  }

  protected function defaultUri() {
    return array('path' => 'salesforce_mapping/' . $this->identifier());
  }
}