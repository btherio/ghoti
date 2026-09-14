<?php
declare(strict_types=1);
/*
 * Apache log analyzer - parsing, reporting, live streaming and PDF export.
 *
 * Absorbed from the standalone Apache Log Analyzer that used to live in
 * lib/Apache-tool. Its own password login (Auth.php), HTTP shell (api.php,
 * index.php) and sudo-backed "clear log" action are gone: inside ghoti the
 * admin session is the authentication, mod/analytics/analytics.async.php is
 * the entry point, and log files are read-only. Nothing here truncates,
 * writes, or shells out.
 *
 * Kept as its own file because of declare(strict_types=1) above: the rest of
 * the CMS is not written against strict argument types, so the boundary stays
 * where it is. This file is required lazily by the analytics endpoints rather
 * than on every request.
 *
 * Configuration is environment-driven (see apache_log_config): APACHE_LOG_DIR
 * selects the directory, the APACHE_LOG_* integers bound how much is read.
 */

final class ApacheLogHttpException extends RuntimeException
{
    private $status;

    public function __construct(string $message, int $status = 400)
    {
        parent::__construct($message);
        $this->status = $status;
    }

    public function getStatus(): int
    {
        return $this->status;
    }
}

final class ApacheLogAnalysisState
{
    private $analyzer;
    private $entries = [];
    private $ignoredLines = 0;
    private $severityCounts = [];
    private $categoryCounts = [];
    private $clients = [];
    private $firstTimestamp = null;
    private $lastTimestamp = null;

    public function __construct(ApacheLogAnalyzer $analyzer)
    {
        $this->analyzer = $analyzer;
    }

    public function appendText(string $content, int $lineOffset = 0): int
    {
        $lines = preg_split('/\r\n|\n|\r/', $content);
        if ($lines === false) {
            return 0;
        }

        $processed = 0;
        $lastIndex = count($lines) - 1;
        foreach ($lines as $index => $line) {
            if ($index === $lastIndex && $line === '') {
                continue;
            }
            $processed++;
            $this->appendLine($line, $lineOffset + $processed);
        }

        return $processed;
    }

    public function appendLine(string $rawLine, int $lineNumber): void
    {
        if (trim($rawLine) === '') {
            return;
        }

        $entry = $this->analyzer->parseLine($rawLine);
        if ($entry !== null) {
            $entry['lineNumber'] = $lineNumber;
            $this->addEntry($entry);
            return;
        }

        if (count($this->entries) > 0 && $this->analyzer->isContinuationLine($rawLine)) {
            $lastIndex = count($this->entries) - 1;
            $this->entries[$lastIndex]['message'] .= ' ' . trim($rawLine);
            return;
        }

        $this->ignoredLines++;
    }

    public function toReport(array $meta = [], ?int $entryLimit = null): array
    {
        $totalEntries = count($this->entries);
        $entries = $this->entries;
        $limited = false;

        if ($entryLimit !== null && $entryLimit > 0 && $totalEntries > $entryLimit) {
            $entries = array_slice($entries, -$entryLimit);
            $limited = true;
        }

        return array_merge($meta, [
            'entries' => array_values($entries),
            'entryCount' => $totalEntries,
            'entriesLimited' => $limited,
            'entryLimit' => $entryLimit,
            'ignoredLines' => $this->ignoredLines,
            'severityCounts' => $this->severityCounts,
            'categoryCounts' => $this->categoryCounts,
            'uniqueClients' => count($this->clients),
            'firstTimestampEpoch' => $this->firstTimestamp,
            'lastTimestampEpoch' => $this->lastTimestamp,
            'recommendations' => $this->analyzer->buildRecommendations($this->entries, $this->categoryCounts),
            'generatedAt' => gmdate(DATE_ATOM),
        ]);
    }

    private function addEntry(array $entry): void
    {
        $entry['hints'] = $this->analyzer->diagnosticHintsForEntry($entry);
        $this->entries[] = $entry;

        $level = $entry['level'] ?: 'unknown';
        $category = $entry['category'] ?: 'Other';
        $this->severityCounts[$level] = ($this->severityCounts[$level] ?? 0) + 1;
        $this->categoryCounts[$category] = ($this->categoryCounts[$category] ?? 0) + 1;

        if (!empty($entry['client'])) {
            $this->clients[$entry['client']] = true;
        }

        if (isset($entry['dateEpoch']) && $entry['dateEpoch'] !== null) {
            $timestamp = $entry['dateEpoch'];
            if ($this->firstTimestamp === null || $timestamp < $this->firstTimestamp) {
                $this->firstTimestamp = $timestamp;
            }
            if ($this->lastTimestamp === null || $timestamp > $this->lastTimestamp) {
                $this->lastTimestamp = $timestamp;
            }
        }
    }
}

final class ApacheLogAnalyzer
{
    private const LEVEL_ORDER = ['emerg', 'alert', 'crit', 'error', 'warn', 'notice', 'info', 'debug', 'trace'];

    public function newState(): ApacheLogAnalysisState
    {
        return new ApacheLogAnalysisState($this);
    }

    public function parseLine(string $raw): ?array
    {
        return $this->parseErrorLine($raw) ?? $this->parseAccessLine($raw);
    }

    public function isContinuationLine(string $line): bool
    {
        $trimmed = trim($line);
        return preg_match('/^\s+\S/', $line) === 1
            || preg_match('/^(?:PHP (?:Stack trace|\d+\.)|Stack trace:|#\d+|Caused by:)/i', $trimmed) === 1;
    }

