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

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionRecur;
use Civi\Api4\Email;
use Civi\Api4\PaymentprocessorWebhook;

/**
 * Class CRM_Core_Payment_AdyenIPN
 */
class CRM_Core_Payment_AdyenIPN {

  use CRM_Core_Payment_MJWIPNTrait;

  /**
   * @var \CRM_Core_Payment_Adyen Payment processor
   */
  protected $_paymentProcessor;

  /**
   * The CiviCRM contact ID that maps to the customer
   *
   * @var int
   */
  protected $contactID = NULL;

  // Properties of the event.

  /**
   * @var string The date/time the charge was made
   */
  protected $receive_date = NULL;

  /**
   * @var float The amount paid
   */
  protected $amount = 0.0;

  /**
   * @var float The fee charged
   */
  protected $fee = 0.0;

  /**
   * @var array The current contribution
   */
  protected $contribution = NULL;

  public function __construct(?CRM_Core_Payment_Adyen $paymentObject = NULL) {
    if ($paymentObject !== NULL && !($paymentObject instanceof CRM_Core_Payment_Adyen)) {
      // This would be a coding error.
      throw new Exception(__CLASS__ . " constructor requires CRM_Core_Payment_Adyen object (or NULL for legacy use).");
    }
    $this->_paymentProcessor = $paymentObject;
  }

  /**
   * Returns TRUE if we handle this event type, FALSE otherwise
   * @param string $eventType
   *
   * @return bool
   */
  public function setEventType($eventType) {
    $this->eventType = $eventType;
    if (!in_array($this->eventType, CRM_Adyen_Webhook::getDefaultEnabledEvents())) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Set and initialise the paymentProcessor object
   * @param int $paymentProcessorID
   *
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function setPaymentProcessor($paymentProcessorID) {
    try {
      $this->_paymentProcessor = \Civi\Payment\System::singleton()->getById($paymentProcessorID);
    }
    catch (Exception $e) {
      $this->exception('Failed to get payment processor');
    }
  }

  /**
   * Check incoming input for validity and extract the data.
   *
   * Alters $this->events and sets
   * $this->paymentProcessorObject unless already set.
   *
   * http request
   *   -> paymentClass::handlePaymentNotification()
   *     -> this::handleRequest()
   *       -> parseWebhookRequest()
   *
   * @throws InvalidArgumentException if signature does not match.
   *
   * @param string $raw_payload
   *
   * @return void
   * @throws \Adyen\AdyenException
   */
  public function parseWebhookRequest($rawPayload) {
    $payload = json_decode($rawPayload, TRUE);
    // \Civi::log()->debug('payload: ' . print_r($payload,TRUE));

    if (empty($payload['notificationItems']) || !is_array($payload['notificationItems'])) {
      throw new \Exception('Invalid notification payload: notificationItems is empty');
    }

    $this->events = [];

    foreach ($payload['notificationItems'] as $item) {
      if (empty($item['NotificationRequestItem'])) {
        throw new \Exception('Invalid notification payload: is empty');
      }
      $item = $item['NotificationRequestItem'];
      // @todo Filter event codes for ones we support?
      // switch ($item['eventCode']) {

      if (empty($item['additionalData']['hmacSignature'])) {
        throw new \Exception('Invalid notification: no HMAC signature provided');
      }

      // verify HMAC
      $hmacValid = FALSE;
      $sig = new \Adyen\Util\HmacSignature();
      // iterate through all enabled HMAC keys and find one that verifies
      // we need to support multiple HMAC keys to support rotation
      foreach ($this->getPaymentProcessor()->getHMACKeys() as $hmacKey) {
        $hmacValid = $sig->isValidNotificationHMAC($hmacKey, $item);
        if ($hmacValid) {
          // we found a valid signature, done
          break;
        }
      }

      if (!$hmacValid) {
        throw new \Exception('Invalid notification: HMAC verification failed');
      }

      if ($this->getPaymentProcessor()->getMerchantAccount() !== $item['merchantAccountCode']) {
        \Civi::log()->debug('MerchantAccountCode ' . $item['merchantAccountCode'] . ' does not match the configured code CiviCRM - ignoring');
        continue;
      }

      $this->events[] = $item;
    }
  }

  /**
   * Handles the incoming http webhook request and returns a suitable http response code.
   */
  public function handleRequest(array $headers, string $body) :int {
    // This verifies HMAC signatures and sets $this->events to an array of "parsed" notifications
    $this->parseWebhookRequest($body);

    $records = [];
    foreach ($this->events as $event) {
      // Nb. we set trigger and identifier here mostly to help when troubleshooting.
      $records[] = [
        'event_id'   => $event['eventDate'],
        'trigger'    => $event['eventCode'],
        'identifier' => $event['pspReference'], // We don't strictly need this but it might be useful for troubleshooting
        'data'       => json_encode($event),
      ];
    }
    if ($records) {
      // Store the events. They will receive status 'new'. Note that
      // because we filter out events we don't need, there may not be any
      // records to record.
      \Civi\Api4\PaymentprocessorWebhook::save(FALSE)
        ->setCheckPermissions(FALSE) // Remove line when minversion>=5.29
        ->setRecords($records)
        ->setDefaults(['payment_processor_id' => $this->getPaymentProcessor()->getID(), 'created_date' => 'now'])
        ->execute();
    }

    \Civi::log()->info("OK: " . count($records) . " webhook events queued for background processing.");
    return 200;
  }

  /**
   * Process a single queued event and update it.
   *
   * @param array $webhookEvent
   *
   * @return bool TRUE on success.
   * @throws \API_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function processQueuedWebhookEvent(array $webhookEvent) :bool {
    $this->setEventID($webhookEvent['event_id']);
    $this->setEventType($webhookEvent['trigger']);

    $event = json_decode($webhookEvent['data'], TRUE);

    $processingResult = $this->processWebhookEvent($event);
    // Update the stored webhook event.
    PaymentprocessorWebhook::update(FALSE)
      ->addWhere('id', '=', $webhookEvent['id'])
      ->addValue('status', $processingResult->ok ? 'success' : 'error')
      ->addValue('message', preg_replace('/^(.{250}).*/su', '$1 ...', $processingResult->message))
      ->addValue('processed_date', 'now')
      ->execute();

    return $processingResult->ok;
  }

