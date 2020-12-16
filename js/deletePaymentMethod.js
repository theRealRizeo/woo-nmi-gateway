async function bng701_deletePM( event, deleteFromAcctScreen = false ) { 
    var tokenId = event.getAttribute('tokenId');
    var nonce = event.getAttribute('nonce');
        
    if (!deleteFromAcctScreen) document.getElementById('paymentMethod_li_' + tokenId).style.backgroundColor = "#CCCCCC";

    return await AjaxCall({
        action: 'bng701_deletePaymentMethod',
        security: nonce,
        tokenId: tokenId,
        isAcctScreen: deleteFromAcctScreen,
    }).then((response) => {
        jQuery('#bng_spinner').show();
        if (deleteFromAcctScreen) {
            return;
        }
        var responseid = response.replace(/^\s+|\s+$/gm,'');
        if (responseid > 3) responseid = responseid / 10;

        if (responseid == 1 || responseid == 3) {
            //implies it was a good delete, either of the billingid or the vault, hide/remove the payment method from the ui
            document.getElementById('paymentMethod_li_' + tokenId).style.display = "none";
                
            // find deleted payment method and remove from store
            var pms = storedVariables.paymentMethods();
            var index = pms.indexOf(pms.filter( (pm) => { return pm.internalId != tokenId; } )[0]);
            storedVariables.paymentMethods().splice(index, 1);

            if (storedVariables.paymentMethods().length) jQuery('.savedPms').css({'display': 'block'});
            else {
                jQuery('.savedPms').css({'display': 'none'});
                if (storedVariables.customerHasVault().length) {
                    storedVariables.update('CustomerHasVault', 'false');
                }
            }
        }
        jQuery('#bng_spinner').hide();
    },
    (error)=>{
        alert(JSON.parse(error.responseText).data);

        if (deleteFromAcctScreen) return false;
        
        document.getElementById('paymentMethod_li_' + tokenId).style.backgroundColor = "#FF3333";
        document.getElementById('paymentMethod_li_' + tokenId).style.color = "#FFFFFF";
    });
}