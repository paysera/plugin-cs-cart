{* $Id: paysera.tpl 6560 2008-12-15 11:41:36Z zeke $ *}

{assign var="return_url" value="`$config.http_location`/payments/paysera.php"}
<p style="display: none;">{$lang.text_paysera_notice|replace:"[return_url]":$return_url}</p>
<hr />

<div class="form-field">
	<label for="account_id">Paysera Project ID:</label>
	<input type="text" name="payment_data[processor_params][project_id]" id="project_id" value="{$processor_params.project_id}" class="input-text"  size="60" />
</div>

<div class="form-field">
	<label for="account_id">Signature password:</label>
	<input type="text" name="payment_data[processor_params][sign]" id="sign" value="{$processor_params.sign}" class="input-text"  size="60" />
</div>

<div class="form-field">
	<label for="test">Test mode:</label>
	<select name="payment_data[processor_params][test]" id="test">
		<option value="1" {if $processor_params.test == "1"}selected="selected"{/if}>Yes</option>
		<option value="0" {if $processor_params.test == "0"}selected="selected"{/if}>No</option>
	</select>
</div>

<div class="form-field">
	<label for="currency">{$lang.currency}:</label>
	<select name="payment_data[processor_params][currency]" id="currency">
		<option value="ANY" {if $processor_params.currency == "LTL"}selected="selected"{/if}>Shop currency</option>
		<option value="LTL" {if $processor_params.currency == "LTL"}selected="selected"{/if}>{$lang.currency_code_ltl}</option>
		<option value="GBP" {if $processor_params.currency == "GBP"}selected="selected"{/if}>{$lang.currency_code_gbp}</option>
		<option value="EUR" {if $processor_params.currency == "EUR"}selected="selected"{/if}>{$lang.currency_code_eur}</option>
		<option value="USD" {if $processor_params.currency == "USD"}selected="selected"{/if}>{$lang.currency_code_usd}</option>
	</select>
</div>

<div class="form-field" style="display: none;">
	<label for="type">{$lang.type}:</label>
 	<select name="payment_data[processor_params][authmode]" id="type">
		<option value="A" {if $processor_params.authmode == "A"}selected="selected"{/if}>{$lang.fullauth}</option>
		<option value="E" {if $processor_params.authmode == "E"}selected="selected"{/if}>{$lang.preauth}</option>
	</select>
</div>