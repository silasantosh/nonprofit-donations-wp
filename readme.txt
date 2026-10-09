=== Nonprofit Donations ===
Contributors: silasantosh
Tags: donation, nonprofit, razorpay, ngo, india
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Donations go straight to your NGO bank account by UPI. No middleman, no cut. No WooCommerce.

== Description ==

A donation form block for Indian nonprofits. In UPI direct mode donors pay your bank account by UPI with no fees and no middleman. In Razorpay mode they pay through your own Razorpay account.

Honest note: UPI direct mode has no live auto-confirmation, because that needs a payment provider. The donor enters the UPI reference (UTR). You confirm each gift against your bank statement on the Verify UPI screen. Receipts are issued only after you confirm.

* Donate block with preset amounts and an "other" amount
* UPI direct: intent link and QR, donation reference in the bank narration, verify screen
* Optional Razorpay Checkout (UPI, cards, netbanking), payment confirmed by signature check and webhook
* Optional PAN, only when the donor asks for an 80G receipt. Stored encrypted.
* Donor list, donations list with filters, CSV export
* Works on phones first

This plugin connects to Razorpay (https://razorpay.com) only when you choose Razorpay mode and add your keys. The Checkout script loads from checkout.razorpay.com on pages with the form. Razorpay terms: https://razorpay.com/terms/ and privacy: https://razorpay.com/privacy/

Milestone 1: one-time donations. Receipts and recurring gifts are planned.

== Installation ==

1. Upload and activate.
2. Open Donations > Settings and enter your NGO UPI ID. That is all you need to start. (Card payments through your own Razorpay are optional, under "Need card payments?".)
3. Add the Donate form block to any page.

== Changelog ==

= 0.1.0 =
* First release.
