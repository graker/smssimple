# SMSimple API integration

Drupal 7 module integrating [SMSimple](http://www.smsimple.ru/) message service API.

Code in `api` directory is provided by SMSSimple with little to no modifications made here.

## Usage

1. Install the module as usual.

2. Go to settings at `admin/config/services/smssimple`, provide your login and password to SMSSimple site, save the form.

3. If you have origins set, they will be loaded so you could select the default origin to use. 
Basic profile info (name, phone, balance) will be loaded from SMSimple service and displayed above the settings form.
 
4. Use the `smssimple_send_sms()` function to send SMS to given array of phones. Example:

```
smssimple_send_sms(array('89012345678', '89263211232'), 'Hello!', TRUE);
```

This will send the message to two phone numbers and will report any errors on the way.
