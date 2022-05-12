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

/**
 * Class CRM_Adyen_Webhook
 */
class CRM_Adyen_Webhook {

  use CRM_Mjwshared_WebhookTrait;

  /**
   * Checks whether the payment processors have a correctly configured webhook
   *
   * @see adyen_civicrm_check()
   *
   * @param array $messages
   * @param bool $attemptFix If TRUE, try to fix the webhook.
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public function check(array &$messages, bool $attemptFix = FALSE) {
    $env = \Civi::settings()->get('environment');
    if ($env && $env !== 'Production') {
      return;
    }
    $result = civicrm_api3('PaymentProcessor', 'get', [
      'class_name' => 'Payment_Adyen',
      'is_active' => 1,
      'domain_id' => CRM_Core_Config::domainID(),
    ]);

    foreach ($result['values'] as $paymentProcessor) {
      $webhook_path = self::getWebhookPath($paymentProcessor['id']);
      $processor = \Civi\Payment\System::singleton()->getById($paymentProcessor['id']);
      if ($processor->client === NULL) {
        // This means we only configured live OR test and not both.
        continue;
      }

      try {
        $notification = new \Adyen\Service\Notification($processor->client);
        $webhooks = $notification->getNotificationConfigurationList([]);
      }
      catch (Exception $e) {
        $error = $e->getMessage();
        $messages[] = new CRM_Utils_Check_Message(
          __FUNCTION__ . $paymentProcessor['id'] . 'adyen_webhook',
          $error,
          $this->getTitle($paymentProcessor),
          \Psr\Log\LogLevel::ERROR,
          'fa-money'
        );

        continue;
      }

      $found_wh = FALSE;
      foreach ($webhooks['configurations'] as $wh) {
        if ($wh['notifyURL'] === $webhook_path) {
          $found_wh = TRUE;
          // Check and update webhook
          try {
            $updates = $this->checkWebhookEvents($wh);

            /*
             * {
  "pspReference": "8616523723776259",
  "configurationDetails": {
    "active": true,
    "description": "Unique description 12223",
    "eventConfigs": [
      {
        "eventType": "ACCOUNT_HOLDER_VERIFICATION",
        "includeMode": "INCLUDE"
      }
    ],
    "notificationId": 27893,
    "notifyURL": "https://www.adyen.com/notification-handler",
    "sslProtocol": "SSLInsecureCiphers"
  }
}
             */
            if ($updates && !empty($wh['active'])) {
              if ($attemptFix) {
                try {
                  // We should try to update the webhook.
                  $processor->client->webhookEndpoints->update($wh['notificationId'], $updates);
                }
                catch (Exception $e) {
                  $messages[] = new CRM_Utils_Check_Message(
                    __FUNCTION__ . $paymentProcessor['id'] . 'adyen_webhook',
                    E::ts('Unable to update the webhook %1. To correct this please delete the webhook at Adyen and then revisit this page which will recreate it correctly. Error was: %2',
                      [
                        1 => urldecode($webhook_path),
                        2 => htmlspecialchars($e->getMessage()),
                      ]
                    ),
                    $this->getTitle($paymentProcessor),
                    \Psr\Log\LogLevel::WARNING,
                    'fa-money'
                  );
                }
              }
              else {
                $message = new CRM_Utils_Check_Message(
                  __FUNCTION__ . $paymentProcessor['id'] . 'adyen_webhook',
                  E::ts('Problems detected with Adyen webhook! <em>Webhook path is: <a href="%1" target="_blank">%1</a>.</em>',
                    [1 => urldecode($webhook_path)]
                  ),
                  $this->getTitle($paymentProcessor),
                  \Psr\Log\LogLevel::WARNING,
                  'fa-money'
                );
                $message->addAction(
                  E::ts('View and fix problems'),
                  NULL,
                  'href',
                  ['path' => 'civicrm/adyen/fix-webhook', 'query' => ['reset' => 1]]
                );
                $messages[] = $message;
              }
            }
          }
          catch (Exception $e) {
            $messages[] = new CRM_Utils_Check_Message(
              __FUNCTION__ . $paymentProcessor['id'] . 'adyen_webhook',
              E::ts('Could not check/update existing webhooks, got error from adyen <em>%1</em>', [
                  1 => htmlspecialchars($e->getMessage())
                ]
              ),
              $this->getTitle($paymentProcessor),
              \Psr\Log\LogLevel::WARNING,
              'fa-money'
            );
          }
        }
      }

      if (!$found_wh) {
        if ($attemptFix) {
          try {
            // Try to create one.
            $this->createWebhook($paymentProcessor['id']);
          }
          catch (Exception $e) {
            $messages[] = new CRM_Utils_Check_Message(
              __FUNCTION__ . $paymentProcessor['id'] . 'adyen_webhook',
              E::ts('Could not create webhook, got error from adyen <em>%1</em>', [
                1 => htmlspecialchars($e->getMessage())
              ]),
              $this->getTitle($paymentProcessor),
              \Psr\Log\LogLevel::WARNING,
              'fa-money'
            );
          }
        }
        else {
          $message = new CRM_Utils_Check_Message(
            __FUNCTION__ . $paymentProcessor['id'] . 'adyen_webhook',
            E::ts(
              'Adyen Webhook missing or needs update! <em>Expected webhook path is: <a href="%1" target="_blank">%1</a></em>',
              [1 => $webhook_path]
            ),
            $this->getTitle($paymentProcessor),
            \Psr\Log\LogLevel::WARNING,
            'fa-money'
          );
          $message->addAction(
            E::ts('View and fix problems'),
            NULL,
            'href',
            ['path' => 'civicrm/adyen/fix-webhook', 'query' => ['reset' => 1]]
          );
          $messages[] = $message;
        }
      }
    }
  }

  /**
   * Get the error message title for the system check
   * @param array $paymentProcessor
   *
   * @return string
   */
  private function getTitle(array $paymentProcessor): string {
    if (!empty($paymentProcessor['is_test'])) {
      $paymentProcessor['name'] .= ' (test)';
    }
    return E::ts('Adyen Payment Processor: %1 (%2)', [
      1 => $paymentProcessor['name'],
      2 => $paymentProcessor['id'],
    ]);
  }

  /**
   * Create a new webhook for payment processor
   *
   * @param int $paymentProcessorId
   */
  public function createWebhook(int $paymentProcessorId) {
    $processor = \Civi\Payment\System::singleton()->getById($paymentProcessorId);

    $params = [
      'enabled_events' => self::getDefaultEnabledEvents(),
      'url' => self::getWebhookPath($paymentProcessorId),
      'connect' => FALSE,
    ];
    $processor->client->webhookEndpoints->create($params);
  }


  /**
   * Check and update existing webhook
   *
   * @param array $webhook
   *
   * @return array of correction params. Empty array if it's OK.
   */
  private function checkWebhookEvents(array $webhook): array {
    return [];

    /*
     * Maybe implement a check in the future
    "eventConfigs": [
      {
        "eventType": "ACCOUNT_HOLDER_VERIFICATION",
        "includeMode": "INCLUDE"
      }
    ],
    $params = [];
    if (array_diff(self::getDefaultEnabledEvents(), $webhook['eventConfigs'])) {
      $params['enabled_events'] = self::getDefaultEnabledEvents();
    }
    return $params;
    */
  }

  /**
   * List of webhooks we currently handle
   *
   * @return array
   */
  public static function getDefaultEnabledEvents(): array {
    return [
      'invoice.finalized',
      //'invoice.paid' Ignore this event because it sometimes causes duplicates (it's sent at almost the same time as invoice.payment_succeeded
      //   and if they are both processed at the same time the check to see if the payment already exists is missed and it gets created twice.
      'invoice.payment_succeeded',
      'invoice.payment_failed',
      'charge.failed',
      'charge.refunded',
      'charge.succeeded',
      'charge.captured',
      'customer.subscription.updated',
      'customer.subscription.deleted',
    ];
  }

  /**
   * List of webhooks that we do NOT process immediately.
   *
   * @return array
   */
  public static function getDelayProcessingEvents(): array {
    return [
      // This event does not need processing in real-time because it will be received simultaneously with
      //   `invoice.payment_succeeded` if start date is "now".
      // If starting a subscription on a specific date we only receive this event until the date the invoice is
      // actually due for payment.
      // If we allow it to process whichever gets in first (invoice.finalized or invoice.payment_succeeded) we will get
      //   delays in completing payments/sending receipts until the scheduled job is run.
      'invoice.finalized'
    ];
  }

}
