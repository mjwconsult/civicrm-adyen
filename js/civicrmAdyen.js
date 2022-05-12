/**
 * JS Integration between CiviCRM & Adyen.
 */
(function($, ts) {

  var script = {
    name: 'adyen',
    elements: {
      dropin: null
    },
    scriptLoading: false,
    paymentProcessorID: null,

    adyenConfiguration: {
      environment: CRM.vars.adyen.env,
      clientKey: CRM.vars.adyen.clientKey, // Public key used for client-side authentication: https://docs.adyen.com/development-resources/client-side-authentication
      session: {
        id: CRM.vars.adyen.session.id, // Unique identifier for the payment session.
        sessionData: CRM.vars.adyen.session.sessionData // The payment session data.
      },
      // Any payment method specific configuration. Find the configuration specific to each payment method:  https://docs.adyen.com/payment-methods
      // For example, this is 3D Secure configuration for cards:
      paymentMethodsConfiguration: {
        card: {
          hasHolderName: true,
          //holderNameRequired: true,
          //billingAddressRequired: true
        }
      },
      onError: (error, component) => {
        console.error(error.name, error.message, error.stack, component);
      },
      /*onSubmit: (state, component) => {
        script.debugging('onSubmit handler');
        if (state.isValid) {}
        // @todo handle failures?
      },*/
      onPaymentCompleted: (result, component) => {
        script.debugging('onPaymentCompleted handler');
        if (result.resultCode === 'Authorised') {
          script.successHandler(result, component);
        }
        else {
          script.debugging('onPaymentCompleted unhandled result: ' + result.resultCode);
        }
      },
    },

    /**
     * Called when payment details have been entered and validated successfully
     *
     * @param {object} result
     * @param {object} component
     */
    successHandler: function(result, component) {
      script.debugging(result.resultCode + ': success - submitting form');

      // Insert the token ID into the form so it gets submitted to the server
      var hiddenInput = document.createElement('input');
      hiddenInput.setAttribute('type', 'hidden');
      hiddenInput.setAttribute('name', 'adyenPaymentReference');
      hiddenInput.setAttribute('value', CRM.vars.adyen.paymentReference);
      CRM.payment.form.appendChild(hiddenInput);

      CRM.payment.resetBillingFieldsRequiredForJQueryValidate();

      // Submit the form
      CRM.payment.form.submit();
    },

    /**
     * Notify user and reset form so it can be submitted again
     */
    doNotifyUserCardDeclined: function() {
      CRM.payment.form.dataset.submitted = 'false';
      CRM.payment.swalFire({
        icon: 'error',
        text: '',
        title: 'Card declined'
      }, '#card-element', true);
    },

    getJQueryPaymentElements: function() {
      return {
        dropin: $('div.adyen-checkout__dropin')
      };
    },

    /**
     * Hide any visible payment elements
     */
    hideJQueryPaymentElements: function() {
      var jQueryPaymentElements = script.getJQueryPaymentElements();
      for (var elementName in jQueryPaymentElements) {
        var element = jQueryPaymentElements[elementName];
        element.hide();
      }
    },

    /**
     * Destroy any payment elements we have already created
     */
    destroyPaymentElements: function() {
      for (var elementName in script.elements) {
        var element = script.elements[elementName];
        if (element !== null) {
          script.debugging("destroying " + elementName + " element");
          element.destroy();
          script.elements[elementName] = null;
        }
      }
    },

    /**
     * Check that payment elements are valid
     * @returns {boolean}
     */
    checkPaymentElementsAreValid: function() {
      var jQueryPaymentElements = script.getJQueryPaymentElements();
      for (var elementName in jQueryPaymentElements) {
        var element = jQueryPaymentElements[elementName];
        if ((element.length !== 0) && (element.children().length !== 0)) {
          script.debugging(elementName + ' element found');
          return true;
        }
      }
      script.debugging('no valid elements found');
      return false;
    },

    handleSubmitCard: function(submitEvent) {
      script.debugging('handle submit card');
      script.threedsHandler(submitEvent);
    },

    /**
     * Payment processor is not ours - cleanup
     */
    notScriptProcessor: function() {
      script.debugging('New payment processor is not ' + script.name + ', clearing CRM.vars.' + script.name);
      script.destroyPaymentElements();
      delete (CRM.vars[script.name]);
      $(CRM.payment.getBillingSubmit()).show();
    },

    checkAndLoad: function() {
      if (typeof CRM.vars[script.name] === 'undefined') {
        script.debugging('CRM.vars' + script.name + ' not defined!');
        return;
      }

      if (typeof AdyenCheckout === 'undefined') {
        if (script.scriptLoading) {
          return;
        }
        script.scriptLoading = true;
        script.debugging('FATAL: adyen.js is not loaded!');
      }
      else {
        script.loadBillingBlock();
      }
    },

    loadBillingBlock: function() {
      script.debugging('loadBillingBlock');

      var oldPaymentProcessorID = script.paymentProcessorID;
      script.paymentProcessorID = CRM.payment.getPaymentProcessorSelectorValue();
      script.debugging('payment processor old: ' + oldPaymentProcessorID + ' new: ' + script.paymentProcessorID + ' id: ' + CRM.vars[script.name].id);
      if ((script.paymentProcessorID !== null) && (script.paymentProcessorID !== parseInt(CRM.vars[script.name].id))) {
        script.debugging('not ' + script.name);
        return script.notScriptProcessor();
      }

      script.debugging('New ID: ' + CRM.vars[script.name].id + ' accessToken: ' + CRM.vars[script.name].accessToken);

      script.createElementAdyenCheckout();
    },

    createElementAdyenCheckout: function() {
      // Hide standard submit button
      $(CRM.payment.getBillingSubmit()).hide();

      // Create dropin elements
      // Create an instance of AdyenCheckout using the configuration object.
      var checkout = AdyenCheckout(script.adyenConfiguration)
        .catch(function (result) {
          script.debugging('FATAL: Could not create Adyen checkout.');
        })
        .then(function (checkout) {
          // Create an instance of Drop-in and mount it to the container you created.
          script.elements.dropin = checkout.create('dropin', {
            onReady: function () {
              script.doAfterElementsHaveLoaded();
            }
          })
            .mount('#dropin-container');
          script.debugging("created new dropin element", script.elements.dropin);
        });
    },

    doAfterElementsHaveLoaded: function() {
      CRM.payment.setBillingFieldsRequiredForJQueryValidate();
      CRM.payment.getBillingSubmit();

      // If another submit button on the form is pressed (eg. apply discount)
      //  add a flag that we can set to stop payment submission
      CRM.payment.form.dataset.submitdontprocess = 'false';

      CRM.payment.addHandlerNonPaymentSubmitButtons();

      for (i = 0; i < CRM.payment.submitButtons.length; ++i) {
        CRM.payment.submitButtons[i].addEventListener('click', submitButtonClick);
      }

      function submitButtonClick(clickEvent) {
        debugger;
        // Take over the click function of the form.
        if (typeof CRM.vars[script.name] === 'undefined') {
          // Do nothing. Not our payment processor
          return false;
        }
        script.debugging('clearing submitdontprocess');
        CRM.payment.form.dataset.submitdontprocess = 'false';

        clickEvent.preventDefault();
        return false;
      }

      // Remove the onclick attribute added by CiviCRM.
      for (i = 0; i < CRM.payment.submitButtons.length; ++i) {
        CRM.payment.submitButtons[i].removeAttribute('onclick');
      }

      CRM.payment.addSupportForCiviDiscount();

      // For CiviCRM Webforms.
      if (CRM.payment.getIsDrupalWebform()) {
        // We need the action field for back/submit to work and redirect properly after submission

        $('[type=submit]').click(function () {
          CRM.payment.addDrupalWebformActionElement(this.value);
        });
        // If enter pressed, use our submit function
        CRM.payment.form.addEventListener('keydown', function (keydownEvent) {
          if (keydownEvent.code === 'Enter') {
            CRM.payment.addDrupalWebformActionElement(this.value);
            keydownEvent.preventDefault();
            return false;
          }
        });

        $('#billingcheckbox:input').hide();
        $('label[for="billingcheckbox"]').hide();
      }

      if (script.checkPaymentElementsAreValid()) {
        CRM.payment.triggerEvent('crmBillingFormReloadComplete', script.name);
        CRM.payment.triggerEvent('crmAdyenBillingFormReloadComplete', script.name);
      }
      else {
        script.debugging('Failed to load payment elements');
        script.triggerEventCrmBillingFormReloadFailed();
      }
    },

    submit: function(submitEvent) {
      script.debugging('submit handler');

      if (CRM.payment.form.dataset.submitted === 'true') {
        return;
      }
      CRM.payment.form.dataset.submitted = 'true';

      if (!CRM.payment.validateCiviDiscount()) {
        return false;
      }

      if (!CRM.payment.validateForm()) {
        return false;
      }

      if (!CRM.payment.validateReCaptcha()) {
        return false;
      }

      if (typeof CRM.vars[script.name] === 'undefined') {
        script.debugging('Submitting - not a ' + script.name + ' processor');
        return true;
      }

      var scriptProcessorId = parseInt(CRM.vars[script.name].id);
      var chosenProcessorId = null;

      // Handle multiple payment options and ours not being chosen.
      if (CRM.payment.getIsDrupalWebform()) {
        // this element may or may not exist on the webform, but we are dealing with a single processor enabled.
        if (!$('input[name="submitted[civicrm_1_contribution_1_contribution_payment_processor_id]"]').length) {
          chosenProcessorId = scriptProcessorId;
        }
        else {
          chosenProcessorId = parseInt(CRM.payment.form.querySelector('input[name="submitted[civicrm_1_contribution_1_contribution_payment_processor_id]"]:checked').value);
        }
      }
      else {
        // Most forms have payment_processor-section but event registration has credit_card_info-section
        if ((CRM.payment.form.querySelector(".crm-section.payment_processor-section") !== null) ||
          (CRM.payment.form.querySelector(".crm-section.credit_card_info-section") !== null)) {
          scriptProcessorId = CRM.vars[script.name].id;
          if (CRM.payment.form.querySelector('input[name="payment_processor_id"]:checked') !== null) {
            chosenProcessorId = parseInt(CRM.payment.form.querySelector('input[name="payment_processor_id"]:checked').value);
          }
        }
      }

      // If any of these are true, we are not using our processor:
      // - Is the selected processor ID pay later (0)
      // - Is our processor ID defined?
      // - Is selected processor ID and our ID undefined? If we only have our ID, then there is only one of our processor on the page
      if ((chosenProcessorId === 0) || (scriptProcessorId === null) ||
        ((chosenProcessorId === null) && (scriptProcessorId === null))) {
        script.debugging('Not a ' + script.name + ' transaction, or pay-later');
        return CRM.payment.doStandardFormSubmit();
      }
      else {
        script.debugging(script.name + ' is the selected payprocessor');
      }

      // Don't handle submits generated by other processors
      if (typeof CRM.vars[script.name].accessToken === 'undefined') {
        script.debugging('submit missing accessToken element or value');
        return true;
      }
      // Don't handle submits generated by the CiviDiscount button etc.
      if (CRM.payment.form.dataset.submitdontprocess === 'true') {
        script.debugging('non-payment submit detected - not submitting payment');
        return true;
      }

      if (CRM.payment.getIsDrupalWebform()) {
        // If we have selected ours but amount is 0 we don't submit via our processor
        if ($('#billing-payment-block').is(':hidden')) {
          script.debugging('no payment processor on webform');
          return true;
        }

        // If we have more than one processor (user-select) then we have a set of radio buttons:
        var processorFields = $('[name="submitted[civicrm_1_contribution_1_contribution_payment_processor_id]"]');
        if (processorFields.length) {
          if (processorFields.filter(':checked')
            .val() === '0' || processorFields.filter(':checked').val() === 0) {
            script.debugging('no payment processor selected');
            return true;
          }
        }
      }

      var totalAmount = CRM.payment.getTotalAmount();
      if (totalAmount === 0.0) {
        script.debugging("Total amount is 0");
        return CRM.payment.doStandardFormSubmit();
      }

      // Disable the submit button to prevent repeated clicks
      for (i = 0; i < CRM.payment.submitButtons.length; ++i) {
        CRM.payment.submitButtons[i].setAttribute('disabled', true);
      }

      script.handleSubmitCard(submitEvent);

      return true;
    },

    /**
     * Output debug information
     * @param {string} errorCode
     */
    debugging: function(errorCode) {
      CRM.payment.debugging(script.name, errorCode);
    },

    /**
     * Trigger the crmBillingFormReloadFailed event and notify the user
     */
    triggerEventCrmBillingFormReloadFailed: function() {
      CRM.payment.triggerEvent('crmBillingFormReloadFailed');
      script.hideJQueryPaymentElements();
      CRM.payment.displayError(ts('Could not load payment element - Is there a problem with your network connection?'), true);
    },

  };

  // Disable the browser "Leave Page Alert" which is triggered because we mess with the form submit function.
  window.onbeforeunload = null;

  if(CRM.payment.hasOwnProperty(script.name)) {
    return;
  }

  // Currently this just flags that we've already loaded
  var crmPaymentObject = {};
  crmPaymentObject[script.name] = script;
  $.extend(CRM.payment, crmPaymentObject);

  CRM.payment.registerScript(script.name);

  // Re-prep form when we've loaded a new payproc via ajax or via webform
  $(document).ajaxComplete(function (event, xhr, settings) {
    if (CRM.payment.isAJAXPaymentForm(settings.url)) {
      script.debugging('triggered via ajax');
      load();
    }
  });

  document.addEventListener('DOMContentLoaded', function () {
    script.debugging('DOMContentLoaded');
    load();
  });

  function load() {
    if (window.civicrmAdyenHandleReload) {
      // Call existing instance of this, instead of making new one.
      script.debugging("calling existing HandleReload.");
      window.civicrmAdyenHandleReload();
    }
  }

  /**
   * This function boots the UI.
   */
  window.civicrmAdyenHandleReload = function () {
    CRM.payment.scriptName = script.name;
    script.debugging('HandleReload');

    // Get the form containing payment details
    CRM.payment.form = CRM.payment.getBillingForm();
    if (typeof CRM.payment.form.length === 'undefined' || CRM.payment.form.length === 0) {
      script.debugging('No billing form!');
      return;
    }

    // Load element onto the form.
    var cardElement = document.getElementById('dropin-container');
    if ((typeof cardElement !== 'undefined') && (cardElement)) {
      if (!cardElement.children.length) {
        script.debugging('checkAndLoad from document.ready');
        script.checkAndLoad();
      }
      else {
        script.debugging('already loaded');
      }
    }
    else {
      script.notScriptProcessor();
      CRM.payment.triggerEvent('crmBillingFormReloadComplete', script.name);
    }
  };

}(CRM.$, CRM.ts('adyen')));