    public function buildRecommendations(array $entries, array $categoryCounts): array
    {
        $advice = $this->categoryAdvice();

        arsort($categoryCounts);
        $recommendations = [];

        foreach ($categoryCounts as $category => $count) {
            if (!isset($advice[$category])) {
                continue;
            }
            $recommendations[] = sprintf(
                '%s (%s): %s',
                $category,
                number_format((int) $count),
                $advice[$category]
            );
            if (count($recommendations) >= 4) {
                break;
            }
        }

        $severe = 0;
        $hintCounts = [];
        foreach ($entries as $entry) {
            if (in_array($entry['level'], ['emerg', 'alert', 'crit', 'error'], true)) {
                $severe++;
            }
            foreach (($entry['hints'] ?? []) as $hint) {
                $hintText = (string) $hint;
                if ($hintText === '') {
                    continue;
                }
                $hintCounts[$hintText] = ($hintCounts[$hintText] ?? 0) + 1;
            }
        }

        if ($severe > 0) {
            array_unshift(
                $recommendations,
                sprintf('Prioritize the %s critical/error entries, beginning with the earliest occurrence.', number_format($severe))
            );
        }

        arsort($hintCounts);
        foreach (array_slice($hintCounts, 0, 4, true) as $hint => $count) {
            $recommendations[] = sprintf(
                'Diagnostic hint seen in %s entries: %s',
                number_format((int) $count),
                $hint
            );
        }

        return array_slice(array_values(array_unique($recommendations)), 0, 8);
    }

    public function diagnosticHintsForEntry(array $entry): array
    {
        $message = (string) ($entry['message'] ?? '');
        $module = (string) ($entry['module'] ?? '');
        $category = (string) ($entry['category'] ?? 'Other');
        $status = is_numeric($entry['status'] ?? null) ? (int) $entry['status'] : 0;
        $level = (string) ($entry['level'] ?? '');
        $code = strtoupper((string) ($entry['code'] ?? ''));
        $text = strtolower($module . ' ' . $message . ' ' . $code);
        $hints = [];

        foreach ($this->diagnosticHintRules() as $rule) {
            if (isset($rule['category']) && $rule['category'] !== $category) {
                continue;
            }
            if (isset($rule['levels']) && !in_array($level, $rule['levels'], true)) {
                continue;
            }
            if (isset($rule['statuses']) && !in_array($status, $rule['statuses'], true)) {
                continue;
            }
            if (isset($rule['statusMin']) && $status < $rule['statusMin']) {
                continue;
            }
            if (isset($rule['statusMax']) && ($status === 0 || $status > $rule['statusMax'])) {
                continue;
            }
            if (isset($rule['codes']) && !in_array($code, $rule['codes'], true)) {
                continue;
            }
            if (isset($rule['pattern']) && preg_match($rule['pattern'], $text) !== 1) {
                continue;
            }

            foreach ($rule['hints'] as $hint) {
                $hints[] = $hint;
            }
        }

        if (!$hints && isset($this->categoryAdvice()[$category])) {
            $hints[] = $this->categoryAdvice()[$category];
        }

        if (in_array($level, ['emerg', 'alert', 'crit'], true)) {
            array_unshift($hints, 'Treat this as service-impacting until proven otherwise; correlate with the first occurrence, recent deploys, and host-level resource graphs.');
        } elseif ($level === 'error') {
            array_unshift($hints, 'Correlate this error with adjacent access-log requests, upstream health, and any application exceptions at the same timestamp.');
        }

        return array_slice(array_values(array_unique($hints)), 0, 5);
    }

    private function categoryAdvice(): array
    {
        return [
            'Access & permissions' => 'Check filesystem ownership and mode, then review Apache authorization directives for the affected path.',
            'Missing resources' => 'Verify deployment paths and rewrite rules; repeated 404s may also identify stale links or automated scans.',
            'Proxy & timeouts' => 'Check upstream service health and latency before increasing ProxyTimeout or application timeout values.',
            'TLS & certificates' => 'Validate the certificate chain, hostname, expiry, and enabled protocols on the affected virtual host.',
            'Application & scripts' => 'Inspect the first application exception or stack trace and correlate it with the deployment at that time.',
            'Database' => 'Check database availability, connection limits, credentials, and slow queries from the same time window.',
            'Resource limits' => 'Review memory, file-descriptor, process, and MaxRequestWorkers usage before raising any limits.',
            'Configuration' => 'Run apachectl configtest and review the cited directive or module before reloading Apache.',
            'Server errors' => 'Correlate 5xx responses with error-log entries and upstream or application health in the same time window.',
            'Client errors' => 'Review the most frequent client-error paths and sources; separate broken links from bots or malicious scans.',
            'Redirects' => 'Check repeated redirects for loops and confirm canonical host and HTTPS rewrite rules.',
            'Successful requests' => 'Use successful traffic as a baseline for volume, latency-sensitive paths, and clients affected by nearby warnings.',
            'Other' => 'Start with the earliest high-severity entry; later messages are often downstream symptoms.',
        ];
    }

