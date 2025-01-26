class AuthorizeDotNetHandler {
    constructor($that, config) {
        this.$form = $that.form;
        this.payformHandler = $that;
        this.config = config;
        this.formId = config?.form_id;
        this.generalConfig = window.wp_payform_general;
    }

    init() {
        this.$form.on('wppayform_next_action_authorizedotnet', (event, data) => {
            const response = data.response;

            console.log('authorize dot net response', response);
            jQuery('<div/>', {
                'id': 'form_success',
                'class': 'wpf_form_notice_success wpf_authorize_text'
            })
                .html(response.data.message)
                .insertAfter(this.$form);
            
            jQuery("#redirectToken").val(response.data.formToken);

            if(response.data.actionName === 'initAuthorizeDotNetCheckout') {
                this.initAuthorizeDotNetCheckout(response.data);
            } else {
                alert('No method found');
            }
        });
    }

    async initAuthorizeDotNetCheckout(res) {
        const that = this;
        window.myForm = this.$form;
        let actionUrl = res.actionUrl;
        let formToken = res.formToken;

        // Define the API endpoint and request payload
   
        const payload = new URLSearchParams({ token: formToken });

        var dataValueInput = jQuery.parseHTML(`<input type="hidden" name="dataValue" id="dataValue" />`);
        var dataDescriptor = jQuery.parseHTML(`<input type="hidden" name="dataDescriptor" id="dataDescriptor" />`);

        var newButtonHtml = jQuery.parseHTML(`
            <button type="button"
                  class="AcceptUI"
                  data-billingAddressOptions='{"show":true, "required":false}' 
                  data-apiLoginID="${res.apiLoginID}"
                  data-clientKey="${res.clientKey}"
                  data-acceptUIFormBtnTxt="Submit" 
                  data-acceptUIFormHeaderTxt="Card Information"
                  data-paymentOptions='{"showCreditCard": true, "showBankAccount": true}' 
                  data-responseHandler="responseHandler">Pay With Authorize.Net
            </button>
          `);
        
        // hide existing button with class 'wpf_submit_button, also remove the 'wpf_loading_svg' loader
        this.$form.find('.wpf_submit_button').hide();
        this.$form.find('.wpf_loading_svg').hide();
        that.$form.parent().find('.wpf_authorize_text').hide();

        this.$form.append(dataValueInput);
        this.$form.append(dataDescriptor);
        this.$form.append(newButtonHtml);
        const script = document.createElement('script');
        script.src = res.scriptUrl; // Replace with the correct URL to accept.js
        script.type = 'text/javascript';
        script.onload = () => {
        };
        script.onerror = () => {
            // hide the button and show the button with class 'wpf_submit_button' and make the button clickable
            this.$form.find('.AcceptUI').hide();
            this.$form.find('.wpf_submit_button').show();
            console.error('Failed to load Accept.js.');
        };

        // Append the script to the document's <head> or <body>
        document.head.appendChild(script);

        // create a function to handle the response from the Accept.js library
        window.responseHandler = function(response) {
            console.log('responseHandler', response, 'form', that.$form);
            if (response.messages.resultCode === 'Error') {
                console.log('Error: ' + response.messages.message[0].code + ' ' + response.messages.message[0].text);
            } else {
                console.log('responseHandler', response);
                jQuery("#dataValue").val(response.opaqueData.dataValue);
                jQuery("#dataDescriptor").val(response.opaqueData.dataDescriptor);

                // now make a api call to the server with the dataValue and dataDescriptor               

                // that.$form.submit();
            }
        }

     

            
        // that.payformHandler.buttonState('loading', '', false);
    }

    myPaymentComplete(response) {
        console.log('payment complete', response);
    
    }
}

// handle response from Accept.js


(function ($) {
    $.each($('form.wppayform_has_payment'), function () {
        const $form = $(this);
        $form.on('wppayform_init_single', function (event, $that, config) {
            (new AuthorizeDotNetHandler($that, config)).init();
        });
    });
}(jQuery));
