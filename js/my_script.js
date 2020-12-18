/**
 * Namespace to be used globally to store necessary data
 */
var storedVariables = storedVariables || ( function() {
    var _args = {}; // private

    return {
        init : function(Args) {
            _args = Args;
        },
        update : function(name, value) {
            _args[name] = value;
        },
        receiptData : function() {
            return _args.ReceiptData;
        },
        pluginPath : function() {
            return _args.PluginPath;
        },
        paymentMethods : function() {
            return _args.PaymentMethods;
        },
        customerHasVault : function() {
            return _args.CustomerHasVault;
        },
        allowSavePayment : function() {
            return _args.AllowSavePayment;
        },
        cartUrl : function() {
            return _args.CartUrl;
        },
        useTokenization : function() {
            return _args.UseTokenization;
        },
        forceSavePayment : function() {
            return _args.ForceSavePayment;
        },
    };
} () );

/**
 * add an event listener to when enter key is pressed
 */
document.addEventListener('keypress', function (e) {
    if (e.key === 'Enter') {
        var button = jQuery("input#bng_submitButton");
        if (button.length) jQuery("input#bng_submitButton").trigger('click');
        else jQuery("button#place_order").trigger('click');
    }
});

function populateStoredPaymentMethods() {
    var ul = document.querySelector('#paymentMethods');
    var paymentMethods = storedVariables.paymentMethods();
    
    if (paymentMethods.length) jQuery('.savedPms').css({'display': 'block'});
    else jQuery('.savedPms').css({'display': 'none'});

    for ( index in paymentMethods ) {
        var li = document.createElement('li'); // children = label
        li.setAttribute('id', `paymentMethod_li_${paymentMethods[index].internalId}`);
        li.setAttribute('tokenId', paymentMethods[index].internalId);
        li.onclick = (e) => { 
            e.preventDefault(); 
            if (e.target.nodeName != 'A') woo_nmi_toggleState(e.currentTarget);
        }
        if (paymentMethods[index].highlight) {
            li.style.backgroundColor = '#6ac372';
            li.style.color = '#ffffff';
        }
        
        var label = document.createElement('label'); // children = imgDiv, detailsDiv
        label.htmlFor = `paymentMethod_${paymentMethods[index].internalId}`;

        var imgDiv = document.createElement('div'); // children = img
        jQuery(imgDiv).addClass('cc');

        var img = document.createElement('img');
        img.src = (paymentMethods[index].type == 'CC') ? paymentMethods[index].card : paymentMethods[index].check;

        var detailsDiv = document.createElement('div'); // children = b, em, a
        jQuery(detailsDiv).addClass('detailsDiv');

        var bold = document.createElement('b');
        var italic = document.createElement('em');
        var boldText, italicText;

        if (paymentMethods[index].type == 'CC') { 
            boldText = `Card ending in ${paymentMethods[index].ccNumber}`;
            italicText = `Expires ${paymentMethods[index].ccExp}`;
        }
        else {
            boldText = `Check for ${paymentMethods[index].acctName}`;
            italicText = `Account ending in ${paymentMethods[index].acctNumber}`;
        }

        bold.append(document.createTextNode(boldText));
        italic.append(document.createTextNode(italicText));

        var link = document.createElement('a');
        link.setAttribute('href', 'javascript:;');
        link.setAttribute('nonce', paymentMethods[index].nonce);
        link.setAttribute('tokenId', paymentMethods[index].internalId);
        link.onclick = async (e) => { 
            e.preventDefault(); 
            jQuery('#bng_spinner').show();
            await woo_nmi_deletePM(e.target);
        }
        link.append(document.createTextNode('Delete'));

        imgDiv.appendChild(img);

        detailsDiv.appendChild(bold);
        detailsDiv.appendChild(document.createElement('br'));        
        detailsDiv.appendChild(italic);
        detailsDiv.appendChild(document.createTextNode(' | '));
        detailsDiv.appendChild(link);
        
        label.appendChild(imgDiv);
        label.appendChild(detailsDiv);

        li.appendChild(label);
        ul.appendChild(li);
    }
}

function populatePaymentMethods() {
    var data = storedVariables.receiptData();
    if ( data['paymenttype'] == 'cc' ) {
        jQuery('#ach').hide();
        jQuery('label[for="ach"]').hide();

        jQuery("input#cc").prop('checked', true).trigger('click');
    }
    else if ( data['paymenttype'] == 'ach') {
        jQuery('#cc').hide();
        jQuery('label[for="cc"]').hide();

        jQuery("input#ach").removeClass('cc_preferred');
        jQuery("input#ach").prop('checked', true).trigger('click');
    }
    else {
        jQuery("input#cc").prop('checked', true).trigger('click');
    }
    
    if (storedVariables.allowSavePayment()) {
        jQuery('.savePmLater').show();
        if (storedVariables.forceSavePayment()) document.getElementById('bng_savePaymentMethod').checked = true;
    }
}