    private function diagnosticHintRules(): array
    {
        return [
            [
                'category' => 'Access & permissions',
                'statuses' => [401],
                'hints' => [
                    'For 401 responses, verify the configured authentication provider, expected credentials, and whether clients are missing required Authorization headers.',
                    'Check recent changes to Basic/AuthType/AuthName, Require directives, SSO proxy headers, or application login middleware.',
                ],
            ],
            [
                'category' => 'Access & permissions',
                'statuses' => [403],
                'hints' => [
                    'For 403 responses, compare filesystem ownership, directory execute bits, SELinux/AppArmor denials, and Apache Require rules for the requested path.',
                    'Check whether .htaccess, Directory, Location, or FilesMatch rules are denying a path that should be public.',
                ],
            ],
            [
                'category' => 'Access & permissions',
                'pattern' => '/permission denied|client denied|access forbidden|authz|authn|authorization|authentication/',
                'hints' => [
                    'Inspect Apache authz/authn rules, group membership, and inherited Directory blocks before changing file permissions.',
                    'Search audit logs for SELinux/AppArmor denials if Unix permissions appear correct.',
                ],
            ],
            [
                'category' => 'Missing resources',
                'statuses' => [404, 410],
                'hints' => [
                    'For missing resources, confirm the file exists under the active DocumentRoot and that aliases or rewrite rules resolve to the expected path.',
                    'Group repeated 404s by URL and client to distinguish broken deploy links from scanners, crawlers, or stale cached assets.',
                ],
            ],
            [
                'category' => 'Missing resources',
                'pattern' => '/file does not exist|not found|no such file|cannot find/',
                'hints' => [
                    'Check case sensitivity, symlinks, deployment artifact paths, and whether the virtual host is serving the intended DocumentRoot.',
                    'If the path should be routed to an app, verify RewriteRule/FallbackResource/front-controller behavior.',
                ],
            ],
            [
                'category' => 'Proxy & timeouts',
                'statuses' => [502, 503, 504],
                'hints' => [
                    'For gateway errors, check upstream process health, listen sockets, DNS resolution, and load balancer target availability first.',
                    'Compare Apache timeout settings with upstream application latency before increasing ProxyTimeout or Timeout.',
                ],
            ],
            [
                'category' => 'Proxy & timeouts',
                'pattern' => '/timeout|timed out|connection refused|connection reset|bad gateway|gateway timeout|proxy error|upstream|backend/',
                'hints' => [
                    'Inspect upstream application logs for the same timestamp and client path; Apache is often only reporting the downstream symptom.',
                    'Check keepalive, connection pool exhaustion, firewall rules, and whether the backend restarted or rotated ports.',
                ],
            ],
            [
                'category' => 'TLS & certificates',
                'pattern' => '/ssl|tls|certificate|cert|handshake|cipher|ocsp|sni/',
                'hints' => [
                    'Validate certificate expiry, hostname/SAN coverage, intermediate chain, private-key match, and SNI virtual host selection.',
                    'Compare client protocol/cipher support with SSLProtocol and SSLCipherSuite settings before disabling security controls.',
                    'Check OCSP stapling, reverse proxy TLS termination, and whether errors began after certificate renewal.',
                ],
            ],
            [
                'category' => 'Application & scripts',
                'pattern' => '/php|cgi|fastcgi|fcgid|proxy_fcgi|script|exception|stack trace|segmentation fault|premature end/',
                'hints' => [
                    'Inspect the application log or PHP-FPM pool log for the first exception or fatal error at this timestamp.',
                    'Check deployment changes, file ownership, PHP-FPM socket permissions, pool saturation, and max_children exhaustion.',
                    'For repeated script failures, reproduce the specific route with the same method, query string, and authenticated user context.',
                ],
            ],
            [
                'category' => 'Database',
                'pattern' => '/database|mysql|mariadb|postgres|pgsql|sqlstate|redis|memcached|connection refused|too many connections/',
                'hints' => [
                    'Check database service availability, connection limits, credentials, DNS, and network ACLs from the web host.',
                    'Correlate with slow query logs, lock waits, connection pool usage, and recent schema or credential changes.',
                ],
            ],
            [
                'category' => 'Resource limits',
                'pattern' => '/out of memory|memory exhausted|too many open files|file descriptor|maxrequestworkers|server reached|resource temporarily|no space left|disk full/',
                'hints' => [
                    'Check memory, disk, inode, file-descriptor, process, and MaxRequestWorkers usage before changing application code.',
                    'Inspect whether traffic spikes, stuck workers, large uploads, or log growth exhausted a shared system limit.',
                    'Review ulimit/systemd limits and Apache MPM settings if the host metrics do not explain the failure.',
                ],
            ],
            [
                'category' => 'Configuration',
                'pattern' => '/configuration|config|invalid command|syntax error|unknown directive|module|could not bind|address already in use/',
                'hints' => [
                    'Run apachectl configtest before reloads and identify the exact included file defining the failing directive.',
                    'Check module enablement, Include order, virtual host conflicts, Listen ports, and environment-specific paths.',
                ],
            ],
            [
                'category' => 'Server errors',
                'statusMin' => 500,
                'statusMax' => 599,
                'hints' => [
                    'For 5xx responses, correlate the request path with error-log entries and upstream/application logs at the same second.',
                    'Check whether only one vhost, backend, route, or client population is affected before broad server changes.',
                ],
            ],
            [
                'category' => 'Client errors',
                'statuses' => [400],
                'hints' => [
                    'For 400 responses, inspect malformed request lines, invalid Host headers, oversized headers, and clients using the wrong protocol for the port.',
                    'Check proxy or load balancer rewrites if many bad requests originate from trusted internal addresses.',
                ],
            ],
            [
                'category' => 'Client errors',
                'statuses' => [405],
                'hints' => [
                    'For 405 responses, confirm the route supports the requested HTTP method and review Limit/LimitExcept or application method restrictions.',
                    'Compare legitimate browser traffic with API clients that may be using POST, PUT, PATCH, or DELETE against the wrong endpoint.',
                ],
            ],
            [
                'category' => 'Client errors',
                'statuses' => [408],
                'hints' => [
                    'For request timeouts, check slow clients, keepalive behavior, request body upload speed, and whether edge proxies are holding connections open.',
                    'If timeouts spike with traffic, compare connection counts, worker saturation, and network packet loss.',
                ],
            ],
            [
                'category' => 'Client errors',
                'statuses' => [413, 414, 431],
                'hints' => [
                    'For oversized request errors, compare Apache limits with proxy, WAF, PHP, and application upload/header limits.',
                    'Identify the exact client and endpoint before raising limits globally.',
                ],
            ],
            [
                'category' => 'Client errors',
                'statuses' => [429],
                'hints' => [
                    'For rate-limit responses, verify whether throttling is coming from Apache modules, the application, a WAF, or an upstream proxy.',
                    'Group by client IP, API key, route, and user agent before changing rate-limit thresholds.',
                ],
            ],
            [
                'category' => 'Client errors',
                'statusMin' => 400,
                'statusMax' => 499,
                'hints' => [
                    'For 4xx spikes, group by path, referrer, user agent, and client to separate broken links, bad clients, and suspicious scanning.',
                    'Check request size limits, method restrictions, WAF/mod_security rules, and stale cached asset references.',
                ],
            ],
            [
                'category' => 'Redirects',
                'statusMin' => 300,
                'statusMax' => 399,
                'hints' => [
                    'For repeated redirects, verify canonical host, HTTPS enforcement, trailing slash rules, and app-level redirect middleware.',
                    'Use curl -I -L against the exact URL to detect loops and conflicting proxy headers like X-Forwarded-Proto.',
                ],
            ],
            [
                'pattern' => '/mod_security|security2|waf|access denied with code|rule id/',
                'hints' => [
                    'Review mod_security audit logs for the matched rule ID, request payload, and anomaly score before disabling a rule.',
                    'Confirm whether this is a legitimate blocked attack, a false positive, or a client sending malformed input.',
                ],
            ],
            [
                'pattern' => '/request body|entity too large|limitrequestbody|413/',
                'hints' => [
                    'Check upload size limits across Apache, reverse proxies, PHP/application settings, and any WAF layer.',
                    'Confirm whether failures are concentrated on large uploads or API clients sending oversized payloads.',
                ],
            ],
            [
                'pattern' => '/dns|name resolution|could not resolve|temporary failure in name resolution/',
                'hints' => [
                    'Check resolver configuration, DNS latency, upstream hostnames, and whether failures match DNS provider incidents.',
                    'Prefer fixing name resolution or service discovery before increasing proxy retry counts.',
                ],
            ],
            [
                'pattern' => '/bot|crawler|scanner|wp-login|xmlrpc|phpmyadmin|\.env|\.git/',
                'hints' => [
                    'Likely automated probing: confirm no sensitive paths are exposed, then rate-limit or block abusive clients at the edge.',
                    'Review access volume by IP and user agent before adding app-level handling for scanner traffic.',
                ],
            ],
        ];
    }

