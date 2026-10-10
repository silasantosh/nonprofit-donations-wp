=== Nonprofit Donations ===
Contributors: silasantosh
Tags: donation, nonprofit, upi, ngo, india
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Donations go straight to your NGO bank account by UPI. No middleman, no cut. No WooCommerce.

== Description ==

A donation form block for Indian nonprofits. In UPI direct mode donors pay your bank account by UPI with no fees and no middleman. In Razorpay mode they pay through your own Razorpay account.

Honest note: UPI direct mode has no instant confirmation from the bank, because that needs a payment provider. A gift is confirmed when the bank alert mail matches it (only if you switch that on and set up a mailbox), or when you confirm it on the Verify UPI screen. 80G receipts are issued only after a gift is confirmed, and only if you hold a valid 80G registration and tick the 80G setting.

* Donate block: preset amounts, an "Other" amount, name, email, phone, state, city, pincode
* UPI direct: intent link, QR and app buttons, donation reference in the bank narration
* Donor status page with three steps: noted, bank being watched, confirmed
* Optional "I have paid" with UTR and screenshot, both optional. The screenshot is read inside the donor's own browser and is not uploaded for reading.
* Optional bank alert mail matching (IMAP mailbox, or a signed forward from your own mail rule). Off by default.
* Optional 80G receipt with PAN and address, mailed after a short hold so a wrong confirmation can be reversed
* Causes (campaigns) with story, goal and progress
* Donor list, donations list with filters, CSV export, reports, Form 113 helper CSV
* Optional Razorpay Checkout in your own Razorpay account, off by default
* Works on phones first. No WooCommerce. No trackers.

This plugin connects to Razorpay (https://razorpay.com) only when you choose Razorpay mode and add your keys. The Checkout script loads from checkout.razorpay.com on pages with the form. Razorpay terms: https://razorpay.com/terms/ and privacy: https://razorpay.com/privacy/

Bundled open-source parts in the ocr folder are listed in ocr/README.txt.

== Installation ==

1. Upload and activate.
2. Open Donations > Settings and enter your NGO UPI ID. That is all you need to start. (Card payments through your own Razorpay are optional, under "Need card payments?".)
3. Add the Donate form block to any page.

== Changelog ==

= 0.7.4 =
* Pincode field, state and city type-to-filter, no-store cache headers on donate pages.

= 0.7.0 =
* Signed forwarded bank-alert mail endpoint (off by default).

= 0.1.0 =
* First release.
