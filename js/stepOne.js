//performs step one of the bng three step process.  formats non-secure data and retrieves a url
async function woo_nmi_stepOne(data, newPm) {
    var goAhead = false;
    var form = document.getElementById('bng_submitPayment');

    if (data.hasOwnProperty('isAcctScreen')) {
        form = document.getElementById('add_payment_method')
    }
    else {
        jQuery('#bng_spinner').show();
    }
    
    //determine which action we are taking
    //  - if user is NOT saving the payment method OR (savepayment is yes AND customervaultid is blank)
    //      - use option (1)
    //  - if savepayment is yes AND customervaultid is not blank
    //      - use option (2)
    if (data['savepaymentmethod'] == 'N' || (data['savepaymentmethod'] == 'Y' && !data['customerHasVault']) || data['tokenid']) {
    // (1) creates new customer vault id and billing id
        await AjaxCall({
            action: 'woo_nmi_stepOne',
            data: data
        }).then((response) => {
            goAhead = true;
            var exploder = response.split('--||--');
            var responseurl = exploder[0];
            var billingid = exploder[1];

            if (billingid != '' && billingid) {
                form.action = responseurl;
            }
            else if (newPm || (data['savepaymentmethod'] == 'Y' && !data['customerHasVault'])) {
                form.action = response.replace(/^\s+|\s+$/gm,'');
            }
            else {
                var response = response.split('||');
                form.action = response[0];
            }
        },
        (error) => {
            if (error.hasOwnProperty('responseText')) alert(JSON.parse(error.responseText).data);
            return false;
        });
    }
    else {
    // (2) add a billing id to an existing customer vault id.  use add-billing first for three step, then process the sale after that has returned
        // testing ajax
        await AjaxCall({
            action: 'woo_nmi_stepOne_addBilling',
            data: data
        }).then((response) => {
            goAhead = true;

            var exploder = response.split('--||--');
            var responseurl = exploder[0];
            var billingid = exploder[1];
            
            if (billingid == '') {
                form.action = response.replace(/^\s+|\s+$/gm,'');
            }
            else {
                form.action = responseurl;  
            } 
        }, (error) => {
            if (error.hasOwnProperty('responseText')) alert(JSON.parse(error.responseText).data);
            return false; 
        });
    }
    
    if (goAhead) {                  
        form.submit();
    }
}

async function AjaxCall(data) {
    return await jQuery.ajax({
        type:   "POST",
        url:    frontendajax.ajaxurl,
        data:   data 
    });
}