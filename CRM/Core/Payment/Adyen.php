<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

use CRM_Adyen_ExtensionUtil as E;
use Civi\Payment\PropertyBag;
use Civi\Api4\PaymentprocessorWebhook;
use Civi\Payment\Exception\PaymentProcessorException;

/**
 * Class CRM_Core_Payment_Adyen
 */
class CRM_Core_Payment_Adyen extends CRM_Core_Payment {

  use CRM_Core_Payment_MJWTrait;

  /**
   * @var \Adyen\Client
   */
  public $client;

  /**
   * @var string
   */
  private $paymentReference;

  /**
   * Constructor
   *
   * @param string $mode
   *   (deprecated) The mode of operation: live or test.
   * @param array $paymentProcessor
   */
  public function __construct($mode, $paymentProcessor) {
    $this->_paymentProcessor = $paymentProcessor;

    if (defined('ADYEN_PHPUNIT_TEST') && isset($GLOBALS['mockAdyenClient'])) {
      // When under test, prefer the mock.
      $this->client = $GLOBALS['mockAdyenClient'];
    }
    else {
      // Normally we create a new adyen client.
      // You can configure only one of live/test so don't initialize AdyenClient if keys are blank
      if (!empty($this->getXApiKey())) {
        $this->client = new \Adyen\Client();
        $this->setAPIParams();
      }
    }
  }

  /**
   * @return string
   */
  public function getXApiKey() {
    return trim($this->_paymentProcessor['password'] ?? '');
  }

  /**
   * @return string
   */
  public function getURLPrefix() {
    return $this->getExtraConfig()['urlPrefix'] ?? '';
  }

  public function getMerchantAccount() {
    return trim($this->_paymentProcessor['user_name'] ?? '');
  }

  public function getClientKey() {
    return $this->getExtraConfig()['clientKey'] ?? '';
  }

  /**
   * @return array
   */
  public function getHMACKeys() {
    return $this->getExtraConfig()['hmacKeys'] ?? [];
  }

  private function getExtraConfig() {
    return json_decode($this->_paymentProcessor['signature'] ?? NULL, TRUE);
  }

  /**
   * Set API parameters for Adyen (such as identifier, api version, api key)
   */
  public function setAPIParams() {
    $this->client->setXApiKey($this->getXApiKey());
    $this->client->setApplicationName('CiviCRM Application');
    if ($this->getIsTestMode()) {
      $this->client->setEnvironment(\Adyen\Environment::TEST);
    }
    else {
      $this->client->setEnvironment(\Adyen\Environment::LIVE, $this->getURLPrefix());
    }
  }

  /**
   * This function checks to see if we have the right config values.
   *
   * @return null|string
   *   The error message if any.
   */
  public function checkConfig() {
    $error = [];
    if (!empty($error)) {
      return implode('<p>', $error);
    }
    else {
      return NULL;
    }
  }

  /**
   * Override CRM_Core_Payment function
   *
   * @return string
   */
  public function getPaymentTypeName() {
    return 'credit_card';
  }

  /**
   * Override CRM_Core_Payment function
   *
   * @return string
   */
  public function getPaymentTypeLabel() {
    return E::ts('Adyen');
  }

  /**
   * We can use the adyen processor on the backend
   * @return bool
   */
  public function supportsBackOffice() {
    return TRUE;
  }

  /**
   * We can edit adyen recurring contributions
   * @return bool
   */
  public function supportsEditRecurringContribution() {
    return FALSE;
  }

  public function supportsRecurring() {
    return TRUE;
  }

  /**
   * Does this payment processor support refund?
   *
   * @return bool
   */
  public function supportsRefund() {
    return FALSE;
  }

  /**
   * Can we set a future recur start date?
   * @return bool
   */
  public function supportsFutureRecurStartDate() {
    return FALSE;
  }

  /**
   * Is an authorize-capture flow supported.
   *
   * @return bool
   */
  protected function supportsPreApproval() {
    return FALSE;
  }

  /**
   * Does this processor support cancelling recurring contributions through code.
   *
   * @return bool
   */
  protected function supportsCancelRecurring() {
    return FALSE;
  }

  /**
   * Does the processor support the user having a choice as to whether to cancel the recurring with the processor?
   *
   * If this returns TRUE then there will be an option to send a cancellation request in the cancellation form.
   *
   * This would normally be false for processors where CiviCRM maintains the schedule.
   *
   * @return bool
   */
  protected function supportsCancelRecurringNotifyOptional() {
    return TRUE;
  }

