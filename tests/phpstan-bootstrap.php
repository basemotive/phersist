<?php

/**
 * Bootstrap for PHPStan (see phpstan.neon): generates the shared models of
 * the runtime tests and loads their classes, so the analysis knows the
 * classes the tests use, like Tests\Models\Basic\User. They're written to
 * tests/.generated/sqlite/models/, as a test run on SQLite does.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */

use Tests\Support\Models;

require __DIR__.'/../vendor/autoload.php';

putenv('PHERSIST_TEST_TARGET=sqlite');
foreach (glob(Models::dir().'/*.xml') ?: [] as $xmlFile)
	Models::generate(basename($xmlFile, '.xml'));
