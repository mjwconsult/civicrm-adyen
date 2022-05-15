<?php
/**
 * @file
 * Exception for when an event in a webhook cannot be processed.
 */
class CRM_Adyen_WebhookEventIgnoredException extends \Exception {

  /**
   * Re use the exception's 'code' prop with a PEAR_LOG_* level.
   */
  public function __construct(string $message, int $level = PEAR_LOG_INFO) {
    parent::__construct($message, $level);
  }

  /**
   * OK: PEAR_LOG_INFO, PEAR_LOG_NOTICE
   */
  public function isOk() :bool {
    return $this->code > PEAR_LOG_WARNING;
  }

  /**
   * ERROR: PEAR_LOG_WARNING, PEAR_LOG_ERR
   */
  public function isError() :bool {
    return $this->code <= PEAR_LOG_WARNING;
  }
}
