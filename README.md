# Woocommerce Till Payments Plugin

Contributors: Till Payments
Tags: Credit Card, e-commerce, payment, processing, checkout
Requires Wordpress: 4.9
Tested up to Wordpress: 6.2
Requires Woocommerce: 3.6.0
Tested up to Woocommerce: 7.5.1
Requires PHP: 7.2.5
Stable tag: 1.8.1

## Description

Till Payments is a multi-region Acquirer offering an omnichannel card present and card not present solution for merchants looking for a one stop payments solution.

The installation and activation of this plugin allows a merchant's store to integrate directly to the Till Payments Gateway.
Please refer to https://tillpayments.com/ for merchant sign-up and more.

## Plugin Functions

- Debit
- Preauthorise + Capture
- 3DSecure (3DS v2)
- Applepay
- Googlepay

## Installation

1. Upload the plugin files to the `/wp-content/plugins/tillpayments` directory, or install the plugin through the WordPress plugins screen directly.
   (**IMPORTANT**: If uploaded via Wordpress please ensure the plugin is placed into the path above / named accordingly)
1. Activate the plugin through the 'Plugins' screen in WordPress

## Plugin Activation

1. Go to `WooCommerce` > `Settings` > `Payments` in your store's backoffice admin area.
1. Click on `Set up` on the `Till Payments` payment method.
   1. Enter your API and payment method credentials (refer to config fields for futher info)
   1. Click on `Save changes`.
   1. Go back to `Payments` overview.
1. Enable configured `Till Payments` payment methods using the slide toggles.

#### Plugin Config

**Title** = what you want the payment option to be called on the storefront eg 'Credit Card'
**API Host** = endpoint where your transactions will be sent:

- **Sandbox / developement** = https://test-gateway.tillpayments.com/
- **Production** = https://gateway.tillpayments.com/
- **API Username**: Obtained from the Users section on the web Gateway portal. This is the API username, not the Web user you logged in with
- **API Password**: Password of the API user
- **API Key**: Obtained from your Gateway connector settings
- **Shared Secret**: The _Shared Secret_ obtained from your Gateway connector
- **Public Integration Key**: Obtained from your Gateway connector where it is labelled as _Public Integration Key (e.g. for payment.js)_

See [Till Payment's Support - Gateway Credentials](https://support.tillpayments.com/hc/en-us/articles/6694543251215-Till-Payments-Gateway-Credentials) for instructions on obtaining your production credentials

![](./config_screenshot.png)

## Common User Errors

### Payment input fields no loading

Ensure you have entered the correct **Integration Key** and are targeting the correct environment.

In rare cases another third party plugin can conflict with our own and introduce undesirable behaviour. Preventing the payment inputs from loading is just one example. This is usually the case when another plugin is raising exceptions and can often be diagnosed by inspecting the server logs as well as checking the browser's console for errors while the payment page is loading.

### All payments declined in testing

While targeting our sandbox environment there is a list of acceptable test cards you can use to simulate different outcomes. Using any other made up card, or a live card number, will result in a decline.

[Test Card Numbers](https://gateway.tillpayments.com/documentation/connectors#simulator-testing-connector-test-data)
