feedcleaner
===========

A simple PHP library that clean RSS feeds to Atom 1.0

# Load FeedCleaner

To load FeedCleaner in your project just include the library to your composer file

```
{
    "require": {
        "movim/feedcleaner": "dev-master"
    }
}
```

# Use FeedCleaner

``` php
use FeedCleaner\Parser;

$parser = new Parser;   // We instanciate the parser
$parser->setXML($xml);  // We set the XML of the current feed
$parser->parse();       // We parse and clean it
$parser->generate();    // And finally we display it !
```
