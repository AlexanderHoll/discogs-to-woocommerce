Plugin notes

TODO - Required
- [ ] setup correct auth flow in order to fetch images
- [ ] use format from product listing to remove format from product title - ie "Kahn (5)" should be "Kahn"
- [ ] decide how to handle stock (i.e always input as 1 in stock?)
- [ ] improve results page to use the wordpress admin panel - simply show the products imported and a back button?
- [ ] fetch all discogs results and show on one page

TODO - Optional
- [ ] signin with Discogs to be able to fetch images or just copy and paste username and store as variable
- [ ] complete working pagination - next page needs to use new / next url to fetch information
- [ ] decide how to divide up plugin php files
- [ ] insert product as draft / published? (could this be different bulk options?)
- [ ] store fetched results in database
- [ ] use some sort of template engine / figure out how to render the page better

DONE
- [X] add functionality for inserting / creating products
- [X] fetch listings from Discogs
- [X] resolve no items found issue
- [X] added WP_List_Table functionality
- [x] add functioning bulk actions with tick boxes (continue to extend WP_List_Table)

should auth have it's own php file?
how should I organise the main plugin php file into multiple php files?

// auth setup
https://www.discogs.com/developers/#page:authentication,header:authentication-discogs-auth-flow

// working GET url
https://api.discogs.com/database/search?q=Nirvana&token=hafTyOCejMyatLolUQCttyfMujjxbjUiSryGAGWR

// Deckheads api fetch
https://api.discogs.com/users/DeckHeadRecords/inventory

// api viewer website
https://reqbin.com/

// discogs api
https://www.discogs.com/settings/developers

// WP_List_Table guides
https://wpengineer.com/2426/wp_list_table-a-step-by-step-guide/#preliminary
https://www.youtube.com/watch?v=zOOe1tCszYM&list=PLT9miexWCpPWD3BOrqHmwaXL2LLVb6kpg

pagination currently relies on the Discogs API url
In reality, I need to fetch ALL entries and paginate myself?
Look into this as there might be a way of implementing using current setup

do I need to fetch from every URL that is returned and store it to ensure all data is filterable?
i.e
https://api.discogs.com/users/DeckHeadRecords/inventory?page=2&per_page=50
https://api.discogs.com/users/DeckHeadRecords/inventory?page=3&per_page=50
https://api.discogs.com/users/DeckHeadRecords/inventory?page=4&per_page=50
https://api.discogs.com/users/DeckHeadRecords/inventory?page=5&per_page=50
store all results in a db table?
this ensures if we filter by artist, Bicep on page 1 and Bicep on page 5 with both be fetched on page 1

does this mean I need to add a "fetch" button that will:
clear the database table
fetch new listings from discogs
populate the table
fetch and display listings from the database table