# PhpSpec Unit-Test Engine for Arcanist

## Usage

In order to use this unit-test engine, simply clone the repository and adapt your `.arcconfig` file to match its location. E.g. if you have both your project and the phpspec-utte directory checked out in the same parent directory, this will work:

```javascript
{
  "phabricator.uri" : "https://your.phabricator.instance/",
  "arc.land.onto.default": "development",
  "load": [
    "./../phpspec-utte/"
  ],
  "unit.engine": "PhpSpecUnitTestEngine",
  "unit.phpspec.binary": "./vendor/bin/phpspec"
}
```


## Feedback & Contributions

Think this repo deserves a packagist entry, have any feedback or want to contribute?

Get in touch with us through [our website](http://www.tentwentyfour.lu)
