<?php

namespace Drupal\salesforce\Plugin\SalesforceAuthProvider;

use Drupal\Core\Form\FormStateInterface;
use Drupal\salesforce\Consumer\SalesforceCredentials;
use Drupal\salesforce\SalesforceAuthProviderPluginBase;
use Drupal\salesforce\Storage\SalesforceAuthTokenStorageInterface;
use OAuth\Common\Http\Client\ClientInterface;
use OAuth\Common\Http\Uri\Uri;
use OAuth\Common\Token\TokenInterface;
use OAuth\OAuth2\Service\Exception\MissingRefreshTokenException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Fallback for broken / missing plugin.
 *
 * @Plugin(
 *   id = "broken",
 *   label = @Translation("Broken or missing provider"),
 *   credentials_class = "Drupal\salesforce\Consumer\SalesforceCredentials"
 * )
 */
class Broken extends SalesforceAuthProviderPluginBase {

  /**
   * Broken constructor.
   *
   * @param array $configuration
   *   Configuration.
   * @param string $plugin_id
   *   Plugin id.
   * @param mixed $plugin_definition
   *   Plugin definition.
   * @param \OAuth\Common\Http\Client\ClientInterface $httpClient
   *   The oauth http client.
   * @param \Drupal\salesforce\Storage\SalesforceAuthTokenStorageInterface $storage
   *   Auth token storage service.
   *
   * @throws \OAuth\OAuth2\Service\Exception\InvalidScopeException
   *   Comment.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ClientInterface $httpClient, SalesforceAuthTokenStorageInterface $storage) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $httpClient, $storage);
    $this->id = $plugin_id;
    $this->credentials = new SalesforceCredentials('', '', '');
  }

  /**
   * {@inheritdoc}
   */
  public function refreshAccessToken(TokenInterface $token) {
    throw new MissingRefreshTokenException();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('salesforce.http_client_wrapper'), $container->get('salesforce.auth_token_storage'));
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $this->messenger()->addError($this->t('Auth provider for %id is missing or broken.', ['%id' => $this->id]));
    return $form;
  }

}