    public function displayLevel(string $level): string
    {
        return $level === 'crit' ? 'Critical' : ucfirst($level);
    }

    private function parseErrorLine(string $raw): ?array
    {
        $trimmed = trim($raw);
        if ($trimmed === '' || $trimmed[0] !== '[') {
            return null;
        }

        $groups = [];
        $rest = $trimmed;
        while (isset($rest[0]) && $rest[0] === '[') {
            $end = strpos($rest, ']');
            if ($end === false) {
                break;
            }
            $groups[] = substr($rest, 1, $end - 1);
            $rest = ltrim(substr($rest, $end + 1));
        }

        if (count($groups) < 2) {
            return null;
        }

        $levelGroupIndex = -1;
        foreach ($groups as $index => $group) {
            if ($index > 0 && preg_match('/(?:^|:)(?:emerg|alert|crit|error|warn|warning|notice|info|debug|trace\d*)$/i', $group) === 1) {
                $levelGroupIndex = $index;
                break;
            }
        }

        if ($levelGroupIndex < 0) {
            return null;
        }

        $levelParts = explode(':', $groups[$levelGroupIndex]);
        $rawLevel = strtolower((string) array_pop($levelParts));
        $level = $this->normalizeLevel($rawLevel);
        $module = implode(':', $levelParts) ?: 'apache';

        $metadata = [];
        foreach ($groups as $index => $group) {
            if ($index !== 0 && $index !== $levelGroupIndex) {
                $metadata[] = $group;
            }
        }
        $metadataText = implode(' ', $metadata);

        $client = '';
        if (preg_match('/(?:client|remote)\s+([^\s\]]+)/i', $metadataText, $match) === 1) {
            $client = $match[1];
        }

        $pid = '';
        if (preg_match('/pid\s+(\d+)/i', $metadataText, $match) === 1) {
            $pid = $match[1];
        }

        $code = '';
        if (preg_match('/\b(AH\d{5})\b/i', $rest, $match) === 1) {
            $code = strtoupper($match[1]);
        }

        $message = $rest !== '' ? $rest : '(No message supplied)';

        return [
            'kind' => 'error',
            'timestamp' => $groups[0],
            'dateEpoch' => $this->parseApacheDate($groups[0]),
            'level' => $level,
            'module' => $module,
            'client' => $client,
            'pid' => $pid,
            'code' => $code,
            'status' => '',
            'bytes' => null,
            'referrer' => '',
            'userAgent' => '',
            'category' => $this->categorizeEntry($message, $module, ''),
            'message' => $message,
        ];
    }

    private function parseAccessLine(string $raw): ?array
    {
        $pattern = '/^(\S+)\s+\S+\s+\S+\s+\[([^\]]+)]\s+"([^"\s]+)(?:\s+([^"\s]*)(?:\s+(HTTP\/[\d.]+))?)?"\s+(\d{3})\s+(\S+)(?:\s+"([^"]*)"\s+"([^"]*)")?/';
        if (preg_match($pattern, $raw, $match) !== 1) {
            return null;
        }

        $status = (int) $match[6];
        $method = $match[3];
        $path = $match[4] ?? '';
        $protocol = $match[5] ?? '';
        $level = $status >= 500 ? 'error' : ($status >= 400 ? 'warn' : ($status >= 300 ? 'notice' : 'info'));
        $message = $method . ($path !== '' ? ' ' . $path : '') . ($protocol !== '' ? ' ' . $protocol : '') . ' returned ' . $status;

        return [
            'kind' => 'access',
            'timestamp' => $match[2],
            'dateEpoch' => $this->parseApacheDate($match[2]),
            'level' => $level,
            'module' => 'access',
            'client' => $match[1],
            'pid' => '',
            'code' => '',
            'status' => (string) $status,
            'bytes' => $match[7] === '-' ? 0 : (int) $match[7],
            'referrer' => $match[8] ?? '',
            'userAgent' => $match[9] ?? '',
            'category' => $this->categorizeEntry($message, 'access', (string) $status),
            'message' => $message,
        ];
    }

