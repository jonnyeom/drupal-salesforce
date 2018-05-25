<?php

namespace Drupal\salesforce_mapping\Plugin\SalesforceMappingField;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\Url;

use Drupal\field\Entity\FieldConfig;
use Drupal\salesforce_mapping\Entity\SalesforceMappingInterface;
use Drupal\salesforce_mapping\SalesforceMappingFieldPluginBase;
use Drupal\typed_data\DataFetcherTrait;


/**
 * Adapter for entity Reference and fields.
 *
 * @Plugin(
 *   id = "RelatedProperties",
 *   label = @Translation("Related Entity Properties")
 * )
 */
class RelatedProperties extends SalesforceMappingFieldPluginBase {

  use DataFetcherTrait;

  /**
   * Implementation of PluginFormInterface::buildConfigurationForm
   * This is basically the inverse of Properties::buildConfigurationForm()
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
      '#attributes' => ['class' => ['drupal-field-value']],
      '#default_value' => $this->config('drupal_field_value'),
    ];
    $element = &$pluginForm[$context_name]['setting'];
    if ($mode == 'selector') {
      $element['#description'] = $this->t("The data selector helps you drill down into the data available.");
      $url = Url::fromRoute('salesforce_mapping.autocomplete_controller_autocomplete', ['entity_type_id' => $mapping->get('drupal_entity_type'), 'bundle' => $mapping->get('drupal_bundle'), 'mapping_plugin_id' => $this->getPluginId()]);
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
   * {@inheritdoc}
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

    // Resetting the `drupal_field_value` to just the `setting` portion,
    // which should be a string.
    $config_value = $form_state->getValue('config');
    $config_value['drupal_field_value'] = $config_value['drupal_field_value']['setting'];
    $form_state->setValue('config', $config_value);
  }

  /**
   *
   */
  public function value(EntityInterface $entity, SalesforceMappingInterface $mapping) {
    $paths = explode('.', $this->config('drupal_field_value'), 2);
    $field_name = array_shift($paths);
    $referenced_field_name = array_shift($paths);

    // Since we're not setting hard restrictions around bundles/fields, we may
    // have a field that doesn't exist for the given bundle/entity. In that
    // case, calling get() on an entity with a non-existent field argument
    // causes an exception during entity save. Probably a bug, but I haven't
    // found it in the issue queue. So, just check first to make sure the field
    // exists.
    $instances = $this->entityFieldManager->getFieldDefinitions(
      $mapping->get('drupal_entity_type'),
      $mapping->get('drupal_bundle')
    );
    if (empty($instances[$field_name])) {
      return;
    }

    $field = $entity->get($field_name);
    if (empty($field->entity)) {
      // This reference field is blank.
      return;
    }

    try {
      $describe = $this
        ->salesforceClient
        ->objectDescribe($mapping->getSalesforceObjectType());
      $field_definition = $describe->getField($this->config('salesforce_field'));
      if ($field_definition['type'] == 'multipicklist') {
        $values = [];
        foreach ($field as $ref_entity) {
          $values[] = $this->getStringValue($ref_entity->entity, $referenced_field_name);
        }
        return implode(';', $values);
      }
      else {
        return $this->getStringValue($field->entity, $referenced_field_name);
      }
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   *
   */
  protected function getConfigurationOptions($mapping) {
    $instances = $this->entityFieldManager->getFieldDefinitions(
      $mapping->get('drupal_entity_type'),
      $mapping->get('drupal_bundle')
    );
    if (empty($instances)) {
      return;
    }

    $options = [];

    // Loop over every field on the mapped entity. For reference fields, expose
    // all properties of the referenced entity.
    foreach ($instances as $instance) {
      if (!$this->instanceOfEntityReference($instance)) {
        continue;
      }
      $settings = $this
        ->selectionPluginManager()
        ->getSelectionHandler($instance)
        ->getConfiguration();
      $entity_type = $settings['target_type'];
      $properties = [];

      // If handler is default and allowed bundles are set, include all fields
      // from all allowed bundles.
      try {
        if (!empty($settings['handler_settings']['target_bundles'])) {
          foreach ($settings['handler_settings']['target_bundles'] as $bundle) {
            $properties += $this
              ->entityFieldManager
              ->getFieldDefinitions($entity_type, $bundle);
          }
        }
        else {
          $properties += $this
            ->entityFieldManager
            ->getBaseFieldDefinitions($entity_type);
        }
      }
      catch (\LogicException $e) {
        // @TODO is there a better way to exclude non-fieldables?
        continue;
      }

      foreach ($properties as $key => $property) {
        $options[(string)$instance->getLabel()][$instance->getName() . ':' . $key] = $property->getLabel();
      }
    }

    if (empty($options)) {
      return;
    }

    // Alphabetize options for UI.
    foreach ($options as $group => &$option_set) {
      asort($option_set);
    }
    asort($options);
    return $options;
  }

  /**
   * Helper Method to check for and retrieve field data.
   *
   * If it is just a regular field/property of the entity, the data is
   * retrieved with ->value(). If this is a property referenced using the
   * typed_data module's extension, use typed_data module's DataFetcher class
   * to retrieve the value.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to search the Typed Data for.
   * @param string $drupal_field_value
   *   The Typed Data property to get.
   *
   * @return string
   *   The String representation of the Typed Data property value.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   * @throws \Drupal\typed_data\Exception\InvalidArgumentException
   */
  protected function getStringValue(EntityInterface $entity, $drupal_field_value) {
    $sub_paths = explode('.', $drupal_field_value);
    if (\count($sub_paths) > 1) {
      $string_data = $this->getDataFetcher()->fetchDataBySubPaths($entity->getTypedData(), $sub_paths)->getString();
      return $string_data;
    }
    $original = $entity->get($drupal_field_value)->value;
    return $original;
  }

  /**
   * {@inheritdoc}
   */
  protected function getFieldDataDefinition(EntityInterface $entity) {
    $data_definition = $this->getDataFetcher()->fetchDefinitionByPropertyPath($entity->getTypedData()->getDataDefinition(), $this->config('drupal_field_value'));
    if ($data_definition instanceof ListDataDefinitionInterface) {
      $data_definition = $data_definition->getItemDefinition();
    }

    return $data_definition;
  }

  /**
   * {@inheritdoc}
   */
  protected function getDrupalFieldType(DataDefinitionInterface $data_definition) {
    $field_main_property = $data_definition;
    if ($data_definition instanceof ComplexDataDefinitionInterface) {
      $field_main_property = $data_definition
        ->getPropertyDefinition($data_definition->getMainPropertyName());
    }

    return $field_main_property ? $field_main_property->getDataType() : NULL;
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
      'config' => [$field_config->getConfigDependencyName()],
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
