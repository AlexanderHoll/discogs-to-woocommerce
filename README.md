# discogs to woocommerce
 A basic plugin that allows the import of a Discogs user's inventory, to WooCommerce as products

 The plugin currently does not use the Discogs auth flow, so importing products is limited to specific product information such as:
 Artist name
 Album name
 Description
 Discogs user comments

 The discogs API requires the use of the Auth flow in order to retrieve product images, this is a goal I am working towards

Setup
 In order to setup the plugin, download the files an import as a zip file as you would a normal plugin with Wordpress
In the project file "discogs-woocommerce.php" update the variable $discogs_user your Discogs username - save changes
Load up wordpress and enable the plugin
Navigate to the Discogs to Woocommerce page in the Wordpress CMS
Load the plugin, tick the boxes of products you wish to import and import them!

I have a number of goals I want to achieve with this plugin, so I intent on continuing to update. In it's current state, it is functional but not very user friendly - please only use if you are familiar with Wordpress plugin development!