    private function normalizeLevel(string $level): string
    {
        if (strpos($level, 'trace') === 0) {
            return 'trace';
        }
        return $level === 'warning' ? 'warn' : $level;
    }

    private function categorizeEntry(string $message, string $module, string $status): string
    {
        $text = strtolower($module . ' ' . $message);
        $numericStatus = is_numeric($status) ? (int) $status : 0;

        if (preg_match('/permission denied|access forbidden|client denied|authorization|authentication|authz|authn|forbidden/', $text) === 1 || in_array($numericStatus, [401, 403], true)) {
            return 'Access & permissions';
        }
        if (preg_match('/not found|file does not exist|cannot find|no such file/', $text) === 1 || $numericStatus === 404) {
            return 'Missing resources';
        }
        if (preg_match('/timeout|timed out|proxy|upstream|backend|connection refused|bad gateway|gateway timeout/', $text) === 1 || in_array($numericStatus, [502, 503, 504], true)) {
            return 'Proxy & timeouts';
        }
        if (preg_match('/ssl|tls|certificate|handshake/', $text) === 1) {
            return 'TLS & certificates';
        }
        if (preg_match('/php|cgi|script|premature end of script|syntax error|uncaught|exception|stack trace/', $text) === 1) {
            return 'Application & scripts';
        }
        if (preg_match('/database|mysql|postgres|sqlstate|redis|database connection/', $text) === 1) {
            return 'Database';
        }
        if (preg_match('/memory|out of memory|too many open files|server reached|maxrequestworkers|resource temporarily/', $text) === 1) {
            return 'Resource limits';
        }
        if (preg_match('/configuration|config|invalid command|syntax error/', $text) === 1) {
            return 'Configuration';
        }
        if ($numericStatus >= 500) {
            return 'Server errors';
        }
        if ($numericStatus >= 400) {
            return 'Client errors';
        }
        if ($numericStatus >= 300) {
            return 'Redirects';
        }
        if ($numericStatus >= 200) {
            return 'Successful requests';
        }

        return 'Other';
    }

    private function parseApacheDate(string $value): ?int
    {
        $date = DateTimeImmutable::createFromFormat('d/M/Y:H:i:s O', $value);
        if ($date instanceof DateTimeImmutable) {
            return $date->getTimestamp();
        }

        $cleaned = preg_replace('/(\d{2}:\d{2}:\d{2})\.\d+/', '$1', $value);
        $cleaned = $cleaned !== null ? preg_replace('/\s+/', ' ', trim($cleaned)) : trim($value);

        foreach (['D M j H:i:s Y', 'D M d H:i:s Y', 'D M j H:i:s Y T', 'D M d H:i:s Y T'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, (string) $cleaned);
            if ($date instanceof DateTimeImmutable) {
                return $date->getTimestamp();
            }
        }

        try {
            $date = new DateTimeImmutable((string) $cleaned);
            return $date->getTimestamp();
        } catch (Exception $exception) {
            return null;
        }
    }
}

function apache_log_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $config = [
        'log_dir' => getenv('APACHE_LOG_DIR') ?: '/var/log/httpd',
        'max_initial_bytes' => apache_env_int('APACHE_LOG_MAX_INITIAL_BYTES', 25 * 1024 * 1024, 1024 * 1024, 512 * 1024 * 1024),
        'json_entry_limit' => apache_env_int('APACHE_LOG_JSON_ENTRY_LIMIT', 5000, 100, 50000),
        'pdf_entry_limit' => apache_env_int('APACHE_LOG_PDF_ENTRY_LIMIT', 5000, 100, 50000),
        //Shorter than the standalone tool's hour: a live stream holds one web
        //server worker for its whole life, and an admin who wandered off should
        //not keep one for an hour. Raise it with APACHE_LOG_STREAM_MAX_SECONDS.
        'stream_max_seconds' => apache_env_int('APACHE_LOG_STREAM_MAX_SECONDS', 900, 30, 86400),
        'stream_poll_microseconds' => apache_env_int('APACHE_LOG_STREAM_POLL_US', 1500000, 250000, 10000000),
        'read_chunk_bytes' => apache_env_int('APACHE_LOG_READ_CHUNK_BYTES', 1048576, 8192, 8 * 1024 * 1024),
    ];

    return $config;
}

function apache_env_int(string $name, int $default, int $min, int $max): int
{
    $value = getenv($name);
    if ($value === false || !is_numeric($value)) {
        return $default;
    }

    return max($min, min($max, (int) $value));
}

function apache_list_log_files(array $config): array
{
    $dir = $config['log_dir'];
    if (!is_dir($dir)) {
        throw new ApacheLogHttpException(sprintf('Log directory not found: %s', $dir), 404);
    }
    if (!is_readable($dir)) {
        throw new ApacheLogHttpException(sprintf('Log directory is not readable: %s', $dir), 403);
    }

    $base = realpath($dir);
    if ($base === false) {
        throw new ApacheLogHttpException(sprintf('Unable to resolve log directory: %s', $dir), 404);
    }

    $files = [];
    $entries = scandir($base);
    if ($entries === false) {
        throw new ApacheLogHttpException(sprintf('Unable to scan log directory: %s', $dir), 500);
    }

    foreach ($entries as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }

        $path = $base . DIRECTORY_SEPARATOR . $name;
        $realPath = realpath($path);
        if ($realPath === false || !is_file($realPath)) {
            continue;
        }

        if (strpos($realPath, $base . DIRECTORY_SEPARATOR) !== 0) {
            continue;
        }

        $compressed = preg_match('/\.(?:gz|bz2|xz|zip)$/i', $name) === 1;
        $readable = is_readable($realPath);
        $size = filesize($realPath);
        $modified = filemtime($realPath);

        $files[] = [
            'id' => apache_base64url_encode($name),
            'name' => $name,
            'size' => $size === false ? 0 : $size,
            'modifiedEpoch' => $modified === false ? null : $modified,
            'readable' => $readable,
            'supported' => $readable && !$compressed,
            'note' => $compressed ? 'Compressed logs are listed but not streamed.' : ($readable ? '' : 'Not readable by the web server user.'),
        ];
    }

    usort($files, static function (array $a, array $b): int {
        return ($b['modifiedEpoch'] ?? 0) <=> ($a['modifiedEpoch'] ?? 0);
    });

    return $files;
}

