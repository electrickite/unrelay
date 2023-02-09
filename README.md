# UnRelay

Application to index statuses in Mastodon by acting as an ActivityPub relay.
This is a PHP reimplementation of the core of Gervasio Marchand's FakeRelay.

## Requirements

  * PHP 8
  * cURL extension
  * OpenSSL extension

## Configure

The application will choose sensible defaults, but configuration can be
provided by creating a `config/relay.php` file in the project root, or by
passing configuration in environment variables.

The server private key will be automatically generated on first use. The key
will also be read from `config/key.pem` in the project root if it exists, or
from `config/relay.php`.

## Run locally

    $ php -S localhost:8080 -t public public/index.php

## Test

A dummy test peer server can be run using:

    $ php -S localhost:8081 test.php

And then endpoints can be tested against the locally running instance:

    $ php test.php localhost:8080 actor
    $ php test.php localhost:8080 follow

## License and Copyright

Copyright (C) 2023 Corey Hinshaw

Licensed under the terms of the MIT license. See the LICENSE file for details.
