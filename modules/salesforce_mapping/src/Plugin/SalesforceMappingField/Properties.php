<?php

namespace Drupal\salesforce_mapping\Plugin\SalesforceMappingField;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\salesforce_mapping\SalesforceMappingFieldPluginBase;
use Drupal\salesforce_mapping\Entity\SalesforceMappingInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\TypedData\TypedDataTrait;
use Drupal\typed_data\Form\SubformState;
use Drupal\typed_data\Util\StateTrait;
use Drupal\typed_data\Widget\FormWidgetManagerTrait;


/**
 * Adapter for entity properties and fields.
 *
 * @Plugin(
 *   id = "properties",
 *   label = @Translation("Properties")
 * )
 */
class Properties extends SalesforceMappingFieldPluginBase {

  use FormWidgetManagerTrait;
  use StateTrait;
  use TypedDataTrait;

  /**
   * Implementation of PluginFormInterface::buildConfigurationForm.
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $pluginForm = parent::buildConfigurationForm($form, $form_state);

    $mapping = $form['#entity'];

    // Display the plugin config form here:
    $context_name = 'drupal_field_value';
    // If the form has been submitted already take the mode from the submitted
    // values, otherwise default to existing configuration. And if that does not
    // exist default to the "input" mode.
    $mode = $form_state->get('context_' . $context_name);
    if (!$mode) {
      if (isset($configuration['context_mapping'][$context_name])) {
        $mode = 'selector';
      }
      else {
        $mode = 'input';
      }
      $form_state->set('context_' . $context_name, $mode);
    }
    $title = $mode == 'selector' ? $this->t('Data selector') : $this->t('Value');

    $pluginForm[$context_name]['setting'] = [
      '#type' => 'textfield',
      '#title' => $title,
      '#default_value' => $this->config('drupal_field_value'),
    ];
    $element = &$pluginForm[$context_name]['setting'];
    if ($mode == 'selector') {
      $element['#description'] = $this->t("The data selector helps you drill down into the data available.");
      $url = Url::fromRoute('salesforce_mapping.autocomplete_controller_autocomplete', ['entity_type_id' => $mapping->get('drupal_entity_type'), 'bundle' => $mapping->get('drupal_bundle')]);
      $element['#attributes']['class'][] = 'salesforce-mapping-autocomplete';
      $element['#attributes']['data-autocomplete-path'] = $url->toString();
      $element['#attached']['library'][] = 'salesforce_mapping/salesforce_mapping.autocomplete';
    }
    $value = $mode == 'selector' ? $this->t('Switch to the direct input mode') : $this->t('Switch to data selection');
    $pluginForm[$context_name]['switch_button'] = [
      '#type' => 'submit',
      '#name' => 'context_' . $context_name,
      '#attributes' => ['class' => ['drupal-field-switch-button']],
      '#parameter' => $context_name,
      '#value' => $value,
      '#submit' => [static::class . '::switchContextMode'],
      // Do not validate!
      '#limit_validation_errors' => [],
    ];

    return $pluginForm;
  }

  /**
   *
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);
    $vals = $form_state->getValues();
    $config = $vals['config'];
    if (empty($config['salesforce_field'])) {
      $form_state->setError($form['config']['salesforce_field'], t('Salesforce field is required.'));
    }
    if (empty($config['drupal_field_value'])) {
      $form_state->setError($form['config']['drupal_field_value'], t('Drupal field is required.'));
    }
    // @TODO: Should we validate the $config['drupal_field_value']['setting'] property?
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    // Resetting the `drupal_field_value` to just the `setting` portion, which should be a string.
    $config_value = $form_state->getValue('config');
    $config_value['drupal_field_value'] = $config_value['drupal_field_value']['setting'];
    $form_state->setValue('config', $config_value);
  }

  /**
   *
   */
  public function value(EntityInterface $entity, SalesforceMappingInterface $mapping) {
    // No error checking here. If a property is not defined, it's a
    // configuration bug that needs to be solved elsewhere.
    // Multipicklist is the only target type that handles multi-valued fields.
    $describe = $this
      ->salesforceClient
      ->objectDescribe($mapping->getSalesforceObjectType());
    $field_definition = $describe->getField($this->config('salesforce_field'));
    if ($field_definition['type'] == 'multipicklist') {
      $values = [];
      foreach ($entity->get($this->config('drupal_field_value')) as $value) {
        $values[] = $value->value;
      }
      return implode(';', $values);
    }
    else {
      return $entity->get($this->config('drupal_field_value'))->value;
    }
  }

  /**
   *
   */
  private function getConfigurationOptions(SalesforceMappingInterface $mapping) {
    $instances = $this->entityFieldManager->getFieldDefinitions(
      $mapping->get('drupal_entity_type'),
      $mapping->get('drupal_bundle')
    );

    $options = [];
    foreach ($instances as $key => $instance) {
      // Entity reference fields are handled elsewhere.
      if ($this->instanceOfEntityReference($instance)) {
        continue;
      }
      $options[$key] = $instance->getLabel();
    }
    asort($options);
    return $options;
  }

  /**
   * {@inheritdoc}
   *
   * @return array
   *   Field config upon which this mapping depends
   */
  public function getDependencies(SalesforceMappingInterface $mapping) {
    $field_config = FieldConfig::loadByName($mapping->get('drupal_entity_type'), $mapping->get('drupal_bundle'), $this->config('drupal_field_value'));
    if (empty($field_config)) {
      return [];
    }
    return [
      'config' => array($field_config->getConfigDependencyName()),
    ];
  }

  /**
   * Submit callback: switch a context to data selecor or direct input mode.
   */
  public static function switchContextMode(array &$form, FormStateInterface $form_state) {
    $element_name = $form_state->getTriggeringElement()['#name'];
    $mode = $form_state->get($element_name);
    $switched_mode = $mode == 'selector' ? 'input' : 'selector';
    $form_state->set($element_name, $switched_mode);
    $form_state->setRebuild();
  }

}