function apache_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function apache_base64url_decode(string $value): string
{
    $normalized = strtr($value, '-_', '+/');
    $padding = strlen($normalized) % 4;
    if ($padding > 0) {
        $normalized .= str_repeat('=', 4 - $padding);
    }

    $decoded = base64_decode($normalized, true);
    if ($decoded === false) {
        throw new ApacheLogHttpException('Invalid log file identifier.', 400);
    }
    return $decoded;
}

function apache_safe_log_path(string $id, array $config): array
{
    $name = apache_base64url_decode($id);
    if ($name === '' || strpos($name, "\0") !== false || basename($name) !== $name) {
        throw new ApacheLogHttpException('Invalid log file selection.', 400);
    }

    $dir = $config['log_dir'];
    $base = realpath($dir);
    if ($base === false || !is_dir($base)) {
        throw new ApacheLogHttpException(sprintf('Log directory not found: %s', $dir), 404);
    }

    $path = realpath($base . DIRECTORY_SEPARATOR . $name);
    if ($path === false || !is_file($path) || strpos($path, $base . DIRECTORY_SEPARATOR) !== 0) {
        throw new ApacheLogHttpException('Selected log file was not found.', 404);
    }
    if (!is_readable($path)) {
        throw new ApacheLogHttpException('Selected log file is not readable by the web server user.', 403);
    }
    if (preg_match('/\.(?:gz|bz2|xz|zip)$/i', $name) === 1) {
        throw new ApacheLogHttpException('Compressed logs cannot be streamed by this analyzer.', 415);
    }

    return [$path, $name];
}

function apache_read_file_window(string $path, int $maxBytes): array
{
    clearstatcache(true, $path);
    $fileSize = filesize($path);
    if ($fileSize === false) {
        throw new ApacheLogHttpException('Unable to read selected log file size.', 500);
    }

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new ApacheLogHttpException('Unable to open selected log file.', 500);
    }

    $startOffset = 0;
    $truncated = false;
    if ($fileSize > $maxBytes) {
        $startOffset = max(0, $fileSize - $maxBytes);
        fseek($handle, $startOffset);
        fgets($handle);
        $current = ftell($handle);
        if ($current !== false) {
            $startOffset = $current;
        }
        $truncated = true;
    }

    fseek($handle, $startOffset);
    $content = stream_get_contents($handle);
    $endOffset = ftell($handle);
    fclose($handle);

    if ($content === false) {
        throw new ApacheLogHttpException('Unable to read selected log file.', 500);
    }

    return [
        $content,
        [
            'fileSize' => $fileSize,
            'bytesAnalyzed' => strlen($content),
            'initialStartOffset' => $startOffset,
            'readEndOffset' => $endOffset === false ? $fileSize : $endOffset,
            'initialWindowTruncated' => $truncated,
        ],
    ];
}

function apache_analyze_file(string $path, string $fileName, array $config, ?int $entryLimit = null): array
{
    [$content, $meta] = apache_read_file_window($path, $config['max_initial_bytes']);
    $analyzer = new ApacheLogAnalyzer();
    $state = $analyzer->newState();
    $state->appendText($content);

    return $state->toReport(array_merge($meta, [
        'fileName' => $fileName,
        'live' => false,
        'logDir' => $config['log_dir'],
    ]), $entryLimit);
}



function apache_stream_file(string $path, string $fileName, array $config): void
{
    @ini_set('display_errors', '0');
    @ini_set('zlib.output_compression', '0');
    @set_time_limit(0);
    ignore_user_abort(false);

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');

    while (ob_get_level() > 0) {
        @ob_end_flush();
    }

    $analyzer = new ApacheLogAnalyzer();
    $state = $analyzer->newState();
    [$content, $meta] = apache_read_file_window($path, $config['max_initial_bytes']);
    $lineNumber = $state->appendText($content);
    $offset = $meta['readEndOffset'];
    $meta['fileName'] = $fileName;
    $meta['live'] = true;
    $meta['logDir'] = $config['log_dir'];

    apache_sse_event('analysis', [
        'ok' => true,
        'report' => $state->toReport($meta, $config['json_entry_limit']),
    ]);

    $started = time();
    $lastHeartbeat = time();
    $partial = '';

    while (!connection_aborted() && (time() - $started) < $config['stream_max_seconds']) {
        clearstatcache(true, $path);
        $size = filesize($path);

        if ($size === false) {
            apache_sse_event('server-error', ['ok' => false, 'error' => 'Unable to stat selected log file.']);
            break;
        }

        if ($size < $offset) {
            $state = $analyzer->newState();
            [$content, $meta] = apache_read_file_window($path, $config['max_initial_bytes']);
            $lineNumber = $state->appendText($content);
            $offset = $meta['readEndOffset'];
            $partial = '';
            $meta['fileName'] = $fileName;
            $meta['live'] = true;
            $meta['logDir'] = $config['log_dir'];

            apache_sse_event('reset', ['ok' => true, 'message' => 'Log file was rotated or truncated; analysis was rebuilt from the latest window.']);
            apache_sse_event('analysis', [
                'ok' => true,
                'report' => $state->toReport($meta, $config['json_entry_limit']),
            ]);
        } elseif ($size > $offset) {
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                apache_sse_event('server-error', ['ok' => false, 'error' => 'Unable to reopen selected log file.']);
                break;
            }

            fseek($handle, $offset);
            $bytesRead = 0;
            while (!feof($handle)) {
                $chunk = fread($handle, $config['read_chunk_bytes']);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $bytesRead += strlen($chunk);
                apache_append_stream_chunk($state, $partial, $chunk, $lineNumber);
            }
            $newOffset = ftell($handle);
            fclose($handle);

            if ($newOffset !== false) {
                $offset = $newOffset;
            } else {
                $offset = $size;
            }

            $meta['fileSize'] = $size;
            $meta['bytesAnalyzed'] += $bytesRead;
            $meta['readEndOffset'] = $offset;

            apache_sse_event('analysis', [
                'ok' => true,
                'report' => $state->toReport($meta, $config['json_entry_limit']),
            ]);
        } elseif (time() - $lastHeartbeat >= 15) {
            apache_sse_event('heartbeat', ['ok' => true, 'time' => gmdate(DATE_ATOM)]);
            $lastHeartbeat = time();
        }

        usleep($config['stream_poll_microseconds']);
    }

    apache_sse_event('end', ['ok' => true, 'message' => 'Live stream ended. Restart analysis to continue watching.']);
}

