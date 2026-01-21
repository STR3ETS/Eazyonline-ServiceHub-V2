<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class SeRankingClient
{
    protected string $projectBaseUrl;
    protected string $projectApiKey;

    protected string $siteAuditBaseUrl;
    protected string $siteAuditApiKey;

    public function __construct()
    {
        $this->projectBaseUrl = rtrim((string) config('seranking.project_base_url', 'https://api4.seranking.com'), '/');
        $this->projectApiKey  = (string) config('seranking.project_api_key', '');

        $this->siteAuditBaseUrl = rtrim((string) config('seranking.site_audit_base_url', 'https://api.seranking.com'), '/');

        $this->siteAuditApiKey  = (string) (
            config('seranking.data_api_key')
            ?: config('seranking.project_api_key')
            ?: env('SERANKING_API_KEY')
            ?: ''
        );
    }

    protected function projectHttp(): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => 'Token ' . $this->projectApiKey,
            'Accept'        => 'application/json',
        ])->baseUrl($this->projectBaseUrl);
    }

    protected function siteAuditHttp(): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => 'Token ' . $this->siteAuditApiKey,
            'Accept'        => 'application/json',
        ])->baseUrl($this->siteAuditBaseUrl);
    }

    /**
     * RAW JSON body (handig voor endpoints die top-level arrays verwachten).
     */
    protected function requestProjectRawJson(string $method, string $path, $payload): array
    {
        $method = strtoupper($method);

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $res = $this->projectHttp()
            ->withHeaders(['Content-Type' => 'application/json; charset=utf-8'])
            ->withBody($json ?: '[]', 'application/json')
            ->send($method, $path);

        if ($res->failed()) {
            logger()->warning('SERanking Project API error', [
                'method' => $method,
                'url'    => $this->projectBaseUrl . $path,
                'status' => $res->status(),
                'body'   => $res->body(),
                'json'   => $res->json(),
                'params' => $payload,
            ]);
        }

        $res->throw();

        return (array) $res->json();
    }

    protected function requestProject(string $method, string $path, array $params = []): array
    {
        $method = strtolower($method);
        $client = $this->projectHttp()->asJson();

        if ($method === 'get') {
            $res = $client->get($path, $params);
        } else {
            $res = $client->{$method}($path, $params);
        }

        if ($res->failed()) {
            logger()->warning('SERanking Project API error', [
                'method' => strtoupper($method),
                'url'    => $this->projectBaseUrl . $path,
                'status' => $res->status(),
                'body'   => $res->body(),
                'json'   => $res->json(),
                'params' => $params,
            ]);
        }

        $res->throw();

        return (array) $res->json();
    }

    protected function requestSiteAudit(string $method, string $path, array $params = []): array
    {
        $method = strtolower($method);
        $client = $this->siteAuditHttp()->asJson();

        if ($method === 'get') {
            $res = $client->get($path, $params);
        } else {
            $res = $client->{$method}($path, $params);
        }

        if ($res->failed()) {
            logger()->warning('SERanking SiteAudit API error', [
                'method' => strtoupper($method),
                'url'    => $this->siteAuditBaseUrl . $path,
                'status' => $res->status(),
                'body'   => $res->body(),
                'json'   => $res->json(),
                'params' => $params,
            ]);
        }

        $res->throw();

        return (array) $res->json();
    }

    // -----------------------------
    // Project API (api4)
    // -----------------------------

    public function getProjects(): array
    {
        return $this->requestProject('get', '/sites');
    }

    /**
     * NIEUW: project/site aanmaken in SE Ranking.
     * Belangrijk: payload is een OBJECT, geen array.
     * POST /sites met { "url": "...", "title": "..." }
     */
    public function createProjectSite(string $domainOrUrl, string $title, array $options = []): array
    {
        $payload = array_merge([
            'url'   => $this->normalizeProjectUrl($domainOrUrl),
            'title' => $this->normalizeTitle($title, $domainOrUrl),
        ], $options);

        return $this->requestProject('post', '/sites', $payload);
    }

    protected function normalizeProjectUrl(string $domainOrUrl): string
    {
        $value = trim($domainOrUrl);
        $value = rtrim($value, '/');

        // als iemand al https:// of http:// invult, laat staan
        if (preg_match('#^https?://#i', $value)) {
            return $value;
        }

        // anders ga uit van domain en zet https:// ervoor
        $value = preg_replace('#^www\.#i', 'www.', $value); // no-op maar houdt intent duidelijk
        return 'https://' . $value;
    }

    protected function normalizeTitle(string $title, string $fallbackDomainOrUrl): string
    {
        $t = trim((string) $title);
        if ($t !== '') {
            return $t;
        }

        $f = trim($fallbackDomainOrUrl);
        $f = preg_replace('#^https?://#i', '', $f);
        $f = rtrim($f, '/');
        return $f !== '' ? $f : 'Project';
    }

    public function getProjectSearchEngines(int $siteId): array
    {
        return $this->requestProject('get', "/sites/{$siteId}/search-engines");
    }

    public function getProjectKeywords(int $siteId, int $siteEngineId): array
    {
        return $this->requestProject('get', "/sites/{$siteId}/keywords", [
            'site_engine_id' => $siteEngineId,
        ]);
    }

    public function getProjectStat(int $siteId): array
    {
        return $this->requestProject('get', "/sites/{$siteId}/stat");
    }

    /**
     * Compatibel met jouw controller:
     * - getPositions($siteId, $dateFrom, $dateTo, $siteEngineId, $withVolume, $withSerp)
     * - OF getPositions($siteId, ['date_from'=>..., ...])
     */
    public function getPositions(
        int $siteId,
        $dateFromOrParams,
        ?string $dateTo = null,
        ?int $siteEngineId = null,
        bool $withVolume = true,
        bool $withSerpFeatures = false
    ): array {
        if (is_array($dateFromOrParams)) {
            return $this->requestProject('get', "/sites/{$siteId}/positions", $dateFromOrParams);
        }

        $dateFrom = (string) $dateFromOrParams;

        $params = [
            'date_from' => $dateFrom,
            'date_to'   => (string) $dateTo,
        ];

        if ($siteEngineId) {
            $params['site_engine_id'] = (int) $siteEngineId;
        }

        $params['with_search_volume'] = $withVolume ? 1 : 0;
        $params['with_serp_features'] = $withSerpFeatures ? 1 : 0;

        return $this->requestProject('get', "/sites/{$siteId}/positions", $params);
    }

    /**
     * Recheck
     */
    public function recheck(int $siteId, array $payloadOrKeywords): array
    {
        $payload = isset($payloadOrKeywords['keywords'])
            ? $payloadOrKeywords
            : ['keywords' => $payloadOrKeywords];

        try {
            return $this->requestProject('post', "/api/sites/{$siteId}/recheck/", $payload);
        } catch (RequestException $e) {
            $status = $e->response?->status();
            $body   = (string) ($e->response?->body() ?? '');

            if ($status === 400 && str_contains($body, 'Bad Request')) {
                $keywords = $payload['keywords'] ?? [];
                $firstEngineId = (int) (($keywords[0]['site_engine_id'] ?? 0));

                $keywordIds = [];
                foreach ($keywords as $k) {
                    $kid = (int) ($k['keyword_id'] ?? 0);
                    if ($kid > 0) $keywordIds[] = $kid;
                }

                $fallbackPayload = [
                    'site_engine_id' => $firstEngineId,
                    'keywords' => $keywordIds,
                ];

                logger()->warning('SERanking recheck: retrying fallback payload', [
                    'site_id' => $siteId,
                    'site_engine_id' => $firstEngineId,
                    'keywords_count' => count($keywordIds),
                ]);

                return $this->requestProjectRawJson('POST', "/api/sites/{$siteId}/recheck/", $fallbackPayload);
            }

            throw $e;
        }
    }

    /**
     * Keywords toevoegen (top-level array)
     */
    public function addProjectKeywords(int $siteId, array $items): array
    {
        $payload = array_is_list($items) ? $items : [$items];
        return $this->requestProjectRawJson('POST', "/sites/{$siteId}/keywords", $payload);
    }

    // -----------------------------
    // Site Audit API
    // -----------------------------

    public function createStandardAudit(string $domain, array $settings = [], ?string $title = null): array
    {
        $payload = ['domain' => $domain];

        if ($title !== null) {
            $payload['title'] = $title;
        }

        if (!empty($settings)) {
            $payload['settings'] = $settings;
        }

        return $this->requestSiteAudit('post', '/v1/site-audit/audits/standard', $payload);
    }

    public function startWebsiteAudit(string $domain, array $options = []): array
    {
        $title    = $options['title'] ?? $domain;
        $settings = $options['settings'] ?? [];
        return $this->createStandardAudit($domain, $settings, $title);
    }

    public function getAuditStatus(int $auditId): array
    {
        return $this->requestSiteAudit('get', '/v1/site-audit/audits/status', [
            'audit_id' => $auditId,
        ]);
    }

    public function getAuditReport(int $auditId): array
    {
        return $this->requestSiteAudit('get', '/v1/site-audit/audits/report', [
            'audit_id' => $auditId,
        ]);
    }

    public function getWebsiteAudit(string $remoteAuditId): array
    {
        return $this->getAuditReport((int) $remoteAuditId);
    }

    public function getAuditPages(int $auditId, int $limit = 100, int $offset = 0): array
    {
        return $this->requestSiteAudit('get', '/v1/site-audit/audits/pages', [
            'audit_id' => $auditId,
            'limit'    => $limit,
            'offset'   => $offset,
        ]);
    }

    public function getIssuePages(int $auditId, string $code, int $limit = 50, int $offset = 0): array
    {
        return $this->requestSiteAudit('get', '/v1/site-audit/audits/issue-pages', [
            'audit_id' => $auditId,
            'code'     => $code,
            'limit'    => $limit,
            'offset'   => $offset,
        ]);
    }

    public function listSystemSearchEngines(): array
    {
        // Project API: GET /system/search-engines
        return $this->requestProject('get', '/system/search-engines');
    }

    public function addProjectSearchEngine(int $siteId, int $searchEngineId, array $options = []): array
    {
        // Project API: POST /sites/{site_id}/search-engines
        // search_engine_id is verplicht. Region/lang zijn optioneel.
        $payload = array_merge([
            'search_engine_id' => $searchEngineId,
        ], $options);

        return $this->requestProject('post', "/sites/{$siteId}/search-engines", $payload);
    }

    /**
     * Zorgt dat het project minimaal 1 search engine heeft: Google Netherlands.
     * Geeft de project site_engine_id terug (die je nodig hebt voor /positions).
     */
    public function ensureGoogleNetherlandsEngine(int $siteId): ?int
    {
        // 1) Als er al engines zijn: pak de eerste site_engine_id
        $existing = $this->getProjectSearchEngines($siteId);
        if (is_array($existing) && count($existing) > 0) {
            $first = $existing[0] ?? [];
            $siteEngineId = (int) ($first['site_engine_id'] ?? 0);
            return $siteEngineId > 0 ? $siteEngineId : null;
        }

        // 2) Zoek "Google Netherlands" in /system/search-engines
        $engines = $this->listSystemSearchEngines();
        $engines = is_array($engines) ? $engines : [];

        $match = null;
        foreach ($engines as $e) {
            $name = strtolower((string) ($e['name'] ?? ''));
            if ($name === 'google netherlands') {
                $match = $e;
                break;
            }
        }

        // fallback: soms heet hij net iets anders (bv "Google Netherlands (nl)")
        if (!$match) {
            foreach ($engines as $e) {
                $name = strtolower((string) ($e['name'] ?? ''));
                if (str_contains($name, 'google') && str_contains($name, 'netherlands')) {
                    $match = $e;
                    break;
                }
            }
        }

        $searchEngineId = (int) ($match['id'] ?? 0);
        if ($searchEngineId <= 0) {
            return null;
        }

        // region_id komt vaak mee in de lijst (soms als regionid)
        $regionId = (int) ($match['region_id'] ?? ($match['regionid'] ?? 0));

        // 3) Voeg toe aan project
        $res = $this->addProjectSearchEngine($siteId, $searchEngineId, [
            'region_id' => $regionId,  // mag 0 zijn, maar NL item heeft meestal een region id
            'lang_code' => 'nl',       // optioneel, maar logisch voor NL
            'merge_map' => 0,
            'paid_results' => 0,
            'featured_snippet' => 0,
        ]);

        // docs: succesvolle response bevat site_engine_id :contentReference[oaicite:3]{index=3}
        $siteEngineId = (int) ($res['site_engine_id'] ?? 0);
        return $siteEngineId > 0 ? $siteEngineId : null;
    }

}
