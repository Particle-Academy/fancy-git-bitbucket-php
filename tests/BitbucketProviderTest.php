<?php

declare(strict_types=1);

namespace FancyGit\Bitbucket\Tests;

use FancyGit\Bitbucket\BitbucketProvider;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;

final class BitbucketProviderTest extends TestCase
{
    public function test_it_identifies_cloud_and_rejects_data_center(): void
    {
        $provider = new BitbucketProvider(new Client);
        self::assertSame(['provider' => 'bitbucket', 'owner' => 'acme', 'name' => 'app'], $provider->identify(['name' => 'origin', 'fetchUrl' => 'git@bitbucket.org:acme/app.git']));
        self::assertNull($provider->identify(['name' => 'origin', 'fetchUrl' => 'https://stash.acme.test/scm/acme/app.git']));
    }
}
