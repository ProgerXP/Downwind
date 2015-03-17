# Downwind
**Flexible remote HTTP requests using native PHP streams**

Standalone unless you're doing `multipart/form-data` `upload()` - in this case
http://proger.i-forge.net/MiMeil class is necessary (see `encodeMultipartData()`).
Note that you'll need to not only `require()` it but also set up as described there.

Released in public domain.

If you are going to use SOCKS proxying you will need **Phisocks** class from
https://github.com/ProgerXP/Phisocks (it's standalone and also in public domain).

## Usage example

```PHP
$downwind = Downwind::make('http://google.com', array(
  'Cookie' => 'test=test',
));

$downwind->addQuery('q' => 'search-me');
$downwind->upload('filevar', 'original.txt', fopen('file.txt', 'r'));
$downwind->contextOptions['ignore_errors'] = 1;
$downwind->thruSocks('localhost', 1083);

echo $downwind->fetchData();
```
