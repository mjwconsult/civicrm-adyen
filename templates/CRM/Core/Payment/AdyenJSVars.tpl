{*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
*}
{* Manually create the CRM.vars.adyen here for drupal webform because \Civi::resources()->addVars() does not work in this context *}
{literal}
<script type="text/javascript">
  CRM.$(function($) {
    $(document).ready(function() {
      if (typeof CRM.vars.adyen === 'undefined') {
        var adyen = {{/literal}{foreach from=$adyenJSVars key=arrayKey item=arrayValue}{$arrayKey}:'{$arrayValue}',{/foreach}{literal}};
        CRM.vars.adyen = adyen;
      }
    });
  });
</script>
{/literal}
