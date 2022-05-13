# adyen

CiviCRM payment processor for integration with [Adyen](https://www.adyen.com/).

The extension is licensed under [AGPL-3.0](LICENSE.txt).

## Installation

Learn more about installing CiviCRM extensions in the [CiviCRM Sysadmin Guide](https://docs.civicrm.org/sysadmin/en/latest/customize/extensions/).

This extension requires Payment Shared (https://lab.civicrm.org/extensions/mjwshared).

## Setup

Login to adyen portal. Create API credentials and make sure you add the URL of your CiviCRM server to "Allow Origins".

In CiviCRM add a new payment processor with type "Adyen".
Configure the Merchant Account, X-API-Key.
Then you need to add a JSON-formatted configuration for the other parameters:
```json
{
  "clientKey": "test_XXX",
  "urlPrefix": "",
  "hmacKeys": {
    "0": "key1",
    "1": "key2"
  }
}
```

- The hmacKeys are used to validate webhooks.
- The client Key is used for submitting payments via CiviCRM.
- The URL prefix is only required for the live payment processor.

## Known issues

- The payment "dropin" for taking payments directly in CiviCRM is not fully implemented because it was not a client requirement.
Currently it loads with a fixed amount (EUR 10).

- The webhook checks do not work - it is supposed to authorize using the X-API-Key but returns 401 unauthorized when getting the list of webhooks.

-
