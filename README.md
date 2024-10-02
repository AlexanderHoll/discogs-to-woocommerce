# Discogs to Woocommerce
 A basic plugin that allows the import of a Discogs user's inventory, to WooCommerce as products

 The plugin currently does not use the Discogs auth flow, so importing products is limited to specific product information such as:
 Artist name
 Album name
 Description
 Discogs user comments

 The discogs API requires the use of the Auth flow in order to retrieve product images, this is a goal I am working towards

 For now, the plugin is reliant on:
 - Discogs API Key
 - Discogs API Secret
 - Discogs Username

## Setup
1. Download the files and import as a zip file as you would a normal plugin with Wordpress
2. Load up wordpress and enable the plugin
3. Go to the settings page and input your Discogs username and Discogs API information

## Importing Products
1. Navigate to the Discogs to Woocommerce page in the Wordpress CMS
2. Load the plugin, tick the boxes of products you wish to import and import them, either as published or as drafts
