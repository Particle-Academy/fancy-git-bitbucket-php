<?php

declare(strict_types=1);

namespace FancyGit\Bitbucket\Tests;

use FancyGit\Bitbucket\BitbucketProvider;
use GuzzleHttp\Client;

test('it identifies cloud and rejects data center', function () {
    $provider = new BitbucketProvider(new Client);
    self::assertSame(['provider' => 'bitbucket', 'owner' => 'acme', 'name' => 'app'], $provider->identify(['name' => 'origin', 'fetchUrl' => 'git@bitbucket.org:acme/app.git']));
    self::assertNull($provider->identify(['name' => 'origin', 'fetchUrl' => 'https://stash.acme.test/scm/acme/app.git']));
});
