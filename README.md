# SMSSimple API integration

Drupal 7 module integrating API of [SMSSimple](http://www.smsimple.ru/) message service.

Code in `api` directory is provided by SMSSimple with little modifications made here.

## Usage

1. Install the module as usual.

2. Go to settings at `admin/config/services/smssimple`, provide your login and password to SMSSimple site, save the form.

3. If you have origins set, now you can select the default one.
 
4. Use the `smssimple_send_sms()` function to send SMS to given array of phones. Example:

```
smssimple_send_sms(array(89012345678, 89263211232), 'Hello!', TRUE);
```

This will send the message to two phone numbers and will report any errors on the way.
