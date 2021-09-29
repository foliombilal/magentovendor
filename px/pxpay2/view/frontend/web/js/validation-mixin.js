define(['jquery'], function($) {
  'use strict';

  return function() {
    $.validator.addMethod(
      'windcave-validate-cvc',
      function(value, element) {
        const ValidationRule = new RegExp('^([0-9]{3}|[0-9]{4})?$');

        return ValidationRule.test(value);
      },
      $.mage.__('Please enter a valid card verification code.')
    );

    $.validator.addMethod(
      'windcave-pattern',
      function(value, element, param) {
        const ValidationRule = new RegExp(param);

        return ValidationRule.test(value);
      },
      $.mage.__('Invalid format.')
    );
  }
});