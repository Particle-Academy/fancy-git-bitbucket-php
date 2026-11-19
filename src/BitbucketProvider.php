<?php

declare(strict_types=1);

namespace FancyGit\Bitbucket;

use FancyGit\Error\GitErrorCode;
use FancyGit\Error\GitException;
use FancyGit\Provider\GitProvider;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

final class BitbucketProvider implements GitProvider
{
    /** How many times a GET that answered 5xx is sent again. Never a POST. */
    public const GET_RETRIES = 2;

    /** The wait before retry n is n times this. */
    public const RETRY_DELAY_MS = 100;

    /** @param string|callable():string|null $token */
    public function __construct(private readonly ClientInterface $client, private readonly mixed $token = null, private readonly string $baseUrl = 'https://api.bitbucket.org/2.0') {}

    public function kind(): string { return 'bitbucket'; }

    public function identify(array $remote): ?array
    {
        if (! preg_match('#^(?:https?://|ssh://git@|git@)([^/:]+)[:/]([^/]+)/([^/]+?)(?:\.git)?$#', $remote['fetchUrl'], $match) || $match[1] !== 'bitbucket.org') {
            return null;
        }
        return ['provider' => 'bitbucket', 'owner' => $match[2], 'name' => $match[3]];
    }

    public function repository(array $ref): array
    {
        $data = $this->request('GET', '/repositories/'.$this->key($ref));
        return ['provider' => 'bitbucket', 'owner' => $ref['owner'], 'name' => $ref['name'], 'id' => $data['uuid'], 'webUrl' => $data['links']['html']['href'], 'defaultBranch' => $data['mainbranch']['name'] ?? 'main', 'private' => $data['is_private'], 'description' => $data['description'] ?: null];
    }

    public function listReviews(array $ref, array $query = []): array
    {
        $state = match ($query['state'] ?? null) { 'merged' => 'MERGED', 'closed' => 'DECLINED', default => 'OPEN' };
        $data = $this->request('GET', '/repositories/'.$this->key($ref).'/pullrequests', ['query' => ['state' => $state, 'page' => $query['cursor'] ?? 1, 'pagelen' => $query['limit'] ?? 30]]);
        return ['items' => array_map($this->mapReview(...), $data['values']), 'nextCursor' => self::nextPage($data['next'] ?? null), 'total' => $data['size'] ?? null];
    }

    /** The page number in Bitbucket's `next` URL, which is the cursor; null on the last page. */
    private static function nextPage(mixed $next): ?string
    {
        if (! is_string($next) || $next === '') {
            return null;
        }
        parse_str((string) parse_url($next, PHP_URL_QUERY), $query);

        return is_string($query['page'] ?? null) && $query['page'] !== '' ? $query['page'] : null;
    }

    public function getReview(array $ref, int $number): array
    {
        $data = $this->request('GET', '/repositories/'.$this->key($ref).'/pullrequests/'.$number);
        return $this->mapReview($data) + ['body' => $data['description'] ?: null, 'createdAt' => $data['created_on'], 'updatedAt' => $data['updated_on']];
    }

    public function createReview(array $ref, array $input): array
    {
        $data = $this->request('POST', '/repositories/'.$this->key($ref).'/pullrequests', ['json' => ['title' => $input['title'], 'description' => $input['body'] ?? null, 'source' => ['branch' => ['name' => $input['sourceBranch']]], 'destination' => ['branch' => ['name' => $input['targetBranch']]]]]);
        return $this->mapReview($data);
    }

    public function compare(array $ref, string $base, string $head): array
    {
        $data = $this->request('GET', '/repositories/'.$this->key($ref).'/commits/'.rawurlencode($head), ['query' => ['exclude' => $base]]);
        return ['aheadBy' => count($data['values']), 'behindBy' => 0, 'commits' => array_map(static fn (array $commit): array => ['id' => $commit['hash'], 'shortId' => substr($commit['hash'], 0, 7), 'parents' => array_column($commit['parents'], 'hash'), 'authorName' => $commit['author']['raw'] ?? 'unknown', 'authorEmail' => '', 'authoredAt' => $commit['date'], 'subject' => strtok($commit['message'], "\n")], $data['values'])];
    }