function apache_append_stream_chunk(ApacheLogAnalysisState $state, string &$partial, string $chunk, int &$lineNumber): void
{
    $buffer = $partial . $chunk;
    $hasTrailingNewline = preg_match('/(?:\r\n|\n|\r)$/', $buffer) === 1;
    $lines = preg_split('/\r\n|\n|\r/', $buffer);
    if ($lines === false) {
        $partial = '';
        return;
    }

    if ($hasTrailingNewline) {
        array_pop($lines);
        $partial = '';
    } else {
        $partial = (string) array_pop($lines);
    }

    foreach ($lines as $line) {
        $lineNumber++;
        $state->appendLine($line, $lineNumber);
    }
}

function apache_sse_event(string $event, array $payload): void
{
    echo 'event: ' . $event . "\n";
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        $json = '{"ok":false,"error":"Unable to encode event payload."}';
    }

    foreach (explode("\n", $json) as $line) {
        echo 'data: ' . $line . "\n";
    }
    echo "\n";
    @ob_flush();
    flush();
}


function apache_format_bytes(int $bytes): string
{
    if ($bytes <= 0) {
        return '0 bytes';
    }
    $units = ['bytes', 'KB', 'MB', 'GB'];
    $index = min((int) floor(log($bytes, 1024)), count($units) - 1);
    $value = $bytes / (1024 ** $index);
    return number_format($value, $index > 0 ? 1 : 0) . ' ' . $units[$index];
}

function apache_format_duration(?int $first, ?int $last): string
{
    if ($first === null || $last === null) {
        return 'Unknown';
    }

    $seconds = max(0, $last - $first);
    if ($seconds < 60) {
        return $seconds . 's';
    }
    if ($seconds < 3600) {
        return round($seconds / 60) . 'm';
    }
    if ($seconds < 86400) {
        return number_format($seconds / 3600, 1) . 'h';
    }
    return number_format($seconds / 86400, 1) . 'd';
}

function apache_format_date_range(?int $first, ?int $last): string
{
    if ($first === null || $last === null) {
        return 'Timestamps unavailable';
    }

    $firstValue = date('M j, Y g:i:s A', $first);
    $lastValue = date('M j, Y g:i:s A', $last);
    return $first === $last ? $firstValue : $firstValue . ' to ' . $lastValue;
}

function apache_build_pdf(array $report): string
{
    $severeCount = apache_sum_levels($report['severityCounts'] ?? [], ['emerg', 'alert', 'crit', 'error']);
    $entryCount = (int) ($report['entryCount'] ?? count($report['entries'] ?? []));
    $entries = $report['entries'] ?? [];
    $lines = [
        ['text' => 'Apache Log Analysis Report', 'style' => 'title'],
        ['text' => 'Source: ' . ($report['fileName'] ?? 'unknown') . ' (' . apache_format_bytes((int) ($report['fileSize'] ?? 0)) . ')', 'style' => 'meta'],
        ['text' => 'Generated: ' . date('M j, Y g:i:s A'), 'style' => 'meta'],
        ['text' => ''],
        ['text' => 'Executive summary', 'style' => 'heading'],
        ['text' => 'Parsed entries: ' . number_format($entryCount) . ' | Critical/errors: ' . number_format($severeCount) . ' | Warnings: ' . number_format((int) (($report['severityCounts']['warn'] ?? 0))) . ' | Unique clients: ' . number_format((int) ($report['uniqueClients'] ?? 0))],
        ['text' => 'Observed period: ' . apache_format_date_range($report['firstTimestampEpoch'] ?? null, $report['lastTimestampEpoch'] ?? null) . ' (' . apache_format_duration($report['firstTimestampEpoch'] ?? null, $report['lastTimestampEpoch'] ?? null) . ')'],
        ['text' => 'Unrecognized lines skipped: ' . number_format((int) ($report['ignoredLines'] ?? 0))],
    ];

    if (!empty($report['initialWindowTruncated'])) {
        $lines[] = ['text' => 'Initial scan window: latest ' . apache_format_bytes((int) ($report['bytesAnalyzed'] ?? 0)) . ' of this log file'];
    }
    if (!empty($report['entriesLimited'])) {
        $lines[] = ['text' => 'Detailed entries in this PDF are limited to the latest ' . number_format(count($entries)) . ' parsed entries.'];
    }

    $lines[] = ['text' => ''];
    $lines[] = ['text' => 'Issue categories', 'style' => 'heading'];

    $categoryCounts = $report['categoryCounts'] ?? [];
    arsort($categoryCounts);
    foreach ($categoryCounts as $category => $count) {
        $percent = $entryCount > 0 ? (int) round(((int) $count / $entryCount) * 100) : 0;
        $lines[] = ['text' => $category . ': ' . number_format((int) $count) . ' (' . $percent . '%)'];
    }

    $lines[] = ['text' => ''];
    $lines[] = ['text' => 'Recommended next steps', 'style' => 'heading'];
    foreach (($report['recommendations'] ?? []) as $index => $recommendation) {
        $lines[] = ['text' => ($index + 1) . '. ' . $recommendation];
    }

    $lines[] = ['text' => ''];
    $lines[] = ['text' => 'Detailed entries', 'style' => 'heading'];

    foreach ($entries as $index => $entry) {
        $sourceParts = array_values(array_filter([
            $entry['module'] ?? '',
            $entry['client'] ?? '',
            !empty($entry['code']) ? $entry['code'] : (!empty($entry['status']) ? 'HTTP ' . $entry['status'] : ''),
        ]));
        $source = count($sourceParts) ? implode(' | ', $sourceParts) : 'n/a';
        $lines[] = ['text' => ($index + 1) . '. [' . ($entry['timestamp'] ?? '') . '] ' . strtoupper((string) ($entry['level'] ?? '')) . ' | ' . ($entry['category'] ?? 'Other'), 'style' => 'entry'];
        $lines[] = ['text' => 'Source: ' . $source];
        $lines[] = ['text' => 'Message: ' . ($entry['message'] ?? '')];
        foreach (array_slice(($entry['hints'] ?? []), 0, 3) as $hint) {
            $lines[] = ['text' => 'Hint: ' . $hint];
        }
        $lines[] = ['text' => ''];
    }

    return apache_serialize_pdf(apache_paginate_pdf_lines($lines));
}

