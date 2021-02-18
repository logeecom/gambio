# Mollie module for Gambio

## Supported GX versions
This branch contains mollie module which is eligible for Gambio versions 3.5.x - 4.0.x. 

If you have Gambio from 4.1.x to 4.3.x versions, please checkout on `4.1-4.x` branch of this Github repository.
https://github.com/mollie/gambio/tree/4.1-4.x

If you have Gambio from 3.0.x to 3.4.x versions, please checkout on `3.0-3.4` branch of this Github repository.
https://github.com/mollie/gambio/tree/3.0-3.4
***

## Installation of the Mollie Gambio Payments module ##

[- Installation via FTP client](#install-using-ftp)

[- Configuration](#configure-the-module)

[- Troubleshooting](#troubleshooting)


## About Mollie Payments ##
With Mollie, you can accept payments and donations online and expand your customer base internationally with support for all major payment methods through a single integration. No need to spend weeks on paperwork or security compliance procedures. No more lost conversions because you don’t support a shopper’s favourite payment method or because they don’t feel safe. We made our products and API expansive, intuitive, and safe for merchants, customers and developers alike. 

Mollie requires no minimum costs, no fixed contracts, no hidden costs. At Mollie you only pay for successful transactions. More about this pricing model can be found [here](https://www.mollie.com/en/pricing/). You can create an account [here](https://www.mollie.com/dashboard/signup). The Mollie Gambio plugin quickly integrates all major payment methods ready-made into your Gambio webshop.
   

## Supported Mollie Payment Methods ##
- iDEAL
- Creditcard
- CartaSi & Cartes Bancaires
- Bancontact
- Belfius Pay Button
- ING HomePay
- KBC/CBC-Betaalknop
- SOFORT Banking
- BankTransfer
- PayPal
- Paysafecard
- Przelewy24
- SEPA bank transfer
- Klarna
- Giftcards
- Apple Pay

## Configuration, FAQ and Troubleshooting  ##
If you experience problems with the extension installation, setup or whenever you need more information about how to setup the Mollie Payment extension in Gambio, please send an e-mail to [info@mollie.com](mailto:info@mollie.com) with an exact description of the problem.

# Install using FTP
```
This branch contains mollie module which is eligible for Gambio versions 3.5.x - 4.0.x.

If you have Gambio from 4.1.x to 4.3.x versions, please checkout on `4.1-4.x` branch of this Github repository.
If you have Gambio from 3.0.x to 3.4.x versions, please checkout on `3.0-3.4` branch of this Github repository.
```

To install the Mollie plugin for the **Gambio 3.5.x - 4.0.x** system, you will need to install some FTP client (Filezilla, Free FTP, Cyberduck, WinSCP...)

Step-by-step to install the Gambio module:
 1. Download the latest `1.x.x` version  of the module (the '.zip' file) via the [Releases page](https://github.com/mollie/gambio/releases) which is compatible with 3.5.x - 4.0.x.
 2. Copy the all content of the `gambio-1.x.x` directory from the extracted files to the root of your Gambio store on your webserver using your FTP client.
 3. Go to `Toolbox` » `Cache` on the Gambio admin page
 4. Clear the module, output, and text cache
 5. Go to `Modules` » `Modules-Center` on the Gambio admin page
 6. From the module list, select `Mollie` and click on the `Install` button
---

# Configure the module
To configure the Mollie Payment module you can go to your Gambio admin page, to `Modules` » `Modules-Center` and select Mollie

 1. Click on the `Edit` button
 2. Enter the API keys from Mollie. You can create API keys in your [Mollie Dashboard](https://www.mollie.com/dashboard/)
 3. Click on the `Verify token` button
 4. If token is correct, click on the `Save changes` button
 5. Under the `Payment methods` section you can find all Mollie available payment methods
 6. Please click on the `Configure` button of the payment method you would like to offer, and click on the `Install` button on the payment method page
 7. Choose a checkout name, description, logo and api method by clicking on `Edit` button
 
# Troubleshooting

Whenever you experiencing some difficulties or troubles with the installation and/or configuration of the Mollie Payment module for Gambio you can check the following points to make sure the configuration is right.

 1. **Perform a API Check**
 Use the [Verify token] button to check if the API Key is valid. You can find the [Verify token] button in the configuration Authorization section located in ‘System’ » ‘Manage Integrations’ » Mollie.
 2. **Check if you enabled the payment methods in the Gambio `Modules` » `Payment System` configuration**
 3. **Check if you enabled the payment methods in your [Mollie Dashboard](https://www.mollie.com/dashboard/)**
The payment methods are disabled by default in your account so you firstly need to activate the payment methods that you want to implement in your [Mollie Dashboard](https://www.mollie.com/dashboard/).
 4. **Check if the order amount min and/or max value is fulfilled**
 5. **Check if there is any information in the Notifications section `Modules` » `Modules-Center` » `Mollie` » `Notifications`**
 6. **Check if there is any information in the logfile `Toolbox` » `Show logs`**

# Release notes
*1.0.7*
- Optimization: Set the transparent background color for the mollie components.
- Optimization: By default, none of the issuers are selected. If the issuer is not selected on the payment checkout form submit, the customer will not be able to proceed with the checkout, and an error message will be displayed.
- Removed ING Home'Pay payment method from the plugin.

*1.0.6*
- Optimization: Restock product quantity, recalculate delivery status and reset article status when order is canceled during the checkout due to failed payment.

*1.0.5*
- Bugfix: Fix issues with mollie components when it is only payment method.
- Bugfix: Add assets files on the checkout for Honeygrid theme.

*1.0.4*
- Bugfix: Use first available language for status name fallback instead of English.

*1.0.3*
- New feature: Implemented integration with Mollie Components.
- New feature: Added iDeal, Giftcard, and KBC/CBC issuer selection in the checkout.
- Bugfix: Fixed links and icons URL within context path.

*1.0.2*
- Removed thousand separator when sending amount to Mollie API.

*1.0.1*
- Translations for NL, DE, and FR are added.

*1.0.0*
- The initial release of Mollie integration with Gambio.
