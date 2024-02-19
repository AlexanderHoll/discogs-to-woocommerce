# Discogs to Woocommerce
 A basic plugin that allows the import of a Discogs user's inventory, to WooCommerce as products

 The plugin currently does not use the Discogs auth flow, so importing products is limited to specific product information such as:
 Artist name
 Album name
 Description
 Discogs user comments

 The discogs API requires the use of the Auth flow in order to retrieve product images, this is a goal I am working towards

## Setup
1. Download the files and import as a zip file as you would a normal plugin with Wordpress
2. In the project file "discogs-woocommerce.php" update the variable $discogs_user your Discogs username and save your changes
3. Load up wordpress and enable the plugin

## Importing Products
1. Navigate to the Discogs to Woocommerce page in the Wordpress CMS
2. Load the plugin, tick the boxes of products you wish to import and import them!

Note: Currently, you are redirected to a php print out showing the results of your import, just click back in your browser and navigate to WooCommerce to continue editing your imported products as normal

## Roundup
I have a number of goals I want to achieve with this plugin, so I intend on continuing to update it. In it's current state, it is functional but not very user friendly - please only use if you are familiar with Wordpress plugin development!
