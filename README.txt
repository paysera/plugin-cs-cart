Installation

1. Download the repository files ant put them in your default cscart directory.
2. Insert this SQL statement in your cscart database:
REPLACE INTO cscart_payment_processors (processor_id, processor, processor_script,processor_template, admin_template, callback, type) 
	values ('1111', 'Paysera','paysera.php', 'paysera.tpl','paysera.tpl', 'N', 'P');
3. In administrator menu go to Administration -> Payment methods -> Add payment. Use these settings General tab -> Name = "Paysera - payment gateway", Processor -> "Paysera".
4. Press configure and fill all the necessary information and save.

Contacts

If any problems occur please feel free to seek help via support@paysera.com