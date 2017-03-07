<?php

namespace Drupal\salesforce\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Utility\Error;
use Drupal\salesforce\Exception;
use Drupal\salesforce\Rest\RestClientInterface;
use Drupal\salesforce\SalesforceClient;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LogLevel;
use Symfony\Component\DependencyInjection\ContainerInterface;
  
/**
 * Creates authorization form for Salesforce.
 */
class AuthorizeForm extends ConfigFormBase {

  protected $sf_client;

  /**
   * The state keyvalue collection.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Constructs a \Drupal\system\ConfigFormBase object.
   *
   * @param \Drupal\Core\Config\ConfigFactory $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Config\Context\ContextInterface $context
   *   The configuration context to use.
   * @param \Drupal\salesforce\SalesforceClient $sf_client
   *   The factory for configuration objects.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state keyvalue collection to use.
   */
  public function __construct(ConfigFactoryInterface $config_factory, RestClientInterface $salesforce_client, StateInterface $state) {
    parent::__construct($config_factory);
    $this->sf_client = $salesforce_client;
    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('salesforce.client'),
      $container->get('state')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormID() {
    return 'salesforce_oauth';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'salesforce.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // We're not actually doing anything with this, but may figure out
    // something that makes sense.
    $config = $this->config('salesforce.settings');

    $form['creds'] = [
      '#title' => $this->t('API / OAuth Connection Settings'),
      '#type' => 'details',
      '#open' => TRUE,
      '#description' => $this->t('Authorize this website to communicate with Salesforce by entering the consumer key and secret from a remote application. Submitting the form will redirect you to Salesforce where you will be asked to grant access.'),
    ];
    $form['creds']['consumer_key'] = [
      '#title' => $this->t('Salesforce consumer key'),
      '#type' => 'textfield',
      '#description' => $this->t('Consumer key of the Salesforce remote application you want to grant access to'),
      '#required' => TRUE,
    ];
    $form['creds']['consumer_secret'] = [
      '#title' => $this->t('Salesforce consumer secret'),
      '#type' => 'password',
      '#description' => $this->t('Consumer secret of the Salesforce remote application you want to grant access to'),
      '#required' => TRUE,
    ];
    $form['creds']['login_url'] = [
      '#title' => $this->t('Login URL'),
      '#type' => 'textfield',
      '#default_value' => $this->sf_client->getLoginUrl(),
      '#required' => TRUE,
    ];

    // If fully configured, attempt to connect to Salesforce and return a list
    // of resources.
    if ($this->sf_client->isAuthorized()) {
      $form['creds']['#open'] = FALSE;
      $form['creds']['#description'] = $this->t('Your Salesforce salesforce instance is currently authorized. Enter credentials here only to change credentials.');
      unset($_SESSION['messages']['salesforce_oauth_error error']);
      try {
        $resources = $this->sf_client->listResources();
        foreach ($resources->resources as $key => $path) {
          $items[] = $key . ': ' . $path;
        }
        if ($items) {
          $form['resources'] = [
            '#title' => $this->t('Your Salesforce instance is authorized and has access to the following resources:'),
            '#items' => $items,
            '#theme' => 'item_list',
          ];
        }
      }
      catch (RequestException $e) {
        drupal_set_message($e->getMessage(), 'warning');
        $this->logger(__CLASS__)->log(
          LogLevel::ERROR,
          '%type: @message in %function (line %line of %file).',
          Error::decodeException($e)
        );
      }
    }
    else {
      drupal_set_message(t('Salesforce needs to be authorized to connect to this website.'), 'salesforce_oauth_error error');
    }

    $form = parent::buildForm($form, $form_state);
    $form['creds']['actions'] = $form['actions'];
    unset($form['actions']);
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $this->sf_client->setConsumerKey($values['consumer_key']);
    $this->sf_client->setConsumerSecret($values['consumer_secret']);
    $this->sf_client->setLoginUrl($values['login_url']);

    try {
      $path = $this->sf_client->getAuthEndpointUrl();
      $query = [
        'redirect_uri' => $this->sf_client->getAuthCallbackUrl(),
        'response_type' => 'code',
        'client_id' => $values['consumer_key'],
      ];

      // Send the user along to the Salesforce OAuth login form. If successful, the user will be redirected to {redirect_uri} to complete the OAuth handshake.
      $form_state->setResponse(new TrustedRedirectResponse($path . '?' . http_build_query($query), 302));
    }
    catch (RequestException $e) {
      drupal_set_message(t("Error during authorization: %message", ['%message' => $e->getMessage()]), 'error');
      $this->logger(__CLASS__)->log(
        LogLevel::ERROR,
        '%type: @message in %function (line %line of %file).',
        Error::decodeException($e)
      );
    }
  }


}
