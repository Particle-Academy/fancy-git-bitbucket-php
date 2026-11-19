<?php

declare(strict_types=1);

use FancyGit\Bitbucket\BitbucketProvider;
use FancyGit\Error\GitErrorCode;
use FancyGit\Error\GitException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

/*
| Found by the fancy-labs git-adapter test: failures were Guzzle exceptions with
| no fancy-git code, a transient 5xx on a GET was not retried, and the review
| cursor was the whole query string of `next` rather than its page number. The
| TypeScript twin is held to the same rules.
*/

const BB_REF = ['provider' => 'bitbucket', 'owner' => 'team', 'name' => 'app'];
const BB_REPO = ['uuid' => '{42}', 'links' => ['html' => ['href' => 'https://bitbucket.org/team/app']], 'mainbranch' => ['name' => 'main'], 'is_private' => true, 'description' => ''];

/** @return array{0: BitbucketProvider, 1: ArrayObject} */
function bbProvider(array $queue, string $baseUrl = 'https://api.bitbucket.org/2.0'): array
{
    $history = new ArrayObject;
    $stack = HandlerStack::create(new MockHandler($queue));
    $stack->push(Middleware::history($history));

    return [new BitbucketProvider(new Client(['handler' => $stack]), 't', $baseUrl), $history];
}

function bbJson(array $body, int $status = 200): Response
{
    return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
}

function bbCaught(callable $call): GitException
{
    try {
        $call();
    } catch (GitException $e) {
        return $e;
    }
    throw new RuntimeException('Expected a GitException.');
}

test('bitbucket failures carry the fancy-git code', function (int $status, GitErrorCode $code) {
    [$provider, $history] = bbProvider([bbJson(['type' => 'error', 'error' => ['message' => 'Nope here']], $status)]);

    $e = bbCaught(fn () => $provider->repository(BB_REF));

    expect($e->errorCode)->toBe($code)
        ->and($e->getCode())->toBe($status)
        ->and($e->getMessage())->toContain('Nope here')
        ->and(count($history))->toBe(1);
})->with([
    [400, GitErrorCode::InvalidArgument],
    [401, GitErrorCode::Auth],
    [403, GitErrorCode::Auth],
    [404, GitErrorCode::NotFound],
    [405, GitErrorCode::Unsupported],
    [409, GitErrorCode::Conflict],
    [422, GitErrorCode::InvalidArgument],
    [429, GitErrorCode::RateLimited],
]);

test('a network failure is a git exception with code unknown', function () {
    [$provider] = bbProvider([new ConnectException('cURL error 7', new Request('GET', 'https://api.bitbucket.org/2.0/repositories/team/app'))]);

    expect(bbCaught(fn () => $provider->repository(BB_REF))->errorCode)->toBe(GitErrorCode::Unknown);
});

test('a GET that answered 5xx is retried', function (int $status) {
    [$provider, $history] = bbProvider([bbJson(['type' => 'error', 'error' => ['message' => 'down']], $status), bbJson(BB_REPO)]);

    expect($provider->repository(BB_REF)['id'])->toBe('{42}')
        ->and(count($history))->toBe(2);
})->with([500, 502, 503, 504]);

test('a GET gives up after a bounded number of retries, with the last 5xx', function () {
    [$provider, $history] = bbProvider(array_map(static fn () => bbJson([], 503), range(1, 10)));

    $e = bbCaught(fn () => $provider->repository(BB_REF));

    expect(count($history))->toBe(1 + BitbucketProvider::GET_RETRIES)
        ->and($e->errorCode)->toBe(GitErrorCode::Unknown)
        ->and($e->getCode())->toBe(503);
});

test('a POST that answered 5xx is never replayed', function () {
    [$provider, $history] = bbProvider([bbJson([], 502), bbJson([], 201)]);

    $e = bbCaught(fn () => $provider->createReview(BB_REF, ['title' => 'T', 'sourceBranch' => 'f', 'targetBranch' => 'main']));

    expect(count($history))->toBe(1)
        ->and($e->getCode())->toBe(502);
});

test('the review cursor is the page number from next, not its whole query string', function () {
    $pr = ['id' => 1, 'title' => 'PR', 'state' => 'OPEN', 'links' => ['html' => ['href' => 'x']], 'source' => ['branch' => ['name' => 'f']], 'destination' => ['branch' => ['name' => 'main']]];
    [$provider, $history] = bbProvider([
        bbJson(['values' => [$pr], 'size' => 2, 'next' => 'https://api.bitbucket.org/2.0/repositories/team/app/pullrequests?state=OPEN&page=2&pagelen=1']),
        bbJson(['values' => [$pr + ['id' => 2]], 'size' => 2]),
    ]);

    $first = $provider->listReviews(BB_REF, ['limit' => 1]);
    $second = $provider->listReviews(BB_REF, ['limit' => 1, 'cursor' => $first['nextCursor']]);

    parse_str($history[1]['request']->getUri()->getQuery(), $query);
    expect($first['nextCursor'])->toBe('2')
        ->and($query['page'])->toBe('2')
        ->and($second['nextCursor'] ?? null)->toBeNull();
});

test('every trailing slash is stripped from the base url', function () {
    [$provider, $history] = bbProvider([bbJson(BB_REPO)], 'https://bb.example.test/2.0'.str_repeat('/', 100000));

    $provider->repository(BB_REF);

    expect((string) $history[0]['request']->getUri())->toBe('https://bb.example.test/2.0/repositories/team/app');
});
