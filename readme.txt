=== PayDo Official ===
Tags: credit cards, payment methods, paydo, payment gateway
Version: 2.1.0
Stable tag: 2.1.0
Requires at least: 6.3
Tested up to: 6.8.2
Requires PHP: 7.4
WC requires at least: 8.3
WC tested up to: 10.0.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add the ability to accept payments in WooCommerce via Paydo.com.

== Description ==

PayDo: Online payment processing service ➦ Accept payments online by 150+ methods from 170+ countries.
Payments gateway for Growing Your Business in New Locations and fast online payments.

What this module does for you:

* Free and quick setup
* Access 150+ local payment solutions with 1 easy integration.
* Highest security standards and anti-fraud technology

== Installation ==

Note: WooCommerce 8.3+ must be installed for this plugin to work.

1. Log in to your WordPress dashboard, navigate to the Plugins menu and click "Add New" button
2. Click "Upload Plugin" button and choose release archive
3. Click "Install Now".
4. After plugin installed, activate the plugin in your WordPress admin area.
5. Open the settings page for WooCommerce and click the "Payments" tab
6. Click on the sub-item for PayDo.
7. Configure and save your settings accordingly.

You can issue  **Public key**, **Secret key** after register as merchant on PayDo.com.

Use below parameters to configure your PayDo project:
* **Callback/IPN URL**: https://{replace-with-your-domain}/?wc-api=wc_paydo&paydo=result

== Support ==

* [PayDo Documentation](https://paydo.com/en/documentation/common/)
* [Contact PayDo support](https://paydo.com/en/contact-us/)

**TIP**: When contacting support it will help us if you provide:

* WordPress and WooCommerce Version
* Other plugins you have installed
  * Some plugins do not play nice
* Configuration settings for the plugin (Most merchants take screenshots)
* Any log files that will help
  * Web server error logs
* Screenshots of error message if applicable.


== Changelog ==

= 1.0.0 = (March 2, 2020)
* Initialization

= 2.0.0 = (March 13, 2025)
* Added: WordPress 6.7.x Compatibility
* Added: WooCommerce 9.7.x Compatibility
* Added: Support for High-Performance Order Storage (HPOS)
* Added: Support for WooCommerce Checkout Blocks (Gutenberg)
* Added: Failed Order page
* Improved: General plugin performance and stability
* Fixed: Error stating "No payment methods available"
* Fixed: Bug related to reordering

= 2.1.0 = (August 4, 2025)
* Added: WordPress 6.8.x Compatibility
* Added: WooCommerce 10.x.x Compatibility
* Fixed: Improved behavior when navigating back from the payment page — users are now correctly redirected to the WooCommerce checkout instead of encountering a broken or expired order page.