  /**
   * Override CRM_Core_Payment function
   *
   * @return array
   */
  public function getPaymentFormFields() {
    return [];
  }

  /**
   * Return an array of all the details about the fields potentially required for payment fields.
   *
   * Only those determined by getPaymentFormFields will actually be assigned to the form
   *
   * @return array
   *   field metadata
   */
  public function getPaymentFormFieldsMetadata() {
    return [];
  }

  /**
   * Get billing fields required for this processor.
   *
   * We apply the existing default of returning fields only for payment processor type 1. Processors can override to
   * alter.
   *
   * @param int $billingLocationID
   *
   * @return array
   */
  public function getBillingAddressFields($billingLocationID = NULL) {
    return [];
  }

  /**
   * Get form metadata for billing address fields.
   *
   * @param int $billingLocationID
   *
   * @return array
   *    Array of metadata for address fields.
   */
  public function getBillingAddressFieldsMetadata($billingLocationID = NULL) {
    return [];
  }

  public function getPaymentReference() {
    if (!isset($this->paymentReference)) {
      $this->paymentReference = CRM_Utils_Request::retrieve('adyenPaymentReference', 'String') ?? md5(uniqid(rand(), TRUE));
    }
    return $this->paymentReference;
  }

  public function createAPISession(\CRM_Core_Form $form) {
    // @fixme lots of params!
    $service = new \Adyen\Service\Checkout($this->client);
    $params = [
      'amount' => [
        'currency' => "EUR",
        'value' => 1000
      ],
      'countryCode' => 'NL',
      'merchantAccount' => 'GreenpeaceCEE',
      'reference' => $this->getPaymentReference(),
      'returnUrl' => $this->getReturnSuccessUrl('qfKey'),
    ];
    $result = $service->sessions($params);
    return $result;
  }

  /**
   * Set default values when loading the (payment) form
   *
   * @param \CRM_Core_Form $form
   */
  public function buildForm(&$form) {
    // Don't use \Civi::resources()->addScriptFile etc as they often don't work on AJAX loaded forms (eg. participant backend registration)

    $session = $this->createAPISession($form);

    $jsVars = [
      'id' => $this->getID(),
      'currency' => $this->getDefaultCurrencyForForm($form),
      'billingAddressID' => CRM_Core_BAO_LocationType::getBilling(),
      'paymentProcessorTypeID' => $this->_paymentProcessor['payment_processor_type_id'],
      'csrfToken' => class_exists('\Civi\Firewall\Firewall') ? \Civi\Firewall\Firewall::getCSRFToken() : NULL,
      'session' => [
        'id' => $session['id'],
        'sessionData' => $session['sessionData'],
      ],
      'env' => $this->_paymentProcessor['is_test'] ? 'test' : 'live',
      'clientKey' => $this->getClientKey(),
      'paymentReference' => $this->getPaymentReference(),
    ];

    $endpoint = $this->getIsTestMode() ? 'test' : 'live';

    \Civi::resources()->addMarkup("
      <script src='https://checkoutshopper-{$endpoint}.adyen.com/checkoutshopper/sdk/5.8.0/adyen.js'
     integrity='sha384-+g4E31JlX0VehjtzsLkNbXWAC8BY8CWXtsmwyr1wVOEfYgnrn/FtuIian6JBi6Va'
     crossorigin='anonymous'></script>
     <link rel='stylesheet'
     href='https://checkoutshopper-{$endpoint}.adyen.com/checkoutshopper/sdk/5.8.0/adyen.css'
     integrity='sha384-sh96qY2G0gI/SWEA7mUoROU6e1DT3KyE36KQRTT4t5bfd/lz6WFU8AbV7GxgKIbt'
     crossorigin='anonymous'>
      <div id='crm-payment-js-billing-form-container' class='adyen'>
        <div id='dropin-container'></div>
        <div id='card-errors' role='alert' class='crm-error alert alert-danger' style='display:none'></div>
      </div>
      ",
      ['region' => 'billing-block']
    );

    /**
    // Add CSS via region (it won't load on drupal webform if added via \Civi::resources()->addStyleFile)
    CRM_Core_Region::instance('billing-block')->add([
      'styleUrl' => \Civi::service('asset_builder')->getUrl(
        'elements.css',
        [
          'path' => \Civi::resources()->getPath(E::LONG_NAME, 'css/elements.css'),
          'mimetype' => 'text/css',
        ]
      ),
      'weight' => -1,
    ]);
     */
    CRM_Core_Region::instance('billing-block')->add([
      'scriptUrl' => \Civi::service('asset_builder')->getUrl(
        'civicrmAdyen.js',
        [
          'path' => \Civi::resources()->getPath(E::LONG_NAME, 'js/civicrmAdyen.js'),
          'mimetype' => 'application/javascript',
        ]
      ),
      // Load after other scripts on form (default = 1)
      'weight' => 100,
    ]);

    \Civi::resources()->addVars(E::SHORT_NAME, $jsVars);
    // Assign to smarty so we can add via Card.tpl for drupal webform and other situations where jsVars don't get loaded on the form.
    // This applies to some contribution page configurations as well.
    $form->assign('adyenJSVars', $jsVars);
    CRM_Core_Region::instance('billing-block')->add(
      ['template' => 'CRM/Core/Payment/AdyenJSVars.tpl', 'weight' => -1]);

    // Enable JS validation for forms so we only (submit) create a paymentIntent when the form has all fields validated.
    $form->assign('isJsValidate', TRUE);
  }

