=== NMI Gateway For WooCommerce ===
Contributors: BizZToolz, freemius
Tags: gateway, woocommerce, nmi, network merchants inc, merchant account, payment gateway, credit card, ach, echecks, subscriptions, wp_cron
Requires at least: 4.6.3
Tested up to: 5.5.3
Stable tag: 1.5.15
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Donate link: 

Securely accept credit cards and echecks directly on your WooCommerce store using NMI Gateway's three step redirect API.

== Description ==

**NMI Gateway Three Step Redirect API and CollectJS with support for secure payment method storage with customer vault.**
 
Securely Accept Credit Cards and EChecks in WooCommerce with the NMI Gateway
 
The NMI Gateway For WooCommerce extension uses NMI's most advanced and secure integration method, their patented three step redirect API. By using this plugin you will be able to accept all major payment methods(credit/debit cards, echecks). Your customers will be able to save their credit cards and echecks to their WooCommerce account for easy checkout on future orders. We are able to do this by leveraging the NMI Gateway's Customer Vault to securely save their credit card and echecks, and pass back a token for storage in WooCommerce for future purchases. This is both extremely convenient for you clients, and is secure and PCI compliant for you.
 
![Alt](/screenshot-8.png "Enter card information when using three step 'no tokenization key'")

The NMI Gateway For WooCommerce extension allows you to keep your customer on your site during the entire checkout process. This gives you complete control of your checkout process and shows your customer a consistent brand, building trust as well as boosting conversion. It is required that you have an SSL Certificate setup on your website to ensure you are meeting PCI compliance guidelines.
 
Why NMI Gateway For WooCommerce is a good fit for you store:

- Your customer never leaves your site
- Customers can securely save and manage payment methods for future orders
- Store cards on NMI's PCI compliant Customer Vault 
- Complete control of your checkout process
- Accept Visa, MasterCard, Discover, American Express
- Accepts electronic checks (ACH)
- Process subscriptions, refunds and  voids directly in WooCommerce
- Capture authorized transactions from the order screen in WooCommerce
- Detailed customer notice displayed when a transaction fails or declines
- Detailed and easy to understand transaction failed or decline responses for shop owner
- Easy setup, just put in a gateway username and password and you're up and running
- Fast, Simple, Secure WooCommerce Checkout
 
With keeping your customer on your site during the entire checkout process and using NMI Gateway's most secure integration method you are getting the best user experience, conversion rate and security. 
 
**Save Credit Cards or EChecks with NMI Gateway For WooCommerce PRO**
 
