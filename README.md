plugin-cs-cart 1.6
==============

Paysera plugin for CS-Cart 3.0.6

Version of specification: 1.6

Installation

1. Download the repository files, put webtopay.php and libwebtopay into default_cscart_directory/payments directory. Copy the skins folder in the default cscart directory.
2. Insert this SQL statement in your cscart database:
REPLACE INTO cscart_payment_processors (processor_id, processor, processor_script,processor_template, admin_template, callback, type) 
	values ('1111', 'WebToPay','webtopay.php', 'webtopay.tpl','webtopay.tpl', 'N', 'P');
3. In administrator menu go to Administration -> Payment methods -> Add payment. Use these settings General tab -> Name = "Webtopay - payment gateway", Processor -> "WebToPay".
4. Press configure and fill all the necessary information and save.
Contacts

If any problems occur please feel free to seek help via support@webtopay.com