    public function checks(array $ref, string $revision): array
    {
        $data = $this->request('GET', '/repositories/'.$this->key($ref).'/commit/'.rawurlencode($revision).'/statuses');
        return array_map(static fn (array $status): array => ['id' => $status['key'], 'name' => $status['name'] ?: $status['key'], 'state' => match ($status['state']) { 'SUCCESSFUL' => 'passed', 'INPROGRESS' => 'running', 'STOPPED' => 'cancelled', default => 'failed' }, 'webUrl' => $status['url'] ?? null, 'startedAt' => $status['created_on'] ?? null, 'completedAt' => $status['updated_on'] ?? null], $data['values']);
    }

    private function key(array $ref): string { return rawurlencode($ref['owner']).'/'.rawurlencode($ref['name']); }
    private function mapReview(array $item): array
    {
        return ['id' => (string) $item['id'], 'number' => $item['id'], 'title' => $item['title'], 'state' => match ($item['state']) { 'MERGED' => 'merged', 'OPEN' => 'open', default => 'closed' }, 'webUrl' => $item['links']['html']['href'], 'sourceBranch' => $item['source']['branch']['name'], 'targetBranch' => $item['destination']['branch']['name'], 'author' => $item['author']['display_name'] ?? 'unknown'];
    }
    /**
     * One request. A GET that answers 5xx is retried, at most GET_RETRIES
     * times; a POST is never replayed, because Bitbucket may already hold what
     * it created. Every failure is a GitException carrying the fancy-git code.
     */
    private function request(string $method, string $path, array $options = []): array
    {
        $token = is_callable($this->token) ? ($this->token)() : $this->token;
        $options['headers'] = ['Accept' => 'application/json', ...($token ? ['Authorization' => 'Bearer '.$token] : []), ...($options['headers'] ?? [])];
        $options['http_errors'] = false;
        $what = $method.' '.$path;

        for ($attempt = 0; ; $attempt++) {
            try {
                $response = $this->client->request($method, rtrim($this->baseUrl, '/').$path, $options);
            } catch (GuzzleException $e) {
                // Message only: the exception's request carries the credential header.
                throw new GitException(GitErrorCode::Unknown, "Bitbucket request {$what} could not be completed: {$e->getMessage()}");
            }
            $status = $response->getStatusCode();
            if ($method !== 'GET' || $status < 500 || $status > 599 || $attempt >= self::GET_RETRIES) {
                break;
            }
            usleep(self::RETRY_DELAY_MS * 1000 * ($attempt + 1));
        }

        if ($status >= 400) {
            throw new GitException(self::errorCode($status), "Bitbucket answered {$what} with {$status}: ".self::errorMessage($response), $status);
        }

        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** The fancy-git contract code for a Bitbucket HTTP status, the same table every adapter uses. */
    private static function errorCode(int $status): GitErrorCode
    {
        return match ($status) {
            401, 403 => GitErrorCode::Auth,
            404 => GitErrorCode::NotFound,
            405 => GitErrorCode::Unsupported,
            409 => GitErrorCode::Conflict,
            400, 422 => GitErrorCode::InvalidArgument,
            429 => GitErrorCode::RateLimited,
            default => GitErrorCode::Unknown,
        };
    }

    /** Bitbucket's error body (`{"type":"error","error":{"message":...}}`), bounded and single-line. */
    private static function errorMessage(ResponseInterface $response): string
    {
        $message = $response->getReasonPhrase() ?: 'no reason given';
        $content = json_decode((string) $response->getBody(), true);
        if (is_array($content) && is_string($content['error']['message'] ?? null)) {
            $message = $content['error']['message'];
        } elseif (is_array($content) && is_string($content['message'] ?? null)) {
            $message = $content['message'];
        }
        $line = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message);

        return strlen($line) > 500 ? substr($line, 0, 500).'...' : $line;
    }
}