function populateMinExpiration() {
    var exp = document.getElementById('billingdate');
    
    var date = new Date();
    var currentYear = date.getFullYear();
    var month = date.getMonth() + 1;
    if (month < 10) month = `0${month}`;
    exp.min = `${currentYear}-${month}`;
    exp.value = `${currentYear}-${month}`;
}

function woo_nmi_toggleState(event) {
    var allPms = document.getElementsByClassName("active");

    if (allPms.length) {
        jQuery.each(allPms, (index, element) => {
            element.classList.remove('active');
        });
    }

    event.classList.add('active');
    
    if (event.id.length) {      
        if (storedVariables.useTokenization()) document.getElementById('bng_payButton').disabled = false;   

        var tokenId = event.getAttribute('tokenId');
        storedVariables.paymentMethods().map( (pm) => {
            if (pm.internalId == tokenId) {
                if (pm.type == "CC") {
                    jQuery('input#payment').attr('value', 'creditcard');
                }
                else if (pm.type == "eCheck") {
                    jQuery('input#payment').attr('value', 'check');
                }
            }
        });
    }
} 

function display_cc_elements(event) {
    event.stopPropagation();

    jQuery('input#payment').attr('value', 'creditcard');
    if (storedVariables.allowSavePayment()) {
        jQuery('.savePmLater').show();
        if (storedVariables.forceSavePayment()) document.getElementById('bng_savePaymentMethod').checked = true;
    }

    if (storedVariables.useTokenization()) {
        useTokenization();
    }
    else {
        jQuery('.cc_type').show();
        jQuery('.ach_type').hide();
        jQuery('.ach_type_extra').attr('style', 'display: none');
    }
}

function display_check_elements(event) {
    event.stopPropagation();

    jQuery('input#payment').attr('value', 'check');
    if (storedVariables.allowSavePayment()) {
        jQuery('.savePmLater').show();
        if (storedVariables.forceSavePayment()) document.getElementById('bng_savePaymentMethod').checked = true;
    }

    if (storedVariables.useTokenization()) {
        useTokenization();
    }
    else {
        jQuery('.cc_type').hide();
        jQuery('.ach_type').show();
        jQuery('.ach_type_extra').attr('style', 'display: flex');
    }
}
    
async function woo_nmi_cc_validate(acctScreenPage = false) {
    //disable form fields
    if (!acctScreenPage) {
        document.getElementById("bng_backButton").disabled = true;
        document.getElementById("bng_submitButton").disabled = true;
        //show spinner
        jQuery('#bng_spinner').show();
    }

    var error = "";
    var newPm = false;
    var allPms = document.getElementsByClassName("active"); // should only be 1 value
    if (allPms.length) {
        if (allPms[0].id == '') {
            newPm = true;
        }
    }

    var paymentType = jQuery('input#payment').attr('value');

    if (newPm) {
        if (paymentType == 'creditcard') {
            var ccNumber = document.getElementById('billingCcNumber').value;
            var ccExp = document.getElementById('billingdate').value;

            //validate ccnumber, remove all spaces and check for non-numeric chars.  if it fails, show an alert.  otherwise, the gateway will handle a failed number too
            var test_ccnumber = ccNumber.replace(/ /g,'');
            if (isNaN(test_ccnumber) == true) error += "- Not a valid credit card number\n";

            if (test_ccnumber == '') error += "- Credit card number must not be blank\n";

            //check exp date.  
            if ( ccExp == '') {
                error += "- Valid Expiration Date";
            }        
        }
        else {
            var acctName = document.getElementById('billingAccountName').value;
            var acctNum = document.getElementById('billingAccountNumber').value;
            var reenterAcctNum = document.getElementById('reenterBillingAccountNumber').value;
            var routingNum = document.getElementById('billingRoutingNumber').value;
            var acctType = document.getElementById('billingAccountType').value;
            var entitytype = document.getElementById('billingEntityType').value;

            var message = "is required for check payments.";

            if (!acctName || !acctName.length) error += `Account Name ${message}\n`;
            if (!acctNum || !acctNum.length) error += `Account Number ${message}\n`;
            if (!acctType || !acctType.length) error += `Account Type ${message}\n`;
            if (!entitytype || !entitytype.length) error += `Account Holder or Entity Type ${message}\n`;
            if (!routingNum || !routingNum.length) error += `Routing Number ${message}\n`;
            if (acctNum !== reenterAcctNum) error += 'Account numbers do not match. Check account number.\n';
            
            if (routingNum) {
                if (routingNum.length !== 9) {
                    error += "Routing Number must be of length nine(9).\n";
                }
                else {
                    // routing checksum check
                    var num = 0;
                    for (var i = 0; i < routingNum.length; i += 3) {
                        num += parseInt(routingNum.charAt(i), 10) * 3
                            +  parseInt(routingNum.charAt(i + 1), 10) * 7
                            +  parseInt(routingNum.charAt(i + 2), 10)
                    }
                    
                    if (num == 0 || (num % 10) != 0) {
                        error += "Routing Number is Invalid\n";
                    }
                }
            }
        }
    }

    if (error != '') {
        alert('Please make sure the following are correct:\n' + error);

        if (!acctScreenPage) {
            document.getElementById("bng_backButton").disabled = false;
            document.getElementById("bng_submitButton").disabled = false;
            //show spinner
            jQuery('#bng_spinner').hide();
        }

        return false;
    }
    else await woo_nmi_arrangeData(newPm, paymentType, acctScreenPage);
}