function apache_sum_levels(array $counts, array $levels): int
{
    $total = 0;
    foreach ($levels as $level) {
        $total += (int) ($counts[$level] ?? 0);
    }
    return $total;
}

function apache_paginate_pdf_lines(array $lines): array
{
    $pages = [];
    $page = [];
    $y = 744;

    foreach ($lines as $line) {
        $style = $line['style'] ?? 'body';
        $fontSize = $style === 'title' ? 18 : ($style === 'heading' ? 13 : ($style === 'entry' ? 10 : 9));
        $lineHeight = $style === 'title' ? 25 : ($style === 'heading' ? 20 : (($line['text'] ?? '') !== '' ? 13 : 8));
        $maxChars = $fontSize >= 13 ? 72 : 96;
        $wrapped = apache_wrap_text(apache_pdf_ascii((string) ($line['text'] ?? '')), $maxChars);

        if ($y - (count($wrapped) * $lineHeight) < 45) {
            if (count($page) > 0) {
                $pages[] = $page;
            }
            $page = [];
            $y = 744;
        }

        foreach ($wrapped as $index => $text) {
            $page[] = [
                'text' => $text,
                'y' => $y,
                'fontSize' => $fontSize,
                'bold' => in_array($style, ['title', 'heading', 'entry'], true) && $index === 0,
            ];
            $y -= $lineHeight;
        }
    }

    if (count($page) > 0) {
        $pages[] = $page;
    }

    return $pages ?: [[]];
}

function apache_serialize_pdf(array $pages): string
{
    $objects = [null, '', '', '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>', '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>'];
    $pageIds = [];

    foreach ($pages as $pageIndex => $page) {
        $commands = [];
        foreach ($page as $line) {
            $commands[] = sprintf(
                'BT /F%d %d Tf 42 %d Td (%s) Tj ET',
                $line['bold'] ? 2 : 1,
                $line['fontSize'],
                $line['y'],
                apache_escape_pdf_text($line['text'])
            );
        }
        $footer = "BT /F1 8 Tf 42 24 Td (Apache Log Analyzer) Tj ET\n"
            . sprintf('BT /F1 8 Tf 520 24 Td (Page %d of %d) Tj ET', $pageIndex + 1, count($pages));
        $stream = implode("\n", $commands) . "\n" . $footer;
        $contentId = count($objects);
        $objects[] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
        $pageId = count($objects);
        $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $contentId . ' 0 R >>';
        $pageIds[] = $pageId;
    }

    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', array_map(static function ($id): string {
        return $id . ' 0 R';
    }, $pageIds)) . '] /Count ' . count($pageIds) . ' >>';

    $output = "%PDF-1.4\n";
    $offsets = [0];
    for ($index = 1; $index < count($objects); $index++) {
        $offsets[$index] = strlen($output);
        $output .= $index . " 0 obj\n" . $objects[$index] . "\nendobj\n";
    }

    $xrefOffset = strlen($output);
    $output .= "xref\n0 " . count($objects) . "\n0000000000 65535 f \n";
    for ($index = 1; $index < count($objects); $index++) {
        $output .= str_pad((string) $offsets[$index], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
    }
    $output .= "trailer\n<< /Size " . count($objects) . " /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF";

    return $output;
}

function apache_wrap_text(string $text, int $maxChars): array
{
    if ($text === '') {
        return [''];
    }

    $words = preg_split('/\s+/', $text);
    if ($words === false) {
        return [$text];
    }

    $lines = [];
    $line = '';

    foreach ($words as $word) {
        while (strlen($word) > $maxChars) {
            if ($line !== '') {
                $lines[] = $line;
                $line = '';
            }
            $lines[] = substr($word, 0, $maxChars);
            $word = substr($word, $maxChars);
        }

        $candidate = $line !== '' ? $line . ' ' . $word : $word;
        if (strlen($candidate) > $maxChars && $line !== '') {
            $lines[] = $line;
            $line = $word;
        } else {
            $line = $candidate;
        }
    }

    if ($line !== '') {
        $lines[] = $line;
    }

    return $lines ?: [''];
}

function apache_pdf_ascii(string $value): string
{
    $value = str_replace(["\xE2\x80\x93", "\xE2\x80\x94", "\xE2\x80\x98", "\xE2\x80\x99", "\xE2\x80\x9C", "\xE2\x80\x9D"], ['-', '-', "'", "'", '"', '"'], $value);
    return preg_replace('/[^\x20-\x7E]/', '?', $value) ?? $value;
}

function apache_escape_pdf_text(string $value): string
{
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
}
