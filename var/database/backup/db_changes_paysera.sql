REPLACE INTO `cscart_payment_processors` (`processor_id`, `processor`, `processor_script`, `processor_template`, `admin_template`, `callback`, `type`) VALUES
(1111, 'Paysera', 'paysera.php', 'views/orders/components/payments/paysera.tpl', 'paysera.tpl', 'N', 'P');

REPLACE INTO cscart_language_values (lang_code, name, value) VALUES ('EN', 'orderid', 'Orderid');
REPLACE INTO cscart_language_values (lang_code, name, value) VALUES ('LT', 'orderid', 'Užsakymo nr.');

REPLACE INTO cscart_language_values (lang_code, name, value) VALUES ('EN', 'lang ', 'Language');
REPLACE INTO cscart_language_values (lang_code, name, value) VALUES ('LT', 'lang ', 'Kalba');

REPLACE INTO cscart_language_values (lang_code, name, value) VALUES ('EN', 'p_firstname', 'Name');
REPLACE INTO cscart_language_values (lang_code, name, value) VALUES ('LT', 'p_firstname', 'Vardas');

REPLACE INTO cscart_language_values (lang_code, name, value) VALUES ('EN', 'p_lastname', 'Lastname');
REPLACE INTO cscart_language_values (lang_code, name, value) VALUES ('LT', 'p_lastname', 'Pavardė');

REPLACE INTO cscart_language_values (lang_code, name, value) VALUES ('EN', 'surename', 'Surname');
REPLACE INTO cscart_language_values (lang_code, name, value) VALUES ('LT', 'surename', 'Pavardė');

REPLACE INTO cscart_language_values (lang_code, name, value) VALUES ('EN', 'p_email', 'Email');
REPLACE INTO cscart_language_values (lang_code, name, value) VALUES ('LT', 'p_email', 'EL. paštas');

REPLACE INTO cscart_language_values (lang_code, name, value) VALUES ('EN', 'p_street', 'Street');
REPLACE INTO cscart_language_values (lang_code, name, value) VALUES ('LT', 'p_street', 'Gatvė');

REPLACE INTO cscart_language_values (lang_code, name, value) VALUES ('EN', 'p_city', 'City');
REPLACE INTO cscart_language_values (lang_code, name, value) VALUES ('LT', 'p_city', 'Miestas');

REPLACE INTO cscart_language_values (lang_code, name, value) VALUES ('EN', 'p_state', 'State code');
REPLACE INTO cscart_language_values (lang_code, name, value) VALUES ('LT', 'p_state', 'Rajonas');

REPLACE INTO cscart_language_values (lang_code, name, value) VALUES ('EN', 'p_zip', 'Zip');
REPLACE INTO cscart_language_values (lang_code, name, value) VALUES ('LT', 'p_zip', 'Pašto kodas');

REPLACE INTO cscart_language_values (lang_code, name, value) VALUES ('EN', 'p_countrycode', 'Country code');
REPLACE INTO cscart_language_values (lang_code, name, value) VALUES ('LT', 'p_countrycode', 'Šalies kodas');

REPLACE INTO cscart_language_values (lang_code, name, value) VALUES ('EN', 'projectid', 'Paysera project id');
REPLACE INTO cscart_language_values (lang_code, name, value) VALUES ('LT', 'projectid', 'Paysera projekto nr.');

REPLACE INTO cscart_language_values (lang_code, name, value) VALUES ('EN', 'paytext', 'Payment text');
REPLACE INTO cscart_language_values (lang_code, name, value) VALUES ('LT', 'paytext', 'Mokėjimo paskirtis');

REPLACE INTO cscart_language_values (lang_code, name, value) VALUES ('EN', '_client_language', 'Client language');
REPLACE INTO cscart_language_values (lang_code, name, value) VALUES ('LT', '_client_language', 'Kliento kalba');

REPLACE INTO cscart_language_values (lang_code, name, value) VALUES ('EN', 'requestid', 'Request id');
REPLACE INTO cscart_language_values (lang_code, name, value) VALUES ('LT', 'requestid', 'Užklausos nr');

REPLACE INTO cscart_language_values (lang_code, name, value) VALUES ('EN', 'payamount', 'Order amount');
REPLACE INTO cscart_language_values (lang_code, name, value) VALUES ('LT', 'payamount', 'Užsakymo suma');

REPLACE INTO cscart_language_values (lang_code, name, value) VALUES ('EN', 'paycurrency', 'Payment currency code');
REPLACE INTO cscart_language_values (lang_code, name, value) VALUES ('LT', 'paycurrency', 'Užsakymo valiutos kodas');