async function woo_nmi_arrangeData(newPm, paymentType, acctScreenPage = false) {
    if (!acctScreenPage) {
        document.getElementById("bng_backButton").disabled = true;
        document.getElementById("bng_submitButton").disabled = true;
        jQuery('#bng_spinner').show();
    }

    var pmselected = "N";
    var activeGroup = document.getElementsByClassName("active");
    if (activeGroup.length) {
        pmselected = "Y"
    }

    if (pmselected == "N") {
        alert("Please select a payment method or create a new one");
        
        if (!acctScreenPage) {
            document.getElementById("bng_backButton").disabled = false;
            document.getElementById("bng_submitButton").disabled = false;
        
            jQuery('#bng_spinner').hide();
        }
        return false;
    }

    var savePaymentMethod = "N";
    var savePM = document.getElementById('bng_savePaymentMethod');
    if (savePM && jQuery(savePM).is(':checked')) savePaymentMethod = "Y";
    
    var data = storedVariables.receiptData();
    if (acctScreenPage) {
        data['woocommerce-add-payment-method-nonce'] = document.getElementById('woocommerce-add-payment-method-nonce').value;
        data['woocommerce_add_payment_method'] = document.getElementById('woocommerce_add_payment_method').value;
    }

    if (!newPm) {
        var allPms = document.getElementsByClassName("active"); // should only be 1 value
        data['tokenid'] = parseInt(allPms[0]?.id.match(/\d+/g), 10 );
    }

    data["savepaymentmethod"] = savePaymentMethod;
    data["customerHasVault"] = (storedVariables.customerHasVault() && storedVariables.customerHasVault() == 'true') ? true : false;
    data['paymentType'] = paymentType;

    if (paymentType == "creditcard") {
        // make ach values empty
        jQuery('#billingAccountType').prop('selectedIndex', -1);
        jQuery('#billingEntityType').prop('selectedIndex', -1);

        if (newPm) {
            var expiry = document.getElementById("billingdate").value;
            document.getElementById("billingccexp").value = expiry.substr(-2) + expiry.substr(2, 2);
        }
    }
    return await woo_nmi_stepOne(data, newPm);
}

function useTokenization() {
    if (storedVariables.useTokenization()) {
        if (storedVariables.receiptData().hasOwnProperty('isAcctScreen')) {
            collectJsConfiguration('#place_order');
        }
        else {
            collectJsConfiguration();
            var pay = document.getElementById('bng_payButton')
            if (pay) pay.disabled = false;
        }
    }
}

function submitOrderUsingOldPaymentMethod() {
    var transaction_type = document.getElementById("bng_transaction_type");
    transaction_type.value = storedVariables.receiptData()['transactiontype'];
    var payment_type = document.getElementById("payment_type");
    payment_type.value = storedVariables.receiptData()['paymenttype'];

    var oldPm  = false;
    var allPms = document.getElementsByClassName("active"); // should only be 1 value
    if (allPms.length) {
        if (allPms[0].id != '') {
            oldPm = true;
            var woo = document.getElementById("bng_woo_token");
            woo.value = parseInt(allPms[0].id.match(/\d+/g), 10 );
        }
    }

    if (oldPm) {
        var form = document.getElementById("bng_submitPayment");
        form.submit();
        setTimeout("CollectJS.closePaymentRequest()", 200);
        document.getElementById("bng_payButton").disabled = true;
        document.getElementById("bng_spinner").style.display = "block";
    }
}

function collectJsConfiguration(selector) {
    CollectJS.configure({
            'theme': 'bootstrap',
            'paymentSelector': (selector) ? selector : '#bng_payButton',
            'primaryColor': '#499ae0',
            'paymentType': (jQuery('input#payment').attr('value') == 'check') ? 'ck' : 'cc',
            'fields': {
            },
            "currency":"USD",
            "country": "US",
            'callback' : function(response) {
                var form = document.getElementById("bng_submitPayment");
                if (selector) {
                    form = document.getElementById('add_payment_method');
                }
                else {
                    document.getElementById("bng_payButton").disabled = true;
                    document.getElementById("bng_spinner").style.display = "block";
                }

                var input = document.getElementById("bng_payment_token");
                input.value = response.token;

                form.submit();
            }
    });
}