  /**
   * Get the amount for the Adyen API formatted in lowest (ie. cents / pennies).
   *
   * @param array|PropertyBag $params
   *
   * @return string
   */
  public function getAmount($params = []): string {
    $amount = number_format((float) $params['amount'] ?? 0.0, CRM_Utils_Money::getCurrencyPrecision($this->getCurrency($params)), '.', '');
    // Adyen amount required in cents.
    $amount = preg_replace('/[^\d]/', '', strval($amount));
    return $amount;
  }

  /**
   * Default payment instrument validation.
   *
   * Implement the usual Luhn algorithm via a static function in the CRM_Core_Payment_Form if it's a credit card
   * Not a static function, because I need to check for payment_type.
   *
   * @param array $values
   * @param array $errors
   */
  public function validatePaymentInstrument($values, &$errors) {
    // Use $_POST here and not $values - for webform fields are not set in $values, but are in $_POST
    CRM_Core_Form::validateMandatoryFields($this->getMandatoryFields(), $_POST, $errors);
  }

  /*
   * Sets a mock adyen client object for this object and all future created
   * instances. This should only be called by phpunit tests.
   *
   * Nb. cannot change other already-existing instances.
   */
  public function setMockAdyenClient($client) {
    if (!defined('ADYEN_PHPUNIT_TEST')) {
      throw new \RuntimeException("setMockAdyenClient was called while not in a ADYEN_PHPUNIT_TEST");
    }
    $GLOBALS['mockAdyenClient'] = $this->client = $client;
  }

  /**
   * Process payment
   * Payment processors should set payment_status_id/payment_status.
   *
   * @param array|PropertyBag $params
   *   Assoc array of input parameters for this transaction.
   * @param string $component
   *
   * @return array
   *   Result array
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function doPayment(&$params, $component = 'contribute') {
    /* @var \Civi\Payment\PropertyBag $propertyBag */
    $propertyBag = \Civi\Payment\PropertyBag::cast($params);

    $zeroAmountPayment = $this->processZeroAmountPayment($propertyBag);
    if ($zeroAmountPayment) {
      return $zeroAmountPayment;
    }
    $propertyBag = $this->beginDoPayment($propertyBag);

    $propertyBag = $this->setStatusPaymentPending($propertyBag);
    $propertyBag = $this->getTokenParameter('adyenPaymentReference', $propertyBag, TRUE);
    $this->setPaymentProcessorOrderID($propertyBag->getCustomProperty('adyenPaymentReference'));
    // For contribution workflow we have a contributionId so we can set parameters directly.
    // For events/membership workflow we have to return the parameters and they might get set...
    return $this->endDoPayment($propertyBag);
  }

  /**
   * Process incoming payment notification (IPN).
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public function handlePaymentNotification() {
    // Set default http response to 200
    http_response_code(200);
    $request = file_get_contents('php://input');
    $handler = new CRM_Core_Payment_AdyenIPN($this);
    $handler->handleRequest([], $request);
    echo '[accepted]';
    CRM_Utils_System::civiExit();
  }

  /**
   * Called by mjwshared extension's queue processor api3 Job.process_paymentprocessor_webhooks
   *
   * The array parameter contains a row of PaymentprocessorWebhook data, which represents a single GC event
   *
   * Return TRUE for success, FALSE if there's a problem
   */
  public function processWebhookEvent(array $webhookEvent) :bool {
    $handler = new CRM_Core_Payment_AdyenIPN($this);
    return $handler->processQueuedWebhookEvent($webhookEvent);
  }

}
