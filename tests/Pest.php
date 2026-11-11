<?php

/*
 * Pest's Laravel bindings. Without this file Pest ran without an application
 * instance, which is why the only two tests in the project were the stock
 * examples.
 */

uses(Tests\TestCase::class)->in('Feature');
