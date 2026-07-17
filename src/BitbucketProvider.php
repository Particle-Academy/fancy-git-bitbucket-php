<?php

declare(strict_types=1);

namespace FancyGit\Bitbucket;

use FancyGit\Provider\GitProvider;
use GuzzleHttp\ClientInterface;

final class BitbucketProvider implements GitProvider
{
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
        return ['items' => array_map($this->mapReview(...), $data['values']), 'nextCursor' => isset($data['next']) ? (string) (parse_url($data['next'], PHP_URL_QUERY) ?? '') : null, 'total' => $data['size'] ?? null];
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
    private function request(string $method, string $path, array $options = []): array
    {
        $token = is_callable($this->token) ? ($this->token)() : $this->token;
        $options['headers'] = ['Accept' => 'application/json', ...($token ? ['Authorization' => 'Bearer '.$token] : []), ...($options['headers'] ?? [])];
        $response = $this->client->request($method, rtrim($this->baseUrl, '/').$path, $options);
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