Your customers will be able to easily save and manage their payments when logged in. By having their preferred payment methods saved, customers are more likely to make return purchases since the checkout process is streamlined and fast.
Please note: your NMI account must have the Customer Vault enabled in order for your customers to save their payment methods.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/woo-nmi-three-step` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Configure the plugin by going to WooCommerce > Settings > Payment > NMI Gateway For WooCommerce

== Screenshots ==

1. Create CollectJS tokenization key
2. Save CollectJS tokenization key
3. NMI Gateway settings page 
4. NMI Gateway settings page 
5. Display saved payment methods using collectJS
6. Enter card information when using CollectJS
7. Enter ACH information when using CollectJS
8. Enter card information when using three step 'no tokenization key'
9. Enter ACH information when using three step 'no tokenization key'
10. Display saved payment methods with no collectJS tokenization key

== New Features ==

* Subscriptions
* Capture orders on edit order screen
* Add payment methods from account page
* Update or change payment methods on subscriptions

== Requirements ==

* WooCommerce
* WooCommerce Subscriptions
* Wp_Cron Extension for subscriptions

== How To Document ==
You can download the 'How To(s)' document [here](https://www.bizztoolz.com/wp-content/uploads/2020/11/NMI_Gateway_HowTos.pdf)
*The 'How To' document is still a work in progress. We are doing everything in our power to make the plusin easy for our customers.
If you have a topic that you need added to the document, please submit a support request on our website.*

== Upgrade Notice ==

= 1.6.11 =
Clash Fixes

= 1.6.10 =
Feature Improvement

= 1.6.9 =
Bug fixes

= 1.6.2 =
Version compatibility

= 1.6.1 =
Bug fixes

= 1.6.0 =
Adding new features
Improving security and efficiency

= 1.5.15 =
Version compatibility check
Security improvement

= 1.5.14 =
Security update

= 1.5.13 =
Version updates
IP Address Update

= 1.5.12 =
Updated list of order statuses

= 1.5.11 =
Minor bug fixes
Version updates

= 1.5.10 = 
Added the woocommerce_thankyou action to the checkout process
Bug fix: Product names can now contain an ampersand (&)

= 1.5.9 =
Update to the default confirmation page after a transaction

= 1.5.8 =
Update to default confirmation page after a transaction

= 1.5.7 =
Bug fix for item names with double quotes, added an option to set the order status, confirmation page upon completion

= 1.5.6 =
Customer vault and billing id update, bug fix for line item totals in gateway

= 1.5.5 =
Update for bug introduced by WooCommerce update 3.2.1, other updates

= 1.5.4 =
Updates to analytics system, changed saved payment methods to premium option, updated product labeling 

= 1.5.3 =
Hotfix, 'Back to Cart' button on checkout page

= 1.5.2 =
Bug fix, added plugin analytics

= 1.5.1 =
Bug fix, added a toggle for Saving Payment Methods

== Changelog ==

= 1.6.10 =
* Conflict resolution when other NMI integrated plugins are installed

= 1.6.10 =
* Capture feature improvement

= 1.6.9 =
* Bug fixes
* Work with other themes

= 1.6.2 =
* Version compatibility with woocommerce and wordpress

= 1.6.1 =
* Bug fixes
* Allowing users without subscriptions to use plugin

= 1.6.0 =
* Integration with Collect.js
* Compatibility with ACH Payments
* Ability to capture, authorized orders
* Modification from payment screen
* Subscriptions
* Early subscription renewal
* Subscription cancellation
* Resubscription and reactivation compatibility
* Updating all subscription payments

= 1.5.15 =
* Security improvement
* Hotfix for WP_Errors
* Works with WooCommerce v4.6.1, php 7.4.1 & WordPress v5.5.1
* Updating freemius

= 1.5.14 =
* Updated the way Freemius is incorporated into the plugin

= 1.5.13 =
* Tested with the most recent version of WordPress (5.2.1) and WooCommerce (3.6.3)
* Changed the way we pull the IP address that is sent to the transaction

= 1.5.12 =
* Removed 'Ready to Ship' order status as it was not supported by vanilla WooCommerce
* Added other possible order statuses (On-Hold, Pending)

= 1.5.11 =
* Tested with the most recent version of WordPress (5.0.3) and WooCommerce (3.5.4)
* Updated SDK for Freemius

= 1.5.10 = 
* If the woocommerce_thankyou action exists, it is run after the sale has been completed but before the redirect to the confirmation page
* Bug fix for product names that contain an ampersand (&).  Previously if a product name contained an ampersand, it was not able to submit the transaction to the gateway and would return to the payment select page.  This has now been fixed so you can use all of the ampersands you want

= 1.5.9 =
* By default, the user will be redirected to the built in order confirmation page (/checkout/order-received/ORDERID/?key=ORDERKEY)

= 1.5.8 =
* By default, the user is now redirected to an order summary page if they are a user

= 1.5.7 =
* Bug fix for a bug reported by abirchler that item names with double quotes throw a js error on checkout
* Also fixed a formatting error for item names with double quotes as displayed in the payment gateway transaction details
* There is now a setting to define the order status after payment has been made to the gateway.  You can now set the status to 'Pending' or 'Completed'
* There is also a setting that will allow you to define the page that is displayed after the transaction has been completed. 

= 1.5.6 =
* Updated the use of customer vault & billing id's
* Fixed line item amount viewable in gateway transaction detail

= 1.5.5 =
* Updated the way payment methods are selected due to a change in WooCommerce v3.2.1
* Changed the way the expiration date of a payment method is added

= 1.5.4 =
* Updated the way Freemius is incorporated into the plugin
* Changed the Saved Payment option to be only available in the premium version
* Fixed some labeling that was inconsistent, removed extra text that wasn't needed

= 1.5.3 =
* Bug fix: the 'Back to Cart' button on the checkout page has been fixed

= 1.5.2 =
* Bug fix: ajax method renamed to prevent overwriting from other plugins
* Freemius analytics and insight system added.

= 1.5.1 =
* Bug Fix: updated definition of process_refund function so it would not throw a warning after activation
* Added a toggle to turn Saved Payment Methods on and off
* Updated plugin details

= 1.5.0 =
* First version released with the three-step payment gateway fully working.
* Allows users to:
 * pay without saving a payment method
 * pay and save a payment method
 * pay using a saved payment method
 * delete a saved payment method