<?php

namespace Drupal\salesforce_mapping;

use Drupal\salesforce_mapping\Event\SalesforcePushOpEvent as ParentSalesforcePushOpEvent;

/**
 * @deprecated Will be removed before Salesforce 8.x-3.0
 *
 * Use the parent class.
 */
class SalesforcePushOpEvent extends ParentSalesforcePushOpEvent {

  public function __construct() {
    @trigger_error(__CLASS__ . ' is deprecated. Use the parent class in the Drupal\salesforce_mapping\Event namespace.', E_USER_DEPRECATED);
    $args = func_get_args();
    call_user_func_array('parent::__construct', $args);
  }

}