  /**
   * Process the given webhook
   *
   * @return bool
   * @throws \API_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function processWebhookEvent($event) :StdClass {
    $return = (object) ['message' => NULL, 'ok' => FALSE, 'exception' => NULL];
    // This event ID is only used for logging messages.
    try {
      // Eg. doAUTHORIZATION
      $method = 'do' . $this->getEventType();
      $return->message = $this->$method($event);
      $return->ok = TRUE;
      \Civi::log()->info($return->message);
    }
    catch (CRM_Adyen_WebhookEventIgnoredException $e) {
      $return->message = $e->getMessage();
      $return->ok = $e->isOk();
      $return->exception = $e;
      \Civi::log()->debug($return->message, $e->getCode());
    }
    catch (Exception $e) {
      $return->message = "FAILED: Had to skip webhook event. Reason: " . $e->getMessage(). "\n" . $e->getTraceAsString();
      $return->exception = $e;
      \Civi::log()->error($return->message);
    }
    return $return;
  }

  /**
   * Handle the "AUTHORISATION" webhook notification
   * @see https://docs.adyen.com/api-explorer/#/Webhooks/latest/post/AUTHORISATION
   * This creates a pending contribution in CiviCRM if it does not already exist
   *
   * @param array $event
   *
   * @return string
   */
  private function doAUTHORISATION($event) :string {
    $trxnID = $this->getContributionTrxnIDFromEvent($event);
    // The authorization for the card was not successful so we ignore it.
    if (empty($event['success'])) {
      throw new CRM_Adyen_WebhookEventIgnoredException('IgnoringAuthorization not successful for merchant Reference: ' . $trxnID);
    }

    // The authorization for the card was successful so we create a pending contribution if we don't already have one.
    $contribution = Contribution::get(FALSE)
      ->addWhere('trxn_id', '=', $trxnID)
      ->execute()
      ->first();
    if (!empty($contribution)) {
      return 'OK. Contribution already exists with ID: ' . $contribution['id'];
    }

    $contactID = $this->getContactIDFromEvent($event);
    $contribution = Contribution::create(FALSE)
      ->addValue('total_amount', $this->getAmountFromEvent($event))
      ->addValue('currency', $this->getCurrencyFromEvent($event))
      ->addValue('contribution_status_id:name', 'Pending')
      ->addValue('trxn_id', $trxnID)
      ->addValue('contact_id', $contactID)
      // @fixme: This should probably be configurable
      ->addValue('financial_type_id.name', 'Donation')
      ->addValue('payment_instrument_id:name', 'Credit Card')
      ->execute()
      ->first();
    return 'OK. Contribution created with ID: ' . $contribution['id'];
  }

  /**
   * Get the contact ID from the event using the shopperEmail / shopperName
   * Match to existing contact or create new contact with the details provided
   *
   * @param array $event
   *
   * @return int
   * @throws \API_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  private function getContactIDFromEvent(array $event): int {
    $email = $event['additionalData']['shopperEmail'];
    //  [shopperName] => [first name=Ivan, infix=null, last name=Velasquez, gender=null]
    preg_match('/\[first name=([^,]*)/', $event['additionalData']['shopperName'], $firstName);
    $firstName = $firstName[1] ?? NULL;
    preg_match('/\last name=([^,]*)/', $event['additionalData']['shopperName'], $lastName);
    $lastName = $lastName[1] ?? NULL;

    $contact = Contact::get(FALSE)
      ->addWhere('contact_type:name', '=', 'Individual');
    if (!empty($firstName)) {
      $contact->addWhere('first_name', '=', $firstName);
    }
    if (!empty($lastName)) {
      $contact->addWhere('last_name', '=', $lastName);
    }
    if (!empty($email)) {
      $contact->addJoin('Email AS email', 'LEFT');
      $contact->addWhere('email.email', '=', $email);
    }
    $contact = $contact->execute()->first();
    if (!empty($contact)) {
      return $contact['id'];
    }

    $newContact = Contact::create(FALSE)
      ->addValue('contact_type:name', 'Individual');
    if (!empty($firstName)) {
      $newContact->addValue('first_name', $firstName);
    }
    if (!empty($lastName)) {
      $newContact->addValue('last_name', $lastName);
    }
    $contact = $newContact->execute()->first();

    if (!empty($email)) {
      Email::create(FALSE)
        ->addValue('contact_id', $contact['id'])
        ->addValue('location_type_id:name', 'Billing')
        ->addValue('email', $email)
        ->execute();
    }
    return $contact['id'];
  }

  /**
   * Get the Contribution TrxnID from the notification
   * @param array $event
   *
   * @return mixed
   * @throws \Exception
   */
  private function getContributionTrxnIDFromEvent(array $event): string {
    if (empty($event['merchantReference'])) {
      throw new Exception('No merchantReference found in payload');
    }
    return $event['merchantReference'];
  }

  /**
   * @param array $event
   *
   * @return float
   */
  private function getAmountFromEvent(array $event): float {
    return ((float) $event['amount']['value']) / 100;
  }

  /**
   * @param array $event
   *
   * @return string
   */
  private function getCurrencyFromEvent(array $event): string {
    return $event['amount']['currency'];
  }

}
