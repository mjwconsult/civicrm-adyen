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

use CRM_Stripe_ExtensionUtil as E;

/**
 * Form controller class
 *
 * @see https://wiki.civicrm.org/confluence/display/CRMDOC/QuickForm+Reference
 */
class CRM_Stripe_Form_UpdateWebhook extends CRM_Core_Form {

  public function buildQuickForm() {
    // Defaults.
    $this->assign('shouldOfferToFix', 0);
    $this->assign('isStillBad', 0);
    $this->assign('isAllOk', 0);

    // Run check.
    $messages = [];
    $webhooks = new CRM_Stripe_Webhook();
    $webhooks->check($messages);
    if (!$messages) {
      $this->assign('isAllOk', 1);
    }
    else {
      $this->assign('shouldOfferToFix', 1);
      $this->assignMessages($messages);

      $this->addButtons(array(
        array(
          'type' => 'submit',
          'name' => E::ts('Update / Create webhook'),
          'isDefault' => TRUE,
        ),
      ));
    }

    // export form elements
    $this->assign('elementNames', $this->getRenderableElementNames());
    parent::buildQuickForm();
  }

  public function postProcess() {
    $messages = [];
    $attemptFix = TRUE;
    $webhooks = new CRM_Stripe_Webhook();
    $webhooks->check($messages, $attemptFix);

    if ($messages) {
      $this->assign('isStillBad', 1);
      $this->assign('shouldOfferToFix', 0);
      $this->assignMessages($messages);
    }
    else {
      $this->assign('isAllOk', 1);
      $this->assign('shouldOfferToFix', 0);
      $this->assign('isStillBad', 0);
      $this->assign('intro', E::ts('All webhooks update successfully.'));
    }

    parent::postProcess();
  }

  /**
   * @param array $messages
   */
  private function assignMessages($messages) {
    $messagesArray = [];
    foreach ($messages as $message) {
      $messagesArray[] = [
        'title' => $message->getTitle(),
        'message' => $message->getMessage(),
      ];
    }
    $this->assign('messages', $messagesArray);
  }

  /**
   * Get the fields/elements defined in this form.
   *
   * @return array (string)
   */
  public function getRenderableElementNames() {
    // The _elements list includes some items which should not be
    // auto-rendered in the loop -- such as "qfKey" and "buttons".  These
    // items don't have labels.  We'll identify renderable by filtering on
    // the 'label'.
    $elementNames = array();
    foreach ($this->_elements as $element) {
      /** @var HTML_QuickForm_Element $element */
      $label = $element->getLabel();
      if (!empty($label)) {
        $elementNames[] = $element->getName();
      }
    }
    return $elementNames;
  }

}
