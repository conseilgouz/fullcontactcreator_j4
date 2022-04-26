# fullcontactcreator_j4
Full Contact Creator creates contacts at user registration. It fills up additional profiles fields and users fields in the contacts.

Like Joomla Contact Creator standard plugin, Full Contact Creator creates contacts in Contact_Details table, automatically filling name, username, userid and email fields. It also extracts profile data: address, zipcode, city, country, phone, website and preferred book.

To work, you'll have to create a link between user fileds and contact fields. This is done through Note field: a user field is copied as contact field if they both contain the same value in their own note field.

Make it work...
Important: to work, you'll be required to

- disable standard Contact Creator plugin,

- check plugins order so that ProfileP Plugin will be before Full Contact Creator and Full Contact Creator Plugin will be after Profile plugin,

- enable Full Contact Creator plugin and ProfileP plugin, of course....
