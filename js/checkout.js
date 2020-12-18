jQuery( function( $ ) {
	'use strict';

    var nmi_error = {},
        card_allowed;

	/**
	 * Object to handle NMI payment forms.
	 */
	var wc_nmi_checkout = {

        /**
		 * Creates all NMI elements that will be used to enter cards or IBANs.
		 */
		createElements: function() {

            CollectJS.configure({
                //"paymentSelector" : "#place_order",
                "variant" : "inline",
                "styleSniffer" : "true",
                //"googleFont": "Montserrat:400",
                "fields": {
                    "ccnumber": {
                        "selector": "#woo-nmi-card-number-element",
                        "placeholder": "•••• •••• •••• ••••"
                    },
                    "ccexp": {
                        "selector": "#woo-nmi-card-expiry-element",
                        "placeholder": wc_nmi_checkout_params.placeholder_expiry
                    },
                    "cvv": {
                        "display": "show",
                        "selector": "#woo-nmi-card-cvc-element",
                        "placeholder": wc_nmi_checkout_params.placeholder_cvc
                    }
                },
                'validationCallback' : function(field, status, message) {
                    if (status) {
                        var message = field + " is OK: " + message;
                        nmi_error[field] = '';
                    } else {
                        nmi_error[field] = message;
                    }
                    console.log(message);
                },
                "timeoutDuration" : 20000,
                "timeoutCallback" : function () {
					$( document ).trigger( 'nmiError', wc_nmi_checkout_params.timeout_error );
					console.log( wc_nmi_checkout_params.timeout_error );
                },
                "fieldsAvailableCallback" : function () {
                    wc_nmi_checkout.unblock();
                    console.log("Collect.js loaded the fields onto the form");
                },
                'callback' : function(response) {
                    wc_nmi_checkout.onNMIResponse(response);
                }
            });

        },

		/**
		 * Initialize event handlers and UI state.
		 */
		init: function() {
			// checkout page
			if ( $( 'form.woocommerce-checkout' ).length ) {
				this.form = $( 'form.woocommerce-checkout' );
			}

			$( 'form.woocommerce-checkout' )
				.on(
					'checkout_place_order_nmi checkout_place_order_nmi_gateway',
					this.onSubmit
				);

			// pay order page
			if ( $( 'form#order_review' ).length ) {
				this.form = $( 'form#order_review' );
			}

			$( 'form#order_review' )
				.on(
					'submit',
					this.onSubmit
				);

			// add payment method page
			if ( $( 'form#add_payment_method' ).length ) {
				this.form = $( 'form#add_payment_method' );
			}

			$( 'form#add_payment_method' )
				.on(
					'submit',
					this.onSubmit
				);

			$( document )
				.on(
					'change',
					'#wc-nmi-cc-form :input',
					this.onCCFormChange
				)
				.on(
					'nmiError',
					this.onError
				)
				.on(
					'checkout_error',
					this.clearToken
				);

            if ( wc_nmi_checkout.isNMIChosen() ) {
                wc_nmi_checkout.block();
                wc_nmi_checkout.createElements();
            }

            /**
			 * Only in checkout page we need to delay the mounting of the
			 * card as some AJAX process needs to happen before we do.
			 */
			if ( 'yes' === wc_nmi_checkout_params.is_checkout ) {
				$( document.body ).on( 'updated_checkout', function() {
					// Re-mount  on updated checkou
                    if ( wc_nmi_checkout.isNMIChosen() ) {
                        wc_nmi_checkout.block();
				        wc_nmi_checkout.createElements();
                    }

				} );
			}

            $( document.body ).on( 'payment_method_selected', function() {
                // Don't re-mount if already mounted in DOM.
                if ( wc_nmi_checkout.isNMIChosen() ) {
                    wc_nmi_checkout.block();
                    wc_nmi_checkout.createElements();
                }
            } );

            if( this.form !== undefined ) {
                this.form.on( 'click change', 'input[name="wc-nmi-payment-token"]', function() {
                    if ( wc_nmi_checkout.isNMIChosen() && ! $( '#woo-nmi-card-number-element' ).children().length ) {
                        wc_nmi_checkout.block();
                        wc_nmi_checkout.createElements();
                    }
                } );
            }
		},

		isNMIChosen: function() {
			return $( '#payment_method_nmi_gateway' ).is( ':checked' ) && ( ! $( 'input[name="wc-nmi-payment-token"]:checked' ).length || 'new' === $( 'input[name="wc-nmi-payment-token"]:checked' ).val() );
		},

		hasToken: function() {
			return ( 0 < $( 'input.nmi_js_token' ).length ) && ( 0 < $( 'input.nmi_js_response' ).length );
		},

		block: function() {
			wc_nmi_checkout.form.block( {
				message: null,
				overlayCSS: {
					background: '#fff',
					opacity: 0.6
				}
			} );
		},

		unblock: function() {
			wc_nmi_checkout.form.unblock();
		},

        getSelectedPaymentElement: function() {
			return $( '.payment_methods input[name="payment_method"]:checked' );
		},

		onError: function( e, result ) {
			//console.log(responseObject.response);
			var message = result;
			var selectedMethodElement = wc_nmi_checkout.getSelectedPaymentElement().closest( 'li' );
			var savedTokens = selectedMethodElement.find( '.woocommerce-SavedPaymentMethods-tokenInput' );
			var errorContainer;

			if ( savedTokens.length ) {
				// In case there are saved cards too, display the message next to the correct one.
				var selectedToken = savedTokens.filter( ':checked' );

				if ( selectedToken.closest( '.woocommerce-SavedPaymentMethods-new' ).length ) {
					// Display the error next to the CC fields if a new card is being entered.
					errorContainer = $( '#wc-nmi-cc-form .nmi-source-errors' );
				} else {
					// Display the error next to the chosen saved card.
					errorContainer = selectedToken.closest( 'li' ).find( '.nmi-source-errors' );
				}
			} else {
				// When no saved cards are available, display the error next to CC fields.
				errorContainer = selectedMethodElement.find( '.nmi-source-errors' );
			}

			wc_nmi_checkout.onCCFormChange();
			$( '.woocommerce-NoticeGroup-checkout' ).remove();
			console.log( result ); // Leave for troubleshooting.
			$( errorContainer ).html( '<ul class="woocommerce_error woocommerce-error wc-nmi-error"><li /></ul>' );
			$( errorContainer ).find( 'li' ).text( message ); // Prevent XSS

			if ( $( '.wc-nmi-error' ).length ) {
				$( 'html, body' ).animate({
					scrollTop: ( $( '.wc-nmi-error' ).offset().top - 200 )
				}, 200 );
			}
			wc_nmi_checkout.unblock();
		},

		onSubmit: function( e ) {
			if ( wc_nmi_checkout.isNMIChosen() && ! wc_nmi_checkout.hasToken() ) {
				e.preventDefault();
				wc_nmi_checkout.block();
                var error_message;

                console.log(nmi_error);

                var validCardNumber = document.querySelector("#woo-nmi-card-number-element .CollectJSValid") !== null;
                var validCardExpiry = document.querySelector("#woo-nmi-card-expiry-element .CollectJSValid") !== null;
                var validCardCvv = document.querySelector("#woo-nmi-card-cvc-element .CollectJSValid") !== null;

                if( !validCardNumber ) {
                    error_message = wc_nmi_checkout_params.card_number_error + ( nmi_error.ccnumber ? ' ' + wc_nmi_checkout_params.error_ref.replace( '[ref]', nmi_error.ccnumber ) : '' );
                    $( document.body ).trigger( 'nmiError', error_message );
					return false;
                }

                if( !validCardExpiry ) {
                    error_message = wc_nmi_checkout_params.card_expiry_error + ( nmi_error.ccexp ? ' ' + wc_nmi_checkout_params.error_ref.replace( '[ref]', nmi_error.ccexp ) : '' );
                    $( document.body ).trigger( 'nmiError', error_message );
					return false;
                }

                if( !validCardCvv ) {
                    error_message = wc_nmi_checkout_params.card_cvc_error + ( nmi_error.cvv ? ' ' + wc_nmi_checkout_params.error_ref.replace( '[ref]', nmi_error.cvv ) : '' );
                    $( document.body ).trigger( 'nmiError', error_message );
					return false;
                }

                CollectJS.startPaymentRequest();

				// Prevent form submitting
				return false;
			}
		},

		onCCFormChange: function() {
			$( '.wc-nmi-error, .nmi_js_token, .nmi_js_response' ).remove();
		},

		onNMIResponse: function( response ) {
            console.log(response);

			wc_nmi_checkout.form.append( "<input type='hidden' class='nmi_js_token' name='nmi_js_token' value='" + response.token + "'/>" );
            wc_nmi_checkout.form.append( "<input type='hidden' class='nmi_js_response' name='nmi_js_response' value='" + JSON.stringify(response) + "'/>" );
            wc_nmi_checkout.form.submit();
		},

		clearToken: function() {
			$( '.nmi_js_token, .nmi_js_response' ).remove();
		}
	};

	wc_nmi_checkout.init();
} );
